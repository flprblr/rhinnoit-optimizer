<?php
/**
 * Preloads each screen size's own banner when a page opens with one per screen size.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Gives each screen size its own banner, early, and never the other one.
 *
 * Home pages built with WPBakery on Porto open with two banners: a section
 * for phones and a section for larger screens, each hidden on the other by
 * Bootstrap's display classes (d-none, d-md-block...). Every browser receives
 * both, because one cached page serves them all. Core counts the first as the
 * page's first image, so it leaves eager with fetchpriority="high" and
 * downloads on every screen, shown or not; the second is lazy, so where it is
 * the one shown it waits for layout. Measured on the two shops: desktops
 * fetching a 352 KB phone banner they never paint, and phones fetching a
 * hidden 339 KB desktop banner at high priority while theirs waited - a 12 to
 * 16 second paint, 7 seconds with this.
 *
 * The fix is one `<link rel="preload" as="image">` per banner, with the media
 * query of the screens that show it, so each screen fetches its own at once
 * and nothing else; both banners then turn lazy, which is what keeps the
 * hidden one from ever downloading.
 *
 * The rule is structural and reads only the page, never a file name or an id:
 *
 * - The queried page's own content, in the main loop, on the outermost
 *   the_content call. Porto and its builders run the_content inside the
 *   content - dynamic tags, blocks - and those nested calls never count.
 * - The content opens with the pair: nothing that renders comes before the
 *   first element whose visibility depends on the screen width, and the next
 *   element is the second one. Wrappers with nothing before the pair, such as
 *   WPBakery's content wrapper, are looked through.
 * - Each banner is the first `<img>` of its container, with a real src, a
 *   srcset and sizes, at least as large as core's own bar for a high-priority
 *   image, and no `<picture>` or other lazy loader around it.
 * - Visibility is read from the display classes of every element from the
 *   container down to the image, with Bootstrap's cascade, and the two images
 *   must split the screen widths exactly between them at sm, md or lg. Those
 *   three (576, 768 and 992 px) are literals in Porto and Bootstrap's own
 *   defaults; xl and xxl follow Porto's container width as of the last time
 *   its stylesheet was compiled, so a split there is never trusted. d-xs-*
 *   exists in no stylesheet and only has to agree with the smallest screens;
 *   a hiding this cannot read - a hidden attribute, an inline display,
 *   WPBakery's or Elementor's own hidden classes - leaves the page alone.
 *
 * Anything short of that returns the content exactly as it came. The links
 * go at the very start of the content, which is where the first banner
 * starts: nothing that renders comes before it. Each names its image's own
 * src, srcset and sizes; "auto" leaves sizes, where only a lazy image may
 * carry it and where it would let the image pick another candidate than the
 * one preloaded, and skip-lazy keeps a JavaScript lazy loader such as Smush's
 * off both images.
 *
 * Smush's Local WebP rewrites src, srcset and href but not imagesrcset, so it
 * is told to rewrite that attribute too, with the same per-URL decision it
 * makes for the image. Its CDN rebuilds srcset and sizes itself and its LCP
 * preload would duplicate this one, so either of those makes this step aside.
 */
final class Viewport_Hero {

	/**
	 * Priority on the_content.
	 *
	 * After do_shortcode (11), which prints the builder's markup, and core's
	 * wp_filter_content_tags (12), which sets loading and fetchpriority. An
	 * image made lazy before 12 is not counted by core, which would then hand
	 * the page's one high priority to the next image.
	 */
	const int PRIORITY = 100;

	/**
	 * Where Bootstrap's display classes switch, in pixels: sm, md, lg, xl, xxl.
	 *
	 * They cut the screen widths into six ranges, numbered 0 to 5 from the
	 * narrowest. A visibility is held as one bit per range, set where it shows.
	 */
	const array BREAKPOINTS = array( 576, 768, 992, 1220, 1400 );

	/**
	 * How many of BREAKPOINTS, from the first, are the same on every site.
	 */
	const int FIXED_BREAKPOINTS = 3;

	/**
	 * All six ranges: shown on every screen.
	 */
	const int EVERY_WIDTH = 0b111111;

	/**
	 * The range each display class infix applies from.
	 */
	const array INFIXES = array(
		''    => 0,
		'sm'  => 1,
		'md'  => 2,
		'lg'  => 3,
		'xl'  => 4,
		'xxl' => 5,
	);

