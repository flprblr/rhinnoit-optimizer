<?php
/**
 * Delivery corrections for the storefront: images, fonts and generated CSS.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the plugin measured on cl.hoka.com, which runs the same theme.
 *
 * The design rule there is worth keeping: nothing static. No block names a
 * file, a path or a version. Each one decides from what WordPress already
 * knows at that moment — the real weight of a file on disk, its modification
 * time, the content of a stylesheet. An earlier version of that plugin carried
 * two hand-written lists of paths to drop and defer stylesheets; they were
 * removed because they measured zero and because any update that renamed a
 * file left them inert without warning.
 *
 * Everything here runs on `wp_head` for every storefront request, so each
 * method has to degrade to "no change" rather than to a fatal error.
 */
final class Delivery {

	/**
	 * Handle of the inline body reset kept when global styles go.
	 */
	const string BODY_RESET = 'ropt-body-reset';

	/**
	 * Largest stylesheet that is worth inlining, in bytes.
	 */
	const int INLINE_LIMIT = 16384;

	/**
	 * Throttle key for a stylesheet that resolved but could not be read.
	 */
	const string UNREADABLE_FLAG = 'ropt_font_sheet_unreadable';

	/**
	 * Throttle key for a stylesheet whose faces could not be rewritten.
	 *
	 * A key of its own, because a shop can hit one of these without the other
	 * and a shared window would let the first failure hide the second for an
	 * hour.
	 */
	const string REWRITE_FLAG = 'ropt_font_rewrite_failed';

	/**
	 * Resolved filesystem paths, keyed by the URL they came from.
	 *
	 * A stylesheet URL is resolved once per request even when several filters
	 * ask for it.
	 *
	 * @var array<string, string>
	 */
	private static array $paths = array();

	/**
	 * Hooks the corrections.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( Settings::feature( 'srcset_prune' ) ) {
			add_filter( 'wp_calculate_image_srcset', array( $this, 'drop_oversized_copies' ), 10, 5 );
		}

		// Porto's wizard ships its own JavaScript lazy loader; the native
		// threshold has nothing left to decide once that is on.
		if ( Settings::feature( 'lazy_first_only' ) && ! self::porto_covers( 'lazyload' ) ) {
			add_filter( 'wp_omit_loading_attr_threshold', array( $this, 'only_first_image_eager' ) );
		}

		if ( Settings::feature( 'font_inline' ) ) {
			add_filter( 'style_loader_tag', array( $this, 'inline_font_stylesheets' ), 10, 3 );
		}

		if ( Settings::feature( 'css_versioning' ) ) {
			add_filter( 'style_loader_src', array( $this, 'version_generated_css' ), 20 );
		}

		// Priority 1, ahead of core's own callbacks at 10 on the same hook.
		if ( Settings::feature( 'global_styles' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'drop_global_styles' ), 1 );
		}

		if ( Settings::feature( 'classic_theme_styles' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'drop_classic_theme_styles' ), 1 );
		}

		if ( Settings::feature( 'speculative_moderate' ) ) {
			add_filter( 'wp_speculation_rules_configuration', array( $this, 'prefetch_on_hover' ) );
		}
	}

	/**
	 * Whether the Porto Speed Optimize Wizard already handles something.
	 *
	 * The wizard keeps every answer in one option, so this reads the site as it
	 * is configured today rather than assuming a theme or a setup. Only while
	 * Porto is the theme: Porto never deletes the option, and its loaders live
	 * in the theme, so a site that left Porto with its lazy load on would
	 * otherwise lose four corrections to a loader that no longer runs. The
	 * template is an option, so this holds in register() too.
	 *
	 * @param string $key Key inside porto_settings_optimize.
	 * @return bool
	 */
	public static function porto_covers( string $key ): bool {
		if ( 'porto' !== get_template() ) {
			return false;
		}

		$porto = get_option( 'porto_settings_optimize', array() );

		return is_array( $porto ) && ! empty( $porto[ $key ] );
	}

