<?php
/**
 * The master switch, the per-feature switches read through it, and stepping
 * aside for the Porto wizard.
 *
 * Every feature reads its own switch through Settings::feature() in its
 * register(), and none may repeat work the theme already does, so a mistake
 * here is silent: the feature simply never hooks, hooks with the plugin
 * switched off, or hooks twice.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Delivery;
use RhinnoIT\Optimizer\Settings;

#[CoversClass( Settings::class )]
#[CoversClass( Delivery::class )]
final class SettingsGateTest extends TestCase {

	/**
	 * Starts every test from an unconfigured site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::$options = array();
	}

	/**
	 * A feature with no stored value falls back to its declared default.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_feature_uses_its_declared_default(): void {
		$this->assertTrue( Settings::feature( 'srcset_prune' ), 'Delivery ships on.' );
	}

	/**
	 * The master switch takes every feature down with it.
	 *
	 * @return void
	 */
	public function test_the_master_switch_overrides_every_feature(): void {
		WP_Stub::$options['ropt_enabled']   = 'no';
		WP_Stub::$options['ropt_feature_a'] = 'yes';

		$this->assertFalse( Settings::feature( 'feature_a' ) );

		unset( WP_Stub::$options['ropt_enabled'] );

		$ships_on = 'yes' === ( Settings::defaults()['ropt_enabled'] ?? null );

		$this->assertSame( $ships_on, Settings::is_enabled(), 'A master switch never saved reads as its declared default.' );
		$this->assertSame( $ships_on, Settings::feature( 'feature_a' ), 'And so does every feature behind it.' );
	}

	/**
	 * A feature switched off stays off while the master switch is on.
	 *
	 * @return void
	 */
	public function test_one_feature_can_be_switched_off_alone(): void {
		WP_Stub::$options['ropt_enabled']   = 'yes';
		WP_Stub::$options['ropt_feature_a'] = 'no';
		WP_Stub::$options['ropt_feature_b'] = 'yes';

		$this->assertFalse( Settings::feature( 'feature_a' ) );
		$this->assertTrue( Settings::feature( 'feature_b' ), 'The others are untouched.' );
	}

	/**
	 * An unknown key is refused rather than silently enabled.
	 *
	 * @return void
	 */
	public function test_an_unknown_feature_is_off(): void {
		WP_Stub::$options['ropt_enabled'] = 'yes';

		$this->assertFalse( Settings::feature( 'no_such_thing' ) );
	}

	/**
	 * A row holding anything but 'yes' reads as off.
	 *
	 * Read on every storefront request, so a corrupt row must neither fatal
	 * nor turn the feature on.
	 *
	 * @return void
	 */
	public function test_a_corrupt_row_reads_as_off(): void {
		WP_Stub::$options['ropt_enabled'] = 'yes';

		foreach ( array( '1', 'on', '', array( 'yes' ), 42, true ) as $stored ) {
			WP_Stub::$options['ropt_feature_a'] = $stored;

			$this->assertFalse( Settings::feature( 'feature_a' ), sprintf( 'Read as on for %s.', var_export( $stored, true ) ) );
		}
	}

	/**
	 * A site without Porto never reports anything as covered.
	 *
	 * @return void
	 */
	public function test_a_site_without_porto_covers_nothing(): void {
		$this->assertFalse( Delivery::porto_covers( 'swap' ) );
		$this->assertFalse( Delivery::porto_covers( 'lazyload' ) );
	}

	/**
	 * An option that is not an array does not fatal.
	 *
	 * The wizard writes an array, but another plugin or a half-finished import
	 * can leave anything there, and this runs on every storefront request.
	 *
	 * @return void
	 */
	public function test_a_malformed_porto_option_does_not_fatal(): void {
		foreach ( array( 'a string', 42, null, false ) as $value ) {
			WP_Stub::$options['porto_settings_optimize'] = $value;

			$this->assertFalse( Delivery::porto_covers( 'swap' ) );
		}
	}

	/**
	 * A wizard option that is on is reported as covered.
	 *
	 * @return void
	 */
	public function test_an_enabled_wizard_option_is_covered(): void {
		WP_Stub::$options['porto_settings_optimize'] = array(
			'swap'     => true,
			'lazyload' => true,
		);

		$this->assertTrue( Delivery::porto_covers( 'swap' ) );
		$this->assertTrue( Delivery::porto_covers( 'lazyload' ) );
		$this->assertFalse( Delivery::porto_covers( 'minify_css' ), 'A key that is absent is not covered.' );
	}

	/**
	 * A wizard option present but off is not covered.
	 *
	 * Porto stores the unchecked state as false rather than dropping the key,
	 * so presence alone cannot be the test.
	 *
	 * @return void
	 */
	public function test_a_wizard_option_that_is_off_is_not_covered(): void {
		WP_Stub::$options['porto_settings_optimize'] = array(
			'swap'     => false,
			'lazyload' => '',
		);

		$this->assertFalse( Delivery::porto_covers( 'swap' ) );
		$this->assertFalse( Delivery::porto_covers( 'lazyload' ) );
	}
}
