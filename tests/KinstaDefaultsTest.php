<?php
/**
 * No default depends on the host.
 *
 * Hover prefetching used to ship on only on Kinsta, on the idea that a host
 * without a page cache pays a render per hover. Both hosts the shops run on
 * cache pages - Kinsta, and cPanel's NGINX - and Chrome keeps at most two
 * hover prefetches in flight, so it ships on everywhere. The first case runs
 * on Kinsta, in its own process: ROPT_TESTS_KINSTA makes the bootstrap define
 * KINSTAMU_VERSION, and Settings::defaults() caches its answer.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Install;
use RhinnoIT\Optimizer\Plugin;
use RhinnoIT\Optimizer\Settings;

#[CoversClass( Settings::class )]
#[CoversClass( Install::class )]
final class KinstaDefaultsTest extends TestCase {

	#[RunInSeparateProcess]
	public function test_kinsta_seeds_the_same_defaults(): void {
		define( 'KINSTAMU_VERSION', '3.7.0' );

		WP_Stub::reset();
		WP_Stub::$options = array();

		$this->assertTrue( Plugin::kinsta_is_active() );

		Install::activate();

		$this->assertSame( 'yes', WP_Stub::$options['ropt_speculative_moderate'] );
		$this->assertSame( 'yes', WP_Stub::$options['ropt_cart_fragments'] );
	}

	public function test_hover_prefetching_ships_on_away_from_kinsta_too(): void {
		$this->assertFalse( Plugin::kinsta_is_active() );
		$this->assertSame( 'yes', Settings::defaults()['ropt_speculative_moderate'] );
	}
}
