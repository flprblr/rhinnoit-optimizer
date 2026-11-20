<?php
/**
 * The cart fragments correction away from Kinsta.
 *
 * cPanel's NGINX cache skips only logged-in users, not visitors with a cart,
 * so there a page with the empty cart drawn would be served to shoppers who
 * have one. Runs in the shared process, where KINSTAMU_VERSION is not set.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\Cart_Fragments;

#[CoversClass( Cart_Fragments::class )]
final class CartFragmentsOffKinstaTest extends TestCase {

	public function test_nothing_is_hooked_away_from_kinsta(): void {
		WP_Stub::reset();
		WP_Stub::$options['ropt_cart_fragments'] = 'yes';

		$this->assertFalse( defined( 'KINSTAMU_VERSION' ) );

		( new Cart_Fragments() )->register();

		$this->assertSame( array(), WP_Stub::$callbacks );
	}
}
