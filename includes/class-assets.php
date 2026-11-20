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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
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
	 * @param string $name Entry point name (build/{$name}.js).
	 * @return string Registered handle, or an empty string when the build is missing.
	 */
	private function enqueue( string $name ): string {
		$asset = $this->read_manifest( $name );

		if ( null === $asset ) {
			return '';
		}

		$handle = 'ropt-' . $name;

		wp_enqueue_script(
			$handle,
			ROPT_URL . 'build/' . $name . '.js',
			$asset['dependencies'],
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
	 * A truncated or half-uploaded build would otherwise reach in_array() with
	 * a non-array and raise a fatal error on every request that enqueues the
	 * bundle.
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
	 * Per bundle because bundles fail independently: a window shared with
	 * another entry point would let the first page view of the hour hide that
	 * one's failure for the other 59 minutes. See BROKEN_BUILD_FLAG.
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
