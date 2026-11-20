<?php
/**
 * Keeps the product image out of lazy loading on its own page.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer\Frontend;

use RhinnoIT\Optimizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A delivery correction that needs WooCommerce, like First_Row.
 *
 * It lives here rather than in Delivery because Delivery is the WordPress half
 * of the plugin and runs on any site. This asks is_product(), which only
 * WooCommerce defines, so Plugin registers it only where WooCommerce is.
 */
final class Product_Image {

	/**
	 * The product the page shows, or 0 on any other page.
	 *
	 * @var int
	 */
	private int $product_id = 0;

	/**
	 * That product's featured image.
	 *
	 * @var int
	 */
	private int $image_id = 0;

	/**
	 * Whether the image has been marked; only its first rendering is the paint.
	 *
	 * @var bool
	 */
	private bool $marked = false;

	/**
	 * Hooks the correction.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'product_image_eager' ) ) {
			return;
		}

		add_action( 'wp', array( $this, 'remember_the_product_image' ) );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'never_defer_the_product_image' ), 10, 3 );
	}

	/**
	 * Notes which product the page shows and its featured image, once.
	 *
	 * Asked on `wp`, before any template runs, because asking inside the image
	 * filter gets the wrong answer: Porto's posts grid sets the queried object
	 * to each item it renders, so on a static front page `is_product()` turns
	 * true and `get_post_thumbnail_id()` names the grid item's image. That
	 * marked all 24 thumbnails of a shop's home page eager and high priority,
	 * where they fought the banner for the connection - and the related and
	 * upsell thumbnails of every product page with them.
	 *
	 * @return void
	 */
	public function remember_the_product_image(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product = get_queried_object_id();
		$image   = 0 < $product ? get_post_thumbnail_id( $product ) : 0;

		if ( is_numeric( $image ) && 0 < (int) $image ) {
			$this->product_id = $product;
			$this->image_id   = (int) $image;
		}
	}

	/**
	 * Keeps the image a product page is judged on out of lazy loading.
	 *
	 * On a product page the featured image is the Largest Contentful Paint,
	 * every time, on every viewport - it is the reason the page exists. Core
	 * still hands it `loading="lazy"` whenever enough images were rendered
	 * before it, which on a Woo shop is easy: a logo, a menu icon, a badge.
	 * Measured on the two shops this was written for, that pushed the paint
	 * from 4.7 to 8.3 seconds, with Lighthouse's `lcp-lazy-loaded` failing.
	 *
	 * The rule asks WordPress which attachment the product is showing rather
	 * than matching a class or a position: `woocommerce-main-image` is a class
	 * one theme prints and another does not - one of these two shops had it and
	 * the other did not until its gallery was rebuilt - and a position is
	 * whatever the header happened to render first. The featured image of the
	 * product being viewed is the same fact on any theme.
	 *
	 * It also sets `fetchpriority` high, because core's own high-priority hint
	 * goes to whichever image it guessed first, and a hint that arrives with
	 * `loading="lazy"` beside it is ignored anyway.
	 *
	 * Only the first large rendering inside the product's own loop is marked:
	 * the same attachment at a thumbnail size - Porto's sticky add-to-cart bar,
	 * the gallery strip, a product card - or outside the loop is not the paint,
	 * and does not use up the mark. Core's "auto" is dropped from `sizes`, where
	 * it is only valid on a lazy image. A lazy-load plugin that rewrites
	 * `src` into `data-src` in the output buffer runs after this; Smush leaves
	 * an image with `fetchpriority="high"` alone, and its WooCommerce
	 * integration keeps an explicit `loading` attribute.
	 *
	 * @param mixed $attr       Attributes about to be printed on the tag.
	 * @param mixed $attachment The attachment post being rendered.
	 * @param mixed $size       Requested image size.
	 * @return mixed
	 */
	public function never_defer_the_product_image( mixed $attr, mixed $attachment, mixed $size = null ): mixed {
		if ( $this->marked || 0 === $this->image_id || ! is_array( $attr ) || ! $attachment instanceof \WP_Post ) {
			return $attr;
		}

		if ( $this->image_id !== $attachment->ID || get_the_ID() !== $this->product_id || ! in_the_loop() ) {
			return $attr;
		}

		if ( in_array( $size, array( 'thumbnail', 'woocommerce_thumbnail', 'woocommerce_gallery_thumbnail' ), true ) ) {
			return $attr;
		}

		$this->marked          = true;
		$attr['loading']       = 'eager';
		$attr['fetchpriority'] = 'high';

		if ( is_string( $attr['sizes'] ?? null ) ) {
			$attr['sizes'] = (string) preg_replace( '/^auto\s*,\s*/i', '', $attr['sizes'] );
		}

		return $attr;
	}
}
