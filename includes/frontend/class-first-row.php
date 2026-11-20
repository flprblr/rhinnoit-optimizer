<?php
/**
 * Loads the first row of a product listing first.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer\Frontend;

use RhinnoIT\Optimizer\Delivery;
use RhinnoIT\Optimizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the first row of the shop, a category or a product search out of lazy loading.
 *
 * On a listing the Largest Contentful Paint is the first product's image, and
 * WordPress defers it: core hands its one high-priority hint to the first
 * large image of the loop, and Porto builds each product's hover image -
 * invisible until the pointer rests on it - before the image the visitor
 * sees. The visible one came second, lazy, and behind a lazy-load plugin's
 * script where one runs. Measured on a shop's category: an 11-second paint on
 * mobile, 4 seconds once the first row loaded first.
 *
 * The rule is positional, never a file or an id, so it holds whatever the
 * products are: the first products the loop renders, as many as a row holds,
 * get their image eager and kept out of Smush's lazy load (`skip-lazy`); a
 * hover image is lazy and never keeps the high priority - Porto hides it on
 * phones - and the first product's image takes it instead. When core gave
 * that hint to an image outside the loop it stays there, and a listing that
 * opens with something else - subcategory tiles, Porto's category image,
 * neither of which core sees - keeps its row lazy. Every other image of the
 * loop leaves with `loading="lazy"` written out, so a later pass that decides
 * loading on the final HTML (Elementor's) cannot count the eager row and wave
 * the next images through.
 *
 * An eager image picks its file before layout, from `sizes` alone, and
 * WooCommerce's says full width: phones fetched the 768px file for a 180px
 * tile. The row's `sizes` is rebuilt from the columns Porto's grid declares,
 * and an image this class makes lazy gets the `auto` core gives its own.
 */
final class First_Row {

	/**
	 * Breakpoints of Porto's column classes below its xl one, which depends on the theme settings.
	 *
	 * @var array<string, int>
	 */
	const array BREAKPOINTS = array(
		'lg' => 992,
		'md' => 768,
		'sm' => 576,
	);

	/**
	 * Classes of a products list whose tiles are not one column of the grid.
	 *
	 * List view shows a fixed 250px image from 576px, the creative grid sizes
	 * each tile on its own, and a slider has no columns at all.
	 *
	 * @var array<int, string>
	 */
	const array NOT_A_GRID = array( 'list', 'grid-creative', 'owl-carousel', 'products-slider', 'divider-line' );

	/**
	 * Whether this request is a product listing.
	 *
	 * @var bool
	 */
	private bool $listing = false;

	/**
	 * Products whose image has been seen, in the order the loop rendered them.
	 *
	 * @var array<int, int> Product id => position.
	 */
	private array $positions = array();

	/**
	 * Whether the listing opens with something other than products.
	 *
	 * @var bool
	 */
	private bool $opens_elsewhere = false;

	/**
	 * Whether a hover image arrived carrying core's high priority.
	 *
	 * @var bool
	 */
	private bool $hint_freed = false;

	/**
	 * Sizes of a tile in the products list being rendered, read from its columns; null when unknown.
	 *
	 * @var string|null
	 */
	private ?string $grid_sizes = null;

	/**
	 * Hooks the correction.
	 *
	 * Porto's own lazy loader rewrites images after this and skips only its
	 * own class, so the correction steps aside while it is on, as Delivery's
	 * lazy-load correction does.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'loop_first_row' ) || Delivery::porto_covers( 'lazyload' ) ) {
			return;
		}

		add_action( 'wp', array( $this, 'detect_listing' ) );
		add_filter( 'woocommerce_product_loop_start', array( $this, 'read_the_grid' ) );
		add_filter( 'woocommerce_product_loop_end', array( $this, 'forget_the_grid' ) );

		// wp_get_attachment_image() sets core's loading attributes before this filter runs.
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'first_row_first' ), 20 );
	}

	/**
	 * Notes whether the page is the shop, a product category or tag, or a product search.
	 *
	 * @return void
	 */
	public function detect_listing(): void {
		$this->listing = is_shop() || is_product_taxonomy() || ( is_search() && 'product' === get_query_var( 'post_type' ) );

		if ( $this->listing ) {
			$this->opens_elsewhere = 'products' !== woocommerce_get_loop_display_mode() || self::porto_category_image();
		}
	}

	/**
	 * Reads the columns of the products list about to render, for the sizes of its first row.
	 *
	 * WooCommerce hands the list's opening tag through this filter just
	 * before the products, so its classes are exactly what the stylesheet
	 * will lay out. Returned unchanged.
	 *
	 * @param mixed $html The opening markup of the products list.
	 * @return mixed
	 */
	public function read_the_grid( mixed $html ): mixed {
		if ( $this->listing && is_string( $html ) ) {
			$this->grid_sizes = self::grid_sizes( $html );
		}

		return $html;
	}

