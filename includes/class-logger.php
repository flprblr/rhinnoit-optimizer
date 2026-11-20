<?php
/**
 * Error logging on top of the WooCommerce logger.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce > Status > Logs under the plugin source, or to PHP's
 * error log on a site without WooCommerce.
 */
final class Logger {

	/**
	 * Source the log entries are grouped by.
	 */
	const string SOURCE = 'rhinnoit-optimizer';

	/**
	 * Logs an error.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra context.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Logs an error at most once per window.
	 *
	 * For the paths that run on every request. Unthrottled, a shop doing a few
	 * thousand requests a day writes a few thousand identical lines into
	 * wc-logs, and the one that matters is indistinguishable from the noise.
	 *
	 * The caller owns the key and it has to be specific. One key shared by two
	 * different failures means the first one silences the second for the whole
	 * window: that is how a shop deployed without a build ended up logging
	 * nothing about the storefront, because a single admin page view had taken
	 * the hour. Prefix it with ropt_, like any other transient of the plugin.
	 *
	 * @param string               $key     Transient key holding the window.
	 * @param string               $message Message.
	 * @param int                  $ttl     Seconds until the next entry is allowed.
	 * @param array<string, mixed> $context Extra context.
	 * @return void
	 */
	public static function error_once( string $key, string $message, int $ttl = HOUR_IN_SECONDS, array $context = array() ): void {
		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, $ttl );

		self::error( $message, $context );
	}

	/**
	 * Logs debugging information.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra context.
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Sends the entry to the WooCommerce logger, or to PHP's log without it.
	 *
	 * The WooCommerce logger is the better home - it keeps entries per source
	 * and shows them under WooCommerce > Status > Logs - but the plugin
	 * also runs on sites that have no WooCommerce, and returning silently there
	 * would bring back exactly the failures-without-a-trace this logger exists
	 * to prevent. PHP's error log is on every host.
	 *
	 * @param string               $level   PSR-3 level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra context.
	 * @return void
	 */
	private static function log( string $level, string $message, array $context ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			$context['source'] = self::SOURCE;

			wc_get_logger()->log( $level, $message, $context );

			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The only log every host has once WooCommerce is absent.
		error_log( sprintf( '[%s] %s: %s', self::SOURCE, strtoupper( $level ), $message ) );
	}
}
