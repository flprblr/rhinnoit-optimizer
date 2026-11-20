<?php
/**
 * The WooCommerce half of hover prefetching.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\Shop_Prefetch;

/**
 * Covers RhinnoIT\Optimizer\Frontend\Shop_Prefetch.
 */
#[CoversClass( Shop_Prefetch::class )]
final class ShopPrefetchTest extends TestCase {

	/**
	 * A shop with its three pages assigned, in Spanish, as on both shops.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$options['ropt_speculative_moderate'] = 'yes';
		WP_Stub::$wc_pages  = array(
			'cart'      => 11,
			'checkout'  => 12,
			'myaccount' => 13,
		);
		WP_Stub::$page_uris = array(
			11 => 'carrito',
			12 => 'finalizar-compra',
			13 => 'tienda/mi-cuenta',
		);
	}

	/**
	 * Each assigned page is excluded with or without its trailing slash.
	 *
	 * '/carrito/*' alone misses '/carrito' on a permalink structure without
	 * trailing slashes; the optional group covers both and what is under it.
	 *
	 * @return void
	 */
	public function test_the_shop_pages_are_excluded(): void {
		$paths = ( new Shop_Prefetch() )->exclude_shop_pages( array( '/wp-admin/*' ) );

		$this->assertSame( array( '/wp-admin/*', '/carrito{/*}?', '/finalizar-compra{/*}?', '/tienda/mi-cuenta{/*}?' ), $paths );
	}

	/**
	 * A page that is not assigned, or no longer exists, adds nothing.
	 *
	 * @return void
	 */
	public function test_a_missing_page_adds_nothing(): void {
		unset( WP_Stub::$wc_pages['myaccount'], WP_Stub::$page_uris[12] );

		$this->assertSame( array( '/carrito{/*}?' ), ( new Shop_Prefetch() )->exclude_shop_pages( array() ) );
	}

	/**
	 * Whatever another plugin passes through the filter, nothing fatals.
	 *
	 * @return void
	 */
	public function test_a_malformed_value_is_left_alone(): void {
		$this->assertNull( ( new Shop_Prefetch() )->exclude_shop_pages( null ) );
	}

	/**
	 * A visitor with a cart keeps prefetch on press.
	 *
	 * Every page they request is uncached, so a hover would cost a render.
	 *
	 * @return void
	 */
	public function test_a_visitor_with_a_cart_keeps_prefetch_on_press(): void {
		$prefetch = new Shop_Prefetch();

		$this->assertTrue( $prefetch->not_with_a_cart( true ) );

		$_COOKIE['woocommerce_items_in_cart'] = '1';
		$this->assertFalse( $prefetch->not_with_a_cart( true ) );

		unset( $_COOKIE['woocommerce_items_in_cart'] );
		$this->assertFalse( $prefetch->not_with_a_cart( false ), 'A no from someone else stays a no.' );
	}

	/**
	 * A page that prefetches on hover switches to press once an item goes in over AJAX.
	 *
	 * That page was built without a cart and served from the cache, so the
	 * server-side choice never reached it: each later hover - on a phone,
	 * each pause of the scroll - was an uncached render for a visitor who
	 * now has a cart.
	 *
	 * @return void
	 */
	public function test_a_hover_page_switches_to_press_after_an_add(): void {
		$prefetch = new Shop_Prefetch();
		$prefetch->not_with_a_cart( true );

		ob_start();
		$prefetch->print_press_after_an_add();
		$footer = (string) ob_get_clean();

		$this->assertStringContainsString( '"added_to_cart"', $footer );
		$this->assertStringContainsString( 'script[type="speculationrules"]', $footer );
		$this->assertStringContainsString( 'x.eagerness="conservative"', $footer );
		$this->assertStringContainsString( 'DOMContentLoaded', $footer, 'jQuery may be deferred.' );
		$this->assertStringContainsString( '"visibilitychange"', $footer, 'A tab opened before the add hears nothing of it.' );
		$this->assertStringContainsString( 'woocommerce_items_in_cart=/.test(d.cookie)', $footer );

		foreach ( array( array( true, '1' ), array( false, null ) ) as list( $allowed, $cookie ) ) {
			if ( null !== $cookie ) {
				$_COOKIE['woocommerce_items_in_cart'] = $cookie;
			}

			$press = new Shop_Prefetch();
			$press->not_with_a_cart( $allowed );

			ob_start();
			$press->print_press_after_an_add();
			$this->assertSame( '', ob_get_clean(), 'A page that already prefetches on press, or not at all, needs nothing.' );

			unset( $_COOKIE['woocommerce_items_in_cart'] );
		}

		ob_start();
		( new Shop_Prefetch() )->print_press_after_an_add();
		$this->assertSame( '', ob_get_clean(), 'Core never asked: a logged-in user, or speculation off.' );
	}

	/**
	 * All three corrections follow the hover switch, which they are half of.
	 *
	 * @return void
	 */
	public function test_it_is_hooked_only_with_hover_prefetching(): void {
		( new Shop_Prefetch() )->register();
		$this->assertSame(
			array(
				'wp_speculation_rules_href_exclude_paths:exclude_shop_pages',
				'ropt_prefetch_on_hover:not_with_a_cart',
				'woocommerce_add_to_cart:clear_prefetched_pages',
				'wp_footer:print_press_after_an_add',
			),
			WP_Stub::$callbacks
		);
		$this->assertSame( 11, WP_Stub::$priorities['wp_footer:print_press_after_an_add'], 'After core prints the rules.' );

		WP_Stub::$options['ropt_speculative_moderate'] = 'no';
		WP_Stub::$callbacks = array();
		( new Shop_Prefetch() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks );
	}

	/**
	 * Clearing the prefetch cache never breaks the add-to-cart request.
	 *
	 * @return void
	 */
	public function test_clearing_the_prefetch_cache_is_safe_after_output(): void {
		( new Shop_Prefetch() )->clear_prefetched_pages();

		$this->addToAssertionCount( 1 );
	}
}
