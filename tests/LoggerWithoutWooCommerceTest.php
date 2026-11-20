<?php
/**
 * The logger on a site with no WooCommerce.
 *
 * Its own process, for the same reason as BootWithoutWooCommerceTest: the
 * WooCommerce stubs load only where WC_VERSION is defined, so this child has
 * no wc_get_logger() at all, exactly like a plain WordPress site.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Logger;

#[CoversClass( Logger::class )]
final class LoggerWithoutWooCommerceTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_WC=none' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_WC' );
	}

	#[RunInSeparateProcess]
	public function test_an_error_still_leaves_a_trace_without_woocommerce(): void {
		$this->assertFalse( function_exists( 'wc_get_logger' ), 'The child has to lack the WooCommerce logger or this case means nothing.' );

		$log = (string) tempnam( sys_get_temp_dir(), 'ropt-log' );
		ini_set( 'error_log', $log );

		Logger::error( 'straight through' );

		$written = (string) file_get_contents( $log );
		unlink( $log );

		// Returning silently here brought back the failures-without-a-trace this
		// logger exists to prevent.
		$this->assertStringContainsString( 'rhinnoit-optimizer', $written );
		$this->assertStringContainsString( 'ERROR: straight through', $written );
	}
}