	/**
	 * The values Bootstrap's display classes take.
	 */
	const array DISPLAYS = array( 'none', 'inline', 'inline-block', 'block', 'grid', 'inline-grid', 'table', 'table-row', 'table-cell', 'flex', 'inline-flex' );

	/**
	 * Elements without a closing tag.
	 */
	const array VOID_TAGS = array( 'AREA', 'BASE', 'BASEFONT', 'BGSOUND', 'BR', 'COL', 'EMBED', 'FRAME', 'HR', 'IMG', 'INPUT', 'KEYGEN', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR' );

	/**
	 * Elements the Tag Processor returns as one token, content and closing tag included.
	 */
	const array ATOMIC_TAGS = array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'XMP' );

	/**
	 * Elements that render nothing, allowed before and between the two containers.
	 */
	const array SILENT_TAGS = array( 'SCRIPT', 'STYLE', 'LINK', 'META' );

	/**
	 * The queried page, read on wp; 0 when the request is not for a page.
	 *
	 * @var int
	 */
	private int $page = 0;

	/**
	 * Whether the page's own content has been looked at.
	 *
	 * @var bool
	 */
	private bool $done = false;

	/**
	 * Hooks the correction.
	 *
	 * Porto's own lazy loader moves every src into a data attribute after
	 * this runs and switches core's lazy loading off, so the correction steps
	 * aside while it is on, as Delivery's lazy-load correction does.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'viewport_hero' ) || Delivery::porto_covers( 'lazyload' ) ) {
			return;
		}

		add_action( 'wp', array( $this, 'remember_page' ) );
		add_filter( 'the_content', array( $this, 'preload_heroes' ), self::PRIORITY );
		add_filter( 'wp_smush_get_image_attribute_names', array( $this, 'smush_rewrites_preloads' ) );
	}

	/**
	 * Notes which page was queried, once the query has run.
	 *
	 * A static front page is a page too. Smush is asked here rather than in
	 * register(), because by now it has loaded.
	 *
	 * @return void
	 */
	public function remember_page(): void {
		$this->page = is_page() && ! self::smush_serves_images() ? (int) get_queried_object_id() : 0;
	}

	/**
	 * Preloads each screen size's banner when the page's content opens with a pair.
	 *
	 * @param mixed $content The page's content, rendered.
	 * @return mixed
	 */
	public function preload_heroes( mixed $content ): mixed {
		if ( $this->done || 0 >= $this->page || ! is_string( $content ) || 1 !== self::content_depth() ) {
			return $content;
		}

		if ( ! in_the_loop() || ! is_main_query() || get_the_ID() !== $this->page ) {
			return $content;
		}

		$this->done = true;

		return self::rewrite( $content );
	}

	/**
	 * Has Smush rewrite the preloads' candidate list as it rewrites the image's srcset.
	 *
	 * Local WebP decides URL by URL whether a .webp exists, for every
	 * attribute in this list, so the preload and the image end up naming the
	 * same files. Without it the preload would fetch the original PNG while
	 * the image loads the WebP: two downloads of the banner.
	 *
	 * @param mixed $names Attribute names Smush rewrites.
	 * @return mixed
	 */
	public function smush_rewrites_preloads( mixed $names ): mixed {
		if ( ! is_array( $names ) || in_array( 'imagesrcset', $names, true ) ) {
			return $names;
		}

		$names[] = 'imagesrcset';

		return $names;
	}

	/**
	 * Whether Smush serves the images itself, from its CDN or with its own preload.
	 *
	 * The CDN rebuilds srcset and sizes from its own sizes, so no preload
	 * built from the page can name what the image then loads; its critical
	 * image preload would print a second one. Smush's own answer counts, since
	 * a free install keeps a Pro CDN setting it no longer runs; its stored
	 * settings stand in when that API is missing.
	 *
	 * @return bool
	 */
	private static function smush_serves_images(): bool {
		if ( ! defined( 'WP_SMUSH_VERSION' ) ) {
			return false;
		}

		$instance = array( 'Smush\Core\Settings', 'get_instance' );

		if ( is_callable( $instance ) ) {
			$settings = call_user_func( $instance );

			if ( is_object( $settings ) && method_exists( $settings, 'is_cdn_active' ) && method_exists( $settings, 'is_lcp_preload_enabled' ) ) {
				return (bool) $settings->is_cdn_active() || (bool) $settings->is_lcp_preload_enabled();
			}
		}

		$smush = get_option( 'wp-smush-settings', array() );

		return is_array( $smush ) && ( ! empty( $smush['cdn'] ) || ! empty( $smush['preload_images'] ) );
	}

