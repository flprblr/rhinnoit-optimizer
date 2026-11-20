<?php
/**
 * Tests for the shared menu node, its place in the menu and the settings form.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Admin\Menu;
use RhinnoIT\Optimizer\Admin\Plugin_Links;
use RhinnoIT\Optimizer\Admin\Settings_Page;

#[CoversClass( Menu::class )]
#[CoversClass( Plugin_Links::class )]
#[CoversClass( Settings_Page::class )]
final class AdminTest extends TestCase {

	/**
	 * Page slug of the other plugin of the group in the cases below.
	 *
	 * Derived from this plugin's own, so it can never be that slug in any copy.
	 * The literal it replaced, 'rhinnoit-woo-optimizer', was the optimizer's own
	 * slug, so there these cases compared the plugin with itself from the day
	 * they arrived.
	 */
	private const string SIBLING = Menu::PAGE_SLUG . '-sibling';

	protected function setUp(): void {
		WP_Stub::reset();
	}

	public function test_first_plugin_of_the_group_creates_the_parent_node(): void {
		( new Menu() )->register_pages();

		$parents = array_values( array_filter( WP_Stub::$menu, static fn( $i ) => 'parent' === $i['type'] ) );

		$this->assertCount( 1, $parents );
		$this->assertSame( 'rhinnoit-tools', $parents[0]['slug'] );
		$this->assertSame( 3, $parents[0]['position'], 'The node must sit right below Dashboard.' );
	}

	public function test_a_second_plugin_does_not_duplicate_the_parent_node(): void {
		( new Menu() )->register_pages();
		WP_Stub::$menu = array();

		( new Menu() )->register_pages();

		$parents = array_filter( WP_Stub::$menu, static fn( $i ) => 'parent' === $i['type'] );
		$this->assertSame( array(), $parents );
	}

	public function test_the_plugin_page_hangs_off_the_shared_node_with_a_callable(): void {
		( new Menu() )->register_pages();

		$children = array_values( array_filter( WP_Stub::$menu, static fn( $i ) => 'child' === $i['type'] ) );

		$this->assertCount( 1, $children, 'One entry per plugin keeps the node readable.' );
		$this->assertSame( 'rhinnoit-tools', $children[0]['parent'] );
		$this->assertTrue( $children[0]['callable'] );
	}

	public function test_the_shared_node_opens_a_page_that_renders(): void {
		( new Menu() )->register_pages();

		$entries = array_values( WP_Stub::$submenu[ ROPT_MENU_PARENT_SLUG ] );

		// WordPress takes the href of a top-level node from its first child.
		$this->assertSame( Menu::PAGE_SLUG, $entries[0]['slug'], 'The node would link to the dead back-link.' );
		$this->assertTrue( $entries[0]['callable'], 'The node would open a page nobody renders.' );
	}

	public function test_the_node_gains_no_back_link_entry_of_its_own(): void {
		( new Menu() )->register_pages();

		$slugs = array_column( WP_Stub::$submenu[ ROPT_MENU_PARENT_SLUG ], 'slug' );

		$this->assertSame( array( Menu::PAGE_SLUG ), $slugs, 'The back-link is a second dead entry under the node.' );
	}

	public function test_the_back_link_is_not_reinserted_for_the_next_plugin_of_the_group(): void {
		( new Menu() )->register_pages();
		( new Menu() )->register_pages();

		$slugs = array_column( WP_Stub::$submenu[ ROPT_MENU_PARENT_SLUG ], 'slug' );

		$this->assertNotContains( ROPT_MENU_PARENT_SLUG, $slugs );
	}

	public function test_two_plugins_of_the_group_share_a_node_that_works(): void {
		( new Menu() )->register_pages();
		$this->register_sibling( self::SIBLING );

		$entries = array_values( WP_Stub::$submenu[ ROPT_MENU_PARENT_SLUG ] );

		$this->assertSame(
			array( Menu::PAGE_SLUG, self::SIBLING ),
			array_column( $entries, 'slug' ),
			'Both plugins hang off the node, and neither adds a back-link to it.'
		);
		$this->assertTrue( $entries[0]['callable'], 'The node opens whichever of them registered first.' );
	}

	public function test_this_plugin_repairs_a_node_an_older_sibling_left_dead(): void {
		// A plugin built before the fix: it creates the node and leaves the
		// back-link core inserted for it.
		$this->register_sibling( self::SIBLING, false );

		( new Menu() )->register_pages();

		$slugs = array_column( array_values( WP_Stub::$submenu[ ROPT_MENU_PARENT_SLUG ] ), 'slug' );

		$this->assertSame(
			array( self::SIBLING, Menu::PAGE_SLUG ),
			$slugs,
			'Installing this plugin next to an unfixed one has to fix the node for both.'
		);
	}

	public function test_an_older_sibling_registering_later_cannot_break_the_node_again(): void {
		( new Menu() )->register_pages();
		$this->register_sibling( self::SIBLING, false );

		$slugs = array_column( array_values( WP_Stub::$submenu[ ROPT_MENU_PARENT_SLUG ] ), 'slug' );

		// Core only inserts the back-link for a node with no children at all.
		$this->assertNotContains( ROPT_MENU_PARENT_SLUG, $slugs );
	}

	public function test_register_hooks_everything_the_page_depends_on(): void {
		( new Menu() )->register();

		// Each of these is one line in register() and a silent loss if it goes:
		// the page itself and the order that keeps the node below Dashboard.
		foreach ( array( 'admin_menu', 'custom_menu_order', 'menu_order' ) as $hook ) {
			$this->assertContains( $hook, WP_Stub::$hooks );
		}

		// Last, or Site Kit's own filter, added later on init, takes the slot.
		$this->assertSame( PHP_INT_MAX, WP_Stub::$priorities['menu_order:move_below_dashboard'] ?? null );

		// The form draws its own fields, so this page is not a WooCommerce
		// screen and must not claim to be one.
		$this->assertNotContains( 'woocommerce_screen_ids', WP_Stub::$hooks );
	}

	public function test_the_node_sits_right_below_dashboard_whatever_else_wanted_the_slot(): void {
		$menu = new Menu();

		// What a real shop looks like: two other plugins got there first, and
		// add_menu_page() resolved the tie with a hash, leaving ours fourth.
		$order = array( 'index.php', 'elementor', 'alpus-addons', ROPT_MENU_PARENT_SLUG, 'separator1', 'edit.php' );

		$this->assertSame(
			array( 'index.php', ROPT_MENU_PARENT_SLUG, 'elementor', 'alpus-addons', 'separator1', 'edit.php' ),
			$menu->move_below_dashboard( $order )
		);
	}

	public function test_the_node_goes_back_above_site_kit(): void {
		// What Site Kit's filter leaves: its node spliced in right after Dashboard.
		$order = array( 'index.php', 'googlesitekit-dashboard', ROPT_MENU_PARENT_SLUG, 'separator1', 'edit.php' );

		$this->assertSame(
			array( 'index.php', ROPT_MENU_PARENT_SLUG, 'googlesitekit-dashboard', 'separator1', 'edit.php' ),
			( new Menu() )->move_below_dashboard( $order )
		);
	}

	public function test_moving_the_node_is_idempotent_across_the_group(): void {
		$menu  = new Menu();
		$order = array( 'index.php', 'elementor', ROPT_MENU_PARENT_SLUG, 'edit.php' );

		// Every plugin of the group registers this filter and moves the same
		// slug, so running it twice has to be the same as running it once.
		$once  = $menu->move_below_dashboard( $order );
		$twice = $menu->move_below_dashboard( $once );

		$this->assertSame( $once, $twice );
	}

	public function test_the_order_filter_leaves_a_menu_it_does_not_recognise_alone(): void {
		$menu = new Menu();

		// Another plugin can hand this filter anything, and dropping the node
		// on a menu with no Dashboard would hide it altogether.
		$this->assertSame( 'not an array', $menu->move_below_dashboard( 'not an array' ) );
		$this->assertSame(
			array( 'edit.php', ROPT_MENU_PARENT_SLUG ),
			$menu->move_below_dashboard( array( 'edit.php', ROPT_MENU_PARENT_SLUG ) ),
			'No Dashboard to sit under, so nothing moves.'
		);
	}

	public function test_the_menu_order_filter_is_turned_on(): void {
		// WordPress ignores menu_order entirely unless something returns true
		// here, so the reordering above would never run.
		$this->assertTrue( ( new Menu() )->enable_menu_order( false ) );
	}

	public function test_the_node_uses_the_group_icon(): void {
		( new Menu() )->register_pages();

		$parents = array_values( array_filter( WP_Stub::$menu, static fn( $i ) => 'parent' === $i['type'] ) );

		$this->assertSame( 'dashicons-admin-settings', $parents[0]['icon'] );
	}

	public function test_a_non_string_page_parameter_does_not_match_this_plugin(): void {
		$_GET['page'] = array( Menu::PAGE_SLUG );

		$this->assertFalse( Menu::is_current_page() );
	}

	/**
	 * The heading renders, and that is all this proves.
	 *
	 * ROPT_MENU_TITLE is a constant with nothing escapable in it, so the regex
	 * below cannot fail: it is here to catch a page that starts printing raw
	 * markup, not to guard the esc_html() around the title. The dynamic values
	 * the screen prints have their own case: the stored option values, in
	 * SettingsPageTest. Print another and it gets one too.
	 *
	 * @return void
	 */
	public function test_the_page_prints_its_title(): void {
		$html = $this->render_page();

		$this->assertStringContainsString( '<h1>Optimizer</h1>', $html );
		$this->assertDoesNotMatchRegularExpression( '/<(script|img|iframe)\b/i', $html );
	}

	public function test_saving_checks_the_shop_manager_capability(): void {
		try {
			( new Settings_Page() )->save();
		} catch ( WP_Halt $e ) {
			unset( $e );
		}

		$this->assertContains(
			Menu::CAPABILITY,
			WP_Stub::$caps_checked,
			'The guard asked about some other capability, so the cases above pass on a weaker one.'
		);
		$this->assertSame( 'manage_woocommerce', Menu::CAPABILITY, 'Anything weaker lets an editor change shop settings.' );
	}

	public function test_a_hostile_plugin_links_value_is_left_alone(): void {
		// Core feeds the shop-wide plugin_action_links filter's result into this
		// one, so a third party doing a bare return hands us null - and a
		// TypeError here ends the plugins screen mid-table.
		$this->assertSame( 'not an array', ( new Plugin_Links() )->add_settings_link( 'not an array' ) );

		$links = ( new Plugin_Links() )->add_settings_link( array( 'deactivate' => '<a>x</a>' ) );

		$this->assertSame( 'settings', array_key_first( (array) $links ), 'The shortcut goes first.' );
	}

	public function test_the_page_is_the_settings_form(): void {
		$html = $this->render_page();

		$this->assertStringContainsString( '_wpnonce', $html, 'No nonce field means check_admin_referer() rejects every save.' );
		$this->assertSame(
			Settings_Page::SAVE_ACTION,
			WP_Stub::$nonce_action,
			'The form and the handler have to share one action, or the screen renders and never saves.'
		);
		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringNotContainsString( 'nav-tab', $html, 'One screen does not need a tab bar.' );
	}

	public function test_a_save_leaves_every_undrawn_option_alone(): void {
		// A field the form did not draw is absent from the post, exactly like
		// an unchecked box. Only the drawn list tells them apart.
		WP_Stub::$options['ropt_scope_wordpress']   = array( 'posts' );
		WP_Stub::$options['ropt_scope_woocommerce'] = array( 'cart' );

		$_POST = array(
			'ropt_enabled' => 'yes',
			'ropt_drawn'   => array( 'ropt_enabled' ),
		);

		$this->save();

		$this->assertSame( array( 'posts' ), WP_Stub::$options['ropt_scope_wordpress'] );
		$this->assertSame( array( 'cart' ), WP_Stub::$options['ropt_scope_woocommerce'] );
	}

	public function test_saving_requires_the_capability(): void {
		WP_Stub::$can = false;

		$this->expectException( WP_Halt::class );
		( new Settings_Page() )->save();
	}

	public function test_saving_without_the_capability_writes_nothing(): void {
		WP_Stub::$can = false;
		WP_Stub::$options['ropt_enabled'] = 'no';

		$_POST = array(
			'ropt_enabled' => 'yes',
			'ropt_drawn'   => array( 'ropt_enabled' ),
		);

		$this->save();

		$this->assertSame( 'no', WP_Stub::$options['ropt_enabled'] );
		$this->assertSame( array(), WP_Stub::$fired, 'Nothing was saved, so nothing is announced.' );
	}

	public function test_saving_with_an_invalid_nonce_writes_nothing(): void {
		WP_Stub::$nonce_ok = false;
		WP_Stub::$options['ropt_enabled'] = 'no';

		$_POST = array(
			'ropt_enabled' => 'yes',
			'ropt_drawn'   => array( 'ropt_enabled' ),
		);

		$this->save();

		$this->assertSame( 'no', WP_Stub::$options['ropt_enabled'] );
	}

	public function test_a_valid_save_redirects_back_to_the_page(): void {
		// What a browser posts: the checked boxes, and the list of fields the
		// form drew. Every box of the WooCommerce list was drawn and left
		// unchecked, so the list is absent from the post altogether.
		$_POST = array(
			'ropt_enabled'         => 'yes',
			'ropt_scope_wordpress' => array( 'posts' ),
			'ropt_drawn'           => array( 'ropt_enabled', 'ropt_scope_wordpress', 'ropt_scope_woocommerce' ),
		);

		$this->save();

		$this->assertSame( 'yes', WP_Stub::$options['ropt_enabled'] ?? null, 'A checked box is stored.' );
		$this->assertSame( array( 'posts' ), WP_Stub::$options['ropt_scope_wordpress'] ?? null );
		$this->assertSame( array(), WP_Stub::$options['ropt_scope_woocommerce'] ?? null, 'A list the browser did not send was emptied.' );
		$this->assertContains( array( Settings_Page::SAVED_HOOK ), WP_Stub::$fired, 'The group hears every save, or the page cache serves the old pages.' );
		$this->assertStringContainsString( 'page=' . Menu::PAGE_SLUG, (string) WP_Stub::$redirect );
		$this->assertStringContainsString( 'updated=1', (string) WP_Stub::$redirect );
	}

	public function test_the_group_hooks_carry_no_plugin_prefix(): void {
		// Every plugin of the group fires the same two, so the one that clears
		// the page cache hears all of them. A prefix would make each private.
		$this->assertSame( 'rhinnoit_settings_saved', Settings_Page::SAVED_HOOK );
		$this->assertSame( 'rhinnoit_page_cache_dirty', RhinnoIT\Optimizer\Install::DIRTY_HOOK );
	}

	public function test_a_shop_keeps_the_shop_manager_capability(): void {
		// The group shares one capability, so a shop manager who sees one entry
		// of the node sees them all. That only holds while WooCommerce is there.
		$this->assertSame( 'manage_woocommerce', Menu::capability() );

		( new Menu() )->register_pages();

		$this->assertSame( array( 'manage_woocommerce' ), array_values( array_unique( array_column( WP_Stub::$menu, 'cap' ) ) ) );
	}

	/**
	 * What another plugin of the group does on admin_menu.
	 *
	 * @param string $slug  Its own page slug.
	 * @param bool   $fixed Whether it carries the back-link removal.
	 * @return void
	 */
	private function register_sibling( string $slug, bool $fixed = true ): void {
		$this->assertNotSame( Menu::PAGE_SLUG, $slug, 'A sibling with this plugin\'s own slug compares the plugin with itself.' );

		if ( ! isset( $GLOBALS['admin_page_hooks'][ ROPT_MENU_PARENT_SLUG ] ) ) {
			add_menu_page(
				ROPT_MENU_PARENT_TITLE,
				ROPT_MENU_PARENT_TITLE,
				Menu::CAPABILITY,
				ROPT_MENU_PARENT_SLUG,
				'',
				Menu::PARENT_ICON,
				Menu::MENU_POSITION
			);
		}

		add_submenu_page( ROPT_MENU_PARENT_SLUG, 'Sibling', 'Sibling', Menu::CAPABILITY, $slug, static function (): void {} );

		if ( $fixed ) {
			remove_submenu_page( ROPT_MENU_PARENT_SLUG, ROPT_MENU_PARENT_SLUG );
		}
	}

	/**
	 * Submits the form, swallowing the halt that stands in for exit.
	 *
	 * @return void
	 */
	private function save(): void {
		try {
			( new Settings_Page() )->save();
		} catch ( WP_Halt $e ) {
			unset( $e );
		}
	}

	/**
	 * Captures the rendered plugin page.
	 *
	 * @return string
	 */
	private function render_page(): string {
		ob_start();
		( new Menu() )->render();

		return (string) ob_get_clean();
	}
}
