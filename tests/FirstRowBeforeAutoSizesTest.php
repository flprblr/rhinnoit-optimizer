<?php
/**
 * Loading the first row first on WordPress 6.5 and 6.6, which have no auto sizes.
 *
 * Runs in its own process with ROPT_TESTS_WP set to 6.6, so the bootstrap
 * leaves out wp_sizes_attribute_includes_valid_auto(), as those versions do;
 * the child inherits the environment.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\First_Row;

/**
 * Covers RhinnoIT\Optimizer\Frontend\First_Row before WordPress 6.7.
 */
#[CoversClass( First_Row::class )]
#[RunTestsInSeparateProcesses]
final class FirstRowBeforeAutoSizesTest extends TestCase {

	/**
	 * Boots a WordPress from before auto sizes.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_WP=6.6' );
	}

	/**
	 * Leaves the environment as the rest of the suite expects it.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_WP' );
	}

	/**
	 * The hover image still leaves lazy, with its sizes as they came.
	 *
	 * Calling core's auto-sizes check there would be a fatal error on every
	 * listing.
	 *
	 * @return void
	 */
	public function test_a_lazy_image_keeps_its_sizes(): void {
		$this->assertFalse( function_exists( 'wp_sizes_attribute_includes_valid_auto' ), 'The bootstrap booted 6.7 or later.' );

		WP_Stub::reset();
		WP_Stub::$page_type   = 'taxonomy';
		WP_Stub::$in_the_loop = true;
		WP_Stub::$the_id      = 101;

		$row = new First_Row();
		$row->detect_listing();

		$hover = $row->first_row_first(
			array(
				'class'         => 'hover-image',
				'fetchpriority' => 'high',
				'sizes'         => '(max-width: 786px) 100vw, 786px',
			)
		);

		$this->assertSame( 'lazy', $hover['loading'] ?? null );
		$this->assertSame( '(max-width: 786px) 100vw, 786px', $hover['sizes'] ?? null );
	}
}
