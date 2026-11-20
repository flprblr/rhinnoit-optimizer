<?php
/**
 * Enqueueing of the scripts and styles built by @wordpress/scripts.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

use RhinnoIT\Optimizer\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the build/ bundles only where they are needed.
 */
final class Assets {

	/**
	 * Transient that throttles the broken build log entry, one per bundle.
	 *
	 * The entry point name is appended. A single shared key meant the first
	 * admin page view of the hour silenced the storefront for the rest of it,
	 * so a shop deployed without a build logged nothing about the shop.
	 */
	const string BROKEN_BUILD_FLAG = 'ropt_broken_build_';

	/**
	 * Hooks the enqueueing.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	/**
	 * Storefront assets.
	 *
	 * @return void
	 */
	public function enqueue_frontend(): void {
		if ( ! Settings::is_enabled() || ! self::scope_matches( Settings::frontend_contexts() ) ) {
			return;
		}

		/*
		 * 'jquery' because WooCommerce fires its refresh events through jQuery
		 * and a native listener never receives them. Declaring it does not load
		 * a second copy - WordPress deduplicates by handle - and on a
		 * WooCommerce page it costs nothing, since one is loaded there anyway.
		 *
		 * It is not free everywhere, though. Pick "Every page of the site" and
		 * this pulls jQuery into a blog post that a block theme would have
		 * served without it, which is the price of the refresh listeners: drop
		 * this and the listeners together if the bundle stops needing them.
		 */
		$handle = $this->enqueue( 'frontend', array( 'jquery' ) );

		if ( '' === $handle ) {
			return;
		}

		// wp_add_inline_script keeps the types; wp_localize_script casts everything to string.
		$data = wp_json_encode(
			array(
				'debug' => defined( 'WP_DEBUG' ) && WP_DEBUG,
			)
		);

		// Printing a failed encode would emit "window.roptFrontend = ;" and break every script on the page.
		if ( ! is_string( $data ) ) {
			return;
		}

		wp_add_inline_script( $handle, 'window.roptFrontend = ' . $data . ';', 'before' );
	}

	/**
	 * Whether the current request is one of the chosen contexts.
	 *
	 * The conditional tags are safe here: wp_enqueue_scripts runs once the
	 * main query is set up. The WooCommerce ids only ever arrive from
	 * Settings::frontend_contexts() on a site with a supported WooCommerce, so
	 * their tags exist whenever they are asked. An id with no branch answers
	 * false instead of raising an UnhandledMatchError, even though the read
	 * already discarded anything unknown.
	 *
	 * @param array<int, string> $contexts Ids from the two Settings::*_scope_choices().
	 * @return bool
	 */
	public static function scope_matches( array $contexts ): bool {
		if ( in_array( 'everywhere', $contexts, true ) ) {
			return true;
		}

		foreach ( $contexts as $context ) {
			$here = match ( $context ) {
				'shop'     => is_shop() || is_product_taxonomy(),
				'product'  => is_product(),
				'cart'     => is_cart(),
				'checkout' => is_checkout(),
				'account'  => is_account_page(),
				'posts'    => is_singular( 'post' ),
				'pages'    => self::is_plain_page(),
				default    => false,
			};

			if ( $here ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A WordPress page that is not one of WooCommerce's.
	 *
	 * Cart, checkout and my account are WP pages, and so is the shop whenever
	 * the shop page is queried as one, so a bare is_page() would load the
	 * bundle on screens whose own box is unchecked. The boxes have to be
	 * independent or they are lying about what they do.
	 *
	 * "Pages" belongs to the WordPress list, so it is asked on sites without
	 * WooCommerce too, where those four tags do not exist and calling one is a
	 * fatal on every page. There every page is a plain one.
	 *
	 * @return bool
	 */
	private static function is_plain_page(): bool {
		if ( ! Plugin::woocommerce_is_active() ) {
			return is_page();
		}

		return is_page() && ! is_shop() && ! is_cart() && ! is_checkout() && ! is_account_page();
	}

	/**
	 * Admin assets, only on the plugin page.
	 *
	 * @return void
	 */
	public function enqueue_admin(): void {
		if ( ! Menu::is_current_page() ) {
			return;
		}

		$this->enqueue( 'admin' );
	}

	/**
	 * Enqueues the bundle and its stylesheet when they exist.
	 *
	 * @param string             $name       Entry point name (build/{$name}.js).
	 * @param array<int, string> $extra_deps Dependencies to add to the ones detected by the build.
	 * @return string Registered handle, or an empty string when the build is missing.
	 */
	private function enqueue( string $name, array $extra_deps = array() ): string {
		$asset = $this->read_manifest( $name );

		if ( null === $asset ) {
			return '';
		}

		$handle = 'ropt-' . $name;

		wp_enqueue_script(
			$handle,
			ROPT_URL . 'build/' . $name . '.js',
			array_values( array_unique( array_merge( $asset['dependencies'], $extra_deps ) ) ),
			$asset['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Only bundles that import @wordpress/i18n get wp-i18n from the build.
		if ( in_array( 'wp-i18n', $asset['dependencies'], true ) ) {
			wp_set_script_translations( $handle, 'rhinnoit-optimizer', ROPT_PATH . 'languages' );
		}

		if ( is_readable( ROPT_PATH . 'build/' . $name . '.css' ) ) {
			wp_enqueue_style( $handle, ROPT_URL . 'build/' . $name . '.css', array(), $asset['version'] );

			// The build emits build/*-rtl.css; WordPress uses it on RTL locales.
			wp_style_add_data( $handle, 'rtl', 'replace' );
		}

		return $handle;
	}

	/**
	 * Reads and validates build/{$name}.asset.php.
	 *
	 * A truncated or half-uploaded build would otherwise reach array_merge()
	 * with a non-array and raise a fatal error on every storefront request.
	 *
	 * @param string $name Entry point name.
	 * @return array{dependencies: list<string>, version: string}|null Null when the manifest is unusable.
	 */
	private function read_manifest( string $name ): ?array {
		$file = ROPT_PATH . 'build/' . $name . '.asset.php';

		if ( ! is_readable( $file ) ) {
			$this->log_broken_build( $name, $file );

			return null;
		}

		try {
			$asset = require $file;
		} catch ( \Throwable $e ) {
			// A file truncated mid-upload raises a ParseError, which would
			// otherwise be fatal on every request that enqueues this bundle.
			$this->log_broken_build( $name, $file );

			return null;
		}

		if ( ! is_array( $asset )
			|| ! isset( $asset['dependencies'], $asset['version'] )
			|| ! is_array( $asset['dependencies'] )
			|| ! is_string( $asset['version'] ) ) {
			$this->log_broken_build( $name, $file );

			return null;
		}

		return array(
			'dependencies' => array_values( array_filter( $asset['dependencies'], 'is_string' ) ),
			'version'      => $asset['version'],
		);
	}

	/**
	 * Logs a broken build once an hour at most, per bundle.
	 *
	 * Per bundle because they fail independently: one window for the storefront
	 * and one for the admin screen, or the first admin view of the hour hides
	 * the storefront for the other 59 minutes.
	 *
	 * @param string $name Entry point name.
	 * @param string $file Manifest path.
	 * @return void
	 */
	private function log_broken_build( string $name, string $file ): void {
		Logger::error_once(
			self::BROKEN_BUILD_FLAG . $name,
			sprintf( '%s is missing or invalid. Run "npm run build".', $file )
		);
	}
}
