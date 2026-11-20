<?php
/**
 * Tests for the asset loading of both bundles.
 *
 * The manifest cases were real fatal errors before it was validated; the rest
 * pin where each bundle is allowed to load and that neither one can silence
 * the other's log window.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Admin\Menu;
use RhinnoIT\Optimizer\Assets;

#[CoversClass( Assets::class )]
final class AssetsTest extends TestCase {

	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * Writes build/{$name}.asset.php with an arbitrary payload.
	 *
	 * @param string $php  Literal PHP the manifest returns.
	 * @param string $name Entry point name.
	 * @return void
	 */
	private function manifest( string $php, string $name = 'frontend' ): void {
		file_put_contents( ROPT_PATH . 'build/' . $name . '.asset.php', '<?php return ' . $php . ';' );
	}

	/**
	 * Manifests that must never reach wp_enqueue_script().
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function brokenManifests(): array {
		return array(
			'empty file'          => array( '' ),
			'returns a string'    => array( '"oops"' ),
			'returns null'        => array( 'null' ),
			'no dependencies key' => array( "array( 'version' => 'abc' )" ),
			'dependencies string' => array( "array( 'dependencies' => 'jquery', 'version' => 'abc' )" ),
			'version is an array' => array( "array( 'dependencies' => array(), 'version' => array( 'x' ) )" ),
		);
	}

	/**
	 * Broken manifests must not reach wp_enqueue_script().
	 *
	 * @param string $payload Manifest body.
	 * @return void
	 */
	#[DataProvider( 'brokenManifests' )]
	public function test_broken_manifest_degrades_instead_of_fataling( string $payload ): void {
		if ( '' === $payload ) {
			file_put_contents( ROPT_PATH . 'build/frontend.asset.php', "<?php\n" );
		} else {
			$this->manifest( $payload );
		}

		( new Assets() )->enqueue_frontend();

		$this->assertSame( array(), WP_Stub::$enqueued, 'Nothing may be enqueued from an unusable manifest.' );
		$this->assertCount( 1, WP_Stub::$logs );
	}

	public function test_truncated_manifest_does_not_escape_as_a_parse_error(): void {
		file_put_contents( ROPT_PATH . 'build/frontend.asset.php', "<?php return array( 'dependencies' =>" );

		( new Assets() )->enqueue_frontend();

		$this->assertSame( array(), WP_Stub::$enqueued );
		$this->assertCount( 1, WP_Stub::$logs );
	}

	public function test_missing_build_directory_is_survivable(): void {
		( new Assets() )->enqueue_frontend();

		$this->assertSame( array(), WP_Stub::$enqueued );
	}

	public function test_broken_build_is_logged_once_per_window(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			( new Assets() )->enqueue_frontend();
		}

		$this->assertCount( 1, WP_Stub::$logs, 'A storefront code path must not flood the log.' );
	}

	public function test_the_admin_bundle_loads_only_on_the_page_of_this_plugin(): void {
		$this->manifest( "array( 'dependencies' => array(), 'version' => 'abc' )", 'admin' );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued, 'Nothing of this plugin is on screen.' );

		$_GET = array( 'page' => Menu::PAGE_SLUG );

		( new Assets() )->enqueue_admin();

		$this->assertSame( 'ropt-admin', WP_Stub::$enqueued[0]['handle'] ?? '' );
	}

	public function test_an_admin_page_view_does_not_silence_the_storefront_for_the_hour(): void {
		$_GET = array( 'page' => Menu::PAGE_SLUG );

		( new Assets() )->enqueue_admin();

		$_GET = array();

		( new Assets() )->enqueue_frontend();

		$this->assertCount( 2, WP_Stub::$logs, 'The bundles fail independently, so each one gets its own window.' );
		$this->assertStringContainsString( 'admin.asset.php', WP_Stub::$logs[0] );
		$this->assertStringContainsString( 'frontend.asset.php', WP_Stub::$logs[1] );
	}

	public function test_valid_manifest_enqueues_with_merged_and_deduplicated_dependencies(): void {
		$this->manifest( "array( 'dependencies' => array( 'wp-i18n', 'jquery' ), 'version' => 'abc' )" );

		( new Assets() )->enqueue_frontend();

		$this->assertSame( array( 'wp-i18n', 'jquery' ), WP_Stub::$enqueued[0]['deps'] );
		$this->assertSame( 'abc', WP_Stub::$enqueued[0]['ver'] );
	}

	public function test_non_string_dependencies_are_filtered_out(): void {
		$this->manifest( "array( 'dependencies' => array( 'wp-i18n', 42, null ), 'version' => 'abc' )" );

		( new Assets() )->enqueue_frontend();

		$this->assertSame( array( 'wp-i18n', 'jquery' ), WP_Stub::$enqueued[0]['deps'] );
	}

	public function test_translations_are_registered_only_for_bundles_that_use_i18n(): void {
		$this->manifest( "array( 'dependencies' => array( 'jquery' ), 'version' => 'abc' )" );

		( new Assets() )->enqueue_frontend();

		$handles = array_column( WP_Stub::$enqueued, 'handle' );
		$this->assertNotContains( 'ropt-frontend:i18n', $handles );
	}

	public function test_inline_data_keeps_php_types(): void {
		$this->manifest( "array( 'dependencies' => array(), 'version' => 'abc' )" );

		( new Assets() )->enqueue_frontend();

		$this->assertSame( 'window.roptFrontend = {"debug":false};', WP_Stub::$inline[0] );
	}

	public function test_a_failed_json_encode_never_emits_broken_javascript(): void {
		$this->manifest( "array( 'dependencies' => array(), 'version' => 'abc' )" );
		WP_Stub::$json_fails = true;

		( new Assets() )->enqueue_frontend();

		$this->assertCount( 1, WP_Stub::$enqueued, 'The bundle still loads.' );
		$this->assertSame( array(), WP_Stub::$inline, 'window.roptFrontend = ; would break every script on the page.' );
	}

	public function test_disabled_plugin_enqueues_nothing(): void {
		$this->manifest( "array( 'dependencies' => array(), 'version' => 'abc' )" );
		WP_Stub::$options['ropt_enabled'] = 'no';

		( new Assets() )->enqueue_frontend();

		$this->assertSame( array(), WP_Stub::$enqueued );
	}

	/**
	 * Chosen contexts, page type, and whether the bundle loads.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function scopes(): array {
		$woo = array( 'shop', 'product', 'cart', 'checkout', 'account' );

		return array(
			'default on product'        => array( $woo, 'product', true ),
			'default on shop'           => array( $woo, 'shop', true ),
			'default on a category'     => array( $woo, 'taxonomy', true ),
			'default on cart'           => array( $woo, 'cart', true ),
			'default on a blog post'    => array( $woo, 'post', false ),
			'default on a page'         => array( $woo, 'page', false ),
			'product only, on product'  => array( array( 'product' ), 'product', true ),
			'product only, on cart'     => array( array( 'product' ), 'cart', false ),
			'checkout only, on checkout' => array( array( 'checkout' ), 'checkout', true ),
			'checkout only, on cart'    => array( array( 'checkout' ), 'cart', false ),
			'cart and account, on cart' => array( array( 'cart', 'account' ), 'cart', true ),
			'cart and account, on shop' => array( array( 'cart', 'account' ), 'shop', false ),
			'posts only, on a post'     => array( array( 'posts' ), 'post', true ),
			'posts only, on a product'  => array( array( 'posts' ), 'product', false ),
			'posts only, on a page'     => array( array( 'posts' ), 'page', false ),
			'pages only, on a page'     => array( array( 'pages' ), 'page', true ),
			'pages only, on checkout'   => array( array( 'pages' ), 'checkout', false ),
			'pages only, on the shop'   => array( array( 'pages' ), 'shop', false ),
			'everywhere on a post'      => array( array( 'everywhere' ), 'post', true ),
			'everywhere on a search'    => array( array( 'everywhere' ), 'other', true ),
			'everywhere beats the rest' => array( array( 'everywhere', 'cart' ), 'other', true ),
			'nothing chosen, on product' => array( array(), 'product', false ),
			'an id with no branch'      => array( array( 'nonsense' ), 'product', false ),
		);
	}

	/**
	 * The chosen contexts decide where the bundle loads.
	 *
	 * @param array<int, string> $contexts Chosen contexts.
	 * @param string             $page     Page type.
	 * @param bool               $expected Whether the bundle should load.
	 * @return void
	 */
	#[DataProvider( 'scopes' )]
	public function test_the_chosen_contexts_decide_where_the_bundle_loads( array $contexts, string $page, bool $expected ): void {
		WP_Stub::$page_type = $page;

		$this->assertSame( $expected, Assets::scope_matches( $contexts ) );
	}
}