	/**
	 * Forgets the columns once the products list closes.
	 *
	 * A list rendered before the main one - a shortcode in the category
	 * description - must not lend its columns to a main loop printed some
	 * other way. Returned unchanged.
	 *
	 * @param mixed $html The closing markup of the products list.
	 * @return mixed
	 */
	public function forget_the_grid( mixed $html ): mixed {
		$this->grid_sizes = null;

		return $html;
	}

	/**
	 * The sizes of one tile of Porto's products grid, from its column classes.
	 *
	 * Each `ccols-{breakpoint}-{n}` class gives n columns from that
	 * breakpoint up, so a tile is at most 100vw / n there: the container, its
	 * padding, the sidebar and the spacing only make it narrower. The answer
	 * can overestimate - a slightly larger file - and never underestimate,
	 * which would blur the image. The conditions are Porto's own min-width
	 * queries, so the count is the one on screen at every width, fractional
	 * ones included. Null for anything else: list view, a slider, a theme
	 * without these classes.
	 *
	 * @param string $html The opening markup of the products list.
	 * @return string|null
	 */
	private static function grid_sizes( string $html ): ?string {
		if ( ! preg_match( '/<ul\b[^>]*\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $match ) ) {
			return null;
		}

		$classes = preg_split( '/\s+/', $match[2], -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $classes ) || ! in_array( 'has-ccols', $classes, true ) || ! in_array( 'grid', $classes, true ) || array() !== array_intersect( $classes, self::NOT_A_GRID ) ) {
			return null;
		}

		$columns = array();

		foreach ( $classes as $class ) {
			if ( preg_match( '/^ccols-(?:(xl|lg|md|sm)-)?([1-8])$/', $class, $count ) ) {
				$columns[ '' === $count[1] ? 'base' : $count[1] ] = (int) $count[2];
			}
		}

		$breakpoints = array( 'xl' => self::xl_breakpoint() ) + self::BREAKPOINTS;

		// Smallest first, so a step that repeats the one below it can go.
		$steps = array();
		$last  = $columns['base'] ?? 1;

		foreach ( array_reverse( $breakpoints, true ) as $breakpoint => $width ) {
			if ( ! isset( $columns[ $breakpoint ] ) || $last === $columns[ $breakpoint ] ) {
				continue;
			}

			// Without the xl breakpoint a smaller count above it would underestimate.
			if ( null === $width ) {
				if ( $columns[ $breakpoint ] < $last ) {
					return null;
				}

				continue;
			}

			$steps[ $width ] = $columns[ $breakpoint ];
			$last            = $columns[ $breakpoint ];
		}

		$sizes = array();

		foreach ( array_reverse( $steps, true ) as $width => $count ) {
			$sizes[] = '(min-width: ' . $width . 'px) ' . self::share( $count );
		}

		$sizes[] = self::share( $columns['base'] ?? 1 );

		return implode( ', ', $sizes );
	}

	/**
	 * One column of n across the viewport, rounded up.
	 *
	 * @param int $count Columns.
	 * @return string
	 */
	private static function share( int $count ): string {
		return rtrim( rtrim( number_format( ceil( 10000 / $count ) / 100, 2, '.', '' ), '0' ), '.' ) . 'vw';
	}

	/**
	 * Where Porto's xl columns start: its container width plus the gutter.
	 *
	 * Porto's own formula: a container of 1360px or more keeps xl at 1140px
	 * and moves the extra width to its sl tier.
	 *
	 * @return int|null Null when the theme settings are not there to say.
	 */
	private static function xl_breakpoint(): ?int {
		$settings = $GLOBALS['porto_settings'] ?? null;

		if ( ! is_array( $settings ) || ! is_numeric( $settings['container-width'] ?? null ) || ! is_numeric( $settings['grid-gutter-width'] ?? null ) ) {
			return null;
		}

		$container = (int) $settings['container-width'];

		return ( 1360 > $container ? $container : 1140 ) + (int) $settings['grid-gutter-width'];
	}

