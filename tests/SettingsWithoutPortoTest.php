<?php
/**
 * The settings screen on a WooCommerce site whose theme is not Porto.
 *
 * Runs in its own process: the bootstrap loads the Porto stubs unless
 * ROPT_TESTS_PORTO is 'none', and defines KINSTAMU_VERSION with
 * ROPT_TESTS_KINSTA=1; the child inherits the environment.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Plugin;
use RhinnoIT\Optimizer\Settings;

/**
 * Covers Settings::available_fields() without Porto.
 */
#[RunTestsInSeparateProcesses]
final class SettingsWithoutPortoTest extends TestCase {

	/**
	 * Boots a site without Porto, on Kinsta, the one host where Porto's switch can act.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_PORTO=none' );
		putenv( 'ROPT_TESTS_KINSTA=1' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_PORTO' );
		putenv( 'ROPT_TESTS_KINSTA' );
	}

	/**
	 * Porto's card is not drawn, and WooCommerce's still is.
	 *
	 * @return void
	 */
	public function test_the_porto_section_is_not_on_the_screen(): void {
		$this->assertNotSame( 'porto', get_template(), 'The process has no Porto, or this case means nothing.' );
		$this->assertTrue( Plugin::kinsta_is_active(), 'Off Kinsta the card has no switch to offer anyway, so this case would mean nothing.' );

		$ids = array_column( Settings::available_fields(), 'id' );

		$this->assertNotContains( 'ropt_porto', $ids );
		$this->assertNotContains( 'ropt_cart_fragments', $ids );
		$this->assertContains( 'ropt_woocommerce', $ids );
		$this->assertContains( 'ropt_product_image_eager', $ids );
	}
}
