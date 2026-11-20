<?php
/**
 * The page head and pings.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Housekeeping;

/**
 * Covers RhinnoIT\Optimizer\Housekeeping.
 */
#[CoversClass( Housekeeping::class )]
final class HousekeepingTest extends TestCase {

	/**
	 * Starts from a configured site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * The head links go, and the REST API link stays.
	 *
	 * The REST link is how integrations find the API; the DTE outage on one of
	 * these shops was a REST response gone wrong, so this plugin does not add
	 * a second way to break it.
	 *
	 * @return void
	 */
	public function test_the_head_links_go_and_the_rest_link_stays(): void {
		( new Housekeeping() )->register();

		foreach ( array( 'wp_head:rsd_link', 'wp_head:wp_shortlink_wp_head', 'template_redirect:wp_shortlink_header', 'wp_head:wp_oembed_add_discovery_links' ) as $removed ) {
			$this->assertContains( $removed, WP_Stub::$removed );
		}

		// Where default-filters.php adds each one. A remove_action() with any
		// other priority removes nothing and says nothing.
		$this->assertSame(
			array(
				'wp_head:rsd_link'                       => 10,
				'wp_head:wp_shortlink_wp_head'           => 10,
				'template_redirect:wp_shortlink_header'  => 11,
				'wp_head:wp_oembed_add_discovery_links'  => 10,
			),
			WP_Stub::$removed_priorities
		);
		// One filter for both generator tags and every feed: WooCommerce
		// appends its tag to core's before the_generator() filters the pair.
		$this->assertContains( 'the_generator:__return_empty_string', WP_Stub::$callbacks );

		$this->assertNotContains( 'wp_head:rest_output_link_wp_head', WP_Stub::$removed, 'Integrations discover the API through it.' );
		$this->assertContains( 'xmlrpc_methods', WP_Stub::$hooks );
		$this->assertContains( 'wp_headers', WP_Stub::$hooks );
	}

	/**
	 * What each head switch takes off, and nothing else.
	 *
	 * @return array<string, array<int, string|array<int, string>>>
	 */
	public static function headSwitches(): array {
		return array(
			'head_rsd'       => array( 'head_rsd', array( 'wp_head:rsd_link' ), array() ),
			'head_shortlink' => array( 'head_shortlink', array( 'wp_head:wp_shortlink_wp_head', 'template_redirect:wp_shortlink_header' ), array() ),
			'head_oembed'    => array( 'head_oembed', array( 'wp_head:wp_oembed_add_discovery_links' ), array() ),
			'head_generator' => array( 'head_generator', array(), array( 'the_generator:__return_empty_string' ) ),
			'pingbacks'      => array( 'pingbacks', array(), array( 'pings_open:__return_false', 'xmlrpc_methods:drop_pingback_methods', 'wp_headers:drop_pingback_header' ) ),
		);
	}

	/**
	 * Each head correction answers to its own switch alone.
	 *
	 * The point of one switch per correction is turning off the one that
	 * misbehaves and keeping the rest, so a switch wired to its neighbour's
	 * hooks would defeat the whole screen.
	 *
	 * @param string             $on      The one switch left on.
	 * @param array<int, string> $removed Core callbacks it takes off.
	 * @param array<int, string> $added   Callbacks it hooks.
	 * @return void
	 */
	#[DataProvider( 'headSwitches' )]
	public function test_each_head_switch_controls_only_its_own_correction( string $on, array $removed, array $added ): void {
		foreach ( array_keys( self::headSwitches() ) as $key ) {
			WP_Stub::$options[ 'ropt_' . $key ] = $on === $key ? 'yes' : 'no';
		}

		( new Housekeeping() )->register();

		$this->assertSame( $removed, WP_Stub::$removed );
		$this->assertSame( $added, WP_Stub::$callbacks );
	}

	/**
	 * Nothing is touched while every head switch is off.
	 *
	 * @return void
	 */
	public function test_nothing_is_touched_while_the_head_switches_are_off(): void {
		foreach ( array_keys( self::headSwitches() ) as $key ) {
			WP_Stub::$options[ 'ropt_' . $key ] = 'no';
		}

		( new Housekeeping() )->register();

		$this->assertSame( array(), WP_Stub::$removed );
		$this->assertSame( array(), WP_Stub::$callbacks );
	}

	/**
	 * Both version tags go, from what the_generator() really passes.
	 *
	 * Core hands the filter get_the_generator( $type ) and the type. In the
	 * head that is its own tag with WooCommerce's already appended by
	 * wc_generator_tag(); in a feed, its own alone. The values are hoka's home
	 * and feed on 2026-10-16.
	 *
	 * @return void
	 */
	public function test_both_generator_tags_go_from_what_core_passes(): void {
		$housekeeping = new Housekeeping();
		$housekeeping->register();

		$callback = null;

		foreach ( WP_Stub::$registered as list( $hook, $class, $method ) ) {
			if ( 'the_generator' === $hook ) {
				$callback = null === $class ? $method : array( $housekeeping, $method );
			}
		}

		$this->assertIsCallable( $callback );

		$head = '<meta name="generator" content="WordPress 7.1.3" />' . "\n" . '<meta name="generator" content="WooCommerce 11.2.1" />';
		$feed = '<generator>https://wordpress.org/?v=7.1.3</generator>';

		$this->assertSame( '', call_user_func( $callback, $head, 'xhtml' ) );
		$this->assertSame( '', call_user_func( $callback, $feed, 'rss2' ) );
	}

	/**
	 * Only the two pingback methods leave XML-RPC.
	 *
	 * Apps and Jetpack still use the rest of it.
	 *
	 * @return void
	 */
	public function test_only_the_pingback_methods_leave_xmlrpc(): void {
		$methods = ( new Housekeeping() )->drop_pingback_methods(
			array(
				'pingback.ping'                    => 'a',
				'pingback.extensions.getPingbacks' => 'b',
				'wp.getPosts'                      => 'c',
			)
		);

		$this->assertSame( array( 'wp.getPosts' => 'c' ), $methods );
	}

	/**
	 * The X-Pingback header goes and every other header stays.
	 *
	 * @return void
	 */
	public function test_the_pingback_header_goes_and_the_rest_stay(): void {
		$headers = ( new Housekeeping() )->drop_pingback_header(
			array(
				'X-Pingback'   => 'https://shop.test/xmlrpc.php',
				'Content-Type' => 'text/html',
			)
		);

		$this->assertSame( array( 'Content-Type' => 'text/html' ), $headers );
	}

	/**
	 * Whatever another plugin passes through these filters, nothing fatals.
	 *
	 * @return void
	 */
	public function test_the_filters_survive_malformed_values(): void {
		$housekeeping = new Housekeeping();

		$this->assertNull( $housekeeping->drop_pingback_methods( null ) );
		$this->assertSame( 'x', $housekeeping->drop_pingback_header( 'x' ) );
	}

}