	/**
	 * Drops srcset candidates that are heavier than a larger one.
	 *
	 * WordPress regenerates WebP thumbnails at a quality that can produce files
	 * heavier than the original, and the browser then picks the heavy one
	 * because it is the right width. A candidate is dropped only when another
	 * one has MORE pixels and LESS weight: such a candidate is not the best
	 * choice on any screen, at any density. The largest is never dropped, so
	 * the resolution ceiling does not move and nothing can look blurrier.
	 *
	 * @param mixed $sources       Candidates keyed by width.
	 * @param mixed $size_array    Unused.
	 * @param mixed $image_src     Unused.
	 * @param mixed $image_meta    Unused.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function drop_oversized_copies( mixed $sources, mixed $size_array, mixed $image_src, mixed $image_meta, mixed $attachment_id ): mixed {
		if ( ! is_array( $sources ) || 2 > count( $sources ) ) {
			return $sources;
		}

		$weights = $this->candidate_weights( $sources, (int) $attachment_id );

		if ( 2 > count( $weights ) ) {
			return $sources;
		}

		foreach ( $weights as $width => $weight ) {
			foreach ( $weights as $other_width => $other_weight ) {
				if ( $other_width > $width && $other_weight <= $weight ) {
					unset( $sources[ $width ] );

					break;
				}
			}
		}

		return $sources;
	}

	/**
	 * Weight in bytes of each srcset candidate, keyed by width.
	 *
	 * @param array<int|string, mixed> $sources       Candidates keyed by width, as the filter passes them.
	 * @param int                      $attachment_id Attachment ID.
	 * @return array<int, int>
	 */
	private function candidate_weights( array $sources, int $attachment_id ): array {
		/*
		 * Held for the request because one image is usually rendered several
		 * times on the same page — a product grid, a carousel that clones its
		 * slides — and every one of those resolves the same srcset. Without
		 * this, a category page of two dozen products costs hundreds of stat
		 * calls, on exactly the pages this is meant to speed up.
		 */
		static $cache = array();

		$key = $attachment_id . ':' . implode( ',', array_keys( $sources ) );

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$cache[ $key ] = array();

		$original = 0 < $attachment_id ? get_attached_file( $attachment_id ) : '';
		$folder   = is_string( $original ) && '' !== $original ? dirname( $original ) : '';

		if ( '' === $folder || ! is_dir( $folder ) ) {
			return $cache[ $key ];
		}

		$weights = array();

		foreach ( $sources as $width => $source ) {
			if ( ! is_array( $source ) || ! isset( $source['url'] ) || ! is_string( $source['url'] ) ) {
				continue;
			}

			$url_path = wp_parse_url( $source['url'], PHP_URL_PATH );

			if ( ! is_string( $url_path ) ) {
				continue;
			}

			$file = $folder . '/' . wp_basename( $url_path );

			if ( ! is_readable( $file ) || is_dir( $file ) ) {
				continue;
			}

			$size = filesize( $file );

			if ( is_int( $size ) && 0 < $size ) {
				$weights[ (int) $width ] = $size;
			}
		}

		$cache[ $key ] = $weights;

		return $weights;
	}

	/**
	 * Leaves only the first image of the page out of lazy loading.
	 *
	 * WordPress exempts the first images of every page from `loading="lazy"`,
	 * with a default threshold of three — exactly the three slides of a banner
	 * carousel, which then all download at once even though two are off-screen.
	 * The first image stays eager and still gets `fetchpriority="high"` from
	 * core. The rule is positional, not nominal: it holds whatever banner is
	 * uploaded and whichever plugin renders it.
	 *
	 * @return int
	 */
	public function only_first_image_eager(): int {
		return 1;
	}

	/**
	 * Lets the browser prefetch a link while the pointer rests on it.
	 *
	 * WordPress 6.8 prints speculation rules on every page and hands this
	 * filter mode and eagerness "auto", which it resolves afterwards to a
	 * prefetch on press - little time to gain. Moderate starts after a short
	 * hover, the setting Kinsta recommends, so the next page is often in
	 * memory when the click lands. Only an "auto" eagerness is raised: an
	 * explicit value is somebody's choice, and so is a default set in
	 * wp-config.php or the environment (WordPress 7.1's
	 * WP_SPECULATIVE_LOADING_DEFAULT_* settings), which always win. The mode is
	 * never touched, so this can never become a prerender. Core's own
	 * exclusions still apply: admin, uploads, links with a query string,
	 * rel="nofollow".
	 *
	 * Null means core switched the feature off for this request (a logged-in
	 * user, plain permalinks), and that stays off.
	 *
	 * @param mixed $config Mode and eagerness, or null when disabled.
	 * @return mixed
	 */
	public function prefetch_on_hover( mixed $config ): mixed {
		if ( ! is_array( $config ) || self::speculation_is_configured_elsewhere() ) {
			return $config;
		}

		if ( 'auto' !== ( $config['eagerness'] ?? 'auto' ) || ! in_array( $config['mode'] ?? 'auto', array( 'auto', 'prefetch' ), true ) ) {
			return $config;
		}

		/**
		 * Whether this request may prefetch on hover.
		 *
		 * The WooCommerce half answers no for a visitor with a cart: every
		 * page they request is uncached, so each hover would cost a render.
		 *
		 * @since 1.0.0
		 * @param bool $allowed Whether to raise the eagerness.
		 */
		if ( ! apply_filters( 'ropt_prefetch_on_hover', true ) ) {
			return $config;
		}

		$config['eagerness'] = 'moderate';

		return $config;
	}

