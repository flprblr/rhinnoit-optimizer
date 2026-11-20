<?php
/**
 * A plain WordPress site, with no WooCommerce at all.
 *
 * Its own process: tests/bootstrap.php takes WC_VERSION from ROPT_TESTS_WC, and
 * the child inherits the environment set before it is spawned. The child loads
 * none of the WooCommerce stubs either, so anything here that calls into
 * WooCommerce is a fatal, exactly as it would be on such a site.
 *
 * The plugin runs its WordPress half here: the services that need no
 * WooCommerce register, the admin page exists and is reachable, and nothing
 * that calls into WooCommerce is hooked or read.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Admin\Menu;
use RhinnoIT\Optimizer\Admin\Settings_Page;
use RhinnoIT\Optimizer\Assets;
use RhinnoIT\Optimizer\Plugin;
use RhinnoIT\Optimizer\Settings;

#[CoversClass( Plugin::class )]
final class BootWithoutWooCommerceTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_WC=none' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_WC' );
	}

	#[RunInSeparateProcess]
	public function test_a_site_without_woocommerce_runs_the_wordpress_half_only(): void {
		$this->assertFalse( defined( 'WC_VERSION' ), 'The child has to boot without WooCommerce or this case means nothing.' );
		$this->assertFalse( function_exists( 'is_shop' ), 'The child has to lack the WooCommerce functions too.' );

		WP_Stub::reset();
		Plugin::boot();

		$this->assertContains( 'wp_enqueue_scripts:enqueue_frontend', WP_Stub::$callbacks, 'The WordPress half runs here.' );
		$this->assertContains( 'admin_menu', WP_Stub::$hooks, 'The page exists, or nobody could configure it.' );
		$this->assertNotContains( 'admin_notices', WP_Stub::$hooks, 'No WooCommerce is a valid site, not an error to announce.' );
	}

	#[RunInSeparateProcess]
	public function test_the_page_is_reachable_without_woocommerce(): void {
		// manage_woocommerce is a capability WooCommerce registers. On a site
		// without it nobody holds it, and the page would be invisible to all.
		$this->assertSame( 'manage_options', Menu::capability() );

		WP_Stub::reset();
		( new Menu() )->register_pages();

		$this->assertSame( array( 'manage_options' ), array_values( array_unique( array_column( WP_Stub::$menu, 'cap' ) ) ), 'The node and the entry both ask for it, or one of them stays hidden.' );
	}

	#[RunInSeparateProcess]
	public function test_the_woocommerce_section_is_not_on_the_screen(): void {
		$ids = array_column( Settings::available_fields(), 'id' );

		$this->assertContains( 'ropt_scope_wordpress', $ids );
		$this->assertNotContains( 'ropt_scope_woocommerce', $ids );
		$this->assertNotContains( 'ropt_woocommerce', $ids, 'The section heading goes with its fields.' );
	}

	#[RunInSeparateProcess]
	public function test_a_crafted_post_cannot_write_the_woocommerce_options(): void {
		WP_Stub::reset();
		WP_Stub::$options['ropt_scope_woocommerce'] = array( 'cart' );

		// Somebody holding manage_options posts the WooCommerce list, marker
		// and all, on a site whose screen never showed it.
		$_POST = array(
			'ropt_scope_woocommerce' => array( 'checkout' ),
			'ropt_scope_wordpress'   => array( 'posts' ),
			'ropt_drawn'             => array( 'ropt_scope_woocommerce', 'ropt_scope_wordpress' ),
		);

		try {
			( new Settings_Page() )->save();
		} catch ( WP_Halt $e ) {
			unset( $e );
		}

		$this->assertSame( array( 'cart' ), WP_Stub::$options['ropt_scope_woocommerce'], 'A hidden field cannot be written, and a value waiting for WooCommerce is kept.' );
		$this->assertSame( array( 'posts' ), WP_Stub::$options['ropt_scope_wordpress'], 'The WordPress half saves normally.' );
	}

	#[RunInSeparateProcess]
	public function test_the_form_draws_only_the_wordpress_section(): void {
		WP_Stub::reset();

		ob_start();
		( new Settings_Page() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '>WordPress</h2>', $html );
		$this->assertStringNotContainsString( '>WooCommerce</h2>', $html );
		$this->assertStringNotContainsString( 'name="ropt_scope_woocommerce[]"', $html );
		$this->assertSame( 2, substr_count( $html, '<section class="ropt-card">' ), 'The master switch and the WordPress card.' );
	}

	#[RunInSeparateProcess]
	public function test_the_storefront_bundle_follows_the_wordpress_list_alone(): void {
		WP_Stub::reset();

		// Left over from a time the site had WooCommerce: kept, never read.
		WP_Stub::$options['ropt_scope_woocommerce'] = array( 'shop', 'cart' );
		WP_Stub::$options['ropt_scope_wordpress']   = array( 'pages' );

		$this->assertSame( array( 'pages' ), Settings::frontend_contexts(), 'Its ids map to tags that do not exist here.' );

		// "Pages, WooCommerce ones aside" asks the WooCommerce tags on a shop,
		// and must not on a site that has none of them.
		WP_Stub::$page_type = 'page';
		$this->assertTrue( Assets::scope_matches( Settings::frontend_contexts() ) );

		WP_Stub::$page_type = 'post';
		$this->assertFalse( Assets::scope_matches( Settings::frontend_contexts() ) );
	}
}
