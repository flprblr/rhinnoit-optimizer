<?php
/**
 * Delivery corrections.
 *
 * Every method here runs on `wp_head` for every storefront request and reads
 * from the filesystem, so the guards matter more than the happy path: a
 * malformed filter value or an unreadable file has to degrade to "no change".
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Delivery;

/**
 * Covers RhinnoIT\Optimizer\Delivery.
 */
final class DeliveryTest extends TestCase {

	/**
	 * Temporary uploads directory for the run.
	 *
	 * @var string
	 */
	private string $uploads = '';

	/**
	 * Service under test.
	 *
	 * @var Delivery
	 */
	private Delivery $delivery;

	/**
	 * Points the uploads stubs at a real temporary directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/ropt-' . uniqid();

		mkdir( $this->uploads, 0777, true );

		WP_Stub::$upload_basedir = $this->uploads;
		WP_Stub::$upload_baseurl = 'https://shop.test/wp-content/uploads';
		WP_Stub::$attached_files = array();
		WP_Stub::$is_admin       = false;
		WP_Stub::$options        = array();
		WP_Stub::$logs           = array();
		WP_Stub::$page_type      = 'shop';
		WP_Stub::$featured_id    = 0;
		WP_Stub::$filters        = array();

		/*
		 * Delivery caches resolved paths for the life of the request, which in
		 * a test run is the life of the process: without this, one test's
		 * temporary directory answers the next test's URL and every assertion
		 * after it is measuring the wrong file.
		 */
		$paths = new ReflectionProperty( Delivery::class, 'paths' );
		$paths->setValue( null, array() );

