<?php
/**
 * Loading the cart fragments script only for a visitor with a cart, on Porto.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\Cart_Fragments;

/**
 * Covers RhinnoIT\Optimizer\Frontend\Cart_Fragments.
 */
#[CoversClass( Cart_Fragments::class )]
#[RunTestsInSeparateProcesses]
final class CartFragmentsTest extends TestCase {

	/**
	 * Runs on Kinsta, the only host whose cache skips visitors with a cart.
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
	 * Starts from a configured Porto shop with the switch on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$options['ropt_cart_fragments'] = 'yes';
	}

	/**
	 * Runs the correction the way a storefront request does.
	 *
	 * @return array{0: Cart_Fragments, 1: string, 2: string} The service, the mini cart and the footer.
	 */
	private function render(): array {
		$fragments = new Cart_Fragments();
		$fragments->register();
		$fragments->drop_while_the_cart_is_empty();

		$minicart = $fragments->draw_the_empty_cart( porto_minicart() );

		ob_start();
		$fragments->print_cart_watcher();

		return array( $fragments, (string) $minicart, (string) ob_get_clean() );
	}

	/**
	 * Hooked after WooCommerce and the theme have enqueued the script.
	 *
	 * @return void
	 */
	public function test_it_hooks_after_the_script_is_enqueued(): void {
		( new Cart_Fragments() )->register();

		$this->assertSame(
			array(
				'wp_enqueue_scripts:drop_while_the_cart_is_empty',
				'porto_minicart:draw_the_empty_cart',
				'wp_footer:print_cart_watcher',
				'woocommerce_set_cookie_options:note_the_session_expiry',
				'woocommerce_set_cart_cookies:keep_the_cart_cookies_with_the_session',
			),
			WP_Stub::$callbacks
		);
		$this->assertSame( 99, WP_Stub::$priorities['wp_enqueue_scripts:drop_while_the_cart_is_empty'], 'At 10 the theme may not have enqueued it yet.' );
		$this->assertSame( 11, WP_Stub::$priorities['woocommerce_set_cart_cookies:keep_the_cart_cookies_with_the_session'], 'WooCommerce sends the session cookie at 10.' );
	}

	/**
	 * A WooCommerce with a cart of one line, as on an add to cart.
	 *
	 * @param object|null $session The session handler WooCommerce runs.
	 * @return WooCommerce
	 */
	private static function shop_with_a_cart( ?object $session = null ): WooCommerce {
		$wc          = new WooCommerce();
		$wc->cart    = new WC_Cart();
		$wc->session = $session;
		$wc->cart->fill( false );

		WP_Stub::$wc = $wc;

		return $wc;
	}

	/**
	 * The cart cookies leave with the expiry of the session cookie sent beside them.
	 *
	 * WooCommerce sends woocommerce_items_in_cart without an expiry, so it
	 * dies with the browser while the cart lives two days: the shopper came
	 * back to cached pages and an empty mini cart. Fed what WooCommerce passes:
	 * the session cookie's options with its future expiry, then the action.
	 *
	 * @return void
	 */
	public function test_the_cart_cookies_live_as_long_as_the_cart(): void {
		$wc        = self::shop_with_a_cart( new WC_Session_Handler() );
		$fragments = new Cart_Fragments();
		$expires   = time() + 2 * DAY_IN_SECONDS;
		$options   = array(
			'expires'  => $expires,
			'secure'   => true,
			'path'     => '/',
			'domain'   => '',
			'httponly' => true,
		);

		$this->assertSame( $options, $fragments->note_the_session_expiry( $options, 'wp_woocommerce_session_abc123' ), 'Only read, never changed.' );

		$fragments->keep_the_cart_cookies_with_the_session( true );

		$this->assertSame(
			array(
				array( 'woocommerce_items_in_cart', '1', $expires ),
				array( 'woocommerce_cart_hash', $wc->cart->get_cart_hash(), $expires ),
			),
			WP_Stub::$cookies
		);
	}

	/**
	 * Without a session cookie in this response, the one the visitor sent sets the expiry.
	 *
	 * That is every later page of the visit, and a cart made before this
	 * release, whose cookie upgrades on its next uncached page.
	 *
	 * @return void
	 */
	public function test_the_visitors_session_cookie_sets_the_expiry_otherwise(): void {
		$session         = new WC_Session_Handler();
		$expires         = time() + DAY_IN_SECONDS;
		$session->cookie = array( 't_abc', (string) $expires, (string) ( $expires - HOUR_IN_SECONDS ), 'hash' );
		self::shop_with_a_cart( $session );

		( new Cart_Fragments() )->keep_the_cart_cookies_with_the_session( true );

		$this->assertSame( array( $expires, $expires ), array_column( WP_Stub::$cookies, 2 ) );
	}

