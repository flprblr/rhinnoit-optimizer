<?php
/**
 * The slice of WooCommerce the plugin touches.
 *
 * Required by tests/bootstrap.php only when WC_VERSION is defined.
 *
 * @package RhinnoIT\Optimizer
 */

function is_product() { return 'product' === WP_Stub::$page_type; }

function is_cart() { return 'cart' === WP_Stub::$page_type; }

function is_checkout() { return 'checkout' === WP_Stub::$page_type; }

function is_account_page() { return 'account' === WP_Stub::$page_type; }

function is_shop() { return 'shop' === WP_Stub::$page_type; }

function is_product_taxonomy() { return 'taxonomy' === WP_Stub::$page_type; }

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
