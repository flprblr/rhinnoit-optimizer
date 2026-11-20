<?php
/**
 * Loading the first row of a product listing first.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\First_Row;

/**
 * Covers RhinnoIT\Optimizer\Frontend\First_Row.
 */
#[CoversClass( First_Row::class )]
final class FirstRowTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var First_Row
	 */
	private First_Row $row;

	/**
	 * Starts on a product category, inside the main loop.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$page_type   = 'taxonomy';
		WP_Stub::$in_the_loop = true;

		$this->row = new First_Row();
		$this->row->detect_listing();
	}

	/**
	 * Renders the image of product $id as core hands it to the filter.
	 *
	 * @param int                  $id   Product whose loop turn it is.
	 * @param array<string, mixed> $attr Attributes core prepared.
	 * @return mixed
	 */
	private function render( int $id, array $attr ): mixed {
		WP_Stub::$the_id = $id;

		return $this->row->first_row_first( $attr );
	}

	/**
	 * A lazy product image, as core prepares every one after the first.
	 *
	 * @return array<string, string>
	 */
	private static function lazy(): array {
		return array(
			'class'   => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail',
			'loading' => 'lazy',
			'sizes'   => 'auto, (max-width: 786px) 100vw, 786px',
		);
	}

	/**
	 * Porto's markup as measured on the shop: the hover image built first, with core's hint.
	 *
	 * The first product's hover image, invisible until the pointer rests on
	 * it, carried the page's only fetchpriority=high while the image the
	 * visitor sees waited, lazy: an 11-second paint on mobile.
	 *
	 * @return void
	 */
	public function test_the_first_row_loads_first_and_the_hover_image_gives_up_the_priority(): void {
		$hover = $this->render(
			101,
			array(
				'class'         => 'hover-image',
				'fetchpriority' => 'high',
				'sizes'         => '(max-width: 786px) 100vw, 786px',
			)
		);
		$first = $this->render( 101, self::lazy() );

		$this->assertIsArray( $hover );
		$this->assertArrayNotHasKey( 'fetchpriority', $hover );
		$this->assertIsArray( $first );
		$this->assertSame( 'eager', $first['loading'] );
		$this->assertSame( 'high', $first['fetchpriority'] );
		$this->assertSame( 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail skip-lazy', $first['class'], 'skip-lazy keeps Smush\'s lazy load off it.' );
		$this->assertSame( '(max-width: 786px) 100vw, 786px', $first['sizes'], '"auto" is only valid on a lazy image.' );

		foreach ( array( 102, 103, 104 ) as $id ) {
			$this->render( $id, array( 'class' => 'hover-image' ) );
			$image = $this->render( $id, self::lazy() );

			$this->assertIsArray( $image );
			$this->assertSame( 'eager', $image['loading'], "Product $id is in the first row." );
			$this->assertArrayNotHasKey( 'fetchpriority', $image, 'One high priority per page.' );
		}

		$this->assertSame( self::lazy(), $this->render( 105, self::lazy() ), 'The second row stays lazy.' );
	}

	/**
	 * The hover image leaves lazy, with the exact attributes core gives it.
	 *
	 * Counted first, core treats it as in the viewport: no loading attribute
	 * and the high priority. Porto hides it on phones, so an eager copy is a
	 * download for nothing.
	 *
	 * @return void
	 */
	public function test_hover_images_leave_lazy(): void {
		$hover = $this->render(
			101,
			array(
				'class'         => 'hover-image',
				'fetchpriority' => 'high',
			)
		);
		$later = $this->render( 102, array( 'class' => 'hover-image' ) );
		$low   = $this->render(
			103,
			array(
				'class'         => 'hover-image',
				'fetchpriority' => 'low',
			)
		);

		$this->assertSame( 'lazy', $hover['loading'] ?? null );
		$this->assertSame( 'lazy', $later['loading'] ?? null );
		$this->assertArrayNotHasKey( 'loading', is_array( $low ) ? $low : array(), 'Core keeps a low-priority image eager on purpose.' );
	}

	/**
	 * An image this class makes lazy gets the "auto" core gives its own lazy images.
	 *
	 * Core adds it before the filter runs and only to images already lazy,
	 * so the first product's hover image - eager with the hint when it
	 * arrives - left lazy on WooCommerce's full-width default and fetched the
	 * 786px file for a 217px slot on desktop. Fed as core hands them over.
	 *
	 * @return void
	 */
	public function test_images_it_makes_lazy_size_themselves_as_core_does(): void {
		$hover = $this->render(
			101,
			array(
				'class'         => 'hover-image',
				'fetchpriority' => 'high',
				'sizes'         => '(max-width: 786px) 100vw, 786px',
			)
		);

		$this->assertSame( 'lazy', $hover['loading'] ?? null );
		$this->assertSame( 'auto, (max-width: 786px) 100vw, 786px', $hover['sizes'] ?? null );

		$lazy = $this->render(
			102,
			array(
				'class'   => 'hover-image',
				'loading' => 'lazy',
				'sizes'   => 'AUTO, (max-width: 786px) 100vw, 786px',
			)
		);

		$this->assertSame( 'AUTO, (max-width: 786px) 100vw, 786px', $lazy['sizes'] ?? null, 'Never twice.' );

		foreach ( array( 1, 2, 3, 4 ) as $id ) {
			$this->render( $id, self::lazy() );
		}

		$past = $this->render(
			5,
			array(
				'class' => 'attachment-woocommerce_thumbnail',
				'sizes' => '(max-width: 786px) 100vw, 786px',
			)
		);

		$this->assertSame( 'lazy', $past['loading'] ?? null );
		$this->assertSame( 'auto, (max-width: 786px) 100vw, 786px', $past['sizes'] ?? null, 'Past the row too.' );

		WP_Stub::$filters['wp_img_tag_add_auto_sizes'] = false;

		$this->assertSame( '(max-width: 786px) 100vw, 786px', $this->render( 110, array( 'class' => 'hover-image', 'sizes' => '(max-width: 786px) 100vw, 786px' ) )['sizes'] ?? null, 'A site that turned auto sizes off.' );
	}

	/**
	 * The products list as Porto prints it on both shops' categories, shop and search.
	 */
	const string PORTO_GRID = '<ul class="products products-container grid has-ccols has-ccols-spacing ccols-xl-4 ccols-lg-3 ccols-md-3 ccols-sm-3 ccols-2 pwidth-lg-4 pwidth-md-3 pwidth-xs-2 pwidth-ls-1"
		data-product_layout="product-outimage_aq_onimage">';

	/**
	 * Porto's container and gutter as both shops store them.
	 *
	 * @return void
	 */
	private static function porto_theme(): void {
		$GLOBALS['porto_settings'] = array(
			'container-width'   => '1200',
			'grid-gutter-width' => '20',
		);
	}

	/**
	 * The eager row takes its sizes from the grid's columns, not WooCommerce's full width.
	 *
	 * Measured on hoka: phones fetched the 768px file for a 180px tile, about
	 * three times the bytes, LCP image included. The answer is at least the
	 * tile at every width - 1.13 to 1.57 times it in Chrome, 575 to 2560px -
	 * so nothing gets blurrier.
	 *
	 * @return void
	 */
	public function test_the_eager_row_is_sized_from_the_grid(): void {
		self::porto_theme();

		$this->assertSame( self::PORTO_GRID, $this->row->read_the_grid( self::PORTO_GRID ), 'Only read, never changed.' );

		$hover = $this->render(
			101,
			array(
				'class'         => 'hover-image',
				'fetchpriority' => 'high',
				'sizes'         => '(max-width: 786px) 100vw, 786px',
			)
		);
		$first = $this->render( 101, self::lazy() );

		$this->assertSame( '(min-width: 1220px) 25vw, (min-width: 576px) 33.34vw, 50vw', $first['sizes'] ?? null );
		$this->assertSame( 'auto, (max-width: 786px) 100vw, 786px', $hover['sizes'] ?? null, 'A lazy image measures its own box.' );

		foreach ( array( 102, 103, 104 ) as $id ) {
			$this->render( $id, self::lazy() );
		}

		$this->assertSame( self::lazy(), $this->render( 105, self::lazy() ), 'The second row keeps core\'s auto sizes.' );
	}

	/**
	 * Each breakpoint follows the theme, and a step that repeats the one below it goes.
	 *
	 * @return void
	 */
	public function test_the_sizes_follow_the_theme(): void {
		$sizes = function ( string $grid ): mixed {
			$row = new First_Row();
			$row->detect_listing();
			$row->read_the_grid( $grid );
			WP_Stub::$the_id = 101;

			return $row->first_row_first( self::lazy() )['sizes'] ?? null;
		};

		$this->assertSame( '(min-width: 576px) 33.34vw, 50vw', $sizes( self::PORTO_GRID ), 'Without the theme settings the lg count covers xl: larger, never smaller.' );

		$GLOBALS['porto_settings'] = array(
			'container-width'   => 1440,
			'grid-gutter-width' => 30,
		);
		$this->assertSame( '(min-width: 1170px) 20vw, (min-width: 768px) 25vw, 50vw', $sizes( "<ul class='products grid has-ccols ccols-xl-5 ccols-lg-4 ccols-md-4 ccols-sm-2 ccols-2'>" ), 'Porto keeps xl at 1140px for a container of 1360px or more.' );

		$this->assertSame( '(min-width: 992px) 16.67vw, (min-width: 768px) 25vw, (min-width: 576px) 33.34vw, 100vw', $sizes( '<ul class="products grid has-ccols ccols-xl-6 ccols-lg-6 ccols-md-4 ccols-sm-3 ccols-sl-8">' ), 'No phone count: one column. The sl tier only has more.' );

		unset( $GLOBALS['porto_settings'] );
		$this->assertSame( '(max-width: 786px) 100vw, 786px', $sizes( '<ul class="products grid has-ccols ccols-xl-2 ccols-lg-3 ccols-2">' ), 'Fewer columns on xl than lg with no xl breakpoint to place them: today\'s value stays.' );
	}

	/**
	 * Anything that is not one column of Porto's grid keeps the value it has today.
	 *
	 * List view shows a fixed 250px image from 576px, which the grid's
	 * columns would undersize.
	 *
	 * @return void
	 */
	public function test_anything_but_portos_grid_keeps_todays_sizes(): void {
		self::porto_theme();

		foreach (
			array(
				str_replace( ' grid ', ' list ', self::PORTO_GRID ),
				str_replace( ' grid ', ' grid grid-creative ', self::PORTO_GRID ),
				str_replace( 'has-ccols ', 'has-ccols owl-carousel ', self::PORTO_GRID ),
				'<ul class="products columns-4">',
				'<div class="products">',
			) as $index => $grid
		) {
			$row = new First_Row();
			$row->detect_listing();
			$row->read_the_grid( $grid );
			WP_Stub::$the_id = 101 + $index;

			$this->assertSame( '(max-width: 786px) 100vw, 786px', $row->first_row_first( self::lazy() )['sizes'] ?? null, $grid );
		}

		$this->assertNull( $this->row->read_the_grid( null ), 'Whatever another plugin passes, nothing fatals.' );
	}

	/**
	 * A list that closed lends its columns to no later one.
	 *
	 * A shortcode in the category description renders its own list before the
	 * main loop; a main loop printed without WooCommerce's opening filter
	 * would otherwise take that list's columns.
	 *
	 * @return void
	 */
	public function test_a_closed_list_lends_its_columns_to_no_other(): void {
		self::porto_theme();

		$this->row->read_the_grid( self::PORTO_GRID );
		$this->assertSame( '</ul>', $this->row->forget_the_grid( '</ul>' ) );

		$this->assertSame( '(max-width: 786px) 100vw, 786px', $this->render( 101, self::lazy() )['sizes'] ?? null );
	}

	/**
	 * Outside a listing the list's columns are not even read.
	 *
	 * @return void
	 */
	public function test_outside_a_listing_the_grid_is_ignored(): void {
		self::porto_theme();
		WP_Stub::$page_type = 'product';

		$row = new First_Row();
		$row->detect_listing();

		$this->assertSame( self::PORTO_GRID, $row->read_the_grid( self::PORTO_GRID ) );
	}

	/**
	 * Past the first row, an image with no loading attribute leaves lazy.
	 *
	 * Elementor's image optimisation decides loading on the final HTML and
	 * skips any image that already has the attribute, so an eager row it did
	 * not count would let the next images through.
	 *
	 * @return void
	 */
	public function test_past_the_row_the_loading_is_written_out(): void {
		foreach ( array( 1, 2, 3, 4 ) as $id ) {
			$this->render( $id, self::lazy() );
		}

		$bare = array( 'class' => 'attachment-woocommerce_thumbnail' );

		$this->assertSame( 'lazy', $this->render( 5, $bare )['loading'] ?? null );
		$this->assertArrayNotHasKey(
			'loading',
			(array) $this->render(
				6,
				array(
					'class'         => 'attachment-woocommerce_thumbnail',
					'fetchpriority' => 'high',
				)
			),
			'An image core chose for the high priority keeps it.'
		);
	}

	/**
	 * A listing that opens with subcategory tiles or Porto's category image keeps its row lazy.
	 *
	 * Neither goes through wp_get_attachment_image(), so core never sees them;
	 * an eager first row would compete with what is really on top.
	 *
	 * @return void
	 */
	public function test_a_listing_that_opens_with_something_else_keeps_its_row_lazy(): void {
		WP_Stub::$loop_display = 'both';
		$tiles                 = new First_Row();
		$tiles->detect_listing();
		WP_Stub::$the_id = 101;

		$this->assertSame( self::lazy(), $tiles->first_row_first( self::lazy() ) );
		$this->assertSame( 'lazy', $tiles->first_row_first( array( 'class' => 'hover-image' ) )['loading'] ?? null, 'Hover images are still lazy.' );

		WP_Stub::$loop_display     = 'products';
		WP_Stub::$queried_object   = new WP_Term( 7 );
		WP_Stub::$present_actions  = array( 'woocommerce_archive_description:porto_woocommerce_category_image' );
		WP_Stub::$metadata['product_cat'][7]['category_image'] = 'https://shop.test/banner.jpg';

		$banner = new First_Row();
		$banner->detect_listing();

		$this->assertSame( self::lazy(), $banner->first_row_first( self::lazy() ), 'Porto prints the category image above the grid.' );

		WP_Stub::$metadata = array();
		$plain             = new First_Row();
		$plain->detect_listing();

		$this->assertSame( 'eager', $plain->first_row_first( self::lazy() )['loading'] ?? null, 'Without an image set, the row loads first.' );
	}

	/**
	 * Porto's shop builder count wins over WooCommerce's, as in Porto's own loop.
	 *
	 * @return void
	 */
	public function test_portos_loop_columns_come_first(): void {
		WP_Stub::$loop_columns                     = 3;
		$GLOBALS['porto_woocommerce_loop']         = array( 'columns' => '5' );

		foreach ( array( 1, 2, 3, 4, 5 ) as $id ) {
			$this->assertSame( 'eager', $this->render( $id, self::lazy() )['loading'] ?? null, "Product $id is in Porto's row of 5." );
		}

		$this->assertSame( self::lazy(), $this->render( 6, self::lazy() ) );
	}

	/**
	 * A row is as long as the loop's columns say.
	 *
	 * @return void
	 */
	public function test_the_row_follows_the_loops_columns(): void {
		WP_Stub::$loop_columns = 2;

		$this->assertSame( 'eager', $this->render( 1, self::lazy() )['loading'] ?? null );
		$this->assertSame( 'eager', $this->render( 2, self::lazy() )['loading'] ?? null );
		$this->assertSame( self::lazy(), $this->render( 3, self::lazy() ) );

		foreach ( array( null, 0, 'three' ) as $columns ) {
			WP_Stub::$loop_columns = $columns;
			$row                   = new First_Row();
			$row->detect_listing();

			foreach ( array( 1, 2, 3, 4 ) as $id ) {
				WP_Stub::$the_id = $id;
				$this->assertSame( 'eager', $row->first_row_first( self::lazy() )['loading'] ?? null, 'WooCommerce\'s default row of 4.' );
			}

			WP_Stub::$the_id = 5;
			$this->assertSame( self::lazy(), $row->first_row_first( self::lazy() ) );
		}

		WP_Stub::$loop_columns = 8;
		$wide                  = new First_Row();
		$wide->detect_listing();

		foreach ( array( 1, 2, 3, 4, 5, 6 ) as $id ) {
			WP_Stub::$the_id = $id;
			$this->assertSame( 'eager', $wide->first_row_first( self::lazy() )['loading'] ?? null );
		}

		WP_Stub::$the_id = 7;
		$this->assertSame( self::lazy(), $wide->first_row_first( self::lazy() ), 'Never more than six.' );
	}

	/**
	 * A hint core gave to an image outside the loop stays there.
	 *
	 * A category banner above the grid is then the paint, and a second high
	 * priority on the first product would compete with it.
	 *
	 * @return void
	 */
	public function test_a_hint_core_gave_outside_the_loop_is_not_duplicated(): void {
		$first = $this->render( 101, self::lazy() );

		$this->assertIsArray( $first );
		$this->assertSame( 'eager', $first['loading'] );
		$this->assertArrayNotHasKey( 'fetchpriority', $first );
	}

	/**
	 * Without Porto's hover image, core's hint on the first image is kept.
	 *
	 * @return void
	 */
	public function test_without_a_hover_image_the_first_image_keeps_cores_hint(): void {
		$first = $this->render(
			101,
			array(
				'class'         => 'attachment-woocommerce_thumbnail',
				'fetchpriority' => 'high',
			)
		);

		$this->assertIsArray( $first );
		$this->assertSame( 'high', $first['fetchpriority'] );
		$this->assertSame( 'eager', $first['loading'] );
	}

	/**
	 * A further image of a product already counted keeps what it had.
	 *
	 * @return void
	 */
	public function test_a_further_image_of_the_same_product_is_left_alone(): void {
		$this->render( 101, self::lazy() );

		$swatch = array(
			'class'   => 'swatch',
			'loading' => 'lazy',
		);

		$this->assertSame( $swatch, $this->render( 101, $swatch ) );
		$this->assertSame( 'eager', $this->render( 102, self::lazy() )['loading'] ?? null, 'The swatch took no place in the row.' );
	}

	/**
	 * Only the shop, product categories and tags, and a product search.
	 *
	 * @return void
	 */
	public function test_only_product_listings_are_touched(): void {
		foreach ( array( 'product', 'page', 'cart' ) as $type ) {
			WP_Stub::$page_type = $type;
			$row                = new First_Row();
			$row->detect_listing();
			WP_Stub::$the_id = 101;

			$this->assertSame( self::lazy(), $row->first_row_first( self::lazy() ), "Not on a $type page." );
		}

		WP_Stub::$page_type = 'page';
		WP_Stub::$is_search = true;

		foreach ( array( '', 'post' ) as $post_type ) {
			WP_Stub::$query_vars['post_type'] = $post_type;
			$blog                             = new First_Row();
			$blog->detect_listing();

			$this->assertSame( self::lazy(), $blog->first_row_first( self::lazy() ), 'A blog or site-wide search is not a product listing.' );
		}

		WP_Stub::$query_vars['post_type'] = 'product';

		$search = new First_Row();
		$search->detect_listing();

		$this->assertSame( 'eager', $search->first_row_first( self::lazy() )['loading'] ?? null, 'A product search is a listing.' );

		WP_Stub::$page_type = 'shop';
		$shop               = new First_Row();
		$shop->detect_listing();

		$this->assertSame( 'eager', $shop->first_row_first( self::lazy() )['loading'] ?? null );
	}

	/**
	 * A products widget or a secondary query on the listing is not the main loop.
	 *
	 * @return void
	 */
	public function test_images_outside_the_main_loop_are_left_alone(): void {
		WP_Stub::$in_the_loop = false;
		$this->assertSame( self::lazy(), $this->render( 101, self::lazy() ) );

		WP_Stub::$in_the_loop = true;
		WP_Stub::$main_query  = false;
		$this->assertSame( self::lazy(), $this->render( 101, self::lazy() ) );

		WP_Stub::$main_query = true;
		$this->assertSame( 'eager', $this->render( 101, self::lazy() )['loading'] ?? null, 'Neither took a place in the row.' );
	}

	/**
	 * Whatever the filter is handed, it does not fatal.
	 *
	 * @return void
	 */
	public function test_the_filter_survives_malformed_arguments(): void {
		$this->assertSame( 'not an array', $this->row->first_row_first( 'not an array' ) );

		WP_Stub::$the_id = false;
		$this->assertSame( self::lazy(), $this->row->first_row_first( self::lazy() ) );

		WP_Stub::$the_id = 101;
		$odd             = array(
			'class' => array( 'not', 'a', 'string' ),
			'sizes' => 42,
		);
		$image           = $this->row->first_row_first( $odd );

		$this->assertIsArray( $image );
		$this->assertSame( 'skip-lazy', $image['class'] );
		$this->assertSame( 42, $image['sizes'] );
	}

	/**
	 * Hooked only while its switch is on and Porto's own lazy load is off.
	 *
	 * @return void
	 */
	public function test_it_is_hooked_only_when_on_and_portos_lazy_load_is_off(): void {
		( new First_Row() )->register();
		$this->assertContains( 'wp_get_attachment_image_attributes:first_row_first', WP_Stub::$callbacks, 'It ships on.' );
		$this->assertSame( 20, WP_Stub::$priorities['wp_get_attachment_image_attributes:first_row_first'], 'After core\'s loading attributes.' );
		$this->assertContains( 'wp:detect_listing', WP_Stub::$callbacks, 'The listing is decided before any template runs.' );
		$this->assertContains( 'woocommerce_product_loop_start:read_the_grid', WP_Stub::$callbacks, 'Without it the row keeps WooCommerce\'s full-width sizes.' );
		$this->assertContains( 'woocommerce_product_loop_end:forget_the_grid', WP_Stub::$callbacks );

		WP_Stub::reset();
		WP_Stub::$options['ropt_loop_first_row'] = 'no';
		( new First_Row() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks );

		WP_Stub::reset();
		WP_Stub::$options['porto_settings_optimize'] = array( 'lazyload' => '1' );
		( new First_Row() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks, 'Porto\'s loader skips only its own class.' );
	}
}
