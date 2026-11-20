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

		foreach ( array( 'admin' ) as $bundle ) {
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
				'ropt_enabled'                  => 'yes',
				'ropt_srcset_prune'             => 'yes',
				'ropt_lazy_first_only'          => 'yes',
				'ropt_viewport_hero'            => 'yes',
				'ropt_font_inline'              => 'yes',
				'ropt_css_versioning'           => 'yes',
				'ropt_global_styles'            => 'yes',
				'ropt_classic_theme_styles'     => 'yes',
				'ropt_speculative_moderate'     => 'yes',
				'ropt_head_rsd'                 => 'yes',
				'ropt_head_shortlink'           => 'yes',
				'ropt_head_oembed'              => 'yes',
				'ropt_head_generator'           => 'yes',
				'ropt_pingbacks'                => 'yes',
				'ropt_page_cache_clear'         => 'yes',
				'ropt_same_url_redirect'        => 'yes',
				'ropt_product_image_eager'      => 'yes',
				'ropt_gallery_strip_lazy'       => 'yes',
				'ropt_loop_first_row'           => 'yes',
				'ropt_page_cache_shop'          => 'yes',
				'ropt_kinsta_stock_status_only' => 'yes',
				'ropt_cart_fragments'           => 'yes',
			),
			Settings::defaults()
		);
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
			'ropt_porto'       => Settings::REQUIRES_PORTO,
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

		$this->assertSame( 'yes', WP_Stub::$options['ropt_enabled'] );
		$this->assertSame( ROPT_VERSION, WP_Stub::$options[ Install::VERSION_OPTION ] );
		$this->assertContains( array( Install::DIRTY_HOOK, 'site' ), WP_Stub::$fired, 'New code can print every page differently, and a copied build fires no upgrader hook.' );
	}

	public function test_deactivation_drops_the_scheduled_clear(): void {
		WP_Stub::$scheduled['ropt_page_cache_run'] = time() + 60;

		Install::deactivate();

		$this->assertSame( array(), WP_Stub::$scheduled );
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
