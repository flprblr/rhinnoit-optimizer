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
use RhinnoIT\Optimizer\Plugin;

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

		$this->assertContains( 'admin_enqueue_scripts:enqueue_admin', WP_Stub::$callbacks, 'The WordPress half runs here.' );
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
}
