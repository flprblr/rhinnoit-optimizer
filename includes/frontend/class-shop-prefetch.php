<?php
/**
 * The WooCommerce half of hover prefetching.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer\Frontend;

use RhinnoIT\Optimizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps hover prefetching from costing the shop or showing a stale cart.
 *
 * Read from the same "Preload pages on hover" switch as the WordPress half,
 * and does three things that need to know about the cart:
 *
 * - The cart, checkout and account pages are never prefetched. They are never
 *   served from a page cache, so each pass of the pointer over the header
 *   cart icon would be a full page render nobody asked for.
 * - A visitor who has a cart keeps WordPress's prefetch on press. Kinsta
 *   bypasses its cache for them on every page, so hover prefetching would
 *   turn each hover into a render, for the visitors who matter most - on a
 *   phone, Chrome's moderate eagerness fires on every pause of the scroll.
 *   The decision is per request. On Kinsta a page for a visitor with a cart
 *   is never cached for anybody else; behind a cache that ignores the cart,
 *   as cPanel's NGINX does, the worst case is a cached page that prefetches
 *   on press, which only loses the speed-up. The page where the item goes
 *   in over AJAX was built without a cart, so a few bytes in its footer
 *   switch its rules to press the moment WooCommerce announces the add.
 * - Adding to the cart clears the browser's prefetch cache. A page prefetched
 *   while the cart was empty would otherwise be shown after the add, with an
 *   empty mini cart - browsers do not drop a same-site prefetch when cookies
 *   change.
 */
final class Shop_Prefetch {

	/**
	 * WooCommerce pages that must not be prefetched.
	 *
	 * @var array<int, string>
	 */
	const array PAGES = array( 'cart', 'checkout', 'myaccount' );

	/**
	 * Whether this page prefetches on hover; known once core builds its rules.
	 *
	 * @var bool
	 */
	private bool $hovers = false;

	/**
	 * Hooks the three corrections when hover prefetching is on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'speculative_moderate' ) ) {
			return;
		}

		add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'exclude_shop_pages' ) );
		add_filter( 'ropt_prefetch_on_hover', array( $this, 'not_with_a_cart' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'clear_prefetched_pages' ) );

		// After core prints its rules, at 10.
		add_action( 'wp_footer', array( $this, 'print_press_after_an_add' ), 11 );
	}

	/**
	 * Adds each assigned shop page, with and without its trailing slash.
	 *
	 * '{/*}?' matches '/carrito', '/carrito/' and anything under it, whatever
	 * the permalink structure says about trailing slashes. Paths are relative
	 * to the home URL; core prefixes a subdirectory install itself. A page that
	 * is not assigned or no longer exists adds nothing.
	 *
	 * @param mixed $paths Patterns core and other plugins already exclude.
	 * @return mixed
	 */
	public function exclude_shop_pages( mixed $paths ): mixed {
		if ( ! is_array( $paths ) || ! function_exists( 'wc_get_page_id' ) ) {
			return $paths;
		}

		foreach ( self::PAGES as $page ) {
			$id  = wc_get_page_id( $page );
			$uri = 0 < $id ? get_page_uri( $id ) : '';

			if ( is_string( $uri ) && '' !== trim( $uri, '/' ) ) {
				$paths[] = '/' . trim( $uri, '/' ) . '{/*}?';
			}
		}

		return $paths;
	}

	/**
	 * Keeps prefetch on press for a visitor who has a cart.
	 *
	 * @param mixed $allowed What the WordPress half would do.
	 * @return bool
	 */
	public function not_with_a_cart( mixed $allowed ): bool {
		// Only its presence is read, never its value.
		$this->hovers = (bool) $allowed && ! isset( $_COOKIE[ Cart_Fragments::CART_COOKIE ] );

		return $this->hovers;
	}

	/**
	 * Switches a page that prefetches on hover to press once something goes into the cart.
	 *
	 * WooCommerce and Porto announce every AJAX add with added_to_cart on the
	 * body. Another tab, or a page the back button restores, hears nothing,
	 * so the cart cookie is checked again whenever the page comes into view.
	 * The rules are rewritten with the eagerness a page built for a cart
	 * gets; Chrome drops a rule set whose script leaves the page. jQuery may
	 * be deferred, so the listener waits for the document.
	 *
	 * @return void
	 */
	public function print_press_after_an_add(): void {
		if ( ! $this->hovers ) {
			return;
		}

		wp_print_inline_script_tag(
			'(function(d){function p(){d.querySelectorAll(\'script[type="speculationrules"]\').forEach(function(s){try{var r=JSON.parse(s.textContent),c=0,k;'
			. 'for(k in r)if(Array.isArray(r[k]))r[k].forEach(function(x){if(x&&"moderate"===x.eagerness){x.eagerness="conservative";c=1}});'
			. 'if(c){var n=d.createElement("script");n.type="speculationrules";n.textContent=JSON.stringify(r);s.replaceWith(n)}}catch(e){}})}'
			. 'function v(){"visible"===d.visibilityState&&/(?:^|;\\s*)' . Cart_Fragments::CART_COOKIE . '=/.test(d.cookie)&&p()}'
			. 'd.addEventListener("visibilitychange",v);addEventListener("pageshow",function(e){e.persisted&&v()});'
			. 'function b(){window.jQuery&&jQuery(d.body).on("added_to_cart",p)}"loading"===d.readyState?d.addEventListener("DOMContentLoaded",b):b()})(document);'
		);
	}

	/**
	 * Tells the browser to drop what it prefetched while the cart was empty.
	 *
	 * Fires for every add, the AJAX one from a listing included. Browsers that
	 * do not know the value ignore the header.
	 *
	 * @return void
	 */
	public function clear_prefetched_pages(): void {
		if ( ! headers_sent() ) {
			header( 'Clear-Site-Data: "prefetchCache"' );
		}
	}
}