	/**
	 * Loads the first row's images first and keeps the priority off hover images.
	 *
	 * @param mixed $attr Attributes about to be printed on the tag.
	 * @return mixed
	 */
	public function first_row_first( mixed $attr ): mixed {
		if ( ! $this->listing || ! is_array( $attr ) || ! in_the_loop() || ! is_main_query() ) {
			return $attr;
		}

		$product = get_the_ID();

		if ( ! is_int( $product ) || 0 >= $product ) {
			return $attr;
		}

		$classes = preg_split( '/\s+/', is_string( $attr['class'] ?? null ) ? $attr['class'] : '', -1, PREG_SPLIT_NO_EMPTY );
		$classes = is_array( $classes ) ? $classes : array();

		// Porto's second image, shown on hover: never what the visitor sees first.
		if ( in_array( 'hover-image', $classes, true ) ) {
			if ( 'high' === ( $attr['fetchpriority'] ?? null ) ) {
				$this->hint_freed = true;
				unset( $attr['fetchpriority'] );
			}

			// Core keeps a low-priority image eager on purpose; anything else can wait.
			if ( 'low' !== ( $attr['fetchpriority'] ?? null ) ) {
				$attr['loading'] = 'lazy';
				$attr            = self::with_auto_sizes( $attr );
			}

			return $attr;
		}

		if ( $this->opens_elsewhere ) {
			return $attr;
		}

		// A further image of a product already counted: a swatch, a badge.
		if ( isset( $this->positions[ $product ] ) ) {
			return $attr;
		}

		$position                    = count( $this->positions );
		$this->positions[ $product ] = $position;

		if ( self::row_length() <= $position ) {
			if ( ! isset( $attr['loading'] ) && 'high' !== ( $attr['fetchpriority'] ?? null ) ) {
				$attr['loading'] = 'lazy';
				$attr            = self::with_auto_sizes( $attr );
			}

			return $attr;
		}

		$classes[]       = 'skip-lazy';
		$attr['class']   = implode( ' ', array_unique( $classes ) );
		$attr['loading'] = 'eager';

		// "auto" in sizes is only valid on a lazy image, and what is left is
		// WooCommerce's full-width default: the grid's own columns say more.
		if ( is_string( $attr['sizes'] ?? null ) ) {
			$attr['sizes'] = $this->grid_sizes ?? (string) preg_replace( '/^auto\s*,\s*/i', '', $attr['sizes'] );
		}

		if ( 0 === $position && $this->hint_freed ) {
			$attr['fetchpriority'] = 'high';
		}

		return $attr;
	}

	/**
	 * Adds "auto" to the sizes of an image this class made lazy, as core does to its own.
	 *
	 * Core decides it before this filter runs, and only for images that are
	 * already lazy by then. One that arrived eager and leaves lazy would pick
	 * its file from WooCommerce's full-width default instead of its real box:
	 * measured on a desktop listing, the 786px file for a 217px slot.
	 *
	 * @param array<mixed> $attr Attributes about to be printed on the tag.
	 * @return array<mixed>
	 */
	private static function with_auto_sizes( array $attr ): array {
		// WordPress 6.7 brought auto sizes; before it, core adds none either.
		if ( ! is_string( $attr['sizes'] ?? null ) || ! function_exists( 'wp_sizes_attribute_includes_valid_auto' ) ) {
			return $attr;
		}

		/**
		 * Whether core adds "auto" to the sizes of lazy images, read as core reads it.
		 *
		 * Documented in wp-includes/media.php.
		 *
		 * @since 6.7.0
		 */
		$auto = apply_filters( 'wp_img_tag_add_auto_sizes', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook.

		if ( $auto && ! wp_sizes_attribute_includes_valid_auto( $attr['sizes'] ) ) {
			$attr['sizes'] = 'auto, ' . $attr['sizes'];
		}

		return $attr;
	}

	/**
	 * Whether Porto prints its category image above this category's products.
	 *
	 * Porto echoes it as a bare <img>, outside wp_get_attachment_image(), so
	 * core never counts it and it would compete with an eager first row.
	 *
	 * @return bool
	 */
	private static function porto_category_image(): bool {
		if ( ! function_exists( 'porto_woocommerce_category_image' ) || ! has_action( 'woocommerce_archive_description', 'porto_woocommerce_category_image' ) || ! is_product_category() ) {
			return false;
		}

		$term = get_queried_object();

		return $term instanceof \WP_Term && '' !== (string) get_metadata( $term->taxonomy, $term->term_id, 'category_image', true );
	}

	/**
	 * How many products a row of the loop holds.
	 *
	 * Porto's shop builder keeps its own count, which its loop reads before
	 * WooCommerce's; a Porto posts grid keeps it in the shortcode alone, so
	 * there the row can come out a product or two long or short.
	 *
	 * @return int
	 */
	private static function row_length(): int {
		$porto   = $GLOBALS['porto_woocommerce_loop'] ?? null;
		$columns = is_array( $porto ) && is_numeric( $porto['columns'] ?? null ) && 0 < (int) $porto['columns'] ? $porto['columns'] : wc_get_loop_prop( 'columns' );

		if ( ! is_numeric( $columns ) || 0 >= (int) $columns ) {
			$columns = wc_get_default_products_per_row();
		}

		return max( 1, min( 6, (int) $columns ) );
	}
}
