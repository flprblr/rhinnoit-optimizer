<?php
/**
 * Tests for the options contract and the upgrade path.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Install;
use RhinnoIT\Optimizer\Settings;

#[CoversClass( Settings::class )]
#[CoversClass( Install::class )]
final class PluginTest extends TestCase {

	protected function setUp(): void {
		WP_Stub::reset();
	}

	public function test_every_option_the_plugin_creates_is_removed_on_uninstall(): void {
		$declared   = array_merge( Settings::option_ids(), Settings::retired_option_ids() );
		$declared[] = Install::VERSION_OPTION;

		$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );

		foreach ( $declared as $option ) {
			$this->assertStringContainsString(
				"'" . $option . "'",
				$uninstall,
				sprintf( '%s is written by the plugin but never deleted.', $option )
			);
		}

		foreach ( array( 'frontend', 'admin' ) as $bundle ) {
			$this->assertStringContainsString(
				"delete_transient( 'ropt_broken_build_" . $bundle . "' )",
				$uninstall,
				sprintf( 'The %s bundle leaves a throttle transient behind.', $bundle )
			);
		}
	}

	public function test_defaults_come_from_the_field_definition(): void {
		$this->assertSame(
			array(
				'ropt_enabled'           => 'no',
				'ropt_scope_wordpress'   => array(),
				'ropt_scope_woocommerce' => array(),
			),
			Settings::defaults()
		);
	}

	public function test_a_fresh_install_loads_the_script_nowhere(): void {
		WP_Stub::$options = Settings::defaults();

		$this->assertSame(
			array(),
			Settings::frontend_contexts(),
			'Both lists ship empty on purpose: nothing loads until somebody picks where.'
		);
	}

	/**
	 * What the two lists hold, and the contexts that come out of them.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function storedScopes(): array {
		return array(
			'both lists chosen'        => array( array( 'cart' ), array( 'posts' ), array( 'cart', 'posts' ) ),
			'unknown ids discarded'    => array( array( 'cart', 'nonsense' ), array(), array( 'cart' ) ),
			'elements that are not strings' => array( array( 'cart', array( 'x' ), 7 ), array(), array( 'cart' ) ),
			'a woo id in the wp list'  => array( array(), array( 'cart' ), array() ),
			'a wp id in the woo list'  => array( array( 'posts' ), array(), array() ),
			'every box unchecked'      => array( array(), array(), array() ),
			'woo list not an array'    => array( 'cart', array(), array() ),
			'woo list never saved'     => array( null, array(), array() ),
			'wp list never saved'      => array( array( 'cart' ), null, array( 'cart' ) ),
		);
	}

	/**
	 * Read on every storefront request, so it takes whatever the rows hold.
	 *
	 * A value that is not an array cannot be filtered - array_filter() on a
	 * string is a TypeError - so it falls back to what the field declares,
	 * which in this template is nothing, the same as unchecking every box.
	 * Each list is filtered against its own field's choices, so an id the other
	 * list owns does not survive a crafted post.
	 *
	 * @param mixed              $woo      Value of the WooCommerce list.
	 * @param mixed              $wordpress Value of the WordPress list.
	 * @param array<int, string> $expected Contexts the plugin acts on.
	 * @return void
	 */
	#[DataProvider( 'storedScopes' )]
	public function test_the_stored_lists_are_filtered_before_they_are_used( $woo, $wordpress, array $expected ): void {
		foreach ( array( 'ropt_scope_woocommerce' => $woo, 'ropt_scope_wordpress' => $wordpress ) as $option => $value ) {
			if ( null === $value ) {
				unset( WP_Stub::$options[ $option ] );
			} else {
				WP_Stub::$options[ $option ] = $value;
			}
		}

		$this->assertSame( $expected, Settings::frontend_contexts() );
	}

	public function test_every_structural_field_is_flagged_so_it_cannot_become_an_option(): void {
		$structural = 0;

		foreach ( Settings::fields() as $field ) {
			if ( ! in_array( $field['type'] ?? '', array( 'title', 'sectionend', 'info' ), true ) ) {
				continue;
			}

			++$structural;

			$this->assertFalse(
				$field['is_option'] ?? true,
				sprintf(
					'%s is form structure. Without \'is_option\' => false, a crafted post makes '
					. 'Settings_Page::sanitize_submission() store the section id as an option nothing reads.',
					(string) ( $field['id'] ?? '?' )
				)
			);
		}

		$this->assertGreaterThan( 0, $structural, 'The field definition lost its section, so this guard checks nothing.' );
	}

	public function test_each_conditional_section_is_marked_row_by_row(): void {
		// available_fields() drops rows by this flag, one by one. A row of a
		// conditional card without it shows up on a site that lacks what it
		// needs; a WordPress row with one disappears from plain sites. A plugin
		// that adds a conditional card adds its title id and mark here.
		$marks   = array(
			'ropt_woocommerce' => Settings::REQUIRES_WOOCOMMERCE,
		);
		$current = '';
		$seen    = array();

		foreach ( Settings::fields() as $field ) {
			if ( 'title' === $field['type'] && isset( $marks[ $field['id'] ] ) ) {
				$current = $marks[ $field['id'] ];
				$seen[]  = $field['id'];
			}

			$this->assertSame(
				$current,
				$field['requires'] ?? '',
				sprintf( '%s (%s) carries the wrong section mark.', $field['id'], $field['type'] )
			);

			if ( 'sectionend' === $field['type'] && isset( $marks[ $field['id'] ] ) ) {
				$current = '';
			}
		}

		$this->assertSame( array_keys( $marks ), $seen, 'Every conditional card is there, in this order.' );
	}

	public function test_activation_seeds_defaults_without_overwriting_existing_values(): void {
		WP_Stub::$options = array( 'ropt_enabled' => 'yes' );

		Install::activate();

		$this->assertSame( 'yes', WP_Stub::$options['ropt_enabled'], 'An existing choice must survive reactivation.' );
		$this->assertSame( ROPT_VERSION, WP_Stub::$options[ Install::VERSION_OPTION ] );
	}

	public function test_a_stale_stored_version_reseeds_missing_options(): void {
		WP_Stub::$options = array( Install::VERSION_OPTION => '0.9.0' );

		Install::check_version();

		$this->assertSame( 'no', WP_Stub::$options['ropt_enabled'] );
		$this->assertSame( ROPT_VERSION, WP_Stub::$options[ Install::VERSION_OPTION ] );
		$this->assertContains( array( Install::DIRTY_HOOK, 'site' ), WP_Stub::$fired, 'New code can print every page differently, and a copied build fires no upgrader hook.' );
	}

	public function test_a_current_version_does_no_work(): void {
		WP_Stub::$options = array( Install::VERSION_OPTION => ROPT_VERSION );

		Install::check_version();

		$this->assertArrayNotHasKey( 'ropt_enabled', WP_Stub::$options, 'Nothing to do when the version matches.' );
		$this->assertSame( array(), WP_Stub::$fired, 'No update, so no page is stale: it runs on every admin page.' );
	}

	/**
	 * Values a corrupt option row can hold.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function unusableEnabledValues(): array {
		return array(
			'empty string' => array( '' ),
			'array'        => array( array( 'a' => 1 ) ),
			'integer'      => array( 42 ),
			'boolean'      => array( true ),
		);
	}

	/**
	 * The switch is read on every storefront request, so it takes whatever the
	 * row holds without fataling and without turning the plugin on. A cast or
	 * wc_string_to_bool() would do neither: an array reads as true, and
	 * strtolower() on one is a TypeError on every page of the shop.
	 *
	 * @param mixed $value Stored option value.
	 * @return void
	 */
	#[DataProvider( 'unusableEnabledValues' )]
	public function test_a_corrupt_switch_leaves_the_plugin_off( $value ): void {
		WP_Stub::$options['ropt_enabled'] = $value;

		$this->assertFalse( Settings::is_enabled() );
	}
}
