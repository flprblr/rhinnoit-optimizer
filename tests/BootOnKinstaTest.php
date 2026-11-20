<?php
/**
 * Booting on Kinsta, where the Kinsta-only corrections register.
 *
 * Its own process: ROPT_TESTS_KINSTA makes tests/bootstrap.php define
 * KINSTAMU_VERSION, and Plugin::boot() is a singleton.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Plugin;

#[CoversClass( Plugin::class )]
final class BootOnKinstaTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_KINSTA=1' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_KINSTA' );
	}

	#[RunInSeparateProcess]
	public function test_the_mini_cart_correction_registers_on_kinsta(): void {
		WP_Stub::reset();
		WP_Stub::$options['ropt_cart_fragments'] = 'yes';

		Plugin::boot();

		$this->assertTrue( Plugin::kinsta_is_active() );
		$this->assertContains( 'wp_enqueue_scripts:drop_while_the_cart_is_empty', WP_Stub::$callbacks );
		$this->assertContains( 'wp_speculation_rules_configuration:prefetch_on_hover', WP_Stub::$callbacks, 'Hover prefetching ships on here.' );
	}
}
