<?php
/**
 * Each screen size's banner: preloaded for the screens that show it, never fetched by the others.
 *
 * The shop cases are fed the_content exactly as the three shops printed it on
 * 2026-09-24, cut after the two banner sections (tests/fixtures/viewport-hero/),
 * and every case runs through WordPress's real HTML API (tests/stubs/html-api/),
 * so what passes here is what the parser does on the shop.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Viewport_Hero;

#[CoversClass( Viewport_Hero::class )]
final class ViewportHeroTest extends TestCase {

	/**
	 * The queried page.
	 */
	private const PAGE = 7;

	/**
	 * A page in its main loop, inside the_content and no other filter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$page_type           = 'page';
		WP_Stub::$queried_id          = self::PAGE;
		WP_Stub::$the_id              = self::PAGE;
		WP_Stub::$in_the_loop         = true;
		WP_Stub::$main_query          = true;
		$GLOBALS['wp_current_filter'] = array( 'the_content' );
	}

	/**
	 * The service, registered, after the query ran.
	 *
	 * @return Viewport_Hero
	 */
	private function service(): Viewport_Hero {
		$service = new Viewport_Hero();
		$service->register();
		$service->remember_page();

		return $service;
	}

	/**
	 * What the filter returns for this content on a fresh request.
	 *
	 * @param string $html The content.
	 * @return mixed
	 */
	private function filter( string $html ): mixed {
		return $this->service()->preload_heroes( $html );
	}

	/**
	 * One shop's content.
	 *
	 * @param string $name Fixture name.
	 * @return string
	 */
	private static function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/fixtures/viewport-hero/' . $name . '.html' );
	}

	/**
	 * The attributes of every tag of one name, in document order.
	 *
	 * @param string $html Markup.
	 * @param string $tag  Tag name.
	 * @return array<int, array<string, string|true>>
	 */
	private static function tags( string $html, string $tag ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$found     = array();

		while ( $processor->next_tag( array( 'tag_name' => $tag ) ) ) {
			$attributes = array();

			foreach ( $processor->get_attribute_names_with_prefix( '' ) ?? array() as $name ) {
				$attributes[ $name ] = $processor->get_attribute( $name );
			}

			$found[] = $attributes;
		}

		return $found;
	}

	/**
	 * The image whose src contains this.
	 *
	 * @param string $html   Markup.
	 * @param string $needle Part of its src.
	 * @return array<string, string|true>
	 */
	private static function image( string $html, string $needle ): array {
		foreach ( self::tags( $html, 'IMG' ) as $image ) {
			if ( is_string( $image['src'] ?? null ) && str_contains( $image['src'], $needle ) ) {
				return $image;
			}
		}

		self::fail( 'No image ' . $needle );
	}

	/**
	 * Markup with every image tag reduced to its name, and the preloads dropped.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private static function skeleton( string $html ): string {
		$html = (string) preg_replace( '/^(?:<link rel="preload" as="image"[^>]*>\n)+/', '', $html );

		return (string) preg_replace( '/<img\b[^>]*>/', '<img>', $html );
	}

	/**
	 * A content opening with two containers, a banner in each.
	 *
	 * @param string               $first  The first container's attributes.
	 * @param string               $second The second container's attributes.
	 * @param array<string, mixed> $images Overrides: first/second image attributes (null drops one), and markup
	 *                                     before the pair, between the containers, and inside the first one
	 *                                     before and after its banner.
	 * @return string
	 */
	private static function pair( string $first, string $second, array $images = array() ): string {
		$defaults = array(
			'first'  => array(
				'width'  => '1080',
				'height' => '1920',
				'src'    => 'https://shop.test/wp-content/uploads/phone.webp',
				'srcset' => 'https://shop.test/wp-content/uploads/phone.webp 1080w, https://shop.test/wp-content/uploads/phone-768x1365.webp 768w',
				'sizes'  => '(max-width: 1080px) 100vw, 1080px',
			),
			'second' => array(
				'width'  => '1920',
				'height' => '1080',
				'src'    => 'https://shop.test/wp-content/uploads/desktop.webp',
				'srcset' => 'https://shop.test/wp-content/uploads/desktop.webp 1920w, https://shop.test/wp-content/uploads/desktop-768x432.webp 768w',
				'sizes'  => 'auto, (max-width: 1920px) 100vw, 1920px',
			),
		);

		$tags = array();

		foreach ( array( 'first', 'second' ) as $which ) {
			$attributes = array_merge( $defaults[ $which ], $images[ $which ] ?? array() );
			$tag        = '<img';

			foreach ( $attributes as $name => $value ) {
				if ( null !== $value ) {
					$tag .= ' ' . $name . '="' . $value . '"';
				}
			}

			$tags[ $which ] = $tag . '>';
		}

		return '<div class="wpb-content-wrapper">' . ( $images['before'] ?? '' )
			. '<section ' . $first . '><div class="vc_row"><div class="owl-carousel">' . ( $images['inside'] ?? '' ) . $tags['first'] . ( $images['after'] ?? '' ) . '<img loading="lazy" width="1080" height="1920" src="https://shop.test/wp-content/uploads/slide-2.webp"></div></div></section>'
			. ( $images['between'] ?? "\n" )
			. '<section ' . $second . '><div class="vc_row"><div class="owl-carousel">' . $tags['second'] . '</div></div></section>'
			. '<section class="vc_section"><p>Products</p></section></div>';
	}

	public function test_hoka_preloads_the_phone_banner_below_md_and_the_desktop_one_from_md(): void {
		$html = self::fixture( 'hoka-home' );
		$out  = $this->filter( $html );

		$this->assertIsString( $out );
		$this->assertStringStartsWith( '<link rel="preload" as="image"', $out, 'The preloads go before the first banner, which nothing precedes.' );

		$links = self::tags( $out, 'LINK' );
		$this->assertCount( 2, $links );

		$phone   = self::image( $html, 'mobile-new-arrivals.webp' );
		$desktop = self::image( $html, 'deskto-new-arrivals.webp' );

		$this->assertSame(
			array(
				'rel'           => 'preload',
				'as'            => 'image',
				'imagesrcset'   => $phone['srcset'],
				'imagesizes'    => '(max-width: 1080px) 100vw, 1080px',
				'media'         => '(max-width: 767.98px)',
				'fetchpriority' => 'high',
			),
			$links[0],
			'Hoka prints the phone section first: d-md-none, shown below 768 px. No href: WebKit before 17.2 would preload the full-size src.'
		);
		$this->assertSame( $desktop['srcset'], $links[1]['imagesrcset'] );
		$this->assertSame( '(min-width: 768px)', $links[1]['media'] );
		$this->assertSame( '(max-width: 1920px) 100vw, 1920px', $links[1]['imagesizes'], 'Core\'s "auto" is dropped: the preload and the image pick the same candidate.' );

		foreach ( array( 'mobile-new-arrivals.webp', 'deskto-new-arrivals.webp' ) as $key => $banner ) {
			$image = self::image( $out, $banner );

			$this->assertSame( 'lazy', $image['loading'], 'Lazy is what keeps the hidden banner from downloading at all.' );
			$this->assertArrayNotHasKey( 'fetchpriority', $image, 'The priority moved to the preload.' );
			$this->assertContains( 'skip-lazy', explode( ' ', (string) $image['class'] ), 'A JavaScript lazy loader leaves it alone.' );
			$this->assertSame( $links[ $key ]['imagesizes'], $image['sizes'] );
		}

		$this->assertSame( self::image( $html, 'mobile-clifton-pro' ), self::image( $out, 'mobile-clifton-pro' ), 'The second slide keeps its lazy loading untouched.' );
		$this->assertSame( array(), WP_Stub::$doing_it_wrong );
	}

	public function test_joia_preloads_its_desktop_banner_first_and_its_phone_banner_below_md(): void {
		$html  = self::fixture( 'joia-home' );
		$out   = (string) $this->filter( $html );
		$links = self::tags( $out, 'LINK' );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'banners_joia-umbro_Desktop-JOIA-scaled.webp', (string) $links[0]['imagesrcset'] );
		$this->assertSame( '(min-width: 768px)', $links[0]['media'], 'Porto\'s interactive banner, desktop first: d-none d-md-block.' );
		$this->assertStringContainsString( 'banners_joia-umbro_MOBILE-JOIA.webp', (string) $links[1]['imagesrcset'] );
		$this->assertSame( '(max-width: 767.98px)', $links[1]['media'] );
		$this->assertSame( '(max-width: 782px) 100vw, 782px', $links[1]['imagesizes'] );
		$this->assertSame( self::image( $html, 'MOBILE-JOIA.webp' )['srcset'], $links[1]['imagesrcset'] );
		$this->assertSame( 'lazy', self::image( $out, 'Desktop-JOIA-scaled.webp' )['loading'] );
		$this->assertArrayNotHasKey( 'fetchpriority', self::image( $out, 'Desktop-JOIA-scaled.webp' ) );
	}

	public function test_easyrider_preloads_the_banners_of_its_plain_rows(): void {
		$links = self::tags( (string) $this->filter( self::fixture( 'easyrider-home' ) ), 'LINK' );

		$this->assertCount( 2, $links, 'Rows, not sections, and PNG banners: the rule reads classes, not markup names.' );
		$this->assertStringContainsString( '7mo-Sorteo-3-Premios-Comp-scaled.png', (string) $links[0]['imagesrcset'] );
		$this->assertSame( '(min-width: 768px)', $links[0]['media'] );
		$this->assertStringContainsString( '7mo-Sorteo-3-Premios-Mobile.png', (string) $links[1]['imagesrcset'] );
		$this->assertSame( '(max-width: 767.98px)', $links[1]['media'] );
	}

	/**
	 * The three shops' contents.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function shops(): array {
		return array(
			'hoka'      => array( 'hoka-home' ),
			'joia'      => array( 'joia-home' ),
			'easyrider' => array( 'easyrider-home' ),
		);
	}

	#[DataProvider( 'shops' )]
	public function test_nothing_but_the_two_banners_changes( string $shop ): void {
		$html = self::fixture( $shop );
		$out  = (string) $this->filter( $html );

		$this->assertSame( self::skeleton( $html ), self::skeleton( $out ), 'Every byte outside the two banner tags is left as it came.' );

		preg_match_all( '/<img\b[^>]*>/', $html, $before );
		preg_match_all( '/<img\b[^>]*>/', $out, $after );

		$this->assertCount( 2, array_diff_assoc( $before[0], $after[0] ), 'Exactly two images change.' );
	}

	public function test_a_page_that_does_not_open_with_a_pair_is_left_alone(): void {
		$html = self::fixture( 'joia-contacto' );

		$this->assertSame( $html, $this->filter( $html ) );
	}

	public function test_an_elementor_page_is_left_alone(): void {
		// Its phone and desktop copies hide with Elementor's own classes, which this does not read.
		$html = self::fixture( 'grupoferia-home' );

		$this->assertSame( $html, $this->filter( $html ) );
	}

	public function test_a_nested_the_content_call_is_left_alone_and_the_outer_one_still_counts(): void {
		$html    = self::fixture( 'hoka-home' );
		$service = $this->service();

		// A Porto block rendered inside the page's content runs the_content again.
		$GLOBALS['wp_current_filter'] = array( 'the_content', 'do_shortcode_tag', 'the_content' );
		$this->assertSame( $html, $service->preload_heroes( $html ) );

		$GLOBALS['wp_current_filter'] = array( 'the_content' );
		$this->assertStringStartsWith( '<link rel="preload"', (string) $service->preload_heroes( $html ) );
	}

	public function test_the_page_is_rewritten_once(): void {
		$html    = self::fixture( 'hoka-home' );
		$service = $this->service();

		$this->assertNotSame( $html, $service->preload_heroes( $html ) );
		$this->assertSame( $html, $service->preload_heroes( $html ), 'A second print of the content gets no second pair of preloads.' );
	}

	/**
	 * Requests and calls that are not the queried page's own content.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function other_content(): array {
		return array(
			'outside the loop' => array( 'loop' ),
			'another query'    => array( 'query' ),
			'another post'     => array( 'post' ),
			'not a page'       => array( 'single' ),
		);
	}

	#[DataProvider( 'other_content' )]
	public function test_content_other_than_the_pages_own_is_left_alone( string $case ): void {
		match ( $case ) {
			'loop'   => WP_Stub::$in_the_loop = false,
			'query'  => WP_Stub::$main_query = false,
			'post'   => WP_Stub::$the_id = 99,
			'single' => WP_Stub::$page_type = 'post',
		};

		$html = self::fixture( 'hoka-home' );

		$this->assertSame( $html, $this->filter( $html ) );
	}

	public function test_a_content_that_is_not_a_string_passes_through(): void {
		$this->assertNull( $this->service()->preload_heroes( null ) );
	}

	public function test_the_smallest_image_bar_is_cores_own(): void {
		// Hoka's banners are 1080x1920: 2,073,600 pixels.
		WP_Stub::$filters['wp_min_priority_img_pixels'] = 2073601;

		$html = self::fixture( 'hoka-home' );

		$this->assertSame( $html, $this->filter( $html ) );
	}

	/**
	 * Splits every width at a breakpoint every site agrees on.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function fixed_splits(): array {
		return array(
			'sm'                   => array( 'class="d-sm-none"', 'class="d-none d-sm-block"', '(max-width: 575.98px)', '(min-width: 576px)' ),
			'md'                   => array( 'class="d-md-none"', 'class="d-none d-md-flex"', '(max-width: 767.98px)', '(min-width: 768px)' ),
			'lg'                   => array( 'class="d-lg-none"', 'class="d-none d-lg-block"', '(max-width: 991.98px)', '(min-width: 992px)' ),
			'desktop first'        => array( 'class="d-none d-md-block"', 'class="d-md-none"', '(min-width: 768px)', '(max-width: 767.98px)' ),
			'd-xs agrees'          => array( 'class="d-xs-block d-md-none"', 'class="d-none d-xs-none d-md-block"', '(max-width: 767.98px)', '(min-width: 768px)' ),
			'print classes ignored' => array( 'class="d-md-none d-print-block"', 'class="d-none d-md-block d-print-none"', '(max-width: 767.98px)', '(min-width: 768px)' ),
		);
	}

	#[DataProvider( 'fixed_splits' )]
	public function test_a_split_at_a_fixed_breakpoint_is_preloaded( string $first, string $second, string $first_media, string $second_media ): void {
		$links = self::tags( (string) $this->filter( self::pair( $first, $second ) ), 'LINK' );

		$this->assertCount( 2, $links );
		$this->assertSame( $first_media, $links[0]['media'] );
		$this->assertSame( $second_media, $links[1]['media'] );
	}

	/**
	 * Containers whose widths do not split cleanly at a fixed breakpoint.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function unsafe_splits(): array {
		return array(
			'split at xl, which follows the container width'  => array( 'class="d-xl-none"', 'class="d-none d-xl-block"' ),
			'split at xxl'                                    => array( 'class="d-xxl-none"', 'class="d-none d-xxl-block"' ),
			'both show between md and lg'                     => array( 'class="d-lg-none"', 'class="d-none d-md-block"' ),
			'neither shows between md and lg'                 => array( 'class="d-md-none"', 'class="d-none d-lg-block"' ),
			'a third screen size in the middle'               => array( 'class="d-none d-md-block d-lg-none"', 'class="d-md-none d-lg-block"' ),
			'the second shows everywhere'                     => array( 'class="d-md-none"', 'class="vc_section"' ),
			'neither depends on the width'                    => array( 'class="vc_section"', 'class="vc_section"' ),
			'the first never shows'                           => array( 'class="d-none"', 'class="d-block"' ),
			'a display class no stylesheet has'               => array( 'class="d-md-none d-sticky-header-none"', 'class="d-none d-md-block"' ),
			'two classes for one breakpoint'                  => array( 'class="d-md-block d-md-none"', 'class="d-none d-md-block"' ), // The stylesheet's order decides, not the attribute's: block wins.
			'd-xs-none, which no stylesheet has, as the base' => array( 'class="d-xs-none d-sm-none d-md-block"', 'class="d-md-none"' ), // Both show below 576.
			'd-xs says otherwise'                             => array( 'class="d-xs-none d-md-none"', 'class="d-none d-md-block"' ),
			'WPBakery hides it too'                           => array( 'class="d-md-none vc_hidden-sm"', 'class="d-none d-md-block"' ),
			'Elementor hides it too'                          => array( 'class="d-md-none elementor-hidden-mobile"', 'class="d-none d-md-block"' ),
			'a hidden attribute'                              => array( 'class="d-md-none" hidden', 'class="d-none d-md-block"' ),
			'an inline display: none'                         => array( 'class="d-md-block" style="display: none !important"', 'class="d-none d-md-block"' ),
		);
	}

	#[DataProvider( 'unsafe_splits' )]
	public function test_a_split_that_is_not_certain_is_left_alone( string $first, string $second ): void {
		$html = self::pair( $first, $second );

		$this->assertSame( $html, $this->filter( $html ) );
	}

	/**
	 * Banners that cannot be preloaded as they are.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function unfit_banners(): array {
		return array(
			'no srcset'             => array( array( 'first' => array( 'srcset' => null ) ) ),
			'an empty srcset'       => array( array( 'second' => array( 'srcset' => ' ' ) ) ),
			'no sizes'              => array( array( 'second' => array( 'sizes' => null ) ) ),
			'sizes that are only auto' => array( array( 'second' => array( 'sizes' => 'auto' ) ) ),
			'a small image'         => array( array( 'second' => array( 'width' => '300', 'height' => '100' ) ) ),
			'no width'              => array( array( 'first' => array( 'width' => null ) ) ),
			'a width with a unit'   => array( array( 'first' => array( 'width' => '100%' ) ) ),
			'a placeholder src'     => array( array( 'first' => array( 'src' => 'data:image/gif;base64,R0lGOD' ) ) ),
			'another lazy loader'   => array( array( 'first' => array( 'data-src' => 'https://shop.test/wp-content/uploads/phone.webp' ) ) ),
			'Porto\'s lazy loader'  => array( array( 'second' => array( 'data-oi' => 'https://shop.test/wp-content/uploads/desktop.webp' ) ) ),
			'a CORS image'          => array( array( 'first' => array( 'crossorigin' => 'anonymous' ) ) ),
			'hidden itself below lg' => array( array( 'first' => array( 'class' => 'd-none d-lg-block' ) ) ),
			'an empty src'          => array( array( 'first' => array( 'src' => '' ) ) ),
			'an uppercase placeholder src' => array( array( 'first' => array( 'src' => 'DATA:image/gif;base64,R0' ) ) ),
			'another lazy loader\'s original' => array( array( 'first' => array( 'data-original' => 'https://shop.test/x.webp' ) ) ),
			'a height with a unit'  => array( array( 'first' => array( 'height' => '1920px' ) ) ),
		);
	}

	/**
	 * Overrides for the pair.
	 *
	 * @param array<string, mixed> $images Overrides.
	 * @return void
	 */
	#[DataProvider( 'unfit_banners' )]
	public function test_a_banner_that_cannot_be_preloaded_leaves_the_page_alone( array $images ): void {
		$html = self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', $images );

		$this->assertSame( $html, $this->filter( $html ) );
	}

	/**
	 * Contents that do not open with the pair, or cannot be read to the end of it.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function other_openings(): array {
		$pair = self::pair( 'class="d-md-none"', 'class="d-none d-md-block"' );

		return array(
			'text before the pair'           => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'before' => 'Welcome' ) ) ),
			'an image before the pair'       => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'before' => '<img src="https://shop.test/logo.png" width="400" height="400">' ) ) ),
			'a row between the two'          => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'between' => '<div class="vc_row-full-width"></div>' ) ) ),
			'a picture around the banner'    => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'inside' => '<picture><source type="image/avif" srcset="https://shop.test/phone.avif">', 'after' => '</picture>' ) ) ),
			'an inline display: none around the banner' => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'inside' => '<div style="DISPLAY:NONE">', 'after' => '</div>' ) ) ),
			'a banner only inside noscript'  => array( '<section class="d-md-none"><noscript><img width="1080" height="1920" src="https://shop.test/a.webp" srcset="https://shop.test/a.webp 1080w" sizes="100vw"></noscript></section><section class="d-none d-md-block"><img width="1920" height="1080" src="https://shop.test/b.webp" srcset="https://shop.test/b.webp 1920w" sizes="100vw"></section>' ),
			'one container alone'            => array( '<div class="wpb-content-wrapper"><section class="d-md-none"><img width="1080" height="1920" src="https://shop.test/a.webp" srcset="https://shop.test/a.webp 1080w" sizes="100vw"></section></div>' ),
			'cut in the middle of a tag'     => array( substr( $pair, 0, (int) strpos( $pair, 'desktop-768x432' ) ) ),
			'cut after the first container'  => array( substr( $pair, 0, (int) strpos( $pair, '<section class="d-none' ) ) ),
			'closing tags out of order'      => array( str_replace( '<div class="owl-carousel">', '<div class="owl-carousel"><span>', $pair ) ),
			'a stray closing tag'            => array( preg_replace( '#</div></div></section>#', '</div></span></section>', $pair, 1 ) ),
			'an empty element before the pair' => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'before' => '<div class="spacer"></div>' ) ) ),
			'the second container outside the first one\'s wrapper' => array( str_replace( array( '<section class="d-md-none">', "</section>\n" ), array( '<div class="w"><section class="d-md-none">', "</section></div>\n" ), $pair ) ),
			'the second container inside a wrapper' => array( str_replace( array( '<section class="d-none d-md-block">', '</section><section class="vc_section">' ), array( '<div class="w"><section class="d-none d-md-block">', '</section></div><section class="vc_section">' ), $pair ) ),
			'the first container has no image' => array( '<section class="d-none d-md-block"><p>Soon</p></section><section class="d-md-none"><img width="1080" height="1920" src="https://shop.test/a.webp" srcset="https://shop.test/a.webp 1080w" sizes="100vw"></section><section class="d-none d-md-block"><img width="1920" height="1080" src="https://shop.test/b.webp" srcset="https://shop.test/b.webp 1920w" sizes="100vw"></section>' ),
			'a pair inside noscript before the real content' => array( '<noscript>' . substr( $pair, strlen( '<div class="wpb-content-wrapper">' ) ) . '</noscript><div>Real hero</div>' ),
			'a pair inside a template'       => array( '<template>' . substr( $pair, strlen( '<div class="wpb-content-wrapper">' ) ) . '</template><div>Real hero</div>' ),
			'a pair inside a closed details' => array( '<details>' . substr( $pair, strlen( '<div class="wpb-content-wrapper">' ) ) . '</details>' ),
			'a pair inside a closed dialog'  => array( '<dialog>' . substr( $pair, strlen( '<div class="wpb-content-wrapper">' ) ) . '</dialog>' ),
			'an empty content'               => array( '' ),
			'plain text'                     => array( "<p>Hello</p>\n" ),
		);
	}

	#[DataProvider( 'other_openings' )]
	public function test_a_content_that_does_not_open_with_a_readable_pair_is_left_alone( string $html ): void {
		$this->assertSame( $html, $this->filter( $html ) );
	}

	/**
	 * Markup around the pair that renders nothing, or that a browser reads as this does.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function harmless_markup(): array {
		return array(
			'no wrapper'                          => array( substr( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"' ), strlen( '<div class="wpb-content-wrapper">' ) ) ),
			'a comment and a style before'        => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'before' => "<!-- banner -->\n<style>.x{color:red}</style>" ) ) ),
			'a script between the two'            => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'between' => '<script>var x = "<section class=\'d-md-none\'>";</script>' ) ) ),
			'a noscript copy before the banner'   => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'inside' => '<noscript><img src="https://shop.test/tiny.png" width="10" height="10"></noscript>' ) ) ),
			'an icon with a self-closing path'    => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'inside' => '<svg viewBox="0 0 8 8"><path d="M0 0h8v8z"/></svg>' ) ) ),
			'an inline display that hides nothing' => array( self::pair( 'class="d-md-none" style="display:block"', 'class="d-none d-md-block"', array( 'first' => array( 'style' => 'display: block; width: 100%' ) ) ) ),
			'display classes beside the banner'   => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'inside' => '<div class="porto-ibanner-desc d-flex"><span class="d-none">x</span></div>' ) ) ),
			'a stylesheet link and a meta before' => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'before' => '<link rel="stylesheet" href="https://shop.test/x.css"><meta itemprop="name" content="x">' ) ) ),
			'a self-closing span that HTML opens' => array( self::pair( 'class="d-md-none"', 'class="d-none d-md-block"', array( 'inside' => '<span class="badge"/>New</span>' ) ) ),
		);
	}

	#[DataProvider( 'harmless_markup' )]
	public function test_markup_that_renders_nothing_or_sits_beside_the_banner_does_not_stop_it( string $html ): void {
		$out      = (string) $this->filter( $html );
		$preloads = array_values( array_filter( self::tags( $out, 'LINK' ), static fn ( array $link ): bool => 'preload' === ( $link['rel'] ?? null ) ) );

		$this->assertCount( 2, $preloads );
		$this->assertStringContainsString( 'phone.webp', (string) $preloads[0]['imagesrcset'] );
	}

	public function test_attribute_values_are_escaped_in_the_preload(): void {
		$html = self::pair(
			'class="d-md-none"',
			'class="d-none d-md-block"',
			array(
				'first' => array(
					'src'    => 'https://shop.test/phone.webp?a=1&amp;b=&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;',
					'srcset' => 'https://shop.test/phone.webp?a=1&amp;b=&quot;&gt;&lt;script&gt; 1080w',
					'sizes'  => '100vw&quot;&gt;&lt;script&gt;',
				),
			)
		);

		$out = (string) $this->filter( $html );

		$this->assertStringNotContainsString( '<script', $out );
		$this->assertSame( 'https://shop.test/phone.webp?a=1&b="><script> 1080w', self::tags( $out, 'LINK' )[0]['imagesrcset'], 'The browser reads the same URLs the image names.' );
		$this->assertSame( '100vw"><script>', self::tags( $out, 'LINK' )[0]['imagesizes'] );
		$this->assertArrayNotHasKey( 'href', self::tags( $out, 'LINK' )[0] );
	}

	public function test_the_filter_runs_after_core_decides_loading(): void {
		$this->service();

		$this->assertGreaterThan( 12, WP_Stub::$priorities['the_content:preload_heroes'], 'An image made lazy before wp_filter_content_tags is not counted, and core hands the high priority to the next one.' );
		$this->assertContains( 'wp:remember_page', WP_Stub::$callbacks );
	}

	public function test_it_is_on_by_default(): void {
		$this->service();

		$this->assertContains( 'the_content:preload_heroes', WP_Stub::$callbacks, 'A delivery correction: it changes nothing the visitor sees.' );
	}

	/**
	 * Settings that switch the correction off.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function switched_off(): array {
		return array(
			'its switch'         => array( 'ropt_viewport_hero' ),
			'the master switch'  => array( 'ropt_enabled' ),
			'Porto\'s lazy load' => array( 'porto_settings_optimize' ),
		);
	}

	#[DataProvider( 'switched_off' )]
	public function test_it_stays_off_when_told_to_or_when_porto_lazy_loads( string $option ): void {
		WP_Stub::$options[ $option ] = 'porto_settings_optimize' === $option ? array( 'lazyload' => true ) : 'no';

		( new Viewport_Hero() )->register();

		$this->assertSame( array(), WP_Stub::$callbacks );
	}

	public function test_a_leftover_smush_setting_does_not_count_without_smush(): void {
		WP_Stub::$options['wp-smush-settings'] = array( 'cdn' => true );

		$this->service();

		$this->assertContains( 'the_content:preload_heroes', WP_Stub::$callbacks, 'Smush is not loaded in this process, so its stored CDN setting serves nothing.' );
	}

	public function test_smush_rewrites_the_preload_candidates_like_the_image_srcset(): void {
		$service = $this->service();
		$smush   = array( 'href', 'src', 'srcset', 'data-src' );

		$this->assertContains( 'wp_smush_get_image_attribute_names:smush_rewrites_preloads', WP_Stub::$callbacks );
		$this->assertSame( array( 'href', 'src', 'srcset', 'data-src', 'imagesrcset' ), $service->smush_rewrites_preloads( $smush ) );
		$this->assertSame( array( 'imagesrcset' ), $service->smush_rewrites_preloads( array( 'imagesrcset' ) ), 'Added once.' );
		$this->assertSame( 'href', $service->smush_rewrites_preloads( 'href' ), 'Anything but a list goes back as it came.' );
	}
}
