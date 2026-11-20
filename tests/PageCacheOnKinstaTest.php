<?php
/**
 * The page cache service on Kinsta, whose own plugin clears content itself.
 *
 * Its own process: ROPT_TESTS_KINSTA makes tests/bootstrap.php define
 * KINSTAMU_VERSION, which the rest of the suite must not see.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Page_Cache;

#[CoversClass( Page_Cache::class )]
#[RunTestsInSeparateProcesses]
final class PageCacheOnKinstaTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_KINSTA=1' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_KINSTA' );
	}

	protected function setUp(): void {
		WP_Stub::reset();
		$GLOBALS['kinsta_cache'] = (object) array( 'kinsta_cache_purge' => new Kinsta_Purge_Stub() );
	}

	/**
	 * A cache that registers, pointed at a uapi that must never run here.
	 *
	 * @return Page_Cache
	 */
	private function service(): Page_Cache {
		$cache = new Page_Cache( '/bin/false', 20 );
		$cache->register();

		return $cache;
	}

	public function test_what_kinsta_clears_itself_is_left_to_it(): void {
		$this->assertTrue( defined( 'KINSTAMU_VERSION' ), 'The child has to boot on Kinsta or this case means nothing.' );

		$cache = $this->service();
		$cache->mark( 'content' );
		$cache->flush();

		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'One post: Kinsta purges it itself.' );
		$this->assertSame( array(), $GLOBALS['kinsta_cache']->kinsta_cache_purge->single );
		$this->assertSame( array(), WP_Stub::$scheduled, 'Nothing is ever scheduled for cPanel on Kinsta.' );
	}

	public function test_what_kinsta_misses_is_purged_once(): void {
		$cache = $this->service();
		$cache->option_updated( 'porto_settings', array(), array( 'a' => 1 ) );
		$cache->option_updated( 'ropt_font_inline', 'yes', 'no' );
		$cache->mark( 'sale' );
		$cache->flush();

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'Porto options, this screen and sales: one purge.' );
	}

	public function test_a_missing_kinsta_api_is_logged_once_and_never_fatal(): void {
		$GLOBALS['kinsta_cache'] = null;

		$cache = $this->service();
		$cache->site_changed();
		$cache->flush();
		$cache->site_changed();
		$cache->flush();

		$this->assertCount( 1, WP_Stub::$logs );
		$this->assertStringContainsString( 'did not expose its purge', WP_Stub::$logs[0] );
	}

	public function test_a_sale_alone_is_purged(): void {
		$cache = $this->service();
		$cache->mark( 'sale', 0 );
		$cache->flush();

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
		$this->assertSame( array( array() ), $GLOBALS['kinsta_cache']->kinsta_cache_purge->arguments, 'Never forced: Kinsta\'s own autopurge switch still applies.' );
	}

	public function test_a_bulk_edit_is_purged_whole(): void {
		$cache = $this->service();
		$cache->post_changed( 'publish', 'publish', new WP_Post( 1, 'product' ) );
		$cache->flush();
		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'One post: Kinsta purges it itself.' );

		$cache->post_changed( 'publish', 'publish', new WP_Post( 1, 'product' ) );
		$cache->post_changed( 'publish', 'publish', new WP_Post( 2, 'product' ) );
		$cache->flush();
		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'Kinsta purges only the first post of a request.' );
	}

	public function test_a_product_saved_without_a_post_update_is_purged_alone(): void {
		$cache = $this->service();
		$cache->mark( 'product', 42 );
		$cache->mark( 'product', 43 );
		$cache->flush();

		$this->assertSame( array( 42, 43 ), $GLOBALS['kinsta_cache']->kinsta_cache_purge->single, 'An ERP pushing prices must not empty the whole cache per product.' );
		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );

		$cache->mark( 'product', 0 );
		$cache->flush();
		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'Many products at once: the product order, an attribute.' );
	}

	public function test_a_purge_kinsta_already_ran_this_request_runs_again(): void {
		$GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_all_happened = true;

		$cache = $this->service();
		$cache->site_changed();
		$cache->flush();

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'The earlier one ran before Porto recompiled its CSS.' );
	}

	public function test_a_purge_object_without_the_method_is_logged(): void {
		$GLOBALS['kinsta_cache'] = (object) array( 'kinsta_cache_purge' => new stdClass() );

		$cache = $this->service();
		$cache->site_changed();
		$cache->flush();

		$this->assertStringContainsString( 'did not expose its purge', WP_Stub::$logs[0] ?? '' );
	}

	public function test_a_save_forces_the_purge_past_kinstas_autopurge_switch(): void {
		$cache = $this->service();
		$cache->settings_saved();
		$cache->flush();

		$this->assertSame( array( array( true ) ), $GLOBALS['kinsta_cache']->kinsta_cache_purge->arguments, 'Kinsta skips an unforced purge while its autopurge is off.' );
	}

	public function test_every_product_that_changed_stock_status_is_purged(): void {
		$cache = $this->service();
		$cache->mark( 'stock', 42 );
		$cache->mark( 'stock', 43 );
		$cache->flush();

		$this->assertSame( array( 42, 43 ), $GLOBALS['kinsta_cache']->kinsta_cache_purge->single, 'Kinsta purges only the first product of a request; an order can sell out two.' );
		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
	}

	public function test_a_scheduled_sale_purges_everything(): void {
		$cache = $this->service();
		$cache->mark( 'sale', 8 );
		$cache->flush();

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'Related products and landing pages elsewhere show the price too.' );
		$this->assertSame( array(), $GLOBALS['kinsta_cache']->kinsta_cache_purge->single );
	}

	public function test_too_many_products_are_one_full_purge(): void {
		$cache = $this->service();

		for ( $id = 1; $id <= Page_Cache::SINGLE_LIMIT + 1; $id++ ) {
			$cache->mark( 'product', $id );
		}

		$cache->flush();

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
		$this->assertSame( array(), $GLOBALS['kinsta_cache']->kinsta_cache_purge->single );
	}

	public function test_without_the_per_post_purge_it_is_one_full_purge_not_one_per_product(): void {
		$GLOBALS['kinsta_cache'] = (object) array( 'kinsta_cache_purge' => new Kinsta_Full_Purge_Only_Stub() );

		$cache = $this->service();
		$cache->mark( 'product', 1 );
		$cache->mark( 'product', 2 );
		$cache->mark( 'product', 3 );
		$cache->flush();

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'A full purge per product would flush Redis once per product.' );
	}

	/**
	 * Kinsta is a cache the plugin can clear, with no uapi anywhere.
	 *
	 * @return void
	 */
	public function test_kinsta_offers_the_cache_switches(): void {
		$this->assertTrue( ( new Page_Cache( '/bin/false', 20 ) )->can_clear() );
	}

	/**
	 * Kinsta purges on Elementor's CSS and mode changes itself; only the mode's first save is left to this.
	 *
	 * @return void
	 */
	public function test_elementor_is_left_to_kinsta(): void {
		$cache = $this->service();

		$this->assertNotContains( 'elementor/core/files/clear_cache:site_changed', WP_Stub::$callbacks );

		$cache->option_updated( 'elementor_maintenance_mode_mode', 'coming_soon', '' );
		$cache->flush();

		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'A change: Elementor fires the action Kinsta purges on.' );

		WP_Stub::$options['elementor_maintenance_mode_mode'] = '';
		$cache->option_added( 'elementor_maintenance_mode_mode' );
		$cache->flush();

		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'A first save as Disabled changes nothing visitors see.' );

		WP_Stub::$options['elementor_maintenance_mode_mode'] = 'coming_soon';
		$cache->option_added( 'elementor_maintenance_mode_mode' );
		$cache->flush();

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'The first save adds the row, and Elementor fires nothing Kinsta hears.' );

		$cache->option_updated( 'elementor_maintenance_mode_template_id', '', '12' );
		$cache->flush();

		$this->assertSame( 2, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'Picking the page turns the mode on, and Elementor fires nothing for it.' );
	}

	/**
	 * A home page answer with the given cache headers.
	 *
	 * @param array<string, string> $headers Response headers.
	 * @param int                   $code    Status code.
	 * @return array<string, mixed>
	 */
	private static function home( array $headers, int $code = 200 ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers,
		);
	}

	/**
	 * An edge copy shaped as Kinsta sends it: Last-Modified is Date minus Age, the time the edge stored it.
	 *
	 * @param int $stored When the edge stored the copy.
	 * @return array<string, mixed>
	 */
	private static function edge_copy( int $stored ): array {
		return self::home(
			array(
				'x-kinsta-cache'     => 'HIT',
				'ki-cf-cache-status' => 'HIT',
				'ki-cache-type'      => 'Edge',
				'last-modified'      => gmdate( 'D, d M Y H:i:s', $stored ) . ' GMT',
				'age'                => (string) max( 0, time() - $stored ),
			)
		);
	}

	/**
	 * A save purges at once and leaves the check for five minutes later, when Kinsta's edge has cleared.
	 *
	 * @return void
	 */
	public function test_a_save_schedules_its_check_five_minutes_later(): void {
		$cache = $this->service();
		$cache->settings_saved();
		$cache->flush();

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
		$this->assertSame( array(), WP_Stub::$remote_requests, 'Nothing is read in the save request: the edge takes minutes to clear.' );
		$this->assertEqualsWithDelta( time() + Page_Cache::VERIFY_DELAY, WP_Stub::$scheduled[ Page_Cache::VERIFY ] ?? 0, 2 );
		$this->assertSame( 0, WP_Stub::$scheduled_args[ Page_Cache::VERIFY ][1] ?? null );
		$this->assertEqualsWithDelta( time(), WP_Stub::$scheduled_args[ Page_Cache::VERIFY ][0] ?? 0, 2 );
	}

	/**
	 * A copy the edge stored after the purge is the renewed page: nothing more happens.
	 *
	 * @return void
	 */
	public function test_a_copy_stored_after_the_purge_passes(): void {
		$since                     = time() - Page_Cache::VERIFY_DELAY;
		WP_Stub::$remote_responses = array( self::edge_copy( $since + 30 ) );

		$this->service()->verify_saved_purge( $since, 0 );

		$this->assertSame( array( 'https://shop.test/' ), WP_Stub::$remote_requests );
		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'A visitor re-stored the edge after the purge: the common case on a busy shop.' );
		$this->assertArrayNotHasKey( Page_Cache::VERIFY, WP_Stub::$scheduled );
		$this->assertSame( array(), WP_Stub::$logs );
	}

	/**
	 * A copy stored before the purge gets one more forced purge, checked the same way.
	 *
	 * @return void
	 */
	public function test_a_copy_stored_before_the_purge_is_purged_once_more(): void {
		$since                     = time() - Page_Cache::VERIFY_DELAY;
		WP_Stub::$remote_responses = array( self::edge_copy( $since - HOUR_IN_SECONDS ) );

		$this->service()->verify_saved_purge( $since, 0 );

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
		$this->assertSame( array( true ), $GLOBALS['kinsta_cache']->kinsta_cache_purge->arguments[0], 'Forced, past Kinsta\'s autopurge switch, as the save was.' );
		$this->assertSame( 1, WP_Stub::$scheduled_args[ Page_Cache::VERIFY ][1] ?? null );
		$this->assertSame( array(), WP_Stub::$logs );
	}

	/**
	 * Still older than the second purge: logged, and no third purge.
	 *
	 * @return void
	 */
	public function test_a_copy_older_than_the_retry_is_logged(): void {
		$since                     = time() - Page_Cache::VERIFY_DELAY;
		WP_Stub::$remote_responses = array( self::edge_copy( $since - HOUR_IN_SECONDS ) );

		$this->service()->verify_saved_purge( $since, 1 );

		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
		$this->assertArrayNotHasKey( Page_Cache::VERIFY, WP_Stub::$scheduled );
		$this->assertCount( 1, WP_Stub::$logs );
		$this->assertStringContainsString( 'still came from a copy cached before the second purge', WP_Stub::$logs[0] );
	}

	/**
	 * The clock difference allowed sits on the right side of the purge.
	 *
	 * @return void
	 */
	public function test_the_skew_is_forgiven_and_no_more(): void {
		$since = time() - Page_Cache::VERIFY_DELAY;

		WP_Stub::$remote_responses = array( self::edge_copy( $since - Page_Cache::SKEW + 1 ) );
		$this->service()->verify_saved_purge( $since, 0 );
		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'A second inside the skew is the same moment.' );

		WP_Stub::$remote_responses = array( self::edge_copy( $since - Page_Cache::SKEW - 2 ) );
		$this->service()->verify_saved_purge( $since, 0 );
		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
	}

	/**
	 * An Age older than the purge is enough when there is no Last-Modified.
	 *
	 * @return void
	 */
	public function test_age_alone_tells_a_stale_copy(): void {
		$since = time() - Page_Cache::VERIFY_DELAY;

		WP_Stub::$remote_responses = array( self::home( array( 'cf-cache-status' => 'HIT', 'age' => '10' ) ) );
		$this->service()->verify_saved_purge( $since, 0 );
		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );

		WP_Stub::$remote_responses = array( self::home( array( 'cf-cache-status' => 'HIT', 'age' => '3600' ) ) );
		$this->service()->verify_saved_purge( $since, 0 );
		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
	}

	/**
	 * What cannot be read - a bot challenge, a redirect, a failure, a HIT without dates - is left alone.
	 *
	 * @return void
	 */
	public function test_an_answer_that_cannot_be_read_is_left_alone(): void {
		foreach (
			array(
				self::home( array(), 403 ),
				self::home( array( 'location' => 'https://www.shop.test/' ), 301 ),
				new WP_Error( 'http_request_failed', 'timed out' ),
				self::home( array( 'x-kinsta-cache' => 'HIT', 'ki-cf-cache-status' => 'SAVING' ) ),
				self::home( array( 'x-kinsta-cache' => 'MISS' ) ),
			) as $answer
		) {
			WP_Stub::reset();
			$GLOBALS['kinsta_cache']   = (object) array( 'kinsta_cache_purge' => new Kinsta_Purge_Stub() );
			WP_Stub::$remote_responses = array( $answer );

			$this->service()->verify_saved_purge( time() - Page_Cache::VERIFY_DELAY, 0 );

			$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
			$this->assertSame( array(), WP_Stub::$logs );
		}
	}

	/**
	 * Without Kinsta's purge API there is nothing to check, and no 'purged twice' line.
	 *
	 * @return void
	 */
	public function test_no_check_without_kinstas_purge(): void {
		$GLOBALS['kinsta_cache'] = null;

		$cache = $this->service();
		$cache->settings_saved();
		$cache->flush();

		$this->assertArrayNotHasKey( Page_Cache::VERIFY, WP_Stub::$scheduled );

		WP_Stub::$remote_responses = array( self::edge_copy( time() - 2 * HOUR_IN_SECONDS ) );
		$cache->verify_saved_purge( time() - HOUR_IN_SECONDS, 0 );

		$this->assertArrayNotHasKey( Page_Cache::VERIFY, WP_Stub::$scheduled );
		$this->assertCount( 1, WP_Stub::$logs, 'Only the line that says Kinsta\'s purge is missing.' );
		$this->assertStringContainsString( 'did not expose its purge', WP_Stub::$logs[0] );
	}

	/**
	 * Only a save is checked: a site-wide change from an editor is not.
	 *
	 * @return void
	 */
	public function test_only_a_save_is_checked(): void {
		$cache = $this->service();
		$cache->site_changed();
		$cache->flush();

		$this->assertSame( 1, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
		$this->assertArrayNotHasKey( Page_Cache::VERIFY, WP_Stub::$scheduled );
	}

	/**
	 * A page that sends a Last-Modified of its own is judged by the cache's Age.
	 *
	 * @return void
	 */
	public function test_age_wins_over_a_page_last_modified(): void {
		WP_Stub::$remote_responses = array(
			self::home(
				array(
					'x-kinsta-cache' => 'HIT',
					'last-modified'  => gmdate( 'D, d M Y H:i:s', time() - DAY_IN_SECONDS ) . ' GMT',
					'age'            => '20',
				)
			),
		);

		$this->service()->verify_saved_purge( time() - Page_Cache::VERIFY_DELAY, 0 );

		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls, 'Stored 20 s ago, whatever date the page carries.' );
	}

	/**
	 * A check that lost its attempt argument can only log, never purge again.
	 *
	 * @return void
	 */
	public function test_a_lost_attempt_never_purges(): void {
		WP_Stub::$remote_responses = array( self::edge_copy( time() - 2 * HOUR_IN_SECONDS ) );

		$this->service()->verify_saved_purge( time() - HOUR_IN_SECONDS );

		$this->assertSame( 0, $GLOBALS['kinsta_cache']->kinsta_cache_purge->calls );
		$this->assertCount( 1, WP_Stub::$logs );
	}

	/**
	 * Deactivation drops a pending check, which carries arguments.
	 *
	 * @return void
	 */
	public function test_deactivation_drops_a_pending_check(): void {
		$cache = $this->service();
		$cache->settings_saved();
		$cache->flush();

		$this->assertArrayHasKey( Page_Cache::VERIFY, WP_Stub::$scheduled );

		RhinnoIT\Optimizer\Install::deactivate();

		$this->assertArrayNotHasKey( Page_Cache::VERIFY, WP_Stub::$scheduled, 'wp_clear_scheduled_hook() without the arguments leaves it.' );
	}

	/**
	 * The check is hooked to take both arguments the event carries.
	 *
	 * @return void
	 */
	public function test_the_check_takes_both_arguments(): void {
		$this->service();

		$this->assertContains( array( Page_Cache::VERIFY, Page_Cache::class, 'verify_saved_purge', 2 ), WP_Stub::$registered );
	}
}
