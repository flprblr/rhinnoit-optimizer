<?php
/**
 * Porto's Speed Optimize answers on a site whose theme is no longer Porto.
 *
 * Its own process: the bootstrap loads the Porto stubs unless
 * ROPT_TESTS_PORTO is 'none', and the child inherits the environment.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Delivery;

/**
 * Covers Delivery::porto_covers() without Porto.
 */
#[CoversClass( Delivery::class )]
#[RunTestsInSeparateProcesses]
final class DeliveryWithoutPortoTest extends TestCase {

	/**
	 * Boots a site on another theme.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_PORTO=none' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_PORTO' );
	}

	/**
	 * A wizard answer left behind by Porto covers nothing once Porto is gone.
	 *
	 * @return void
	 */
	public function test_a_former_porto_sites_lazy_load_answer_covers_nothing(): void {
		WP_Stub::reset();
		WP_Stub::$options['porto_settings_optimize'] = array( 'lazyload' => '1' );

		$this->assertNotSame( 'porto', get_template(), 'The process has Porto, or this case means nothing.' );
		$this->assertFalse( Delivery::porto_covers( 'lazyload' ) );

		( new Delivery() )->register();

		$this->assertContains( 'wp_omit_loading_attr_threshold:only_first_image_eager', WP_Stub::$callbacks, 'Porto\'s loader left with the theme, so the switch acts again.' );
	}
}
