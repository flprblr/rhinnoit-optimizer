<?php
/**
 * Clearing cPanel's NGINX cache after changes.
 *
 * Every clear empties the whole cPanel account, so what must NOT clear - an
 * option nobody sees, a draft, a stock change on a shopper's request - matters
 * as much as what must. uapi is replaced by small scripts written for each
 * case, which leave a mark when they run.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Admin\Settings_Page;
use RhinnoIT\Optimizer\Install;
use RhinnoIT\Optimizer\Page_Cache;

#[CoversClass( Page_Cache::class )]
final class PageCacheTest extends TestCase {

	/**
	 * Folder the fake uapi scripts and their marks live in.
	 *
	 * @var string
	 */
	private string $dir;

	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$can = false;

		$this->dir = sys_get_temp_dir() . '/ropt-uapi-' . uniqid();
		mkdir( $this->dir );
		// What ea-nginx installs: a cPanel server with NGINX, the case most of these cover.
		touch( $this->dir . '/ea-nginx.conf' );
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: array() );
		rmdir( $this->dir );
	}

	/**
	 * Writes a fake uapi that leaves a mark and prints the given answer.
	 *
	 * @param string $answer What it prints on stdout.
	 * @param int    $sleep  Seconds it waits first.
	 * @return string Its path.
	 */
	private function uapi( string $answer, int $sleep = 0 ): string {
		$path = $this->dir . '/uapi';

		file_put_contents(
			$path,
			"#!/bin/sh\necho \"$*\" >> " . escapeshellarg( $this->dir . '/called' ) . "\n"
			. ( $sleep ? "sleep {$sleep}\n" : '' )
			. 'printf %s ' . escapeshellarg( $answer ) . "\n"
		);
		chmod( $path, 0755 );

		return $path;
	}

	/**
	 * How many times the fake uapi ran, and with what.
	 *
	 * @return array<int, string>
	 */
	private function calls(): array {
		$file = $this->dir . '/called';

		return is_file( $file ) ? array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) : array();
	}

	/**
	 * A registered service against a uapi that answers success.
	 *
	 * @param int $timeout Seconds allowed.
	 * @return Page_Cache
	 */
	private function service( int $timeout = 20, string $answer = '{"result":{"status":1,"errors":null}}', int $sleep = 0 ): Page_Cache {
		$cache = new Page_Cache( $this->uapi( $answer, $sleep ), $timeout, false, array( $this->dir . '/ea-nginx.conf' ) );
		$cache->register();

		return $cache;
	}

	/**
	 * A registered service against a hand-written fake uapi.
	 *
	 * @param string    $script  Shell lines after the shebang.
	 * @param int       $timeout Seconds allowed.
	 * @param bool|null $as_root Whether to act as root.
	 * @return Page_Cache
	 */
	private function service_with( string $script, int $timeout = 20, ?bool $as_root = false ): Page_Cache {
		$path = $this->dir . '/uapi';
		file_put_contents( $path, "#!/bin/sh\necho \"$*\" >> " . escapeshellarg( $this->dir . '/called' ) . "\n" . $script . "\n" );
		chmod( $path, 0755 );

		$cache = new Page_Cache( $path, $timeout, $as_root, array( $this->dir . '/ea-nginx.conf' ) );
		$cache->register();

		return $cache;
	}

	public function test_only_the_save_is_heard_while_it_is_off(): void {
		WP_Stub::$options['ropt_page_cache_clear'] = 'no';

		( new Page_Cache() )->register();

		$this->assertSame( array( 'rhinnoit_settings_saved:settings_saved', 'ropt_page_cache_verify:verify_saved_purge', 'shutdown:flush' ), WP_Stub::$callbacks, 'The save that turns it back on still has to clear, and the save that turned it off still gets its check.' );
	}

	public function test_the_flush_runs_last_on_shutdown(): void {
		$this->service();

		$this->assertSame( 100, WP_Stub::$priorities['shutdown:flush'] );
		$this->assertContains( Page_Cache::DIRTY . ':dirty', WP_Stub::$callbacks );
		$this->assertSame( Install::DIRTY_HOOK, Page_Cache::DIRTY, 'Every plugin of the group fires it by the name Install holds.' );
		$this->assertSame( Settings_Page::SAVED_HOOK, Page_Cache::SAVED, 'Every settings screen of the group fires it by the name Settings_Page holds.' );
	}

	public function test_nothing_pending_runs_nothing(): void {
		$this->service()->flush();

		$this->assertSame( array(), $this->calls() );
		$this->assertArrayNotHasKey( Page_Cache::STATE, WP_Stub::$options );
	}

	public function test_an_editors_change_clears_once_with_the_fixed_command(): void {
		WP_Stub::$can = true;
		$cache        = $this->service();

		$cache->post_changed( 'publish', 'publish', new WP_Post( 1, 'product' ) );
		$cache->post_changed( 'publish', 'draft', new WP_Post( 2, 'page' ) );
		$cache->flush();

		$this->assertSame( array( '--output=json NginxCaching clear_cache' ), $this->calls(), 'Two changes, one clear, and nothing but the fixed arguments.' );
		$this->assertSame( 0, WP_Stub::$options[ Page_Cache::STATE ]['failed_until'] );
	}

	public function test_what_visitors_cannot_see_does_not_clear(): void {
		$cache = $this->service();

		$cache->post_changed( 'draft', 'draft', new WP_Post( 1, 'post' ) );
		$cache->post_changed( 'publish', 'publish', new WP_Post( 2, 'shop_order' ) );
		$cache->post_changed( 'publish', 'publish', 'not a post' );
		$cache->comment_changed( 'hold', 'spam' );
		$cache->comment_posted( 5, 0 );
		$cache->term_changed( 1, 1, 'nav_menu_hidden' );
		$cache->option_updated( '_transient_something', 'a', 'b' );
		$cache->option_updated( 'ropt_page_cache_state', array(), array( 'last' => 1 ) );
		$cache->option_added( 'cron' );
		$cache->mark( 'everything' );
		$cache->mark( array( 'site' ) );
		$cache->flush();

		$this->assertSame( array(), $this->calls() );
		$this->assertSame( array(), WP_Stub::$scheduled );
	}

	public function test_a_change_to_theme_mods_only_in_used_blocks_is_ignored(): void {
		$cache = $this->service();

		$cache->option_updated( 'theme_mods_porto-child', array( 'a' => 1, '_used_blocks' => array( 1 ) ), array( 'a' => 1, '_used_blocks' => array( 2 ) ) );
		$cache->flush();
		$this->assertSame( array(), $this->calls(), 'Porto rewrites that key on ordinary post saves.' );

		$cache->option_updated( 'theme_mods_porto-child', array( 'a' => 1 ), array( 'a' => 2 ) );
		$cache->flush();
		$this->assertCount( 1, $this->calls() );
	}

	public function test_site_wide_changes_clear_even_from_a_visitor_request(): void {
		foreach (
			array(
				fn ( Page_Cache $c ) => $c->option_updated( 'porto_settings', array(), array( 'x' => 1 ) ),
				fn ( Page_Cache $c ) => $c->option_added( 'widget_text' ),
				fn ( Page_Cache $c ) => $c->option_updated( 'ropt_srcset_prune', 'yes', 'no' ),
				fn ( Page_Cache $c ) => $c->post_changed( 'publish', 'publish', new WP_Post( 3, 'porto_builder' ) ),
				fn ( Page_Cache $c ) => $c->site_changed(),
				fn ( Page_Cache $c ) => $c->mark( 'sale' ),
			) as $i => $change
		) {
			WP_Stub::$options[ Page_Cache::STATE ] = array( 'last' => 0, 'failed_until' => 0 );
			$before = count( $this->calls() );
			$cache  = $this->service();

			$change( $cache );
			$cache->flush();

			$this->assertCount( $before + 1, $this->calls(), sprintf( 'Change %d did not clear.', $i ) );
		}
	}

	public function test_a_clear_right_after_another_waits_its_turn(): void {
		WP_Stub::$can                          = true;
		WP_Stub::$options[ Page_Cache::STATE ] = array( 'last' => time() - 5, 'failed_until' => 0 );
		$cache                                 = $this->service();

		$cache->site_changed();
		$cache->flush();

		$this->assertSame( array(), $this->calls() );
		$this->assertSame( WP_Stub::$options[ Page_Cache::STATE ]['last'] + Page_Cache::SPACING, WP_Stub::$scheduled[ Page_Cache::EVENT ] );
	}

	public function test_stock_and_visitor_changes_never_clear_on_the_request(): void {
		WP_Stub::$options[ Page_Cache::STATE ] = array( 'last' => time() - 60, 'failed_until' => 0 );

		foreach ( array( 'stock', 'content' ) as $reason ) {
			WP_Stub::$scheduled = array();
			$cache              = $this->service();

			$cache->mark( $reason );
			$cache->flush();

			$this->assertSame( array(), $this->calls(), sprintf( 'A %s change ran uapi on a shopper\'s request.', $reason ) );
			$this->assertSame( time() - 60 + Page_Cache::WINDOW, WP_Stub::$scheduled[ Page_Cache::EVENT ], 'Deferred to one clear per window.' );
		}
	}

	public function test_a_deferred_clear_keeps_the_earliest_time(): void {
		WP_Stub::$options[ Page_Cache::STATE ]   = array( 'last' => time() - 60, 'failed_until' => 0 );
		WP_Stub::$scheduled[ Page_Cache::EVENT ] = time() + 10;
		$cache                                   = $this->service();

		$cache->mark( 'stock' );
		$cache->flush();

		$this->assertSame( time() + 10, WP_Stub::$scheduled[ Page_Cache::EVENT ], 'An earlier clear already covers this one.' );

		WP_Stub::$options[ Page_Cache::STATE ] = array( 'last' => 0, 'failed_until' => 0 );
		$cache                                 = $this->service();

		$cache->mark( 'stock' );
		$cache->flush();

		$this->assertSame( time(), WP_Stub::$scheduled[ Page_Cache::EVENT ], 'With no recent clear, as soon as cron runs.' );
	}

	public function test_the_scheduled_clear_runs_and_clears_the_queue(): void {
		WP_Stub::$scheduled[ Page_Cache::EVENT ] = time();

		$this->service()->run_scheduled();

		$this->assertCount( 1, $this->calls() );
		$this->assertArrayNotHasKey( Page_Cache::EVENT, WP_Stub::$scheduled );
	}

	public function test_a_refused_clear_is_logged_once_and_backs_off(): void {
		WP_Stub::$can = true;
		$cache        = $this->service( 20, '{"result":{"status":0,"errors":["NGINX is not installed.\u0007"]}}' );

		$cache->site_changed();
		$cache->flush();

		$this->assertCount( 1, WP_Stub::$logs );
		$this->assertStringContainsString( 'NGINX is not installed.', WP_Stub::$logs[0] );
		$this->assertStringNotContainsString( "\u{7}", WP_Stub::$logs[0], 'Control characters stay out of the log.' );
		$this->assertGreaterThan( time(), WP_Stub::$options[ Page_Cache::STATE ]['failed_until'] );

		$cache->site_changed();
		WP_Stub::$options[ Page_Cache::STATE ]['last'] = 0;
		$cache->flush();
		$this->assertCount( 1, $this->calls(), 'While backing off, uapi is not started again.' );
	}

	public function test_an_answer_that_is_not_json_is_a_failure(): void {
		WP_Stub::$can = true;
		$cache        = $this->service( 20, 'Segmentation fault' );

		$cache->site_changed();
		$cache->flush();

		$this->assertCount( 1, WP_Stub::$logs );
		$this->assertStringContainsString( 'did not answer with JSON', WP_Stub::$logs[0] );
	}

	public function test_a_stuck_uapi_is_given_up(): void {
		WP_Stub::$can = true;
		$cache        = $this->service( 1, '{"result":{"status":1}}', 5 );
		$started      = microtime( true );

		$cache->site_changed();
		$cache->flush();

		$this->assertLessThan( 4, microtime( true ) - $started, 'A PHP worker must not wait for a stuck uapi.' );
		$this->assertStringContainsString( 'within 1 seconds', WP_Stub::$logs[0] ?? '' );
	}

	public function test_only_a_host_it_can_clear_offers_the_cache_switches(): void {
		$this->assertFalse( ( new Page_Cache( $this->dir . '/missing', 20, false ) )->can_clear(), 'Neither Kinsta nor cPanel: both switches would do nothing.' );
		$this->assertTrue( ( new Page_Cache( $this->uapi( '{"result":{"status":1}}' ), 20, false, array( $this->dir . '/ea-nginx.conf' ) ) )->can_clear(), 'cPanel with NGINX.' );
		$this->assertFalse( ( new Page_Cache( $this->dir . '/uapi', 20, false, array( $this->dir . '/missing.conf' ) ) )->can_clear(), 'cPanel serving pages with Apache or LiteSpeed: no NGINX cache to clear.' );
		$this->assertTrue( ( new Page_Cache( $this->dir . '/uapi', 20, false, array( $this->dir . '/missing.conf', $this->dir . '/ea-nginx.conf' ) ) )->can_clear(), 'Either file ea-nginx installs will do.' );
		$this->assertSame( array(), $this->calls(), 'Asking never runs uapi.' );
	}

	public function test_the_clear_never_waits_on_the_nginx_files(): void {
		WP_Stub::$can = true;
		$cache        = new Page_Cache( $this->uapi( '{"result":{"status":1}}' ), 20, false, array( $this->dir . '/missing.conf' ) );
		$cache->register();

		$cache->site_changed();
		$cache->flush();

		$this->assertCount( 1, $this->calls(), 'A cage can hide /etc/nginx from PHP while uapi still clears: only the screen asks.' );
	}

	public function test_the_saved_purge_check_is_kinsta_only(): void {
		$this->service()->verify_saved_purge( time() - 300, 0 );

		$this->assertSame( array(), WP_Stub::$remote_requests, 'cPanel answers a clear itself, through uapi.' );
	}

	public function test_a_host_without_uapi_is_left_alone_and_silent(): void {
		$cache = new Page_Cache( $this->dir . '/missing', 20, false );
		$cache->register();

		$cache->site_changed();
		$cache->flush();

		$this->assertSame( array(), WP_Stub::$logs );
		$this->assertSame( array(), WP_Stub::$scheduled );
	}

	/**
	 * A PHP that disables proc_open gets a log line, not a fatal error.
	 *
	 * On PHP 8 calling a disabled function is an Error, and the flush runs at
	 * the end of an ordinary request. Runs a child PHP with proc_open
	 * disabled, because a function cannot be disabled at runtime.
	 *
	 * @return void
	 */
	public function test_a_php_without_proc_open_is_logged_not_fatal(): void {
		$script = $this->dir . '/run.php';

		file_put_contents(
			$script,
			'<?php require ' . var_export( dirname( __DIR__ ) . '/vendor/autoload.php', true ) . ';'
			. ' require ' . var_export( __DIR__ . '/bootstrap.php', true ) . ';'
			. ' WP_Stub::reset(); WP_Stub::$can = true;'
			. ' $c = new RhinnoIT\\Optimizer\\Page_Cache( ' . var_export( $this->uapi( '{"result":{"status":1}}' ), true ) . ', 5, null, ' . var_export( array( $this->dir . '/ea-nginx.conf' ), true ) . ' );'
			. ' $c->register(); $c->site_changed(); $c->flush();'
			. ' echo json_encode( WP_Stub::$logs );'
		);

		$output = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d disable_functions=proc_open ' . escapeshellarg( $script ) . ' 2>&1' );
		$logs   = json_decode( $output, true );

		$this->assertIsArray( $logs, 'The child died instead of logging: ' . $output );
		$this->assertStringContainsString( 'PHP disables proc_open', (string) ( $logs[0] ?? '' ) );
		$this->assertSame( array(), $this->calls() );
	}

	public function test_every_change_it_listens_to_is_hooked(): void {
		$this->service();

		$this->assertSame(
			array(
				'rhinnoit_settings_saved:settings_saved',
				'ropt_page_cache_verify:verify_saved_purge',
				'shutdown:flush',
				'transition_post_status:post_changed',
				'transition_comment_status:comment_changed',
				'comment_post:comment_posted',
				'edit_comment:comment_edited',
				'edited_term:term_changed',
				'delete_term:term_changed',
				'wp_update_nav_menu:content_changed',
				'wp_delete_nav_menu:content_changed',
				'switch_theme:content_changed',
				'updated_option:option_updated',
				'added_option:option_added',
				'customize_save_after:site_changed',
				'upgrader_process_complete:site_changed',
				'activated_plugin:site_changed',
				'deactivated_plugin:site_changed',
				'elementor/core/files/clear_cache:site_changed',
				'rhinnoit_page_cache_dirty:dirty',
				'ropt_page_cache_run:run_scheduled',
			),
			WP_Stub::$callbacks
		);
	}

	public function test_live_comments_terms_and_unpublishing_count(): void {
		WP_Stub::$can = true;

		foreach (
			array(
				'an approved comment'        => fn ( Page_Cache $c ) => $c->comment_posted( 5, 1 ),
				'a comment approved later'   => fn ( Page_Cache $c ) => $c->comment_changed( 'approved', 'hold' ),
				'an approved comment edited' => fn ( Page_Cache $c ) => $c->comment_edited( 5, array( 'comment_approved' => '1' ) ),
				'a category term'            => fn ( Page_Cache $c ) => $c->term_changed( 1, 1, 'category' ),
				'an attribute term'          => fn ( Page_Cache $c ) => $c->term_changed( 1, 1, 'pa_color' ),
				'an unpublished post'        => fn ( Page_Cache $c ) => $c->post_changed( 'draft', 'publish', new WP_Post( 1, 'post' ) ),
				'the site language'          => fn ( Page_Cache $c ) => $c->option_updated( 'WPLANG', 'es_CL', '' ),
				'a launch out of coming soon' => fn ( Page_Cache $c ) => $c->option_updated( 'elementor_maintenance_mode_mode', 'coming_soon', '' ),
			) as $label => $change
		) {
			WP_Stub::$options[ Page_Cache::STATE ] = array( 'last' => 0, 'failed_until' => 0 );
			$before = count( $this->calls() );
			$cache  = $this->service();

			$change( $cache );
			$cache->flush();

			$this->assertCount( $before + 1, $this->calls(), sprintf( '%s did not clear.', ucfirst( $label ) ) );
		}
	}

	public function test_the_maintenance_page_clears_only_while_a_mode_is_set(): void {
		WP_Stub::$can                          = true;
		WP_Stub::$options[ Page_Cache::STATE ] = array( 'last' => 0, 'failed_until' => 0 );

		$cache = $this->service();
		$cache->option_updated( 'elementor_maintenance_mode_template_id', '', '12' );
		$cache->flush();

		$this->assertSame( array(), $this->calls(), 'With no mode, the page shows nowhere.' );

		WP_Stub::$options['elementor_maintenance_mode_mode'] = 'coming_soon';

		$cache = $this->service();
		$cache->option_updated( 'elementor_maintenance_mode_template_id', '', '12' );
		$cache->flush();

		$this->assertCount( 1, $this->calls(), 'Elementor applies the mode only with a page, so picking it turns coming soon on.' );
	}

	public function test_the_first_save_of_elementors_tools_clears_only_with_a_mode(): void {
		WP_Stub::$can                                        = true;
		WP_Stub::$options[ Page_Cache::STATE ]               = array( 'last' => 0, 'failed_until' => 0 );
		WP_Stub::$options['elementor_maintenance_mode_mode'] = '';

		$cache = $this->service();
		$cache->option_added( 'elementor_maintenance_mode_mode' );
		$cache->flush();

		$this->assertSame( array(), $this->calls(), 'Saved as Disabled with another Tools setting: nothing visitors see changed.' );

		WP_Stub::$options['elementor_maintenance_mode_mode'] = 'coming_soon';

		$cache = $this->service();
		$cache->option_added( 'elementor_maintenance_mode_mode' );
		$cache->flush();

		$this->assertCount( 1, $this->calls(), 'Coming soon switched on for the first time.' );
	}

	public function test_a_held_comment_edited_does_not_count(): void {
		$cache = $this->service();

		$cache->comment_edited( 5, array( 'comment_approved' => '0' ) );
		$cache->comment_edited( 5, 'not data' );
		$cache->flush();

		$this->assertSame( array(), WP_Stub::$scheduled );
	}

	public function test_content_from_cron_or_the_cli_clears_right_away(): void {
		WP_Stub::$doing_cron = true;
		$cache               = $this->service();

		$cache->post_changed( 'publish', 'future', new WP_Post( 9, 'post' ) );
		$cache->flush();

		$this->assertCount( 1, $this->calls(), 'A scheduled post going live is a trusted change.' );
	}

	public function test_a_scheduled_run_covers_what_its_own_request_recorded(): void {
		WP_Stub::$doing_cron = true;
		$cache               = $this->service();

		$cache->mark( 'sale' );
		$cache->run_scheduled();
		$cache->flush();

		$this->assertCount( 1, $this->calls(), 'One cron request, one clear.' );
		$this->assertSame( array(), WP_Stub::$scheduled );
	}

	public function test_a_run_in_progress_holds_off_the_next(): void {
		WP_Stub::$can = true;
		$cache        = $this->service();

		$cache->site_changed();
		$cache->flush();

		$this->assertSame( 0, WP_Stub::$options[ Page_Cache::STATE ]['failed_until'], 'The lease is released after a success.' );
	}

	public function test_root_names_the_account_that_owns_the_site(): void {
		WP_Stub::$can = true;
		$cache        = $this->service_with( 'printf %s \'{"result":{"status":1}}\'', 20, true );

		$cache->site_changed();
		$cache->flush();

		$owner = posix_getpwuid( (int) fileowner( ROPT_PATH ) );

		$this->assertSame( array( '--output=json --user=' . $owner['name'] . ' NginxCaching clear_cache' ), $this->calls() );
	}

	public function test_a_child_that_closes_its_output_early_does_not_spin(): void {
		WP_Stub::$can = true;
		$cache        = $this->service_with( 'printf %s \'{"result":{"status":1}}\'; exec 1>&- 2>&-; sleep 2', 10 );
		$before       = getrusage();

		$cache->site_changed();
		$cache->flush();

		$after = getrusage();
		$cpu   = ( $after['ru_utime.tv_sec'] + $after['ru_utime.tv_usec'] / 1e6 ) - ( $before['ru_utime.tv_sec'] + $before['ru_utime.tv_usec'] / 1e6 );

		$this->assertLessThan( 0.5, $cpu, 'Waiting on a closed pipe burned the CPU.' );
		$this->assertSame( array(), WP_Stub::$logs );
	}

	public function test_a_child_that_ignores_term_is_killed(): void {
		WP_Stub::$can = true;
		$cache        = $this->service_with( 'trap "" TERM; sleep 30', 1 );
		$started      = microtime( true );

		$cache->site_changed();
		$cache->flush();

		$this->assertLessThan( 5, microtime( true ) - $started, 'proc_close() waited on a child that ignored TERM.' );
		$this->assertStringContainsString( 'within 1 seconds', WP_Stub::$logs[0] ?? '' );
	}

	public function test_a_long_error_is_cut_on_a_character_boundary(): void {
		WP_Stub::$can = true;
		$message      = str_repeat( 'a', 299 ) . 'ó mas texto';
		$cache        = $this->service( 20, (string) json_encode( array( 'result' => array( 'status' => 0, 'errors' => array( $message ) ) ) ) );

		$cache->site_changed();
		$cache->flush();

		$this->assertTrue( mb_check_encoding( WP_Stub::$logs[0] ?? '', 'UTF-8' ), 'A cut through a character blanks the line in the log viewer.' );
	}

	/**
	 * Any function the clear needs, disabled, is a log line and not a fatal.
	 *
	 * @return void
	 */
	public function test_a_php_without_any_process_function_is_logged_not_fatal(): void {
		foreach ( array( 'proc_get_status', 'stream_select', 'proc_close' ) as $function ) {
			$script = $this->dir . '/run-' . $function . '.php';

			file_put_contents(
				$script,
				'<?php require ' . var_export( dirname( __DIR__ ) . '/vendor/autoload.php', true ) . ';'
				. ' require ' . var_export( __DIR__ . '/bootstrap.php', true ) . ';'
				. ' WP_Stub::reset(); WP_Stub::$can = true;'
				. ' $c = new RhinnoIT\\Optimizer\\Page_Cache( ' . var_export( $this->uapi( '{"result":{"status":1}}' ), true ) . ', 5, false, ' . var_export( array( $this->dir . '/ea-nginx.conf' ), true ) . ' );'
				. ' $c->register(); $c->site_changed(); $c->flush();'
				. ' echo json_encode( WP_Stub::$logs );'
			);

			$output = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d disable_functions=' . $function . ' ' . escapeshellarg( $script ) . ' 2>&1' );
			$logs   = json_decode( $output, true );

			$this->assertIsArray( $logs, sprintf( 'With %s disabled the child died: %s', $function, $output ) );
			$this->assertStringContainsString( 'PHP disables ' . $function, (string) ( $logs[0] ?? '' ) );
		}
	}

	public function test_saving_the_screen_clears_at_once_even_right_after_a_clear(): void {
		WP_Stub::$options[ Page_Cache::STATE ] = array( 'last' => time() - 5, 'failed_until' => 0 );
		$cache                                 = $this->service();

		$cache->settings_saved();
		$cache->flush();

		$this->assertCount( 1, $this->calls(), 'A save is the admin asking; it does not wait its turn.' );
		$this->assertSame( array(), WP_Stub::$scheduled );
	}

	public function test_the_group_hook_cannot_force_a_clear(): void {
		WP_Stub::$options[ Page_Cache::STATE ] = array( 'last' => time() - 5, 'failed_until' => 0 );
		$cache                                 = $this->service();

		$cache->dirty( 'saved' );
		$cache->flush();

		$this->assertSame( array(), $this->calls(), 'Only the save of a settings screen forces a clear, not another plugin of the group.' );
		$this->assertSame( array(), WP_Stub::$scheduled );

		$cache->dirty( 'site' );
		$cache->flush();

		$this->assertSame( array(), $this->calls(), 'The five reasons the group fires keep the spacing.' );
		$this->assertArrayHasKey( Page_Cache::EVENT, WP_Stub::$scheduled, 'And are passed on, not dropped.' );
	}

	public function test_a_save_clears_when_the_switch_is_on_before_or_after(): void {
		foreach (
			array(
				'on, then off' => array( 'yes', 'no', 1 ),
				'off, then on' => array( 'no', 'yes', 1 ),
				'off, and off' => array( 'no', 'no', 0 ),
			) as $label => $case
		) {
			WP_Stub::reset();
			WP_Stub::$options['ropt_page_cache_clear'] = $case[0];
			$before                                    = count( $this->calls() );
			$cache                                     = new Page_Cache( $this->uapi( '{"result":{"status":1}}' ), 20, false, array( $this->dir . '/ea-nginx.conf' ) );
			$cache->register();

			WP_Stub::$options['ropt_page_cache_clear'] = $case[1];
			$cache->settings_saved();
			$cache->flush();

			$this->assertCount( $before + $case[2], $this->calls(), sprintf( 'Switch %s.', $label ) );
		}
	}

	public function test_a_price_change_from_cron_waits_for_the_window(): void {
		WP_Stub::$doing_cron                   = true;
		WP_Stub::$options[ Page_Cache::STATE ] = array( 'last' => time() - 60, 'failed_until' => 0 );
		$cache                                 = $this->service();

		$cache->mark( 'product', 42 );
		$cache->flush();

		$this->assertSame( array(), $this->calls(), 'A connector plugin syncing from WP-Cron is no editor.' );
		$this->assertSame( time() - 60 + Page_Cache::WINDOW, WP_Stub::$scheduled[ Page_Cache::EVENT ] );
	}

	public function test_the_shop_half_can_name_the_product(): void {
		$this->service();

		$this->assertContains( array( 'rhinnoit_page_cache_dirty', Page_Cache::class, 'dirty', 2 ), WP_Stub::$registered, 'With one argument every product arrives as 0, and 0 purges everything.' );
	}
}
