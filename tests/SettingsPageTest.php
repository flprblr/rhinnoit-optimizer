<?php
/**
 * The settings form: what it draws and what it agrees to store.
 *
 * The form draws itself, so the guarantees WC_Admin_Settings would give for
 * free have to be earned here: an undeclared key is never stored, a structural
 * row is never stored, a list keeps only the ids its field offers, and a value
 * read back from the database is escaped before it reaches the page.
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
	 * A list keeps only the ids its own field offers, each once.
	 *
	 * @return void
	 */
	public function test_a_list_keeps_only_the_ids_its_field_offers(): void {
		$values = Settings_Page::sanitize_submission(
			Settings::fields(),
			array(
				'ropt_scope_wordpress'   => array( 'posts', 'cart', 'posts', array( 'pages' ), 7, 'nonsense', 'pages' ),
				'ropt_scope_woocommerce' => 'cart',
			)
		);

		$this->assertSame( array( 'posts', 'pages' ), $values['ropt_scope_wordpress'], 'A WooCommerce id, a duplicate, a nested array and an unknown id all go.' );
		$this->assertSame( array(), $values['ropt_scope_woocommerce'], 'Not a list: not a form this plugin drew.' );
		$this->assertSame(
			array(),
			Settings_Page::sanitize_submission( Settings::fields(), array() )['ropt_scope_wordpress'],
			'A list with every box unchecked is absent from the post, and stores the empty list.'
		);
	}

	/**
	 * The form shows both sections on a shop.
	 *
	 * @return void
	 */
	public function test_a_shop_sees_both_sections(): void {
		$html = $this->form();

		$this->assertStringContainsString( '<h2 class="ropt-card__title">WordPress</h2>', $html );
		$this->assertStringContainsString( '<h2 class="ropt-card__title">WooCommerce</h2>', $html );
		$this->assertLessThan( strpos( $html, '>WooCommerce</h2>' ), strpos( $html, '>WordPress</h2>' ), 'The platform before the addition.' );
		$this->assertSame( 3, substr_count( $html, '<section class="ropt-card">' ), 'The master switch, then one card per platform.' );
		$this->assertSame( 2, substr_count( $html, '<h2' ), 'The master switch has no heading of its own.' );
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

		$this->assertSame( 9, $checkboxes, 'The master switch, three WordPress contexts and five WooCommerce ones.' );
		$this->assertSame( $checkboxes, substr_count( $html, 'type="checkbox" role="switch" class="ropt-switch"' ) );
		$this->assertStringContainsString( '<label class="ropt-row__label" for="ropt_enabled">Enable</label>', $html );
		$this->assertStringContainsString( 'aria-describedby="ropt_enabled-desc"', $html );
		$this->assertStringContainsString( '<p class="ropt-row__desc" id="ropt_enabled-desc">', $html );
	}

	/**
	 * A list is a labelled group with one switch per choice, posted as a list.
	 *
	 * @return void
	 */
	public function test_a_list_is_drawn_as_a_labelled_group_of_switches(): void {
		$html = $this->form();

		$this->assertStringContainsString( '<div class="ropt-group" role="group" aria-labelledby="ropt_scope_wordpress-label" aria-describedby="ropt_scope_wordpress-desc">', $html );
		$this->assertStringContainsString( '<h3 class="ropt-card__group" id="ropt_scope_wordpress-label">Load the script on</h3>', $html );
		$this->assertStringContainsString( '<label class="ropt-row__label" for="ropt_scope_wordpress-posts">Blog posts</label>', $html );
		$this->assertStringContainsString( 'id="ropt_scope_wordpress-posts" name="ropt_scope_wordpress[]" value="posts"', $html );
		$this->assertSame( 3, substr_count( $html, 'name="ropt_scope_wordpress[]"' ), 'One switch per WordPress context.' );
		$this->assertSame( 5, substr_count( $html, 'name="ropt_scope_woocommerce[]"' ), 'One switch per WooCommerce context.' );
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
		WP_Stub::$options['ropt_enabled']         = '"><script>alert(1)</script>';
		WP_Stub::$options['ropt_scope_wordpress'] = array( '"><script>alert(2)</script>', 'posts' );

		$html = $this->form();

		$this->assertDoesNotMatchRegularExpression( '/<script\b/i', $html );
		$this->assertStringNotContainsString( 'alert(', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="ropt_enabled" value="yes"[^>]* checked/', $html, 'And it reads as off.' );
		$this->assertMatchesRegularExpression( '/name="ropt_scope_wordpress\[\]" value="posts" checked="checked"/', $html, 'The known id next to it still reads as on.' );
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
	 * A choice is drawn checked exactly when its list holds it.
	 *
	 * @return void
	 */
	public function test_a_choice_is_checked_exactly_when_its_list_holds_it(): void {
		WP_Stub::$options['ropt_scope_wordpress'] = array( 'pages' );

		$html = $this->form();

		$this->assertMatchesRegularExpression( '/value="pages" checked="checked"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="posts" checked/', $html );

		// A row that is not a list, or holds the id as something else, checks nothing.
		foreach ( array( 'pages', array( array( 'pages' ) ), array( 1 ) ) as $stored ) {
			WP_Stub::$options['ropt_scope_wordpress'] = $stored;

			$this->assertDoesNotMatchRegularExpression(
				'/name="ropt_scope_wordpress\[\]" value="[a-z]+" checked/',
				$this->form(),
				sprintf( 'Drawn on for %s.', var_export( $stored, true ) )
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
		WP_Stub::$options['ropt_enabled']         = 'no';
		WP_Stub::$options['ropt_scope_wordpress'] = array();

		foreach ( array( null, 'ropt_enabled', array(), array( array( 'ropt_enabled' ) ) ) as $drawn ) {
			$this->assertTrue( Settings_Page::drawn_by_an_older_version( null === $drawn ? array() : array( 'ropt_drawn' => $drawn ) ) );
		}

		// What the form posted before it drew itself.
		$_POST = array(
			'ropt_enabled'         => '1',
			'ropt_scope_wordpress' => array( 'posts', 'pages' ),
		);

		try {
			( new Settings_Page() )->save();
		} catch ( WP_Halt $e ) {
			unset( $e );
		}

		$this->assertStringContainsString( 'outdated=1', (string) WP_Stub::$redirect, 'The screen asks for a reload.' );
		$this->assertStringNotContainsString( 'updated=1', (string) WP_Stub::$redirect, 'Nothing was saved, so it must not say so.' );
		$this->assertSame( array(), WP_Stub::$fired, 'Nor tell the group it was saved.' );
		$this->assertSame( 'no', WP_Stub::$options['ropt_enabled'] );
		$this->assertSame( array(), WP_Stub::$options['ropt_scope_wordpress'] );
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
	 * A field that was not on the screen is not stored as unchecked.
	 *
	 * The field list is rebuilt at save time. If WooCommerce became available
	 * while the form was open, its section was never drawn, and storing its
	 * absent list as empty would undo a choice nobody touched.
	 *
	 * @return void
	 */
	public function test_a_field_that_was_not_drawn_is_left_alone(): void {
		$fields = Settings_Page::fields_to_save(
			Settings::fields(),
			array( 'ropt_drawn' => array( 'ropt_enabled', 'ropt_scope_wordpress' ) )
		);

		$this->assertSame( array( 'ropt_enabled', 'ropt_scope_wordpress' ), array_column( $fields, 'id' ) );
		$this->assertArrayNotHasKey(
			'ropt_scope_woocommerce',
			Settings_Page::sanitize_submission( $fields, array() ),
			'Undrawn, so its stored value survives.'
		);
	}

	/**
	 * The drawn list can narrow the save but never widen it.
	 *
	 * @return void
	 */
	public function test_the_drawn_list_cannot_name_a_field_this_site_lacks(): void {
		$wordpress_only = array_values(
			array_filter(
				Settings::fields(),
				static fn ( array $field ): bool => 'woocommerce' !== ( $field['requires'] ?? '' )
			)
		);

		$fields = Settings_Page::fields_to_save(
			$wordpress_only,
			array( 'ropt_drawn' => array( 'ropt_scope_woocommerce', 'siteurl', array( 'x' ) ) )
		);

		$this->assertSame( array(), $fields, 'A crafted marker is intersected with what the site can use.' );
		$this->assertSame( array(), Settings_Page::fields_to_save( $wordpress_only, array( 'ropt_drawn' => 'not a list' ) ) );
	}

	/**
	 * Every option the form draws carries its marker, once.
	 *
	 * @return void
	 */
	public function test_every_drawn_option_carries_its_marker(): void {
		$html = $this->form();

		foreach ( array( 'ropt_enabled', 'ropt_scope_wordpress', 'ropt_scope_woocommerce' ) as $id ) {
			$this->assertSame( 1, substr_count( $html, sprintf( 'name="ropt_drawn[]" value="%s"', $id ) ), sprintf( '%s is drawn once, so it is marked once.', $id ) );
		}

		$this->assertStringNotContainsString( 'value="ropt_wordpress"', $html, 'A section heading is not an option and has no marker.' );
	}
}
