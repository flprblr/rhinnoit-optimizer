<?php
/**
 * The settings screen of a Porto shop on Kinsta.
 *
 * Its own process: ROPT_TESTS_KINSTA makes tests/bootstrap.php define
 * KINSTAMU_VERSION, and Plugin::kinsta_is_active() caches its answer.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Plugin;
use RhinnoIT\Optimizer\Settings;

/**
 * Covers Settings::available_fields() on Kinsta.
 */
#[CoversClass( Settings::class )]
#[RunTestsInSeparateProcesses]
final class SettingsOnKinstaTest extends TestCase {

	/**
	 * Boots on Kinsta.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_KINSTA=1' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_KINSTA' );
	}

	/**
	 * The page cache switch is offered on Kinsta.
	 *
	 * @return void
	 */
	public function test_the_kinsta_and_page_cache_switches_are_on_the_screen(): void {
		WP_Stub::reset();

		$this->assertTrue( Plugin::kinsta_is_active() );

		$ids = array_column( Settings::available_fields(), 'id' );

		foreach ( array( 'ropt_page_cache_clear' ) as $id ) {
			$this->assertContains( $id, $ids );
		}
	}
}
