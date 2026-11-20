/**
 * Storefront behavior.
 *
 * `window.roptFrontend` is injected by Assets::enqueue_frontend().
 */
import './frontend.scss';

const settings = window.roptFrontend || {};

const init = () => {
	// Storefront logic goes here.
	if ( settings.debug ) {
		// eslint-disable-next-line no-console
		console.log( 'rhinnoit-optimizer: frontend ready' );
	}
};

document.addEventListener( 'DOMContentLoaded', init );

/*
 * WooCommerce replaces the cart and checkout markup over AJAX, so everything
 * has to be initialised again after each refresh. The events are fired through
 * jQuery: a native listener does not receive them.
 */
if ( window.jQuery ) {
	window
		.jQuery( document.body )
		.on( 'updated_cart_totals updated_checkout updated_wc_div', init );
}