	/**
	 * Whether wp-config.php or the environment set the speculation defaults.
	 *
	 * @return bool
	 */
	private static function speculation_is_configured_elsewhere(): bool {
		foreach ( array( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS', 'WP_SPECULATIVE_LOADING_DEFAULT_MODE' ) as $name ) {
			if ( defined( $name ) || false !== getenv( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drops the block editor's global styles on a theme that does not use them.
	 *
	 * Core prints about 11 KB of inline CSS on every page - the theme.json
	 * presets and the classes that consume them - whatever the theme is. On
	 * the three sites this was measured on, 103 to 131 presets were declared
	 * and not one was referenced anywhere else on the page.
	 *
	 * The same stylesheet also carries the base layout rules of core blocks
	 * (.is-layout-flex, .is-layout-grid and friends). A post or page built with
	 * the block editor - including the WooCommerce cart and checkout blocks -
	 * loses its columns and rows without them. That is why the setting says to
	 * check such pages: it ships on everywhere (owner's decision, 2026-10-16),
	 * and is offered only on a classic theme.
	 *
	 * Both call sites go: the head and, for classic themes that load block
	 * assets separately, the footer.
	 *
	 * One rule of the sheet stays: core's `:where(body){margin:0}`. A theme
	 * without a reset of its own then gets the browser's 8 px margin around
	 * every page; Hello Elementor without its reset.css did, on grupoferia
	 * (2026-10-19). At zero specificity any theme rule still wins.
	 *
	 * @return void
	 */
	public function drop_global_styles(): void {
		if ( self::is_block_theme() ) {
			return;
		}

		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );

		wp_register_style( self::BODY_RESET, false, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- No file: an inline rule only.
		wp_enqueue_style( self::BODY_RESET );
		wp_add_inline_style( self::BODY_RESET, ':where(body){margin:0}' );
	}

	/**
	 * Drops the small stylesheet core adds for block content on classic themes.
	 *
	 * The classic-theme-styles sheet styles the button and pullquote blocks for
	 * themes that do not. Core wires it both on wp_enqueue_scripts and on
	 * enqueue_block_assets, which wp_enqueue_scripts fires on the front end, so
	 * both go or the sheet still prints.
	 *
	 * @return void
	 */
	public function drop_classic_theme_styles(): void {
		if ( self::is_block_theme() ) {
			return;
		}

		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_classic_theme_styles' );
		remove_action( 'enqueue_block_assets', 'wp_enqueue_classic_theme_styles' );
	}

	/**
	 * Whether the active theme is a block theme, which is built on these styles.
	 *
	 * Asked from inside the callbacks rather than in register(): whether the
	 * active theme is a block theme is only known once the theme has loaded,
	 * which is after plugins_loaded. Hence removing core's callbacks from
	 * inside a callback on the same hook - WP_Hook handles a removal made while
	 * the hook is running, and these run at priority 1, ahead of core's 10.
	 *
	 * @return bool
	 */
	private static function is_block_theme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * Inlines font declaration stylesheets and gives them font-display:swap.
	 *
	 * A stylesheet that only declares `@font-face` is small and blocks painting
	 * without contributing layout. Inlining it removes one request from the
	 * critical chain, and `font-display:swap` keeps the text visible while the
	 * font file downloads.
	 *
	 * It is identified by content, not by name or path: a small sheet under
	 * uploads whose rules are all `@font-face`. That describes a font
	 * declaration file no matter which plugin or theme generated it.
	 *
	 * @param mixed $tag    The `<link>` tag.
	 * @param mixed $handle Stylesheet handle.
	 * @param mixed $href   Stylesheet URL.
	 * @return mixed
	 */
	public function inline_font_stylesheets( mixed $tag, mixed $handle, mixed $href ): mixed {
		if ( ! is_string( $tag ) || ! is_string( $href ) || is_admin() ) {
			return $tag;
		}

		$path = $this->path_from_url( $href );

		if ( '' === $path ) {
			return $tag;
		}

		/*
		 * Checked again rather than trusted from the resolver, which caches its
		 * answer for the request: a deploy that swaps the uploads directory
		 * mid-request leaves a path that was readable a moment ago, and
		 * filesize() on it raises a PHP warning - on a storefront page, on
		 * every stylesheet, for every visitor.
		 */
		if ( ! is_readable( $path ) ) {
			Logger::error_once(
				self::UNREADABLE_FLAG,
				sprintf( 'Could not read %s, so its fonts are left as a request.', $path )
			);

			return $tag;
		}

		$size = filesize( $path );

		/*
		 * Split from the size test on purpose. "Too big to inline" is a
		 * decision and says nothing; a file that resolved and then could not be
		 * measured is a failure, and it looks identical from outside - the font
		 * simply stops being inlined. Two of the five corrections on these
		 * shops turned out to be doing nothing for months, so a failure now
		 * leaves a trace.
		 */
		if ( ! is_int( $size ) ) {
			Logger::error_once(
				self::UNREADABLE_FLAG,
				sprintf( 'Could not measure %s, so its fonts are left as a request.', $path )
			);

			return $tag;
		}

		if ( $size > self::INLINE_LIMIT ) {
			return $tag;
		}

		/*
		 * A local file that was already checked with is_readable(). WP_Filesystem
		 * would load its whole abstraction on a storefront request to read a few
		 * kilobytes, and it can ask for credentials.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$css = file_get_contents( $path );

		if ( ! is_string( $css ) ) {
			Logger::error_once(
				self::UNREADABLE_FLAG,
				sprintf( 'Could not read %s, so its fonts are left as a request.', $path )
			);

			return $tag;
		}

		// Not a font sheet is a decision, not a failure: nothing to report.
		if ( ! $this->is_font_stylesheet( $css ) ) {
			return $tag;
		}

		/*
		 * Read from the sheet, never from a setting that claims to have
		 * rewritten it. This used to stand aside whenever Porto's "Swap for
		 * Font Display" option was on, and on cl.hoka.com that option is on
		 * while the sheet it serves declares no font-display at all, so the
		 * plugin skipped the rewrite for a promise nothing had kept. There is
		 * no need for the guard either way: add_font_display() leaves any face
		 * that already declares one exactly as it found it, so a sheet the
		 * wizard really did cover comes back unchanged.
		 */
		$body = $this->add_font_display( $css );

		/*
		 * preg_replace_callback() answers null when PCRE gives up, which a
		 * large sheet can do through the backtrack limit. Falling back to the
		 * original request is right; doing it without a word is what hid this
		 * kind of thing before.
		 */
		if ( '' === $body ) {
			Logger::error_once(
				self::REWRITE_FLAG,
				sprintf( 'Could not add font-display to %s, so its fonts are left as a request.', $path )
			);

			return $tag;
		}

		return '<style id="ropt-fonts-' . esc_attr( (string) $handle ) . '">' . $body . "</style>\n";
	}

	/**
	 * Whether every rule in this stylesheet is an `@font-face` block.
	 *
	 * Checked by removing the `@font-face` blocks and seeing whether anything
	 * rule-shaped is left, so the file is recognised by what it is.
	 *
	 * @param string $css Stylesheet content.
	 * @return bool
	 */
	private function is_font_stylesheet( string $css ): bool {
		if ( false === stripos( $css, '@font-face' ) ) {
			return false;
		}

		$rest = preg_replace( '/@font-face\s*\{[^}]*\}/i', '', $css );

		if ( ! is_string( $rest ) ) {
			return false;
		}

		$rest = preg_replace( '#/\*.*?\*/#s', '', $rest );

		return is_string( $rest ) && false === strpos( $rest, '{' );
	}

	/**
	 * Adds `font-display:swap` to every `@font-face` that does not declare it.
	 *
	 * @param string $css Stylesheet content.
	 * @return string Empty when the replacement failed.
	 */
	private function add_font_display( string $css ): string {
		$result = preg_replace_callback(
			'/@font-face\s*\{[^}]*\}/i',
			static function ( array $block ): string {
				$text = $block[0];

				if ( false !== stripos( $text, 'font-display' ) ) {
					return $text;
				}

				$close = strrpos( $text, '}' );

				if ( false === $close ) {
					return $text;
				}

				$inside = rtrim( substr( $text, 0, $close ) );

				if ( ';' !== substr( $inside, -1 ) ) {
					$inside .= ';';
				}

				return $inside . 'font-display:swap;}';
			},
			$css
		);

		return is_string( $result ) ? $result : '';
	}

	/**
	 * Versions generated CSS by its modification time.
	 *
	 * Files under uploads that are served as CSS are generated or uploaded, not
	 * versioned releases: their content changes without the enqueued version
	 * number changing. Porto rewrites its own on every options save but still
	 * serves them as a fixed `?ver=`, cached for a year without revalidating,
	 * so a browser that already had one keeps the stale copy. On cl.hoka.com
	 * that is exactly what broke the site typography: the cached CSS kept
	 * asking for a font family the regenerated sheet no longer declared.
	 *
	 * @param mixed $src Stylesheet URL.
	 * @return mixed
	 */
	public function version_generated_css( mixed $src ): mixed {
		if ( ! is_string( $src ) || self::has_its_own_buster( $src ) ) {
			return $src;
		}

		$path = $this->path_from_url( $src );

		if ( '' === $path ) {
			return $src;
		}

		$modified = filemtime( $path );

		if ( ! is_int( $modified ) || 0 >= $modified ) {
			return $src;
		}

		return add_query_arg( 'ver', $modified, remove_query_arg( 'ver', $src ) );
	}

	/**
	 * Whether a stylesheet URL carries a query argument other than ver.
	 *
	 * That is a cache-buster of its own, and a plugin that prints the same URL
	 * in its own <link> too - Trustindex does, on tucasafacil - would have its
	 * sheet downloaded twice once ver is appended to the enqueued copy.
	 *
	 * @param string $src Stylesheet URL.
	 * @return bool
	 */
	private static function has_its_own_buster( string $src ): bool {
		$query = wp_parse_url( $src, PHP_URL_QUERY );

		if ( ! is_string( $query ) || '' === $query ) {
			return false;
		}

		parse_str( $query, $args );

		return array() !== array_diff( array_keys( $args ), array( 'ver' ) );
	}

	/**
	 * Translates an uploads URL into its path on disk.
	 *
	 * The comparison drops the scheme on purpose: the upload base URL can be
	 * registered as http while the stylesheet URL arrives as https, and a plain
	 * string replacement would leave these filters inert without warning.
	 *
	 * @param string $url Stylesheet URL.
	 * @return string Empty when it is not under uploads or cannot be read.
	 */
	private function path_from_url( string $url ): string {
		if ( isset( self::$paths[ $url ] ) ) {
			return self::$paths[ $url ];
		}

		self::$paths[ $url ] = '';

		$uploads = wp_upload_dir();

		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$without_query = strtok( $url, '?' );

		if ( ! is_string( $without_query ) ) {
			return '';
		}

		/*
		 * The scheme is optional as well as ignored. WordPress and the themes
		 * around it emit protocol-relative `//host/path` URLs freely - both
		 * shops already serve their favicon and their @font-face sources that
		 * way - and a pattern that insisted on http or https left this helper
		 * answering "not under uploads" for a file that plainly was.
		 */
		$base  = preg_replace( '#^(?:https?:)?//#', '', $uploads['baseurl'] );
		$given = preg_replace( '#^(?:https?:)?//#', '', $without_query );

		if ( ! is_string( $base ) || ! is_string( $given ) || 0 !== strpos( $given, $base ) ) {
			return '';
		}

		$path = $uploads['basedir'] . substr( $given, strlen( $base ) );

		if ( is_readable( $path ) && ! is_dir( $path ) ) {
			self::$paths[ $url ] = $path;
		}

		return self::$paths[ $url ];
	}
}
