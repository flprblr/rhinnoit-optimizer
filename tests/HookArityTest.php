<?php
/**
 * Every hooked method can be called with the arguments WordPress will pass.
 *
 * add_action() passes as many arguments as it was told to accept. A method
 * that requires more is an ArgumentCountError on every request that fires
 * the hook, and the stubs, which call nothing, cannot catch it any other way.
 *
 * Add every new service to the list below.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\TestCase;

final class HookArityTest extends TestCase {

	public function test_no_hooked_method_needs_more_arguments_than_it_gets(): void {
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

		// The listed services hook 41 methods: Delivery 7, Viewport_Hero 3,
		// Housekeeping 2 (its two core callbacks are names, skipped above),
		// Databot_Loader 1, Page_Cache 21, Canonical_Redirect 1, Assets 1, Menu
		// 3, Settings_Page 1 through Menu, and Plugin_Links 1. Fewer means the
		// list above lost a service; raise it with every service added, lower it
		// with one removed.
		$this->assertGreaterThanOrEqual( 41, $checked, 'Most hooks went unchecked, so this case proves little.' );
	}
}
