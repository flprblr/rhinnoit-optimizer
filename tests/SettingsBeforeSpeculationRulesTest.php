<?php
/**
 * The settings screen on WordPress 6.5 to 6.7, which have no speculative loading.
 *
 * Runs in its own process with ROPT_TESTS_WP set to 6.6, so the bootstrap
 * leaves out wp_get_speculation_rules_configuration(), as those versions do;
 * the child inherits the environment.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Settings;

/**
 * Covers Settings::available_fields() before WordPress 6.8.
 */
#[CoversClass( Settings::class )]
#[RunTestsInSeparateProcesses]
final class SettingsBeforeSpeculationRulesTest extends TestCase {

	/**
	 * Boots a WordPress from before speculative loading.
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
	 * Hover prefetching has no core rules to change, so it is not offered.
	 *
	 * @return void
	 */
	public function test_hover_prefetching_is_not_on_the_screen(): void {
		WP_Stub::reset();

		$this->assertFalse( function_exists( 'wp_get_speculation_rules_configuration' ), 'The process has 6.8\'s speculative loading, or this case means nothing.' );

		$ids = array_column( Settings::available_fields(), 'id' );

		$this->assertNotContains( 'ropt_speculative_moderate', $ids );
		$this->assertContains( 'ropt_head_rsd', $ids, 'The rest of the card stays.' );
	}
}
