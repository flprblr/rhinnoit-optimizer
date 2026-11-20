<?php
/**
 * The Kinsta stock switch, on Kinsta.
 *
 * Its own process: ROPT_TESTS_KINSTA makes tests/bootstrap.php define
 * KINSTAMU_VERSION, which the rest of the suite must not see.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\Shop_Cache;

#[CoversClass( Shop_Cache::class )]
#[RunTestsInSeparateProcesses]
final class ShopCacheOnKinstaTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_KINSTA=1' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_KINSTA' );
	}

	public function test_the_switch_turns_off_kinstas_stock_purge_through_its_filter(): void {
		WP_Stub::reset();
		WP_Stub::$options['ropt_kinsta_stock_status_only'] = 'yes';

		( new Shop_Cache() )->register();

		$this->assertContains( 'kinsta/kmp/cache/autopurge:no_kinsta_stock_purge', WP_Stub::$callbacks );
	}

	public function test_it_ships_on_and_needs_the_page_cache_switch(): void {
		WP_Stub::reset();
		( new Shop_Cache() )->register();
		$this->assertContains( 'kinsta/kmp/cache/autopurge:no_kinsta_stock_purge', WP_Stub::$callbacks, 'On by default.' );

		WP_Stub::reset();
		WP_Stub::$options['ropt_kinsta_stock_status_only'] = 'yes';
		WP_Stub::$options['ropt_page_cache_clear']         = 'no';
		( new Shop_Cache() )->register();
		$this->assertNotContains( 'kinsta/kmp/cache/autopurge:no_kinsta_stock_purge', WP_Stub::$callbacks, 'Without our purge, a sold-out product would stay cached.' );
	}
}
