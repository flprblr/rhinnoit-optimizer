<?php
/**
 * Tests for the throttled log write.
 *
 * CLAUDE.md requires a window on anything that logs from a storefront path, so
 * these cases pin the window and, just as much, pin that the plain error() has
 * none: a global window would drop the entries that matter.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Logger;

#[CoversClass( Logger::class )]
final class LoggerTest extends TestCase {

	protected function setUp(): void {
		WP_Stub::reset();
	}

	public function test_a_second_write_inside_the_window_is_dropped(): void {
		Logger::error_once( 'ropt_window', 'first' );
		Logger::error_once( 'ropt_window', 'second' );

		$this->assertSame( array( 'error: first' ), WP_Stub::$logs, 'A storefront path must not flood the log.' );
	}

	public function test_two_keys_do_not_share_a_window(): void {
		Logger::error_once( 'ropt_one', 'from one' );
		Logger::error_once( 'ropt_two', 'from two' );

		$this->assertCount( 2, WP_Stub::$logs, 'One key for two failures means the first hides the second.' );
	}

	public function test_the_plain_error_keeps_no_window(): void {
		Logger::error( 'straight through' );
		Logger::error( 'straight through' );

		$this->assertCount( 2, WP_Stub::$logs, 'A window here would drop the entry somebody is waiting for.' );
	}
}
