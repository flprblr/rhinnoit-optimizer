<?php
/**
 * The slice of WooCommerce the plugin touches.
 *
 * Required by tests/bootstrap.php only when WC_VERSION is defined.
 *
 * @package RhinnoIT\Optimizer
 */

function woocommerce_mini_cart() { echo WP_Stub::$mini_cart; }

function wc_get_page_id( $page ) { return WP_Stub::$wc_pages[ $page ] ?? -1; }

function is_product() { return 'product' === WP_Stub::$page_type; }

function is_cart() { return 'cart' === WP_Stub::$page_type; }

function is_checkout() { return 'checkout' === WP_Stub::$page_type; }

function is_account_page() { return 'account' === WP_Stub::$page_type; }

function is_shop() { return 'shop' === WP_Stub::$page_type; }

function is_product_taxonomy() { return 'taxonomy' === WP_Stub::$page_type; }

function wc_get_loop_prop( $prop, $default_value = '' ) { return 'columns' === $prop && null !== WP_Stub::$loop_columns ? WP_Stub::$loop_columns : $default_value; }

function wc_get_default_products_per_row() { return 4; }

function woocommerce_get_loop_display_mode() { return WP_Stub::$loop_display; }

function is_product_category() { return WP_Stub::$queried_object instanceof WP_Term && 'product_cat' === WP_Stub::$queried_object->taxonomy; }

function wc_get_logger() {
	return new class {
		/**
		 * Records a log line.
		 *
		 * @param string $level   Level.
		 * @param string $message Message.
		 * @param array  $context Context.
		 * @return void
		 */
		public function log( $level, $message, $context = array() ): void {
			WP_Stub::$logs[] = $level . ': ' . $message;
		}
	};
}

/**
 * Minimal product: only what Frontend\Shop_Cache asks it, and the kind WC_Cart::fill() gives it.
 */
class WC_Product {

	public bool $virtual;
	public int $id;
	public int $parent;

	/**
	 * @param bool $virtual Whether the product ships.
	 * @param int  $id      Product id.
	 * @param int  $parent  Parent id, for a variation.
	 */
	public function __construct( bool $virtual = false, int $id = 0, int $parent = 0 ) {
		$this->virtual = $virtual;
		$this->id      = $id;
		$this->parent  = $parent;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_parent_id(): int {
		return $this->parent;
	}

	/** @var array<string, mixed> Properties changed and not yet saved. */
	public array $changes = array();

	public function get_changes(): array {
		return $this->changes;
	}

	/**
	 * Whether the product needs no shipping.
	 *
	 * @return bool
	 */
	public function is_virtual(): bool {
		return $this->virtual;
	}
}

/**
 * Minimal cart: only what Frontend\Cart_Fragments asks it.
 */
class WC_Cart {

	/** @var array<int, array<string, mixed>> */
	public array $items = array();

	/**
	 * Whether the cart holds nothing.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->items;
	}

	/**
	 * Fills the cart with products of the given kind.
	 *
	 * @param bool ...$virtual One flag per line.
	 * @return void
	 */
	public function fill( bool ...$virtual ): void {
		$this->items = array();

		foreach ( $virtual as $is_virtual ) {
			$this->items[] = array( 'data' => new WC_Product( $is_virtual ) );
		}
	}

	/**
	 * The hash WooCommerce keeps in the woocommerce_cart_hash cookie.
	 *
	 * @return string
	 */
	public function get_cart_hash(): string {
		return md5( (string) wp_json_encode( $this->items ) );
	}
}

/**
 * WooCommerce's cookie session: only the cookie it read from the request.
 */
class WC_Session_Handler {

	/** @var array<int, mixed>|false What get_session_cookie() answers: id, expiration, expiring, hash. */
	public array|false $cookie = false;

	/**
	 * The session cookie the visitor sent, once its hash checks out.
	 *
	 * @return array<int, mixed>|false
	 */
	public function get_session_cookie(): array|false {
		return $this->cookie;
	}
}

/**
 * Stands in for the WooCommerce singleton.
 */
class WooCommerce {

	public ?WC_Cart $cart = null;

	/** The cookie handler, or the Store API's own handler for a cart token. */
	public ?object $session = null;
}

function WC() { return WP_Stub::$wc; }

function wc_setcookie( $name, $value, $expire = 0, $secure = false, $httponly = false ) { WP_Stub::$cookies[] = array( (string) $name, (string) $value, (int) $expire ); }
