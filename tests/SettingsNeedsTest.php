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
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Settings;

/**
 * Covers the marks of Settings::available_fields().
 */
#[CoversClass( Settings::class )]
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
