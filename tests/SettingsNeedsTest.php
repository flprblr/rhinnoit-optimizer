<?php
/**
 * A field that cannot act on this site is not on its screen.
 *
 * Settings::available_fields() hides a field whose 'requires' or 'needs' names
 * a mark this site lacks, and Settings::conditions() is the one place that
 * answers each mark. A mark it does not answer counts as missing, so a typo
 * hides its field on every site with no error anywhere.
 *
 * The template ships no 'needs' mark, only the WooCommerce section's
 * 'requires', so the rules themselves are pinned on Settings::fields_for_marks()
 * with fields and marks of the cases' own. A plugin's first NEEDS_* mark
 * brings its own cases here too: its field hidden where the mark is missing,
 * drawn where it is there, and its stored value kept either way.
 *
 * The shared process is a Porto shop on a host with no page cache the plugin
 * can clear, without Kinsta, on a classic theme with no theme.json and a
 * WordPress with speculative loading. The cases that need Kinsta or an older
 * core run in their own process: SettingsOnKinstaTest and
 * SettingsBeforeSpeculationRulesTest.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Admin\Settings_Page;
use RhinnoIT\Optimizer\Page_Cache;
use RhinnoIT\Optimizer\Plugin;
use RhinnoIT\Optimizer\Settings;

/**
 * Covers the marks of Settings::available_fields().
 */
#[CoversClass( Settings::class )]
#[CoversClass( Settings_Page::class )]
final class SettingsNeedsTest extends TestCase {

	/**
	 * Starts from a configured site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * Every mark a field names is one the screen reads, and only options carry 'needs'.
	 *
	 * A typo would hide its field on every site, with no error anywhere.
	 *
	 * @return void
	 */
	public function test_every_mark_is_a_condition_the_screen_reads(): void {
		$conditions = Settings::conditions();
		$marked     = 0;

		foreach ( Settings::fields() as $field ) {
			$id = (string) $field['id'];

			$this->assertArrayHasKey( $field['requires'] ?? '', $conditions, sprintf( '%s requires a mark nobody reads.', $id ) );

			$marked += '' !== ( $field['requires'] ?? '' ) ? 1 : 0;

			if ( ! isset( $field['needs'] ) ) {
				continue;
			}

			++$marked;

			$this->assertNotFalse( $field['is_option'] ?? true, sprintf( '%s: a card is hidden through its section mark, not through needs.', $id ) );
			$this->assertIsArray( $field['needs'] );
			$this->assertNotEmpty( $field['needs'] );

			foreach ( $field['needs'] as $mark ) {
				$this->assertIsString( $mark );
				$this->assertArrayHasKey( $mark, $conditions, sprintf( '%s needs a mark nobody reads.', $id ) );
			}
		}

		$this->assertGreaterThan( 0, $marked, 'No field carries a mark, so this guard checks nothing.' );
	}

	/**
	 * The screen reads this site's own marks.
	 *
	 * @return void
	 */
	public function test_the_screen_filters_with_this_sites_marks(): void {
		$this->assertSame( Settings::fields_for_marks( Settings::fields(), Settings::conditions() ), Settings::available_fields() );
	}

	/**
	 * An option whose 'needs' the site lacks is not offered; the rest of its card is.
	 *
	 * @return void
	 */
	public function test_an_option_whose_mark_is_missing_is_left_off(): void {
		$ids = self::ids_for(
			array(
				'' => true,
				'a' => true,
				'b' => false,
			),
			self::card( 'ropt_card', array( self::option( 'ropt_one', array( 'a' ) ), self::option( 'ropt_two', array( 'a', 'b' ) ), self::option( 'ropt_three' ) ) )
		);

		$this->assertSame( array( 'ropt_card', 'ropt_one', 'ropt_three', 'ropt_card' ), $ids, 'Every mark must hold; one missing is enough to hide it.' );
	}

	/**
	 * A mark nobody answers hides its option on every site.
	 *
	 * @return void
	 */
	public function test_a_mark_nobody_answers_counts_as_missing(): void {
		$ids = self::ids_for(
			array( '' => true ),
			self::card( 'ropt_card', array( self::option( 'ropt_typo', array( 'not_a_mark' ) ), self::option( 'ropt_kept' ) ) )
		);

		$this->assertSame( array( 'ropt_card', 'ropt_kept', 'ropt_card' ), $ids );
	}

