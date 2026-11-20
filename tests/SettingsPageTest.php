<?php
/**
 * The settings form: what it draws and what it agrees to store.
 *
 * The form draws itself, so the guarantees WC_Admin_Settings would give for
 * free have to be earned here: an undeclared key is never stored, a structural
 * row is never stored, and a value read back from the database is escaped
 * before it reaches the page.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Admin\Settings_Page;
use RhinnoIT\Optimizer\Settings;

/**
 * Covers RhinnoIT\Optimizer\Admin\Settings_Page.
 */
#[CoversClass( Settings_Page::class )]
final class SettingsPageTest extends TestCase {

	/**
	 * Starts from a configured site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * Draws the form and returns the HTML.
	 *
	 * @return string
	 */
	private function form(): string {
		ob_start();
		( new Settings_Page() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * A key nobody declared is never stored, whatever the post carries.
	 *
	 * @return void
	 */
	public function test_an_undeclared_key_is_never_stored(): void {
		$values = Settings_Page::sanitize_submission(
			Settings::fields(),
			array(
				'ropt_enabled'  => 'yes',
				'ropt_is_admin' => 'yes',
				'siteurl'       => 'https://attacker.test',
			)
		);

		$this->assertArrayNotHasKey( 'ropt_is_admin', $values );
		$this->assertArrayNotHasKey( 'siteurl', $values, 'A crafted post must not reach core options.' );
		$this->assertSame( 'yes', $values['ropt_enabled'] );
	}

	/**
	 * A section heading is form structure and is never stored as an option.
	 *
	 * @return void
	 */
	public function test_a_structural_row_is_never_stored(): void {
		$values = Settings_Page::sanitize_submission(
			Settings::fields(),
			array(
				'ropt_general'     => 'yes',
				'ropt_wordpress'   => 'yes',
				'ropt_woocommerce' => 'yes',
			)
		);

		$this->assertArrayNotHasKey( 'ropt_general', $values );
		$this->assertArrayNotHasKey( 'ropt_wordpress', $values );
		$this->assertArrayNotHasKey( 'ropt_woocommerce', $values );

		// The type already keeps a title out. This isolates the flag itself: a
		// row marked structural is skipped even when its type could be stored.
		$flagged = Settings_Page::sanitize_submission(
			array(
				array(
					'id'        => 'ropt_structural',
					'type'      => 'checkbox',
					'is_option' => false,
				),
			),
			array( 'ropt_structural' => 'yes' )
		);

		$this->assertSame( array(), $flagged );
	}

	/**
	 * A checkbox is yes only for the exact value, and no when absent.
	 *
	 * Browsers send nothing for an unchecked box, so absence is the "off" signal.
	 * Anything but the literal the form prints is off too: an array, a "1", a
	 * "true" all mean somebody built the post by hand.
	 *
	 * @return void
	 */
	public function test_a_checkbox_is_yes_only_for_the_exact_value(): void {
		$this->assertSame( 'yes', Settings_Page::sanitize_submission( Settings::fields(), array( 'ropt_enabled' => 'yes' ) )['ropt_enabled'] );
		$this->assertSame( 'no', Settings_Page::sanitize_submission( Settings::fields(), array() )['ropt_enabled'], 'An absent box was unchecked.' );

		foreach ( array( '1', 'true', 'on', array( 'yes' ) ) as $posted ) {
			$this->assertSame(
				'no',
				Settings_Page::sanitize_submission( Settings::fields(), array( 'ropt_enabled' => $posted ) )['ropt_enabled'],
				sprintf( 'Stored as on for %s.', var_export( $posted, true ) )
			);
		}
	}

	/**
	 * The form shows one card per thing the shop runs, in order.
	 *
	 * @return void
	 */
	public function test_a_shop_sees_a_card_per_domain(): void {
		$html = $this->form();

		$this->assertStringContainsString( '<h2 class="ropt-card__title">WordPress</h2>', $html );
		$this->assertSame( 2, substr_count( $html, '<section class="ropt-card">' ), 'The master switch, then one card per domain with a switch to offer.' );
		$this->assertSame( 1, substr_count( $html, '<h2' ), 'The master switch has no heading of its own.' );
		$this->assertSame( substr_count( $html, '<section' ), substr_count( $html, '</section>' ), 'Every card closes what it opened.' );
		$this->assertSame( substr_count( $html, '<div' ), substr_count( $html, '</div>' ), 'Every row closes what it opened.' );
	}

	/**
	 * Every checkbox is drawn as an on/off switch, and its row label toggles it.
	 *
	 * Still a real checkbox, so the save logic above does not change; the role
	 * tells a screen reader it is a switch, and the class is what the admin
	 * stylesheet draws.
	 *
	 * @return void
	 */
	public function test_every_checkbox_is_drawn_as_a_switch(): void {
		$html       = $this->form();
		$checkboxes = substr_count( $html, 'type="checkbox"' );

		$this->assertSame( 15, $checkboxes, 'One switch per optimization that can apply, plus the master switch: not the page cache one on a site without a cache to clear.' );
		$this->assertSame( $checkboxes, substr_count( $html, 'type="checkbox" role="switch" class="ropt-switch"' ) );
		$this->assertStringContainsString( '<label class="ropt-row__label" for="ropt_enabled">Apply the optimizations</label>', $html );
		$this->assertStringContainsString( 'aria-describedby="ropt_enabled-desc"', $html );
		$this->assertStringContainsString( '<p class="ropt-row__desc" id="ropt_enabled-desc">', $html );
	}

	/**
	 * A group label is printed once, above the first switch of its run.
	 *
	 * @return void
	 */
	public function test_a_group_label_heads_its_run_once(): void {
		$html = $this->form();

		$this->assertSame( 1, substr_count( $html, '<h3 class="ropt-card__group">Page head</h3>' ) );
		$this->assertLessThan( strpos( $html, 'for="ropt_head_rsd"' ), strpos( $html, '>Page head</h3>' ) );
		$this->assertGreaterThan( strpos( $html, 'for="ropt_classic_theme_styles"' ), strpos( $html, '>Page head</h3>' ) );
		$this->assertSame( 6, substr_count( $html, '<h3 class="ropt-card__group">' ), 'The six groups of the WordPress card.' );
	}

	/**
	 * A value read back from the database never reaches the page.
	 *
	 * The screen prints only whether a box is on, never what the row holds, so
	 * markup written into an option cannot surface here in any form.
	 *
	 * @return void
	 */
	public function test_a_stored_value_cannot_reach_the_page(): void {
		WP_Stub::$options['ropt_enabled'] = '"><script>alert(1)</script>';

		$html = $this->form();

		$this->assertDoesNotMatchRegularExpression( '/<script\b/i', $html );
		$this->assertStringNotContainsString( 'alert(', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="ropt_enabled" value="yes"[^>]* checked/', $html, 'And it reads as off.' );
	}

	/**
	 * A box is drawn checked exactly when its option is yes.
	 *
	 * @return void
	 */
	public function test_a_box_is_checked_exactly_when_its_option_is_yes(): void {
		WP_Stub::$options['ropt_enabled'] = 'yes';

		$this->assertMatchesRegularExpression( '/name="ropt_enabled" value="yes"[^>]* checked="checked"/', $this->form() );

		// Settings reads anything but 'yes' as off, so the screen must not draw
		// any of these as on.
		foreach ( array( 'no', '1', 'on', '', array( 'yes' ), 42, true ) as $stored ) {
			WP_Stub::$options['ropt_enabled'] = $stored;

			$this->assertDoesNotMatchRegularExpression(
				'/name="ropt_enabled" value="yes"[^>]* checked/',
				$this->form(),
				sprintf( 'Drawn on for %s, which the plugin reads as off.', var_export( $stored, true ) )
			);
		}
	}

	/**
	 * Nothing has been retired, so a form this version drew is never turned away.
	 *
	 * @return void
	 */
	public function test_a_current_form_is_not_taken_for_an_older_one(): void {
		$this->assertFalse( Settings_Page::drawn_by_an_older_version( array( 'ropt_drawn' => Settings::option_ids() ) ) );
		$this->assertFalse( Settings_Page::drawn_by_an_older_version( array( 'ropt_drawn' => array( 'ropt_enabled' ) ) ) );
	}

	/**
	 * A form that lists nothing it drew is refused, not saved as nothing.
	 *
	 * Every form this version draws lists the master switch. One that lists
	 * nothing was drawn before the list existed - WC_Admin_Settings drew the
	 * fields then - and would store nothing while the redirect said it had.
	 *
	 * @return void
	 */
	public function test_a_form_that_lists_nothing_it_drew_is_refused(): void {
		WP_Stub::$options['ropt_enabled'] = 'no';

		foreach ( array( null, 'ropt_enabled', array(), array( array( 'ropt_enabled' ) ) ) as $drawn ) {
			$this->assertTrue( Settings_Page::drawn_by_an_older_version( null === $drawn ? array() : array( 'ropt_drawn' => $drawn ) ) );
		}

		// What the form posted before it drew itself.
		$_POST = array( 'ropt_enabled' => '1' );

		try {
			( new Settings_Page() )->save();
		} catch ( WP_Halt $e ) {
			unset( $e );
		}

		$this->assertStringContainsString( 'outdated=1', (string) WP_Stub::$redirect, 'The screen asks for a reload.' );
		$this->assertStringNotContainsString( 'updated=1', (string) WP_Stub::$redirect, 'Nothing was saved, so it must not say so.' );
		$this->assertSame( array(), WP_Stub::$fired, 'Nor tell the group it was saved.' );
		$this->assertSame( 'no', WP_Stub::$options['ropt_enabled'] );
	}

	/**
	 * The screen explains a refused save instead of claiming it worked.
	 *
	 * @return void
	 */
	public function test_a_refused_save_says_so(): void {
		$_GET['outdated'] = '1';

		$html = $this->form();

		$this->assertStringContainsString( 'nothing was saved', $html );
		$this->assertStringNotContainsString( 'Settings saved.', $html );
	}

	/**
	 * Every option the form draws carries its marker, once.
	 *
	 * @return void
	 */
	public function test_every_drawn_option_carries_its_marker(): void {
		$html = $this->form();

		foreach ( array( 'ropt_enabled', 'ropt_srcset_prune', 'ropt_speculative_moderate' ) as $id ) {
			$this->assertSame( 1, substr_count( $html, sprintf( 'name="ropt_drawn[]" value="%s"', $id ) ), sprintf( '%s is drawn once, so it is marked once.', $id ) );
		}

		$this->assertStringNotContainsString( 'value="ropt_wordpress"', $html, 'A section heading is not an option and has no marker.' );
	}
}
