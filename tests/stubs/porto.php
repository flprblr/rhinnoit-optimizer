<?php
/**
 * The slice of the Porto theme the plugin touches.
 *
 * Required by tests/bootstrap.php unless ROPT_TESTS_PORTO is 'none'.
 *
 * @package RhinnoIT\Optimizer
 */

/**
 * Porto's category image, printed above a category's products when its term meta holds one.
 *
 * @return void
 */
function porto_woocommerce_category_image() {}

/**
 * Porto's mini cart: the spinner it prints where the cart goes, through its filter.
 *
 * @return string
 */
function porto_minicart() {
	return apply_filters( 'porto_minicart', '<div id="mini-cart"><div class="cart-popup widget_shopping_cart"><div class="widget_shopping_cart_content"><div class="cart-loading"></div></div></div></div>' );
}