	/**
	 * A card left with nothing to switch is not drawn at all.
	 *
	 * @return void
	 */
	public function test_a_card_left_with_no_option_goes_whole(): void {
		$ids = self::ids_for(
			array(
				'' => true,
				'a' => false,
			),
			array_merge(
				self::card( 'ropt_general', array( self::option( 'ropt_enabled' ) ) ),
				self::card( 'ropt_emptied', array( self::option( 'ropt_hidden', array( 'a' ) ) ) ),
				self::card( 'ropt_empty', array() ),
				self::card( 'ropt_kept', array( self::option( 'ropt_shown' ) ) )
			)
		);

		$this->assertSame( array( 'ropt_general', 'ropt_enabled', 'ropt_general', 'ropt_kept', 'ropt_shown', 'ropt_kept' ), $ids );
	}

	/**
	 * A section mark takes the whole card, title and end included.
	 *
	 * @return void
	 */
	public function test_a_section_mark_takes_the_whole_card(): void {
		$rows = array_map(
			static fn ( array $field ): array => $field + array( 'requires' => 'shop' ),
			self::card( 'ropt_shop', array( self::option( 'ropt_cart' ) ) )
		);

		$this->assertSame( array(), self::ids_for( array( '' => true, 'shop' => false ), $rows ) );
		$this->assertSame( array( 'ropt_shop', 'ropt_cart', 'ropt_shop' ), self::ids_for( array( '' => true, 'shop' => true ), $rows ) );
	}

	/**
	 * Without Kinsta or a page cache it can clear, their switches are not offered; the rest is.
	 *
	 * @return void
	 */
	public function test_a_switch_that_cannot_act_here_is_not_on_the_screen(): void {
		$this->assertFalse( Plugin::kinsta_is_active(), 'The process is on Kinsta, or this case means nothing.' );
		$this->assertFalse( ( new Page_Cache() )->can_clear(), 'The process can clear a page cache, or this case means nothing.' );

		$ids = self::drawn();

		foreach ( array( 'ropt_kinsta_stock_status_only', 'ropt_cart_fragments', 'ropt_page_cache_clear', 'ropt_page_cache_shop' ) as $id ) {
			$this->assertNotContains( $id, $ids, sprintf( '%s does nothing on this site.', $id ) );
		}

		$this->assertNotContains( 'ropt_porto', $ids, 'Its one switch needs Kinsta, so on this Porto shop the card has nothing to offer.' );

		foreach ( array( 'ropt_global_styles', 'ropt_classic_theme_styles', 'ropt_speculative_moderate', 'ropt_same_url_redirect', 'ropt_product_image_eager' ) as $id ) {
			$this->assertContains( $id, $ids, sprintf( '%s can act here.', $id ) );
		}
	}

	/**
	 * A block theme is built on the styles both block-style switches remove.
	 *
	 * @return void
	 */
	public function test_a_block_theme_is_offered_neither_block_style_switch(): void {
		WP_Stub::$block_theme = true;

		$ids = self::drawn();

		$this->assertNotContains( 'ropt_global_styles', $ids );
		$this->assertNotContains( 'ropt_classic_theme_styles', $ids );
		$this->assertContains( 'ropt_srcset_prune', $ids, 'The rest of the card stays.' );
	}

	/**
	 * Core adds no classic theme styles for a theme with theme.json; global styles stay.
	 *
	 * @return void
	 */
	public function test_a_theme_json_hides_only_the_classic_theme_styles(): void {
		WP_Stub::$theme_json = true;

		$ids = self::drawn();

		$this->assertNotContains( 'ropt_classic_theme_styles', $ids );
		$this->assertContains( 'ropt_global_styles', $ids );
	}

