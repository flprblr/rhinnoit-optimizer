<?php
/**
 * Tests for the asset loading of the admin bundle, the only one shipped.
 *
 * The manifest cases were real fatal errors before it was validated; the rest
 * pin that the bundle loads on the page of this plugin and nowhere else. Each
 * manifest case puts the request on that page first: anywhere else
 * enqueue_admin() returns before it reads a manifest, and the case would pass
 * without testing anything.
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
	private function manifest( string $php, string $name = 'admin' ): void {
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
		$_GET['page'] = Menu::PAGE_SLUG;

		if ( '' === $payload ) {
			file_put_contents( ROPT_PATH . 'build/admin.asset.php', "<?php\n" );
		} else {
			$this->manifest( $payload );
		}

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued, 'Nothing may be enqueued from an unusable manifest.' );
		$this->assertCount( 1, WP_Stub::$logs );
	}

	public function test_truncated_manifest_does_not_escape_as_a_parse_error(): void {
		$_GET['page'] = Menu::PAGE_SLUG;

		file_put_contents( ROPT_PATH . 'build/admin.asset.php', "<?php return array( 'dependencies' =>" );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued );
		$this->assertCount( 1, WP_Stub::$logs );
	}

	public function test_missing_build_directory_is_survivable(): void {
		$_GET['page'] = Menu::PAGE_SLUG;

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued );
	}

	public function test_broken_build_is_logged_once_per_window(): void {
		$_GET['page'] = Menu::PAGE_SLUG;

		for ( $i = 0; $i < 5; $i++ ) {
			( new Assets() )->enqueue_admin();
		}

		$this->assertCount( 1, WP_Stub::$logs, 'A repeated code path must not flood the log.' );
	}

	public function test_the_admin_bundle_loads_only_on_the_page_of_this_plugin(): void {
		$this->manifest( "array( 'dependencies' => array(), 'version' => 'abc' )", 'admin' );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array(), WP_Stub::$enqueued, 'Nothing of this plugin is on screen.' );

		$_GET = array( 'page' => Menu::PAGE_SLUG );

		( new Assets() )->enqueue_admin();

		$this->assertSame( 'ropt-admin', WP_Stub::$enqueued[0]['handle'] ?? '' );
	}

	public function test_valid_manifest_enqueues_with_its_dependencies_and_version(): void {
		$_GET['page'] = Menu::PAGE_SLUG;

		$this->manifest( "array( 'dependencies' => array( 'wp-i18n', 'wp-api-fetch' ), 'version' => 'abc' )" );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array( 'wp-i18n', 'wp-api-fetch' ), WP_Stub::$enqueued[0]['deps'] );
		$this->assertSame( 'abc', WP_Stub::$enqueued[0]['ver'], 'Without the manifest version a new build stays cached.' );
	}

	public function test_non_string_dependencies_are_filtered_out(): void {
		$_GET['page'] = Menu::PAGE_SLUG;

		$this->manifest( "array( 'dependencies' => array( 'wp-i18n', 42, null ), 'version' => 'abc' )" );

		( new Assets() )->enqueue_admin();

		$this->assertSame( array( 'wp-i18n' ), WP_Stub::$enqueued[0]['deps'] );
	}

	public function test_translations_are_registered_only_for_bundles_that_use_i18n(): void {
		$_GET['page'] = Menu::PAGE_SLUG;

		$this->manifest( "array( 'dependencies' => array( 'jquery' ), 'version' => 'abc' )" );

		( new Assets() )->enqueue_admin();

		$handles = array_column( WP_Stub::$enqueued, 'handle' );
		$this->assertContains( 'ropt-admin', $handles, 'The bundle loaded, so the absence below means something.' );
		$this->assertNotContains( 'ropt-admin:i18n', $handles );
	}
}
