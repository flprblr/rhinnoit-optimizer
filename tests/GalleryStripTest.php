<?php
/**
 * Lazy-loading the thumbnail strip under a product's gallery.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\Gallery_Strip;

/**
 * Covers RhinnoIT\Optimizer\Frontend\Gallery_Strip.
 */
#[CoversClass( Gallery_Strip::class )]
final class GalleryStripTest extends TestCase {

	/**
	 * One strip item exactly as Porto 7.9's product-thumbnails.php prints it on the shop.
	 */
	private const PORTO_THUMB = '<div class="img-thumbnail"><img class="img-responsive" alt="Hoka Clifton Pro - Imagen 2" src="https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1176510AVRV_VIEW2-786x786.jpg" width="786" height="786" /></div>';

	/**
	 * Service under test.
	 *
	 * @var Gallery_Strip
	 */
	private Gallery_Strip $strip;

	/**
	 * Starts on a clean slate.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();

		$this->strip = new Gallery_Strip();
	}

	/**
	 * Inside the strip, Porto's bare thumbnail becomes lazy.
	 *
	 * With it eager, the preload scanner fetched eight 786-pixel thumbs next
	 * to the main image, and the main image painted the cached thumb instead
	 * of its own prioritised request.
	 *
	 * @return void
	 */
	public function test_portos_bare_thumbnail_is_lazy_inside_the_strip(): void {
		$this->strip->open_strip();

		$this->assertSame(
			'<div class="img-thumbnail"><img class="img-responsive" alt="Hoka Clifton Pro - Imagen 2" src="https://cl.hoka.com/wp-content/uploads/2026/08/HOKA_HK1176510AVRV_VIEW2-786x786.jpg" width="786" height="786" loading="lazy" decoding="async" /></div>',
			$this->strip->lazy_strip( self::PORTO_THUMB )
		);
	}

	/**
	 * The same filter runs for the main slider, before the strip opens: untouched.
	 *
	 * @return void
	 */
	public function test_the_main_slider_before_the_strip_is_untouched(): void {
		$this->assertSame( self::PORTO_THUMB, $this->strip->lazy_strip( self::PORTO_THUMB ) );

		$this->strip->open_strip();
		$this->strip->close_strip();

		$this->assertSame( self::PORTO_THUMB, $this->strip->lazy_strip( self::PORTO_THUMB ), 'After the strip closes.' );

		$this->strip->close_strip();
		$this->strip->open_strip();

		$this->assertStringContainsString( 'loading="lazy"', (string) $this->strip->lazy_strip( self::PORTO_THUMB ), 'An extra close never leaves the count below zero.' );
	}

	/**
	 * An image that already decided its loading, or holds the high priority, keeps it.
	 *
	 * WooCommerce's own strip goes through wp_get_attachment_image() and
	 * arrives with core's loading attribute.
	 *
	 * @return void
	 */
	public function test_an_image_that_already_decided_keeps_its_choice(): void {
		$this->strip->open_strip();

		foreach (
			array(
				'<img src="a.jpg" loading="lazy" decoding="async" />',
				'<img src="a.jpg" loading="eager">',
				'<img src="a.jpg" fetchpriority="high">',
				"<img src='a.jpg' fetchpriority='high'>",
				'<div>No image here</div>',
			) as $html
		) {
			$this->assertSame( $html, $this->strip->lazy_strip( $html ) );
		}

		$this->assertSame( '<img src="a.jpg" decoding="sync" loading="lazy">', $this->strip->lazy_strip( '<img src="a.jpg" decoding="sync">' ), 'A decoding attribute is kept.' );
		$this->assertSame( '<img data-loading="x" src="a.jpg" loading="lazy" decoding="async">', $this->strip->lazy_strip( '<img data-loading="x" src="a.jpg">' ), 'data-loading is another attribute.' );
	}

	/**
	 * Every image of an item, and a ">" inside a quoted attribute, are handled.
	 *
	 * @return void
	 */
	public function test_every_image_of_an_item_and_quoted_brackets_are_handled(): void {
		$this->strip->open_strip();

		$html = '<a data-caption="a > b"><img alt="x > y" src="poster.jpg"><IMG SRC="b.jpg"/></a>';

		$this->assertSame(
			'<a data-caption="a > b"><img alt="x > y" src="poster.jpg" loading="lazy" decoding="async"><IMG SRC="b.jpg" loading="lazy" decoding="async" /></a>',
			$this->strip->lazy_strip( $html )
		);
	}

	/**
	 * Whatever the filter is handed, it does not fatal.
	 *
	 * @return void
	 */
	public function test_the_filter_survives_malformed_arguments(): void {
		$this->strip->open_strip();

		$this->assertNull( $this->strip->lazy_strip( null ) );
		$this->assertSame( array( 'x' ), $this->strip->lazy_strip( array( 'x' ) ) );
		$this->assertSame( '<img', $this->strip->lazy_strip( '<img' ), 'An unclosed tag is left alone.' );
	}

	/**
	 * Hooked around the strip's action, on both of Porto's item filters.
	 *
	 * @return void
	 */
	public function test_it_is_hooked_around_the_strip_only_when_on(): void {
		( new Gallery_Strip() )->register();

		$this->assertSame( 0, WP_Stub::$priorities['woocommerce_product_thumbnails:open_strip'] );
		$this->assertSame( PHP_INT_MAX, WP_Stub::$priorities['woocommerce_product_thumbnails:close_strip'] );
		$this->assertContains( 'woocommerce_single_product_image_thumbnail_html:lazy_strip', WP_Stub::$callbacks );
		$this->assertContains( 'woocommerce_single_product_video_thumbnail_html:lazy_strip', WP_Stub::$callbacks, 'A video item prints a poster image.' );

		WP_Stub::reset();
		WP_Stub::$options['ropt_gallery_strip_lazy'] = 'no';
		( new Gallery_Strip() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks );

		WP_Stub::reset();
		WP_Stub::$options['porto_settings_optimize'] = array( 'lazyload' => '1' );
		( new Gallery_Strip() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks, 'Porto\'s loader takes the same filter over.' );
	}
}
