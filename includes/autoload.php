<?php
/**
 * Autoloader mapping the plugin namespace to the WordPress file convention.
 *
 * RhinnoIT\Optimizer\Assets              -> includes/class-assets.php
 * RhinnoIT\Optimizer\Admin\Settings_Page -> includes/admin/class-settings-page.php
 *
 * @package RhinnoIT\Optimizer
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'RhinnoIT\\Optimizer\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$parts     = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$file_name = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$sub_dir   = empty( $parts ) ? '' : strtolower( implode( '/', $parts ) ) . '/';
		$path      = ROPT_PATH . 'includes/' . $sub_dir . $file_name;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