	/**
	 * How many the_content filters are running, this one included.
	 *
	 * Read from core's own stack of running hooks: a builder that renders a
	 * block inside the content calls the_content again, and that inner call
	 * sees two.
	 *
	 * @return int
	 */
	private static function content_depth(): int {
		$running = $GLOBALS['wp_current_filter'] ?? array();

		return is_array( $running ) ? count( array_keys( $running, 'the_content', true ) ) : 0;
	}

	/**
	 * Adds the preloads and quiets both banners, or returns the content untouched.
	 *
	 * @param string $html The page's content.
	 * @return string
	 */
	private static function rewrite( string $html ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		$heroes    = self::find_heroes( $processor );
		$split     = null === $heroes ? null : self::split( $heroes[0]['visible'], $heroes[1]['visible'] );

		if ( null === $heroes || null === $split ) {
			return $html;
		}

		$links = '';

		foreach ( $heroes as $hero ) {
			$media  = 1 === ( $hero['visible'] & 1 ) ? sprintf( '(max-width: %.2Fpx)', $split - 0.02 ) : sprintf( '(min-width: %dpx)', $split );
			$links .= self::preload_link( $hero, $media ) . "\n";
		}

		return $links . $processor->get_updated_html();
	}

	/**
	 * Walks the opening of the content to the first image of each of the two containers.
	 *
	 * Each banner is marked on the processor as it is found - lazy, no
	 * priority, skip-lazy, sizes without "auto" - and the caller keeps those
	 * marks only when the pair holds.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor at the start of the content.
	 * @return array<int, array{src: string, srcset: string, sizes: string, visible: int}>|null
	 */
	private static function find_heroes( \WP_HTML_Tag_Processor $processor ): ?array {
		// Open elements, outermost first: tag name and visibility.
		$open = array();
		// Index in $open of the container being walked; -1 outside both.
		$block = -1;
		// Containers entered so far.
		$blocks = 0;
		$heroes = array();

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( '#text' === $type ) {
				// Text before or between the containers renders: no pair at the top.
				if ( 0 > $block && '' !== trim( $processor->get_modifiable_text() ) ) {
					return null;
				}

				continue;
			}

			// Comments, and whatever else is not an element.
			if ( '#tag' !== $type ) {
				continue;
			}

			$tag = (string) $processor->get_tag();

			if ( $processor->is_tag_closer() ) {
				$closed = array_pop( $open );

				// A wrapper that ends before the pair, or one container alone, or broken nesting.
				if ( 0 > $block || null === $closed || $tag !== $closed['tag'] ) {
					return null;
				}

				if ( count( $open ) === $block ) {
					if ( count( $heroes ) < $blocks ) {
						return null;
					}

					$block = -1;
				}

				continue;
			}

			$childless = in_array( $tag, self::VOID_TAGS, true ) || in_array( $tag, self::ATOMIC_TAGS, true ) || ( $processor->has_self_closing_flag() && self::is_foreign( $open, $tag ) );

			if ( 0 > $block ) {
				if ( in_array( $tag, self::SILENT_TAGS, true ) ) {
					continue;
				}

				$visible = $childless ? null : self::visibility( $processor );

				// Something that renders before the pair. An element that never shows
				// becomes a container whose banner no screen shows, and fails the split.
				if ( null === $visible ) {
					return null;
				}

				$open[] = array(
					'tag'     => $tag,
					'visible' => $visible,
				);

				if ( self::EVERY_WIDTH !== $visible ) {
					$block = count( $open ) - 1;
					++$blocks;
				} elseif ( 0 < $blocks ) {
					// The element after the first container shows everywhere: no pair.
					return null;
				}

				continue;
			}

			if ( 'IMG' === $tag && count( $heroes ) < $blocks && ! self::is_inside( $open, $block, array( 'NOSCRIPT', 'TEMPLATE' ) ) ) {
				$hero = self::is_inside( $open, $block, array( 'PICTURE' ) ) ? null : self::read_hero( $processor, array_slice( $open, $block ) );

				if ( null === $hero ) {
					return null;
				}

				$heroes[] = $hero;

				if ( 2 === count( $heroes ) ) {
					return $heroes;
				}

				continue;
			}

			if ( ! $childless ) {
				$open[] = array(
					'tag'     => $tag,
					'visible' => self::visibility( $processor ),
				);
			}
		}