		$this->delivery = new Delivery();
	}

	/**
	 * Removes the temporary directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( (array) glob( $this->uploads . '/*' ) as $file ) {
			if ( is_string( $file ) ) {
				unlink( $file );
			}
		}

		rmdir( $this->uploads );

		WP_Stub::$is_admin = true;
	}

	/**
	 * Writes a file of an exact size and returns its path.
	 *
	 * @param string $name  File name.
	 * @param int    $bytes Size in bytes.
	 * @return string
	 */
	private function write_file( string $name, int $bytes ): string {
		$path = $this->uploads . '/' . $name;

		file_put_contents( $path, str_repeat( 'x', $bytes ) );

		return $path;
	}

	/**
	 * A value that is not an array comes back untouched.
	 *
	 * @return void
	 */
	public function test_srcset_ignores_a_non_array(): void {
		$this->assertSame( 'not an array', $this->delivery->drop_oversized_copies( 'not an array', null, null, null, 1 ) );
		$this->assertFalse( $this->delivery->drop_oversized_copies( false, null, null, null, 1 ) );
	}

	/**
	 * A single candidate is never touched: there is nothing to compare it to.
	 *
	 * @return void
	 */
	public function test_srcset_ignores_a_single_candidate(): void {
		$sources = array( 768 => array( 'url' => 'https://shop.test/a.webp' ) );

		$this->assertSame( $sources, $this->delivery->drop_oversized_copies( $sources, null, null, null, 1 ) );
	}

	/**
	 * Candidates whose entries are not arrays do not fatal.
	 *
	 * The filter value comes from whatever ran before, so it can hold anything.
	 *
	 * @return void
	 */
	public function test_srcset_survives_malformed_candidates(): void {
		$sources = array(
			768  => 'a string',
			1024 => array( 'no_url_key' => true ),
			1536 => null,
		);

		$this->assertSame( $sources, $this->delivery->drop_oversized_copies( $sources, null, null, null, 1 ) );
	}

	/**
	 * A copy that is heavier than a larger one is dropped; the largest stays.
	 *
	 * @return void
	 */
	public function test_srcset_drops_a_copy_heavier_than_a_larger_one(): void {
		$original = $this->write_file( 'banner.webp', 400 );

		$this->write_file( 'banner-768x1365.webp', 900 );
		$this->write_file( 'banner-1024x1820.webp', 600 );

		WP_Stub::$attached_files[7] = $original;

		$sources = array(
			768  => array( 'url' => 'https://shop.test/wp-content/uploads/banner-768x1365.webp' ),
			1024 => array( 'url' => 'https://shop.test/wp-content/uploads/banner-1024x1820.webp' ),
			1600 => array( 'url' => 'https://shop.test/wp-content/uploads/banner.webp' ),
		);

		$result = $this->delivery->drop_oversized_copies( $sources, null, null, null, 7 );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 768, $result, 'The 768 copy is heavier than both larger ones.' );
		$this->assertArrayHasKey( 1600, $result, 'The largest candidate must never be dropped.' );
	}

	/**
	 * When weight grows with size, nothing is dropped.
	 *
	 * @return void
	 */
	public function test_srcset_leaves_healthy_images_alone(): void {
		$original = $this->write_file( 'clean.webp', 900 );

		$this->write_file( 'clean-768x1365.webp', 300 );
		$this->write_file( 'clean-1024x1820.webp', 600 );

		WP_Stub::$attached_files[9] = $original;

		$sources = array(
			768  => array( 'url' => 'https://shop.test/wp-content/uploads/clean-768x1365.webp' ),
			1024 => array( 'url' => 'https://shop.test/wp-content/uploads/clean-1024x1820.webp' ),
			1600 => array( 'url' => 'https://shop.test/wp-content/uploads/clean.webp' ),
		);

		$this->assertSame( $sources, $this->delivery->drop_oversized_copies( $sources, null, null, null, 9 ) );
	}

	/**
	 * Only the first image of the page stays eager.
	 *
	 * @return void
	 */
	public function test_only_the_first_image_is_eager(): void {
		$this->assertSame( 1, $this->delivery->only_first_image_eager() );
	}

	/**
	 * A stylesheet that only declares fonts is inlined with font-display.
	 *
	 * @return void
	 */
	public function test_a_font_stylesheet_is_inlined_with_swap(): void {
		$this->write_file( 'fonts.css', 0 );

		file_put_contents(
			$this->uploads . '/fonts.css',
			"@font-face{font-family:'Porto';src:url(a.woff2)}\n@font-face{font-family:'Other';src:url(b.woff2);font-display:block}"
		);

		$tag    = '<link rel="stylesheet" href="https://shop.test/wp-content/uploads/fonts.css" />';
		$result = $this->delivery->inline_font_stylesheets( $tag, 'porto-fonts', 'https://shop.test/wp-content/uploads/fonts.css' );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( '<style id="ropt-fonts-porto-fonts">', $result );
		$this->assertStringContainsString( 'font-display:swap;}', $result, 'The face without a font-display gets one.' );
		$this->assertStringContainsString( 'font-display:block', $result, 'An explicit font-display is left alone.' );
		$this->assertSame( 1, substr_count( $result, 'font-display:swap' ), 'Only the face that lacked it is touched.' );
	}

	/**
	 * A face without font-display is rewritten even where Porto claims Swap.
	 *
	 * The sheet quoted here is the one cl.hoka.com serves: the Porto wizard has
	 * its Swap option on and the faces still declare no font-display, so the
	 * only trustworthy answer is the one in the file. The second face proves
	 * the guard that replaced the old one still holds - a sheet the wizard
	 * really did cover comes back untouched.
	 *
	 * @return void
	 */
	public function test_a_face_is_rewritten_even_when_porto_claims_swap(): void {
		WP_Stub::$options['porto_settings_optimize'] = array( 'swap' => 1 );

		file_put_contents(
			$this->uploads . '/redux-fonts.css',
			"@font-face {font-family:'Futurapt-heavy';src:url('//shop.test/wp-content/uploads/f/heavy.ttf') format('truetype');font-weight: normal;font-style: normal;}"
			. "@font-face {font-family:'Futurapt';src:url('//shop.test/wp-content/uploads/f/medium.ttf') format('truetype');font-weight: 500;font-style: normal;}"
			. "@font-face {font-family:'Covered';src:url(c.woff2);font-display:optional;}"
		);

		$href   = 'https://shop.test/wp-content/uploads/redux-fonts.css';
		$result = $this->delivery->inline_font_stylesheets( '<link rel="stylesheet" href="' . $href . '" />', 'redux-custom-fonts', $href );

		$this->assertIsString( $result );
		$this->assertSame( 2, substr_count( $result, 'font-display:swap' ), 'Both bare faces are rewritten.' );
		$this->assertStringContainsString( 'font-display:optional', $result, 'A face that already declares one is left alone.' );
	}

	/**
	 * A stylesheet that resolved and then could not be read leaves a trace.
	 *
	 * The helper caches the resolved path for the request, so a file that goes
	 * away between two stylesheets of the same page reaches filesize() as a
	 * path that no longer exists. From outside this looks exactly like "not a
	 * font sheet": the font is simply not inlined. It is a failure, and the
	 * whole point of this audit was that failures were invisible.
	 *
	 * @return void
	 */
	public function test_a_stylesheet_that_cannot_be_read_is_logged(): void {
		$href = 'https://shop.test/wp-content/uploads/gone.css';

		file_put_contents( $this->uploads . '/gone.css', "@font-face{font-family:'A';src:url(a.woff2)}" );

		$tag = '<link rel="stylesheet" href="' . $href . '" />';

		$this->assertStringStartsWith( '<style', (string) $this->delivery->inline_font_stylesheets( $tag, 'fonts', $href ), 'It inlines while the file is there.' );

		WP_Stub::$logs = array();

		unlink( $this->uploads . '/gone.css' );

		$warnings = array();

		set_error_handler(
			static function ( int $number, string $message ) use ( &$warnings ): bool {
				$warnings[] = $message;

				return true;
			}
		);

		$result = $this->delivery->inline_font_stylesheets( $tag, 'fonts', $href );

		restore_error_handler();

		$this->assertSame( $tag, $result, 'It falls back to the request.' );
		$this->assertNotEmpty( WP_Stub::$logs, 'And says so, instead of failing silently.' );
		$this->assertSame(
			array(),
			$warnings,
			'And says it without raising a PHP warning: this runs on wp_head, for every visitor, on every stylesheet.'
		);
	}

	/**
	 * A sheet that is simply not about fonts is not reported as a failure.
	 *
	 * The log has to stay readable on a shop with two dozen stylesheets under
	 * uploads, so a decision must not look like a fault.
	 *
	 * @return void
	 */
	public function test_a_stylesheet_that_is_not_about_fonts_is_not_logged(): void {
		file_put_contents( $this->uploads . '/theme.css', '.header{color:red}' );

		WP_Stub::$logs = array();

		$href = 'https://shop.test/wp-content/uploads/theme.css';

		$this->delivery->inline_font_stylesheets( '<link href="' . $href . '" />', 'theme', $href );

		$this->assertSame( array(), WP_Stub::$logs );
	}

	/**
	 * A protocol-relative uploads URL resolves like any other.
	 *
	 * Both shops already serve their favicon and their @font-face sources as
	 * `//host/path`, so a stylesheet arriving that way is a matter of time -
	 * and the helper used to answer "not under uploads" for a file that was.
	 *
	 * @return void
	 */
	public function test_a_protocol_relative_uploads_url_is_resolved(): void {
		file_put_contents( $this->uploads . '/scheme-less.css', "@font-face{font-family:'A';src:url(a.woff2)}" );
		touch( $this->uploads . '/scheme-less.css', 1700000000 );

		$href = '//shop.test/wp-content/uploads/scheme-less.css';

		$this->assertStringStartsWith(
			'<style id="ropt-fonts-fonts">',
			(string) $this->delivery->inline_font_stylesheets( '<link href="' . $href . '" />', 'fonts', $href ),
			'A missing scheme is not a reason to leave the sheet alone.'
		);

		$this->assertStringContainsString(
			'ver=1700000000',
			(string) $this->delivery->version_generated_css( $href . '?ver=1' ),
			'And the version rewrite reaches it too.'
		);
	}

	/**
	 * A stylesheet with ordinary rules is left as a request.
	 *
	 * @return void
	 */
	public function test_a_normal_stylesheet_is_not_inlined(): void {
		file_put_contents(
			$this->uploads . '/theme.css',
			"@font-face{font-family:'Porto';src:url(a.woff2)}\n.header{color:red}"
		);

		$tag = '<link rel="stylesheet" href="https://shop.test/wp-content/uploads/theme.css" />';

		$this->assertSame( $tag, $this->delivery->inline_font_stylesheets( $tag, 'theme', 'https://shop.test/wp-content/uploads/theme.css' ) );
	}

	/**
	 * A stylesheet outside uploads is never read.
	 *
	 * @return void
	 */
	public function test_a_stylesheet_outside_uploads_is_untouched(): void {
		$tag = '<link rel="stylesheet" href="https://shop.test/wp-content/themes/porto/style.css" />';

		$this->assertSame( $tag, $this->delivery->inline_font_stylesheets( $tag, 'porto', 'https://shop.test/wp-content/themes/porto/style.css' ) );
	}

	/**
	 * Malformed arguments never reach the filesystem.
	 *
	 * @return void
	 */
	public function test_inlining_survives_malformed_arguments(): void {
		$this->assertNull( $this->delivery->inline_font_stylesheets( null, 'h', 'https://shop.test/wp-content/uploads/fonts.css' ) );
		$this->assertSame( '<link />', $this->delivery->inline_font_stylesheets( '<link />', 'h', false ) );
	}

	/**
	 * Generated CSS is versioned by its modification time.
	 *
	 * @return void
	 */
	public function test_generated_css_is_versioned_by_mtime(): void {
		$path = $this->write_file( 'porto-generated.css', 10 );

		touch( $path, 1755000000 );

		$result = $this->delivery->version_generated_css( 'https://shop.test/wp-content/uploads/porto-generated.css?ver=7.7.1' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'ver=1755000000', $result );
		$this->assertStringNotContainsString( '7.7.1', $result, 'The stale version has to go, not sit alongside.' );
	}

	/**
	 * A URL that carries a cache-buster of its own is left as it came.
	 *
	 * Trustindex enqueues its uploads sheet as ?<mtime> and prints the same
	 * URL in a <link> of its own; adding ver made two URLs and two downloads.
	 *
	 * @return void
	 */
	public function test_a_url_with_its_own_cache_buster_is_left_alone(): void {
		$path = $this->write_file( 'trustindex-google-widget.css', 10 );

		touch( $path, 1791550996 );

		foreach ( array( 'https://shop.test/wp-content/uploads/trustindex-google-widget.css?1791550996', 'https://shop.test/wp-content/uploads/trustindex-google-widget.css?v=3&ver=1' ) as $src ) {
			$this->assertSame( $src, $this->delivery->version_generated_css( $src ) );
		}

		$this->assertStringContainsString( 'ver=1791550996', (string) $this->delivery->version_generated_css( 'https://shop.test/wp-content/uploads/trustindex-google-widget.css?ver=9' ), 'Only ver is still replaced by the mtime.' );
	}

	/**
	 * A URL outside uploads keeps its own version.
	 *
	 * @return void
	 */
	public function test_css_outside_uploads_keeps_its_version(): void {
		$src = 'https://shop.test/wp-content/themes/porto/style.css?ver=7.7.1';

		$this->assertSame( $src, $this->delivery->version_generated_css( $src ) );
	}

	/**
	 * A non-string source comes back untouched.
	 *
	 * @return void
	 */
	public function test_a_non_string_source_is_untouched(): void {
		$this->assertFalse( $this->delivery->version_generated_css( false ) );
		$this->assertNull( $this->delivery->version_generated_css( null ) );
	}

	/**
	 * The uploads base URL is matched regardless of the scheme.
	 *
	 * The base can be registered as http while the stylesheet arrives as https;
	 * a plain string comparison would leave the filter inert without warning.
	 *
	 * @return void
	 */
	public function test_the_scheme_does_not_break_the_match(): void {
		WP_Stub::$upload_baseurl = 'http://shop.test/wp-content/uploads';

		$path = $this->write_file( 'scheme.css', 10 );

		touch( $path, 1755000001 );

		$result = $this->delivery->version_generated_css( 'https://shop.test/wp-content/uploads/scheme.css' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'ver=1755000001', $result );
	}

	/**
	 * The weights of one image are read from disk once per request.
	 *
	 * A product grid or a looping carousel resolves the same srcset many times
	 * on one page. Without this the filter costs a stat call per candidate per
	 * render, on exactly the pages it is meant to speed up — and the loss is
	 * silent, which is why it is pinned here.
	 *
	 * @return void
	 */
	public function test_the_weights_of_an_image_are_read_once_per_request(): void {
		$original = $this->write_file( 'cached.webp', 400 );

		$this->write_file( 'cached-768x1365.webp', 900 );
		$this->write_file( 'cached-1024x1820.webp', 600 );

		WP_Stub::$attached_files[4242] = $original;

		$sources = array(
			768  => array( 'url' => 'https://shop.test/wp-content/uploads/cached-768x1365.webp' ),
			1024 => array( 'url' => 'https://shop.test/wp-content/uploads/cached-1024x1820.webp' ),
			1600 => array( 'url' => 'https://shop.test/wp-content/uploads/cached.webp' ),
		);

		$first = $this->delivery->drop_oversized_copies( $sources, null, null, null, 4242 );
		$this->assertArrayNotHasKey( 768, $first );

		// Make the files disagree with what was measured. A second read of the
		// disk would now keep the 768 copy; the cached weights must not.
		$this->write_file( 'cached-768x1365.webp', 10 );

		$second = ( new Delivery() )->drop_oversized_copies( $sources, null, null, null, 4242 );

		$this->assertSame( $first, $second, 'The second render re-read the disk.' );
	}
	/**
	 * On a classic theme both of core's global-styles call sites go, and only those.
	 *
	 * @return void
	 */
	public function test_global_styles_go_on_a_classic_theme(): void {
		WP_Stub::$block_theme = false;
		WP_Stub::$removed     = array();

		$this->delivery->drop_global_styles();

		$this->assertSame(
			array( 'wp_enqueue_scripts:wp_enqueue_global_styles', 'wp_footer:wp_enqueue_global_styles' ),
			WP_Stub::$removed,
			'The head and the footer call site, for classic themes with separate block assets; classic-theme-styles has its own switch.'
		);
		$this->assertSame( 10, WP_Stub::$removed_priorities['wp_enqueue_scripts:wp_enqueue_global_styles'] );
		$this->assertSame( 1, WP_Stub::$removed_priorities['wp_footer:wp_enqueue_global_styles'], 'Core adds the footer call at 1; any other priority removes nothing.' );
		$this->assertContains( 'enqueue:ropt-body-reset', WP_Stub::$styles );
		$this->assertSame( ':where(body){margin:0}', WP_Stub::$inline_styles['ropt-body-reset'] ?? '', 'Core\'s own reset stays: Hello Elementor without its reset.css framed every page in 8 px.' );
	}

	/**
	 * On a classic theme both of core's classic-theme-styles call sites go, and only those.
	 *
	 * @return void
	 */
	public function test_classic_theme_styles_go_on_a_classic_theme(): void {
		WP_Stub::$block_theme = false;
		WP_Stub::$removed     = array();

		$this->delivery->drop_classic_theme_styles();

		$this->assertSame(
			array( 'wp_enqueue_scripts:wp_enqueue_classic_theme_styles', 'enqueue_block_assets:wp_enqueue_classic_theme_styles' ),
			WP_Stub::$removed,
			'Core wires it on enqueue_block_assets too, which wp_enqueue_scripts fires on the front end, so without it the sheet still prints.'
		);
	}

	/**
	 * A block theme keeps both: it is built on those styles.
	 *
	 * @return void
	 */
	public function test_block_editor_styles_stay_on_a_block_theme(): void {
		WP_Stub::$block_theme   = true;
		WP_Stub::$removed       = array();
		WP_Stub::$inline_styles = array();

		$this->delivery->drop_global_styles();
		$this->delivery->drop_classic_theme_styles();

		$this->assertSame( array(), WP_Stub::$removed, 'A block theme would lose its colours and spacing.' );
		$this->assertSame( array(), WP_Stub::$inline_styles, 'Nothing removed, nothing to put back.' );
	}

	/**
	 * Each stylesheet answers to its own switch, and both ship on.
	 *
	 * @return void
	 */
	public function test_each_block_stylesheet_is_hooked_only_by_its_own_switch(): void {
		$hooked = static function ( array $options ): array {
			WP_Stub::$options   = $options + array( 'ropt_enabled' => 'yes' );
			WP_Stub::$callbacks = array();
			( new Delivery() )->register();

			return array_values( array_intersect( WP_Stub::$callbacks, array( 'wp_enqueue_scripts:drop_global_styles', 'wp_enqueue_scripts:drop_classic_theme_styles' ) ) );
		};

		// Both rows present, as on any site Install has seeded.
		$this->assertSame( array( 'wp_enqueue_scripts:drop_global_styles', 'wp_enqueue_scripts:drop_classic_theme_styles' ), $hooked( array() ), 'Both ship on.' );
		$this->assertSame( array(), $hooked( array( 'ropt_global_styles' => 'no', 'ropt_classic_theme_styles' => 'no' ) ) );
		$this->assertSame( array( 'wp_enqueue_scripts:drop_global_styles' ), $hooked( array( 'ropt_global_styles' => 'yes', 'ropt_classic_theme_styles' => 'no' ) ) );
		$this->assertSame( array( 'wp_enqueue_scripts:drop_classic_theme_styles' ), $hooked( array( 'ropt_global_styles' => 'no', 'ropt_classic_theme_styles' => 'yes' ) ) );

		// Ahead of core's own callbacks at 10, or they have already run.
		$hooked( array( 'ropt_global_styles' => 'yes', 'ropt_classic_theme_styles' => 'yes' ) );
		$this->assertSame( 1, WP_Stub::$priorities['wp_enqueue_scripts:drop_global_styles'] );
		$this->assertSame( 1, WP_Stub::$priorities['wp_enqueue_scripts:drop_classic_theme_styles'] );
	}

	/**
	 * Hover prefetching raises core's "auto" eagerness and nothing else.
	 *
	 * "auto" is what core really passes: it resolves it to prefetch on press
	 * only after the filter has run. A case fed "conservative" passed for a
	 * whole release while the switch did nothing on either shop.
	 *
	 * @return void
	 */
	public function test_prefetch_moves_from_press_to_hover(): void {
		$this->assertSame(
			array( 'mode' => 'auto', 'eagerness' => 'moderate' ),
			$this->delivery->prefetch_on_hover( array( 'mode' => 'auto', 'eagerness' => 'auto' ) )
		);
		$this->assertSame(
			array( 'mode' => 'prefetch', 'eagerness' => 'moderate' ),
			$this->delivery->prefetch_on_hover( array( 'mode' => 'prefetch', 'eagerness' => 'auto' ) )
		);
	}

	/**
	 * What core switched off, or someone else chose, stays.
	 *
	 * Null is core disabling it for a logged-in user or plain permalinks. An
	 * explicit eagerness, conservative included, is a decision. A prerender
	 * mode is never paired with hover, which would run pages nobody opens.
	 *
	 * @return void
	 */
	public function test_prefetch_leaves_other_choices_alone(): void {
		$this->assertNull( $this->delivery->prefetch_on_hover( null ) );
		$this->assertSame( 'x', $this->delivery->prefetch_on_hover( 'x' ) );

		foreach (
			array(
				array( 'mode' => 'auto', 'eagerness' => 'conservative' ),
				array( 'mode' => 'prefetch', 'eagerness' => 'eager' ),
				array( 'mode' => 'prerender', 'eagerness' => 'auto' ),
			) as $config
		) {
			$this->assertSame( $config, $this->delivery->prefetch_on_hover( $config ) );
		}
	}

	/**
	 * The WooCommerce half can keep a request on prefetch on press.
	 *
	 * @return void
	 */
	public function test_prefetch_asks_the_shop_half_first(): void {
		WP_Stub::$filters['ropt_prefetch_on_hover'] = false;

		$this->assertSame(
			array( 'mode' => 'auto', 'eagerness' => 'auto' ),
			$this->delivery->prefetch_on_hover( array( 'mode' => 'auto', 'eagerness' => 'auto' ) )
		);
	}

	/**
	 * A default set in the environment wins, like one in wp-config.php.
	 *
	 * @return void
	 */
	public function test_prefetch_steps_aside_for_an_environment_default(): void {
		putenv( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS=conservative' );

		try {
			$config = $this->delivery->prefetch_on_hover( array( 'mode' => 'auto', 'eagerness' => 'auto' ) );
		} finally {
			putenv( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS' );
		}

		$this->assertSame( 'auto', $config['eagerness'] );
	}

	/**
	 * A default set in wp-config.php wins.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_prefetch_steps_aside_for_a_wp_config_default(): void {
		define( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE', 'prefetch' );

		$this->assertSame(
			array( 'mode' => 'auto', 'eagerness' => 'auto' ),
			( new Delivery() )->prefetch_on_hover( array( 'mode' => 'auto', 'eagerness' => 'auto' ) )
		);
	}

	/**
	 * Hooked by its switch, which ships on on every host.
	 *
	 * @return void
	 */
	public function test_prefetch_is_hooked_only_by_its_switch(): void {
		WP_Stub::$options   = array( 'ropt_enabled' => 'yes' );
		WP_Stub::$callbacks = array();
		( new Delivery() )->register();
		$this->assertContains( 'wp_speculation_rules_configuration:prefetch_on_hover', WP_Stub::$callbacks, 'On by default, Kinsta or not.' );

		WP_Stub::$options   = array( 'ropt_enabled' => 'yes', 'ropt_speculative_moderate' => 'no' );
		WP_Stub::$callbacks = array();
		( new Delivery() )->register();
		$this->assertNotContains( 'wp_speculation_rules_configuration:prefetch_on_hover', WP_Stub::$callbacks );
	}

}