	/**
	 * A hidden group leaves no label behind on the form.
	 *
	 * @return void
	 */
	public function test_a_group_with_every_switch_hidden_has_no_label(): void {
		WP_Stub::$block_theme = true;

		ob_start();
		( new Settings_Page() )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '>Block styles</h3>', $html );
		$this->assertSame( 1, substr_count( $html, '>Page cache</h3>' ), 'The WordPress card keeps the redirect switch; the WooCommerce card has none left.' );
	}

	/**
	 * A post naming a hidden switch cannot write it, and its value is kept.
	 *
	 * @return void
	 */
	public function test_a_hidden_switch_keeps_its_value_whatever_the_post_says(): void {
		$posted = array(
			'ropt_page_cache_clear'    => 'yes',
			'ropt_srcset_prune'        => 'yes',
			Settings_Page::DRAWN_FIELD => array( 'ropt_page_cache_clear', 'ropt_srcset_prune' ),
		);

		$values = Settings_Page::sanitize_submission( Settings_Page::fields_to_save( Settings::available_fields(), $posted ), $posted );

		$this->assertSame( array( 'ropt_srcset_prune' => 'yes' ), $values );
	}

	/**
	 * Hiding a switch never changes what it does: a hidden one still reads its row.
	 *
	 * @return void
	 */
	public function test_hiding_is_only_the_screen(): void {
		WP_Stub::$options['ropt_page_cache_clear'] = 'no';

		$this->assertNotContains( 'ropt_page_cache_clear', self::drawn() );
		$this->assertFalse( Settings::feature( 'page_cache_clear' ) );
		$this->assertContains( 'ropt_page_cache_clear', Settings::option_ids(), 'Still an option: seeded, migrated and uninstalled.' );
	}

	/**
	 * A cPanel shop is offered both page cache switches, and no Kinsta one.
	 *
	 * @return void
	 */
	public function test_a_cpanel_shop_is_offered_the_page_cache_switches(): void {
		$dir = sys_get_temp_dir() . '/ropt-uapi-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/uapi', "#!/bin/sh\nexit 0\n" );
		chmod( $dir . '/uapi', 0755 );
		touch( $dir . '/ea-nginx.conf' );

		$ids     = array_column( Settings::fields_for_marks( Settings::fields(), array_merge( Settings::conditions(), array( Settings::NEEDS_HOST_CACHE => ( new Page_Cache( $dir . '/uapi', 20, false, array( $dir . '/ea-nginx.conf' ) ) )->can_clear() ) ) ), 'id' );
		$without = array_column( Settings::fields_for_marks( Settings::fields(), array_merge( Settings::conditions(), array( Settings::NEEDS_HOST_CACHE => ( new Page_Cache( $dir . '/uapi', 20, false, array( $dir . '/missing.conf' ) ) )->can_clear() ) ) ), 'id' );

		unlink( $dir . '/ea-nginx.conf' );
		unlink( $dir . '/uapi' );
		rmdir( $dir );

		$this->assertContains( 'ropt_page_cache_clear', $ids );
		$this->assertContains( 'ropt_page_cache_shop', $ids, 'Shop_Cache clears through cPanel too, not only Kinsta.' );
		$this->assertNotContains( 'ropt_kinsta_stock_status_only', $ids );
		$this->assertNotContains( 'ropt_cart_fragments', $ids );
		$this->assertNotContains( 'ropt_page_cache_clear', $without, 'A cPanel server without ea-nginx has no NGINX cache to clear.' );
		$this->assertNotContains( 'ropt_page_cache_shop', $without );
	}

	/**
	 * Ids of the fields this site's screen draws.
	 *
	 * @return array<int, string>
	 */
	private static function drawn(): array {
		return array_column( Settings::available_fields(), 'id' );
	}

	/**
	 * Ids of the fields a site with these marks would be offered.
	 *
	 * @param array<string, bool>              $present Mark => whether the site has it.
	 * @param array<int, array<string, mixed>> $fields  Field definitions.
	 * @return array<int, string>
	 */
	private static function ids_for( array $present, array $fields ): array {
		return array_map( static fn ( array $field ): string => (string) $field['id'], Settings::fields_for_marks( $fields, $present ) );
	}

	/**
	 * A card: its title, its rows, its end.
	 *
	 * @param string                           $id   Section id.
	 * @param array<int, array<string, mixed>> $rows Option rows.
	 * @return array<int, array<string, mixed>>
	 */
	private static function card( string $id, array $rows ): array {
		return array_merge(
			array(
				array(
					'id'        => $id,
					'type'      => 'title',
					'is_option' => false,
				),
			),
			$rows,
			array(
				array(
					'id'        => $id,
					'type'      => 'sectionend',
					'is_option' => false,
				),
			)
		);
	}

	/**
	 * A checkbox option, with its marks.
	 *
	 * @param string             $id    Option id.
	 * @param array<int, string> $needs Its 'needs' marks.
	 * @return array<string, mixed>
	 */
	private static function option( string $id, array $needs = array() ): array {
		$field = array(
			'id'   => $id,
			'type' => 'checkbox',
		);

		if ( array() !== $needs ) {
			$field['needs'] = $needs;
		}

		return $field;
	}
}
