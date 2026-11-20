<?php
/**
 * Lazy-loads the thumbnail strip under a product's gallery.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer\Frontend;

use RhinnoIT\Optimizer\Delivery;
use RhinnoIT\Optimizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the gallery's thumbnail strip from competing with the product image.
 *
 * Porto prints the strip under the gallery as bare <img> tags built from
 * wp_get_attachment_image_src(), so core's loading optimisation never sees
 * them and they leave with no loading attribute at all. Measured on a shop's
 * product page with a lazy-load plugin switched off: eight 786-pixel thumbs
 * fetched by the preload scanner alongside the main image, and the main
 * image painting the cached thumb instead of its own prioritised request -
 * a 4.9-second paint on mobile, 3.9 once the strip waited.
 *
 * The rule is structural: every <img> printed inside
 * woocommerce_product_thumbnails - the action that renders the strip on
 * WooCommerce's template and Porto's alike - gets `loading="lazy"` and
 * `decoding="async"`, unless it already carries a loading attribute or the
 * high priority. The main image is printed before that action, so it is never
 * touched; WooCommerce's own strip goes through wp_get_attachment_image() and
 * already has its loading attribute, so on a theme without Porto nothing
 * changes.
 */
final class Gallery_Strip {

	/**
	 * How deep inside woocommerce_product_thumbnails the rendering is.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Hooks the correction.
	 *
	 * Porto's own lazy loader takes over the same filter at 9999 and skips
	 * only its own class, so the correction steps aside while it is on, as
	 * First_Row does.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'gallery_strip_lazy' ) || Delivery::porto_covers( 'lazyload' ) ) {
			return;
		}

		add_action( 'woocommerce_product_thumbnails', array( $this, 'open_strip' ), 0 );
		add_action( 'woocommerce_product_thumbnails', array( $this, 'close_strip' ), PHP_INT_MAX );
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( $this, 'lazy_strip' ), 20 );
		add_filter( 'woocommerce_single_product_video_thumbnail_html', array( $this, 'lazy_strip' ), 20 );
	}

	/**
	 * Marks the start of the strip.
	 *
	 * @return void
	 */
	public function open_strip(): void {
		++$this->depth;
	}

	/**
	 * Marks the end of the strip.
	 *
	 * @return void
	 */
	public function close_strip(): void {
		$this->depth = max( 0, $this->depth - 1 );
	}

	/**
	 * Adds lazy loading to the strip's images that carry no loading attribute.
	 *
	 * @param mixed $html One strip item's markup.
	 * @return mixed
	 */
	public function lazy_strip( mixed $html ): mixed {
		if ( 0 === $this->depth || ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		// An <img> tag whose quoted attribute values may hold a ">".
		$lazy = preg_replace_callback(
			'/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i',
			static function ( array $tag ): string {
				$img = $tag[0];

				if ( preg_match( '/\sloading\s*=/i', $img ) || preg_match( '/\sfetchpriority\s*=\s*(["\']?)high\1/i', $img ) ) {
					return $img;
				}

				$self_closing = str_ends_with( $img, '/>' );
				$body         = rtrim( substr( $img, 0, $self_closing ? -2 : -1 ) );
				$extra        = ' loading="lazy"' . ( preg_match( '/\sdecoding\s*=/i', $img ) ? '' : ' decoding="async"' );

				return $body . $extra . ( $self_closing ? ' />' : '>' );
			},
			$html
		);

		return is_string( $lazy ) ? $lazy : $html;
	}
}
