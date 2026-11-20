<?php
/**
 * Loads the cart fragments script only for a visitor who has a cart.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer\Frontend;

use RhinnoIT\Optimizer\Plugin;
use RhinnoIT\Optimizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps wc-cart-fragments off the page while the cart is empty, on Porto.
 *
 * The script posts to ?wc-ajax=get_refreshed_fragments on the first page of
 * every visit, a request no page cache can answer. Porto enqueues it on every
 * page for its header mini cart, so every new visitor - and every bot that
 * runs scripts - costs one uncached PHP request before anything is in the
 * cart.
 *
 * Porto's mini cart is also why the script cannot simply go: porto_minicart()
 * prints a loading spinner where the cart goes and leaves the script to fill
 * it. So when the script is dropped, the empty cart is drawn on the server in
 * the spinner's place, exactly what the script would have fetched. On a theme
 * without porto_minicart() nothing is dropped.
 *
 * It relies on the page cache skipping visitors with a cart, which Kinsta
 * does: WooCommerce sets woocommerce_items_in_cart with the first item and
 * clears it when the cart empties, and a visitor who carries it gets a fresh
 * page with the script back. WooCommerce makes it a browser-session cookie,
 * though, while the cart it stands for lives as long as the session cookie
 * (two days for a guest): a shopper who closed the browser came back to
 * cached pages and an empty cart, with nothing left to correct it. So the two
 * cart cookies are sent again with the session cookie's expiry on every
 * request that has a cart. cPanel's NGINX cache has no such rule - it
 * skips only logged-in users - so there a visitor with items would be served
 * a cached empty cart and no script to fix it. Hence Porto on Kinsta only,
 * the one place its switch is offered. Two edge cases the script used to cover are covered by a
 * few bytes in the footer instead: a page restored by the back button, and a
 * second tab, reload once a cart exists.
 *
 * Dequeued, never deregistered: a script that declares it as a dependency
 * would silently disappear with it.
 */
final class Cart_Fragments {

	/**
	 * Handle WooCommerce registers the script under.
	 */
	const string HANDLE = 'wc-cart-fragments';

	/**
	 * Cookie WooCommerce keeps while the cart has items.
	 */
	const string CART_COOKIE = 'woocommerce_items_in_cart';

	/**
	 * Cookie WooCommerce keeps the cart's hash in, beside CART_COOKIE.
	 */
	const string HASH_COOKIE = 'woocommerce_cart_hash';

	/**
	 * Start of the name of WooCommerce's session cookie, the one that keeps the cart.
	 */
	const string SESSION_COOKIE = 'wp_woocommerce_session_';

	/**
	 * When the session cookie sent in this response expires; 0 when none was.
	 *
	 * @var int
	 */
	private int $session_expires = 0;

	/**
	 * What porto_minicart() prints where the cart goes.
	 */
	const string PLACEHOLDER = '<div class="cart-loading"></div>';

	/**
	 * Whether this request dropped the script.
	 *
	 * @var bool
	 */
	private bool $dropped = false;

	/**
	 * Hooks the dequeue, the empty cart, the footer watcher and the cart cookies' lifetime.
	 *
	 * @return void
	 */
	public function register(): void {
		// The template is an option, so it is known before the theme loads. Its
		// switch is offered only on Porto, so nothing else may run anywhere else.
		if ( ! Settings::feature( 'cart_fragments' ) || ! Plugin::kinsta_is_active() || 'porto' !== get_template() ) {
			return;
		}

		// After WooCommerce (10) and the theme have enqueued it.
		add_action( 'wp_enqueue_scripts', array( $this, 'drop_while_the_cart_is_empty' ), 99 );
		add_filter( 'porto_minicart', array( $this, 'draw_the_empty_cart' ) );
		add_action( 'wp_footer', array( $this, 'print_cart_watcher' ) );
		add_filter( 'woocommerce_set_cookie_options', array( $this, 'note_the_session_expiry' ), 10, 2 );

		// After WooCommerce sends the session cookie, at 10 on the same action.
		add_action( 'woocommerce_set_cart_cookies', array( $this, 'keep_the_cart_cookies_with_the_session' ), 11 );
	}