	/**
	 * A deletion stays a deletion, and no session leaves WooCommerce's cookies as they were.
	 *
	 * WooCommerce deletes the cart cookies with value 0 and an expiry an hour
	 * back, and the session cookie with an empty value a year back; turning
	 * either into a two-day cookie would keep an emptied cart bypassing the
	 * cache.
	 *
	 * @return void
	 */
	public function test_a_deletion_or_a_missing_session_sends_nothing(): void {
		self::shop_with_a_cart( new WC_Session_Handler() );
		$fragments = new Cart_Fragments();

		$fragments->note_the_session_expiry( array( 'expires' => time() + DAY_IN_SECONDS ), 'wp_woocommerce_session_abc123' );
		$fragments->keep_the_cart_cookies_with_the_session( false );
		$this->assertSame( array(), WP_Stub::$cookies, 'WooCommerce deleted the cart cookies.' );

		$fragments->note_the_session_expiry( array( 'expires' => time() - YEAR_IN_SECONDS ), 'wp_woocommerce_session_abc123' );
		$fragments->keep_the_cart_cookies_with_the_session( true );
		$this->assertSame( array(), WP_Stub::$cookies, 'The session cookie was deleted and the visitor sent none.' );

		$fragments->note_the_session_expiry( array( 'expires' => time() + DAY_IN_SECONDS ), 'woocommerce_recently_viewed' );
		$fragments->keep_the_cart_cookies_with_the_session( true );
		$this->assertSame( array(), WP_Stub::$cookies, 'Another cookie says nothing about the session.' );

		WP_Stub::$wc->cart->items = array();
		$fragments->note_the_session_expiry( array( 'expires' => time() + DAY_IN_SECONDS ), 'wp_woocommerce_session_abc123' );
		$fragments->keep_the_cart_cookies_with_the_session( true );
		$this->assertSame( array(), WP_Stub::$cookies, 'An empty cart.' );
	}

	/**
	 * The Store API's own session handler, for a cart token, has no cookie to read.
	 *
	 * Asking it for one would be a fatal error on that request.
	 *
	 * @return void
	 */
	public function test_the_store_apis_session_handler_is_left_alone(): void {
		self::shop_with_a_cart( new class() {} );

		( new Cart_Fragments() )->keep_the_cart_cookies_with_the_session( true );
		$this->assertSame( array(), WP_Stub::$cookies );

		WP_Stub::$wc->cart = null;
		( new Cart_Fragments() )->keep_the_cart_cookies_with_the_session( true );
		$this->assertSame( array(), WP_Stub::$cookies, 'No cart object before wp_loaded.' );
	}

	/**
	 * Off, it hooks nothing.
	 *
	 * @return void
	 */
	public function test_nothing_is_hooked_while_it_is_off(): void {
		WP_Stub::$options['ropt_cart_fragments'] = 'no';

		( new Cart_Fragments() )->register();

		$this->assertSame( array(), WP_Stub::$callbacks );
	}

	/**
	 * An empty cart loses the script and gets the empty cart drawn instead.
	 *
	 * Porto prints a spinner where the cart goes and leaves the script to fill
	 * it; without the empty cart drawn here, the spinner never stops.
	 *
	 * @return void
	 */
	public function test_an_empty_cart_is_drawn_in_place_of_the_spinner(): void {
		list( , $minicart, $footer ) = $this->render();

		$this->assertSame( array( 'wc-cart-fragments' ), WP_Stub::$dequeued );
		$this->assertStringNotContainsString( 'cart-loading', $minicart );
		$this->assertStringContainsString( '<div class="widget_shopping_cart_content"><ul class="cart_list"><li class="empty">No products in the cart.</li></ul></div>', $minicart );
		$this->assertStringContainsString( 'pageshow', $footer, 'The back button case the script used to cover.' );
		$this->assertStringContainsString( 'storage', $footer, 'The second tab case.' );
		$this->assertStringContainsString( 'woocommerce_items_in_cart=', $footer );
	}

	/**
	 * A visitor with a cart keeps the script, the spinner it fills, and no watcher.
	 *
	 * @return void
	 */
	public function test_a_visitor_with_a_cart_keeps_everything(): void {
		foreach ( array( '1', '', array( 'x' ) ) as $value ) {
			WP_Stub::reset();
			WP_Stub::$options['ropt_cart_fragments'] = 'yes';
			$_COOKIE['woocommerce_items_in_cart']    = $value;

			list( , $minicart, $footer ) = $this->render();

			$this->assertSame( array(), WP_Stub::$dequeued );
			$this->assertStringContainsString( 'cart-loading', $minicart, 'The script is there to fill it.' );
			$this->assertSame( '', $footer );
		}
	}

	/**
	 * Markup the correction does not recognise is left as it came.
	 *
	 * @return void
	 */
	public function test_unknown_markup_is_left_alone(): void {
		list( $fragments ) = $this->render();

		$this->assertSame( '<div>theme changed</div>', $fragments->draw_the_empty_cart( '<div>theme changed</div>' ) );
		$this->assertNull( $fragments->draw_the_empty_cart( null ) );

		WP_Stub::$mini_cart = '  ';
		$this->assertStringContainsString( 'cart-loading', (string) $fragments->draw_the_empty_cart( porto_minicart() ), 'Nothing to put there, so the spinner stays.' );
	}
}