		// The content ended, or the parse stopped, before both images.
		return null;
	}

	/**
	 * Reads a banner's attributes and marks it lazy, if it can be preloaded.
	 *
	 * @param \WP_HTML_Tag_Processor                            $processor Processor on the image.
	 * @param array<int, array{tag: string, visible: int|null}> $chain     Its container and every element down to it.
	 * @return array{src: string, srcset: string, sizes: string, visible: int}|null
	 */
	private static function read_hero( \WP_HTML_Tag_Processor $processor, array $chain ): ?array {
		$visible = self::visibility( $processor );

		foreach ( $chain as $element ) {
			$visible = null === $visible || null === $element['visible'] ? null : $visible & $element['visible'];
		}

		$src    = $processor->get_attribute( 'src' );
		$srcset = $processor->get_attribute( 'srcset' );
		$sizes  = $processor->get_attribute( 'sizes' );
		$width  = $processor->get_attribute( 'width' );
		$height = $processor->get_attribute( 'height' );

		if ( null === $visible || ! is_string( $src ) || ! is_string( $srcset ) || ! is_string( $sizes ) || ! is_string( $width ) || ! is_string( $height ) ) {
			return null;
		}

		// A placeholder, or an image fetched in CORS mode, which a plain preload would not match.
		if ( '' === trim( $srcset ) || '' === trim( $src ) || str_starts_with( strtolower( trim( $src ) ), 'data:' ) || null !== $processor->get_attribute( 'crossorigin' ) ) {
			return null;
		}

		// Another lazy loader keeps the real image in a data attribute.
		foreach ( $processor->get_attribute_names_with_prefix( 'data-' ) ?? array() as $name ) {
			if ( str_contains( $name, 'src' ) || in_array( $name, array( 'data-oi', 'data-original' ), true ) ) {
				return null;
			}
		}

		/**
		 * Core's own bar for a high-priority image, read as core reads it.
		 *
		 * Documented in wp-includes/media.php.
		 *
		 * @since 6.3.0
		 */
		$least = apply_filters( 'wp_min_priority_img_pixels', 50000 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook.
		$least = is_numeric( $least ) ? (int) $least : 50000;

		if ( ! ctype_digit( trim( $width ) ) || ! ctype_digit( trim( $height ) ) || (int) $width * (int) $height < $least ) {
			return null;
		}

		$own = self::without_auto( $sizes );

		if ( '' === trim( $own ) ) {
			return null;
		}

		$processor->set_attribute( 'loading', 'lazy' );
		$processor->remove_attribute( 'fetchpriority' );
		$processor->add_class( 'skip-lazy' );

		if ( $own !== $sizes ) {
			$processor->set_attribute( 'sizes', $own );
		}

		return array(
			'src'     => $src,
			'srcset'  => $srcset,
			'sizes'   => $own,
			'visible' => $visible,
		);
	}

	/**
	 * The screen widths the element at the cursor shows on, from its own attributes.
	 *
	 * Bootstrap's cascade: every display class is !important, one breakpoint
	 * overrides the ones below it, and a class with no breakpoint applies
	 * from zero.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor on an opening tag.
	 * @return int|null One bit per range of widths; null when something this cannot read may hide it.
	 */
	private static function visibility( \WP_HTML_Tag_Processor $processor ): ?int {
		$tag = $processor->get_tag();

		// Content that never renders as it stands: a banner there is no banner.
		if ( in_array( $tag, array( 'NOSCRIPT', 'TEMPLATE' ), true ) || ( in_array( $tag, array( 'DIALOG', 'DETAILS' ), true ) && null === $processor->get_attribute( 'open' ) ) ) {
			return null;
		}

		$style = $processor->get_attribute( 'style' );

		// The display classes are !important and win over any inline display but a hiding one.
		if ( null !== $processor->get_attribute( 'hidden' ) || ( is_string( $style ) && preg_match( '/display\s*:\s*none/i', $style ) ) ) {
			return null;
		}

		$class    = $processor->get_attribute( 'class' );
		$classes  = is_string( $class ) ? preg_split( '/[ \t\f\r\n]+/', $class, -1, PREG_SPLIT_NO_EMPTY ) : array();
		$rules    = array();
		$smallest = array();

		foreach ( is_array( $classes ) ? $classes : array() as $name ) {
			// WPBakery's, Elementor's and Bootstrap 3's own per-screen hiding.
			if ( preg_match( '/^(?:vc_|elementor-)?(?:hidden|visible)-/', $name ) ) {
				return null;
			}

			if ( ! str_starts_with( $name, 'd-' ) ) {
				continue;
			}

			if ( ! preg_match( '/^d-(?:(xs|sm|md|lg|xl|xxl|print)-)?([a-z-]+)$/', $name, $match ) || ! in_array( $match[2], self::DISPLAYS, true ) ) {
				return null;
			}

			$shows = 'none' !== $match[2];

			if ( 'print' === $match[1] ) {
				continue;
			}

			if ( 'xs' === $match[1] ) {
				$smallest[] = $shows;

				continue;
			}

			$from = self::INFIXES[ $match[1] ];

			// Two classes for the same breakpoint that disagree: the stylesheet's order decides.
			if ( isset( $rules[ $from ] ) && $rules[ $from ] !== $shows ) {
				return null;
			}

			$rules[ $from ] = $shows;
		}

		$visible = 0;
		$shows   = true;

		foreach ( self::INFIXES as $range ) {
			$shows = $rules[ $range ] ?? $shows;

			if ( $shows ) {
				$visible |= 1 << $range;
			}
		}

		foreach ( $smallest as $shows ) {
			if ( ( 1 === ( $visible & 1 ) ) !== $shows ) {
				return null;
			}
		}

		return $visible;
	}

	/**
	 * The width at which one banner hands over to the other.
	 *
	 * @param int $first  Widths the first banner shows on.
	 * @param int $second Widths the second banner shows on.
	 * @return int|null Null unless they share every width between them, at a breakpoint every site agrees on.
	 */
	private static function split( int $first, int $second ): ?int {
		if ( 0 !== ( $first & $second ) || self::EVERY_WIDTH !== ( $first | $second ) ) {
			return null;
		}

		$narrow = 1 === ( $first & 1 ) ? $first : $second;

		for ( $range = 1; $range <= self::FIXED_BREAKPOINTS; $range++ ) {
			if ( ( 1 << $range ) - 1 === $narrow ) {
				return self::BREAKPOINTS[ $range - 1 ];
			}
		}

		return null;
	}

	/**
	 * The preload for one banner.
	 *
	 * The URLs are the image's own, escaped as attribute text: esc_url()
	 * rewrites some URLs, and a preload that names another URL than the image
	 * is a second download. There is no href: WebKit before 17.2 ignores
	 * imagesrcset and would preload the src - the full-size file, where the
	 * image then picks a smaller candidate on the same screen. A browser that
	 * cannot read imagesrcset gets no preload, and the lazy banner it had.
	 *
	 * @param array{src: string, srcset: string, sizes: string, visible: int} $hero  The banner.
	 * @param string                                                          $media The screens it shows on.
	 * @return string
	 */
	private static function preload_link( array $hero, string $media ): string {
		return sprintf(
			'<link rel="preload" as="image" imagesrcset="%s" imagesizes="%s" media="%s" fetchpriority="high">',
			esc_attr( $hero['srcset'] ),
			esc_attr( $hero['sizes'] ),
			esc_attr( $media )
		);
	}

	/**
	 * Sizes without the leading "auto" core gives a lazy image.
	 *
	 * The same test core makes in wp_sizes_attribute_includes_valid_auto().
	 *
	 * @param string $sizes The image's sizes.
	 * @return string
	 */
	private static function without_auto( string $sizes ): string {
		$parts = explode( ',', $sizes, 2 );

		if ( 'auto' !== strtolower( trim( $parts[0], " \t\f\r\n" ) ) ) {
			return $sizes;
		}

		return ltrim( $parts[1] ?? '', " \t\f\r\n" );
	}

	/**
	 * Whether the walk is inside one of these elements, below the container.
	 *
	 * @param array<int, array{tag: string, visible: int|null}> $open  Open elements.
	 * @param int                                               $block Index of the container.
	 * @param array<int, string>                                $tags  Tag names.
	 * @return bool
	 */
	private static function is_inside( array $open, int $block, array $tags ): bool {
		foreach ( array_slice( $open, $block ) as $element ) {
			if ( in_array( $element['tag'], $tags, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a self-closing tag really closes itself: only inside SVG or MathML.
	 *
	 * @param array<int, array{tag: string, visible: int|null}> $open Open elements.
	 * @param string                                            $tag  The tag.
	 * @return bool
	 */
	private static function is_foreign( array $open, string $tag ): bool {
		if ( 'SVG' === $tag || 'MATH' === $tag ) {
			return true;
		}

		foreach ( $open as $element ) {
			if ( 'SVG' === $element['tag'] || 'MATH' === $element['tag'] ) {
				return true;
			}
		}

		return false;
	}
}
