<?php
/**
 * WordPress defaults that cost something and give nothing on these sites.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * The page head and pings.
 *
 * Neither moves a Lighthouse score. The head links are discovery endpoints for
 * tools nobody runs against a shop; the generator tags tell every scanner which
 * WordPress and WooCommerce release to try; pings are an amplification target
 * that turns up in access logs as floods of uncached PHP requests.
 *
 * Nothing here repeats a wp-config.php setting. A constant set there always
 * wins, so a switch for the same thing on this screen could only ever be
 * overruled or confusing - which is why the revision limit of 2.0 is gone.
 *
 * What it leaves alone is as deliberate as what it removes. The REST API
 * discovery link stays, because integrations find the API through it. XML-RPC
 * as a whole stays, because apps and Jetpack still use it; only the two
 * pingback methods go.
 */
final class Housekeeping {

	/**
	 * Pingback methods removed from XML-RPC.
	 *
	 * @var array<int, string>
	 */
	const array PINGBACK_METHODS = array( 'pingback.ping', 'pingback.extensions.getPingbacks' );

	/**
	 * Hooks what is switched on.
	 *
	 * @return void
	 */
	public function register(): void {
		// Core adds these in default-filters.php, before any plugin loads, so
		// they are already registered and can be taken off here.
		if ( Settings::feature( 'head_rsd' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( Settings::feature( 'head_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( Settings::feature( 'head_oembed' ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		}

		// the_generator() prints core's tag in the head and in every feed, and
		// WooCommerce appends its own to core's through get_the_generator_html
		// and get_the_generator_xhtml, so emptying the one filter both go
		// through takes off both, and nothing else. Site Kit, Elementor and
		// WPBakery echo theirs on wp_head themselves and keep them.
		if ( Settings::feature( 'head_generator' ) ) {
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( Settings::feature( 'pingbacks' ) ) {
			add_filter( 'pings_open', '__return_false' );
			add_filter( 'xmlrpc_methods', array( $this, 'drop_pingback_methods' ) );
			add_filter( 'wp_headers', array( $this, 'drop_pingback_header' ) );
		}
	}

	/**
	 * Removes the pingback methods from XML-RPC.
	 *
	 * @param mixed $methods Method name => callback.
	 * @return mixed
	 */
	public function drop_pingback_methods( mixed $methods ): mixed {
		if ( ! is_array( $methods ) ) {
			return $methods;
		}

		foreach ( self::PINGBACK_METHODS as $method ) {
			unset( $methods[ $method ] );
		}

		return $methods;
	}

	/**
	 * Removes the X-Pingback response header.
	 *
	 * @param mixed $headers Header name => value.
	 * @return mixed
	 */
	public function drop_pingback_header( mixed $headers ): mixed {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}

		return $headers;
	}
}
