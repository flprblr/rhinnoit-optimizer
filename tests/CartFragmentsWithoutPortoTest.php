<?php
/**
 * The cart fragments correction on a theme that is not Porto.
 *
 * Its own process: tests/bootstrap.php loads the Porto stubs unless
 * ROPT_TESTS_PORTO is 'none', and the child inherits the environment.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\Cart_Fragments;

#[CoversClass( Cart_Fragments::class )]
final class CartFragmentsWithoutPortoTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_PORTO=none' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_PORTO' );
	}

	#[RunInSeparateProcess]
	public function test_nothing_is_dropped_without_portos_mini_cart(): void {
		$this->assertFalse( function_exists( 'porto_minicart' ), 'The child has to boot without Porto or this case means nothing.' );

		WP_Stub::reset();
		WP_Stub::$options['ropt_cart_fragments'] = 'yes';

		$fragments = new Cart_Fragments();
		$fragments->register();
		$fragments->drop_while_the_cart_is_empty();

		ob_start();
		$fragments->print_cart_watcher();

		$this->assertSame( '', (string) ob_get_clean() );
		$this->assertSame( array(), WP_Stub::$dequeued, 'Whatever mini cart this theme has would wait for the script forever.' );
	}

	#[RunInSeparateProcess]
	public function test_nothing_is_hooked_on_kinsta_without_porto(): void {
		define( 'KINSTAMU_VERSION', '3.7.0' );

		WP_Stub::reset();
		WP_Stub::$options['ropt_cart_fragments'] = 'yes';

		( new Cart_Fragments() )->register();

		$this->assertSame( array(), WP_Stub::$callbacks, 'Its switch is offered only on Porto, so the cart cookies are left as WooCommerce sends them.' );
	}
}
