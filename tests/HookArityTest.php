<?php
/**
 * Every hooked method can be called with the arguments WordPress will pass.
 *
 * add_action() passes as many arguments as it was told to accept. A method
 * that requires more is an ArgumentCountError on every request that fires
 * the hook, and the stubs, which call nothing, cannot catch it any other way.
 *
 * Add every new service to the list below. The list is checked twice: in the
 * shared process, and on Kinsta in a process of its own, because the mini
 * cart correction and Shop_Cache's Kinsta filter hook nothing anywhere else,
 * and Page_Cache's cPanel half hooks nothing on Kinsta.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class HookArityTest extends TestCase {

	public function test_no_hooked_method_needs_more_arguments_than_it_gets(): void {
		// The listed services hook 72 methods off Kinsta: Delivery 7,
		// Viewport_Hero 3, Housekeeping 2 (its two core callbacks are names,
		// skipped below), Databot_Loader 1, Page_Cache 21, Canonical_Redirect
		// 1, Assets 1, Product_Image 2, First_Row 4, Gallery_Strip 4,
		// Shop_Prefetch 4, Shop_Cache 17, Menu 3, Settings_Page 1 through
		// Menu, and Plugin_Links 1. Fewer means the list lost a service; raise
		// it with every service added, lower it with one removed.
		$this->assertGreaterThanOrEqual( 72, $this->check_every_service(), 'Most hooks went unchecked, so this case proves little.' );
	}

	#[RunInSeparateProcess]
	public function test_no_kinsta_hook_needs_more_arguments_than_it_gets(): void {
		define( 'KINSTAMU_VERSION', '3.7.0' );

		// 77 on Kinsta: Cart_Fragments' 5 and Shop_Cache's Kinsta filter come
		// in, and Page_Cache hooks 1 fewer.
		$this->assertGreaterThanOrEqual( 77, $this->check_every_service(), 'The Kinsta corrections went unchecked.' );
	}

	/**
	 * Registers every service with every switch on and checks each hooked method.
	 *
	 * @return int How many hooked methods were checked.
	 */
	private function check_every_service(): int {
		WP_Stub::reset();

		foreach ( RhinnoIT\Optimizer\Settings::option_ids() as $option ) {
			WP_Stub::$options[ $option ] = 'yes';
		}

		$services = array(
			new RhinnoIT\Optimizer\Delivery(),
			new RhinnoIT\Optimizer\Viewport_Hero(),
			new RhinnoIT\Optimizer\Housekeeping(),
			new RhinnoIT\Optimizer\Databot_Loader(),
			new RhinnoIT\Optimizer\Page_Cache(),
			new RhinnoIT\Optimizer\Canonical_Redirect(),
			new RhinnoIT\Optimizer\Assets(),
			new RhinnoIT\Optimizer\Frontend\Product_Image(),
			new RhinnoIT\Optimizer\Frontend\First_Row(),
			new RhinnoIT\Optimizer\Frontend\Gallery_Strip(),
			new RhinnoIT\Optimizer\Frontend\Cart_Fragments(),
			new RhinnoIT\Optimizer\Frontend\Shop_Prefetch(),
			new RhinnoIT\Optimizer\Frontend\Shop_Cache(),
			new RhinnoIT\Optimizer\Admin\Menu(),
			new RhinnoIT\Optimizer\Admin\Plugin_Links(),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		$checked = 0;

		foreach ( WP_Stub::$registered as list( $hook, $class, $method, $accepted ) ) {
			if ( null === $class ) {
				continue;
			}

			$required = ( new ReflectionMethod( $class, $method ) )->getNumberOfRequiredParameters();

			$this->assertLessThanOrEqual( $accepted, $required, sprintf( '%s::%s needs %d arguments; %s passes %d.', $class, $method, $required, $hook, $accepted ) );
			++$checked;
		}

		return $checked;
	}
}