	/**
	 * Takes the script off the page when this visitor has no cart.
	 *
	 * @return void
	 */
	public function drop_while_the_cart_is_empty(): void {
		// Only its presence is read, never its value.
		if ( isset( $_COOKIE[ self::CART_COOKIE ] ) ) {
			return;
		}

		// Without Porto's mini cart there is no known way to draw the empty
		// cart, and whatever mini cart there is would wait for the script.
		if ( ! function_exists( 'porto_minicart' ) || ! function_exists( 'woocommerce_mini_cart' ) ) {
			return;
		}

		wp_dequeue_script( self::HANDLE );

		$this->dropped = true;
	}

	/**
	 * Puts the empty cart where Porto left its spinner.
	 *
	 * @param mixed $html Porto's mini cart markup.
	 * @return mixed
	 */
	public function draw_the_empty_cart( mixed $html ): mixed {
		if ( ! $this->dropped || ! is_string( $html ) || ! str_contains( $html, self::PLACEHOLDER ) ) {
			return $html;
		}

		ob_start();
		woocommerce_mini_cart();
		$cart = (string) ob_get_clean();

		return '' === trim( $cart ) ? $html : str_replace( self::PLACEHOLDER, $cart, $html );
	}

	/**
	 * Reloads a page shown from memory once the visitor has a cart.
	 *
	 * The script this replaces listened for two cases: the back button
	 * restoring a page from the browser's cache, and another tab changing the
	 * cart. Without it such a page would keep showing the empty cart. The
	 * reload gets a fresh page, which has a cart and so has the script.
	 *
	 * @return void
	 */
	public function print_cart_watcher(): void {
		if ( ! $this->dropped ) {
			return;
		}

		wp_print_inline_script_tag(
			'(function(){function c(){return/(?:^|;\\s*)' . self::CART_COOKIE . '=/.test(document.cookie)}'
			. 'addEventListener("pageshow",function(e){if(e.persisted&&c())location.reload()});'
			. 'addEventListener("storage",function(e){if(e.key&&0===e.key.indexOf("wc_cart_hash")&&c())location.reload()})})();'
		);
	}

	/**
	 * Notes when the session cookie this response sends expires.
	 *
	 * Only reads: the options leave as they came. A deletion carries an
	 * expiry in the past and resets the note.
	 *
	 * @param mixed $options Options about to be passed to setcookie().
	 * @param mixed $name    Cookie name.
	 * @return mixed
	 */
	public function note_the_session_expiry( mixed $options, mixed $name ): mixed {
		if ( is_array( $options ) && is_string( $name ) && str_starts_with( $name, self::SESSION_COOKIE ) && is_int( $options['expires'] ?? null ) ) {
			$this->session_expires = time() < $options['expires'] ? $options['expires'] : 0;
		}

		return $options;
	}

	/**
	 * Sends the cart cookies again so they expire with the session that keeps the cart.
	 *
	 * Runs each time WooCommerce sets them, which is every request with a
	 * cart - requests the page cache skips anyway - so the expiry follows the
	 * session as it slides. A deletion keeps WooCommerce's past expiry, and
	 * without a session to follow the cookies stay as WooCommerce sent them.
	 *
	 * @param mixed $set Whether WooCommerce set the cookies (true) or deleted them.
	 * @return void
	 */
	public function keep_the_cart_cookies_with_the_session( mixed $set ): void {
		if ( true !== $set || ! function_exists( 'WC' ) || ! function_exists( 'wc_setcookie' ) ) {
			return;
		}

		$wc = WC();

		if ( ! $wc instanceof \WooCommerce || ! $wc->cart instanceof \WC_Cart || $wc->cart->is_empty() ) {
			return;
		}

		$expires = 0 < $this->session_expires ? $this->session_expires : self::request_session_expiry( $wc );

		if ( time() >= $expires ) {
			return;
		}

		// The values WooCommerce gives them, so only the expiry changes.
		wc_setcookie( self::CART_COOKIE, '1', $expires );
		wc_setcookie( self::HASH_COOKIE, $wc->cart->get_cart_hash(), $expires );
	}

	/**
	 * When the session cookie the visitor sent expires; 0 without one.
	 *
	 * The Store API swaps in a session handler of its own, without cookies,
	 * for requests that carry a cart token.
	 *
	 * @param \WooCommerce $wc The WooCommerce instance.
	 * @return int
	 */
	private static function request_session_expiry( \WooCommerce $wc ): int {
		$session = $wc->session;

		if ( ! $session instanceof \WC_Session_Handler ) {
			return 0;
		}

		$cookie = $session->get_session_cookie();

		return is_array( $cookie ) && is_numeric( $cookie[1] ?? null ) ? (int) $cookie[1] : 0;
	}
}
