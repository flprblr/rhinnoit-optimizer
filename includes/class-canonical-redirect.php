<?php
/**
 * Keeps WordPress's canonical redirect from sending a request back to itself.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Drops a canonical redirect whose target is the request it answers.
 *
 * Where redirect_canonical() has a target of its own - the static front page,
 * and page 2 onwards of a listing - it rebuilds the query string with
 * add_query_arg(), which writes an empty value without its "=" and encodes a
 * space as %20. A request carrying ?utm_term= or ?utm_term=hoka+bondi then
 * gets a 301 to the same URL written the other way. Measured on hoka's and
 * joia's home pages and on hoka's /c/zapatillas-mujer/page/2/ and
 * /e/cyber/page/2/ on 2026-10-05; other pages answer 200. Google Ads sends
 * both: an empty ValueTrack value, and search terms with "+". A page cache
 * that leaves tracking parameters out of its key, as Kinsta's does once asked
 * to, stores that 301 under the bare URL, and while it lives every visitor is
 * sent to the ad's address: one more round trip, and every visit counted as
 * that campaign. hoka's home served one that morning, cached at 10:43 and
 * gone by 10:50; Kinsta purges the home page on every stock change, so each
 * purge reopens the race.
 *
 * A target with the same scheme, host, port, path and query values asks for
 * the same page, so the redirect is dropped and the page is served. Every
 * other canonical redirect - ?page_id= to the front page, a missing trailing
 * slash, http to https, ?p= to a permalink - changes one of those and is left
 * alone.
 */
final class Canonical_Redirect {

	/**
	 * Hooks the check while its switch is on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'same_url_redirect' ) ) {
			return;
		}

		// Late, so the target compared is the one WordPress would send.
		add_filter( 'redirect_canonical', array( $this, 'drop_same_url' ), 99, 2 );
	}

	/**
	 * Cancels a redirect to the request itself.
	 *
	 * Core always passes both arguments; a plugin that applies the filter with
	 * one would otherwise be a fatal on every page it runs.
	 *
	 * @param mixed $redirect_url  Where WordPress is about to redirect, or false.
	 * @param mixed $requested_url The request, as redirect_canonical() built it: %XX octets in lower case.
	 * @return mixed False to serve the page; otherwise the target, unchanged.
	 */
	public function drop_same_url( mixed $redirect_url, mixed $requested_url = null ): mixed {
		if ( ! is_string( $redirect_url ) || '' === $redirect_url || ! is_string( $requested_url ) ) {
			return $redirect_url;
		}

		return self::same_request( $redirect_url, $requested_url ) ? false : $redirect_url;
	}

	/**
	 * Whether two URLs ask for the same thing.
	 *
	 * Scheme and host compare without case, the path byte for byte but for the
	 * case of its %XX octets - core lowercases them in the request it passes,
	 * never in the target - and the query by its decoded values, so ?a= and
	 * ?a, %20 and +, %c3 and %C3, and the order of the parameters do not
	 * count. A URL that does not parse is never the same.
	 *
	 * @param string $target    The redirect target.
	 * @param string $requested The request.
	 * @return bool
	 */
	private static function same_request( string $target, string $requested ): bool {
		$to   = wp_parse_url( $target );
		$from = wp_parse_url( $requested );

		if ( ! is_array( $to ) || ! is_array( $from ) ) {
			return false;
		}

		foreach ( array( 'scheme', 'host' ) as $part ) {
			if ( strtolower( (string) ( $to[ $part ] ?? '' ) ) !== strtolower( (string) ( $from[ $part ] ?? '' ) ) ) {
				return false;
			}
		}

		if ( ( $to['port'] ?? null ) !== ( $from['port'] ?? null ) || self::path( $to ) !== self::path( $from ) ) {
			return false;
		}

		return self::query_values( (string) ( $to['query'] ?? '' ) ) === self::query_values( (string) ( $from['query'] ?? '' ) );
	}

	/**
	 * A parsed URL's path, its %XX octets in lower case as core writes the request's.
	 *
	 * @param array<string, int|string> $url Parts from wp_parse_url().
	 * @return string
	 */
	private static function path( array $url ): string {
		return (string) preg_replace_callback(
			'/%[0-9A-F]{2}/i',
			static fn( array $octet ): string => strtolower( $octet[0] ),
			(string) ( $url['path'] ?? '/' )
		);
	}

	/**
	 * A query string's parameters, in name order.
	 *
	 * @param string $query Query string without the "?".
	 * @return array<int|string, mixed>
	 */
	private static function query_values( string $query ): array {
		$values = array();
		wp_parse_str( $query, $values );
		ksort( $values );

		return $values;
	}
}
