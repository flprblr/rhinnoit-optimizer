<?php
/**
 * The product image correction, which is the WooCommerce half's.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\Product_Image;

/**
 * Covers RhinnoIT\Optimizer\Frontend\Product_Image.
 */
#[CoversClass( Product_Image::class )]
final class ProductImageTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var Product_Image
	 */
	private Product_Image $image;

	/**
	 * Starts from a page that is not a product.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$options   = array();
		WP_Stub::$page_type = 'shop';

		$this->image = new Product_Image();
	}

	/**
	 * Opens product 10, whose featured image is attachment 55, and renders inside its loop.
	 *
	 * @return void
	 */
	private function open_product(): void {
		WP_Stub::$page_type      = 'product';
		WP_Stub::$queried_id     = 10;
		WP_Stub::$thumbnails[10] = 55;

		// On `wp` no loop has started yet; the queried object names the product.
		WP_Stub::$the_id = false;
		$this->image->remember_the_product_image();
		WP_Stub::$the_id      = 10;
		WP_Stub::$in_the_loop = true;
	}

	/**
	 * The product's featured image is never deferred on its own page.
	 *
	 * It is the Largest Contentful Paint of every product page, and core still
	 * hands it loading="lazy" once a logo or a badge was rendered before it.
	 * On the shops this was written for that cost 3.6 seconds.
	 *
	 * @return void
	 */
	public function test_the_featured_image_of_a_product_is_eager_on_its_page(): void {
		$this->open_product();

		$attr = $this->image->never_defer_the_product_image(
			array(
				'loading' => 'lazy',
				'class'   => 'whatever-the-theme-calls-it',
			),
			new WP_Post( 55 )
		);

		$this->assertIsArray( $attr );
		$this->assertSame( 'eager', $attr['loading'] );
		$this->assertSame( 'high', $attr['fetchpriority'], 'A priority hint next to loading=lazy is ignored, so both move together.' );
	}

	/**
	 * Core's "auto" leaves sizes with the lazy loading it belongs to.
	 *
	 * wp_get_attachment_image() prefixes it to a lazy image's sizes before
	 * the filter runs, and it is only valid on a lazy image.
	 *
	 * @return void
	 */
	public function test_cores_auto_sizes_leave_with_the_lazy_loading(): void {
		$this->open_product();

		$attr = $this->image->never_defer_the_product_image(
			array(
				'loading' => 'lazy',
				'sizes'   => 'auto, (max-width: 600px) 100vw, 600px',
			),
			new WP_Post( 55 ),
			'woocommerce_single'
		);

		$this->assertIsArray( $attr );
		$this->assertSame( '(max-width: 600px) 100vw, 600px', $attr['sizes'] );
	}

	/**
	 * A thumbnail-size copy, or one outside the loop, neither counts nor uses up the mark.
	 *
	 * A Porto builder can put the sticky add-to-cart bar, which prints the
	 * featured image at thumbnail size, above the gallery; a header can print
	 * the product before the loop starts.
	 *
	 * @return void
	 */
	public function test_thumbnails_and_copies_outside_the_loop_do_not_take_the_mark(): void {
		$this->open_product();
		$attr = array( 'loading' => 'lazy' );

		foreach ( array( 'thumbnail', 'woocommerce_thumbnail', 'woocommerce_gallery_thumbnail' ) as $size ) {
			$this->assertSame( $attr, $this->image->never_defer_the_product_image( $attr, new WP_Post( 55 ), $size ), "A $size copy is not the paint." );
		}

		WP_Stub::$in_the_loop = false;
		$this->assertSame( $attr, $this->image->never_defer_the_product_image( $attr, new WP_Post( 55 ), 'woocommerce_single' ), 'Before the loop.' );

		WP_Stub::$in_the_loop = true;
		$gallery              = $this->image->never_defer_the_product_image( $attr, new WP_Post( 55 ), 'woocommerce_single' );

		$this->assertIsArray( $gallery );
		$this->assertSame( 'high', $gallery['fetchpriority'], 'The gallery image still gets the mark.' );
	}

	/**
	 * Any other image on the product page keeps whatever it had.
	 *
	 * The gallery, the related products and the badges are not the paint.
	 *
	 * @return void
	 */
	public function test_another_image_on_a_product_page_is_left_alone(): void {
		$this->open_product();

		$attr = array( 'loading' => 'lazy' );

		$this->assertSame( $attr, $this->image->never_defer_the_product_image( $attr, new WP_Post( 987 ) ) );
	}

	/**
	 * Related and upsell loops, and a second rendering, keep their lazy loading.
	 *
	 * WooCommerce and Porto move the global post to each related product, so
	 * the loop being rendered, not the page, says whose image it is. The same
	 * attachment printed again in the product's own loop - Porto's sticky
	 * add-to-cart bar - is not the paint either.
	 *
	 * @return void
	 */
	public function test_only_the_first_rendering_in_the_products_own_loop_is_marked(): void {
		$this->open_product();
		$attr = array( 'loading' => 'lazy' );

		WP_Stub::$the_id = 11;
		$this->assertSame( $attr, $this->image->never_defer_the_product_image( $attr, new WP_Post( 55 ) ), 'Another product showing the same attachment.' );

		WP_Stub::$the_id = 10;
		$first           = $this->image->never_defer_the_product_image( $attr, new WP_Post( 55 ) );
		$this->assertIsArray( $first );
		$this->assertSame( 'high', $first['fetchpriority'] );
		$this->assertSame( $attr, $this->image->never_defer_the_product_image( $attr, new WP_Post( 55 ) ), 'The sticky bar prints it again.' );
	}

	/**
	 * A grid on a page that is not a product marks nothing.
	 *
	 * Porto's posts grid sets the queried object to each item it renders, so
	 * inside the grid is_product() is true and the global post's featured
	 * image is the item's. Asked there, this marked all 24 thumbnails of a
	 * shop's home page eager and high priority.
	 *
	 * @return void
	 */
	public function test_a_porto_grid_on_the_home_page_marks_nothing(): void {
		WP_Stub::$page_type = 'page';
		WP_Stub::$queried_id = 2;
		$this->image->remember_the_product_image();

		// Inside the grid, as Porto leaves the query.
		WP_Stub::$page_type      = 'product';
		WP_Stub::$queried_id     = 30;
		WP_Stub::$featured_id    = 55;
		WP_Stub::$thumbnails[30] = 55;
		WP_Stub::$the_id         = 30;

		$attr = array( 'loading' => 'lazy' );

		$this->assertSame( $attr, $this->image->never_defer_the_product_image( $attr, new WP_Post( 55 ) ) );
	}

	/**
	 * A page that is not a product keeps its own featured image as it was.
	 *
	 * @return void
	 */
	public function test_a_page_that_is_not_a_product_marks_its_own_image_neither(): void {
		WP_Stub::$page_type     = 'page';
		WP_Stub::$queried_id    = 2;
		WP_Stub::$thumbnails[2] = 66;
		$this->image->remember_the_product_image();
		WP_Stub::$the_id = 2;

		$attr = array( 'loading' => 'lazy' );

		$this->assertSame( $attr, $this->image->never_defer_the_product_image( $attr, new WP_Post( 66 ) ) );
	}

	/**
	 * The same image elsewhere on the shop is left alone.
	 *
	 * On a category page that attachment is one tile of a grid and deferring
	 * it is right.
	 *
	 * @return void
	 */
	public function test_the_same_image_is_left_alone_outside_the_product_page(): void {
		WP_Stub::$page_type      = 'shop';
		WP_Stub::$thumbnails[10] = 55;
		WP_Stub::$the_id         = 10;
		$this->image->remember_the_product_image();

		$attr = array( 'loading' => 'lazy' );

		$this->assertSame( $attr, $this->image->never_defer_the_product_image( $attr, new WP_Post( 55 ) ) );
	}

	/**
	 * Whatever the filter is handed, it does not fatal.
	 *
	 * This runs on every image of every product page, so the guards are the
	 * point: another plugin can pass anything through this filter.
	 *
	 * @return void
	 */
	public function test_the_product_image_filter_survives_malformed_arguments(): void {
		$this->open_product();

		$this->assertSame( 'not an array', $this->image->never_defer_the_product_image( 'not an array', new WP_Post( 55 ) ) );
		$this->assertSame( array( 'a' => 1 ), $this->image->never_defer_the_product_image( array( 'a' => 1 ), null ) );
		$this->assertSame( array( 'a' => 1 ), $this->image->never_defer_the_product_image( array( 'a' => 1 ), 'not a post' ) );

		WP_Stub::$thumbnails[12] = 'nonsense';
		WP_Stub::$queried_id     = 12;
		WP_Stub::$the_id         = 12;
		$image                   = new Product_Image();
		$image->remember_the_product_image();

		$this->assertSame( array( 'a' => 1 ), $image->never_defer_the_product_image( array( 'a' => 1 ), new WP_Post( 55 ) ) );
	}

	/**
	 * The correction is only hooked while its own option is on.
	 *
	 * @return void
	 */
	public function test_the_product_image_filter_is_hooked_only_when_switched_on(): void {
		WP_Stub::$options = array( 'ropt_product_image_eager' => 'no' );
		WP_Stub::$hooks   = array();

		( new Product_Image() )->register();

		$this->assertNotContains( 'wp_get_attachment_image_attributes', WP_Stub::$hooks );

		WP_Stub::$options = array();
		WP_Stub::$hooks   = array();

		( new Product_Image() )->register();

		$this->assertContains( 'wp_get_attachment_image_attributes', WP_Stub::$hooks, 'It ships on.' );
		$this->assertContains( 'wp', WP_Stub::$hooks, 'The product is read before any template runs.' );

		$accepted = array();

		foreach ( WP_Stub::$registered as list( $hook, , $method, $args ) ) {
			$accepted[ $hook . ':' . $method ] = $args;
		}

		$this->assertSame( 3, $accepted['wp_get_attachment_image_attributes:never_defer_the_product_image'] ?? null, 'Without the size, a thumbnail copy would take the mark.' );
	}
}
