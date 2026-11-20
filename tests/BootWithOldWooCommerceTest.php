<?php
/**
 * The support gate on a shop whose WooCommerce is below the minimum.
 *
 * Separate from the missing-WooCommerce case so that dropping the version half
 * of the check and keeping the defined() half cannot pass.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Admin\Menu;
use RhinnoIT\Optimizer\Plugin;
use RhinnoIT\Optimizer\Settings;

#[CoversClass( Plugin::class )]
final class BootWithOldWooCommerceTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_WC=7.9' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_WC' );
	}

	#[RunInSeparateProcess]
	public function test_a_woocommerce_below_the_minimum_gets_the_wordpress_half_and_a_notice(): void {
		$this->assertSame( '7.9', WC_VERSION );

		WP_Stub::reset();
		Plugin::boot();

		$this->assertContains( 'admin_notices', WP_Stub::$hooks, 'The shop is told why half is missing.' );
		$this->assertContains( 'admin_enqueue_scripts:enqueue_admin', WP_Stub::$callbacks, 'And still gets the half that does not need WooCommerce.' );
		$this->assertNotContains( 'ropt_woocommerce', array_column( Settings::available_fields(), 'id' ), 'The WooCommerce section stays off on a version it does not support.' );

		// Supported decides what the plugin does with WooCommerce; active
		// decides only what exists. An old version is the one place they differ.
		$this->assertSame( 'manage_woocommerce', Menu::capability(), 'Shop managers keep the page: WooCommerce is there, just old.' );
	}
}
