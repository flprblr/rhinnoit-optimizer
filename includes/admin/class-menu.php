<?php
/**
 * Shared menu node and plugin page.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer\Admin;

use RhinnoIT\Optimizer\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Hangs the plugin page off the node shared by every plugin in the group.
 */
final class Menu {

	/**
	 * Slug of the plugin page, which is the plugin slug.
	 *
	 * It is what ?page= carries, so it says which plugin the screen belongs to
	 * rather than making the reader decode a prefix, the way core's own
	 * page=wc-settings does. It also collides with less: a four-letter slug in
	 * a shared query parameter is a coin toss on a shop with forty plugins.
	 *
	 * The renamer rewrites this through the slug, not the function prefix, so
	 * anything comparing against it has to use the constant - a literal 'ropt'
	 * elsewhere would be rewritten through the prefix instead and the two would
	 * drift apart in every copy.
	 */
	const string PAGE_SLUG = 'rhinnoit-optimizer';

	/**
	 * Capability required to view and save on a shop.
	 *
	 * Shared across every plugin of the group, so a shop manager who can see
	 * one entry of the RHINNO IT node can see them all.
	 */
	const string CAPABILITY = 'manage_woocommerce';

	/**
	 * Capability required on a site without WooCommerce.
	 *
	 * WooCommerce registers manage_woocommerce itself, so on a plain WordPress
	 * site nobody holds it and the page would be invisible to everyone, the
	 * administrator included.
	 */
	const string FALLBACK_CAPABILITY = 'manage_options';

	/**
	 * Parent node icon. Must be the same in every plugin of the group.
	 */
	const string PARENT_ICON = 'dashicons-admin-settings';

	/**
	 * Parent node position: the free slot between Dashboard (2) and the first
	 * separator (4).
	 *
	 * Only a request. add_menu_page() breaks a tie on an occupied slot with a
	 * fraction derived from md5( slug . title ), so with two plugins asking for
	 * 3 the winner is effectively random, and the node ends up under whatever
	 * else wanted to be there. move_below_dashboard() is what actually decides.
	 */
	const int MENU_POSITION = 3;

	/**
	 * The settings form, which is the whole page.
	 *
	 * @var Settings_Page
	 */
	private Settings_Page $settings;

	/**
	 * Creates the view.
	 */
	public function __construct() {
		$this->settings = new Settings_Page();
	}

	/**
	 * Hooks the menu and the form handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_pages' ) );
		add_filter( 'custom_menu_order', array( $this, 'enable_menu_order' ) );
		// Last: Site Kit moves its own node right below Dashboard from a filter
		// added on init, after this one, so at the same priority it won.
		add_filter( 'menu_order', array( $this, 'move_below_dashboard' ), PHP_INT_MAX );

		$this->settings->register();
	}

	/**
	 * Declares the plugin page and the parent node.
	 *
	 * @return void
	 */
	public function register_pages(): void {
		$this->register_parent();

		add_submenu_page(
			ROPT_MENU_PARENT_SLUG,
			ROPT_MENU_TITLE,
			ROPT_MENU_TITLE,
			self::capability(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		/*
		 * A node that had no children gets a back-link to itself as its first
		 * child, inserted by add_submenu_page(). WordPress then takes the href
		 * of a top-level node from that first child, and this parent carries no
		 * callback, so both the node and the entry it just gained point at a
		 * page nobody renders.
		 *
		 * Removing the back-link leaves this plugin's page first, which is what
		 * the node should open. The submenu key stays declared, so the next
		 * plugin of the group gets no back-link of its own either, and calling
		 * this when the back-link is already gone does nothing.
		 */
		remove_submenu_page( ROPT_MENU_PARENT_SLUG, ROPT_MENU_PARENT_SLUG );
	}

	/**
	 * Creates the parent node unless another plugin of the group already did.
	 *
	 * The trace add_menu_page() leaves in $admin_page_hooks works as the check:
	 * nothing has to be coordinated between plugins and no state is stored. If
	 * the one that created it gets deactivated, the next one creates it.
	 *
	 * @return void
	 */
	private function register_parent(): void {
		if ( isset( $GLOBALS['admin_page_hooks'][ ROPT_MENU_PARENT_SLUG ] ) ) {
			return;
		}

		add_menu_page(
			ROPT_MENU_PARENT_TITLE,
			ROPT_MENU_PARENT_TITLE,
			self::capability(),
			ROPT_MENU_PARENT_SLUG,
			'',
			self::PARENT_ICON,
			self::MENU_POSITION
		);
	}

	/**
	 * Prints the page: the heading, then the form.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( ROPT_MENU_TITLE ); ?></h1>
			<?php $this->settings->render(); ?>
		</div>
		<?php
	}

	/**
	 * Turns on the menu_order filter, which WordPress ignores otherwise.
	 *
	 * Enabling it is additive: WooCommerce and others already do it to place
	 * their own nodes, and returning true never undoes their ordering. It does
	 * cut both ways - a menu_order filter another plugin registered and never
	 * saw run starts running too - so a scrambled admin menu on a shop is worth
	 * checking here first.
	 *
	 * @param mixed $enabled Whether some plugin already asked for it.
	 * @return bool
	 */
	public function enable_menu_order( mixed $enabled ): bool {
		unset( $enabled );

		return true;
	}

	/**
	 * Puts the shared node straight below Dashboard, whatever else asked for
	 * the slot.
	 *
	 * The position handed to add_menu_page() cannot guarantee this: a taken
	 * slot is resolved by a hash of the slug, so a shop with two other plugins
	 * aiming at 3 pushes the node to fourth. This filter runs once every menu
	 * is registered, so it is the only place where the answer is deterministic -
	 * as long as it runs last: Site Kit splices its own node in right after
	 * Dashboard from a menu_order filter of its own, added on init, and at the
	 * same priority it ran after this one and took the slot.
	 *
	 * Both plugins of the group move the same slug to the same place, so it
	 * does not matter how many of them do it, or in which order.
	 *
	 * @param mixed $order Top-level menu slugs, in the order WordPress will print them.
	 * @return mixed
	 */
	public function move_below_dashboard( mixed $order ): mixed {
		if ( ! is_array( $order ) || ! in_array( 'index.php', $order, true ) ) {
			return $order;
		}

		$reordered = array();

		foreach ( $order as $slug ) {
			if ( ROPT_MENU_PARENT_SLUG === $slug ) {
				continue;
			}

			$reordered[] = $slug;

			if ( 'index.php' === $slug ) {
				$reordered[] = ROPT_MENU_PARENT_SLUG;
			}
		}

		return $reordered;
	}

	/**
	 * The capability this site actually has.
	 *
	 * @return string
	 */
	public static function capability(): string {
		return Plugin::woocommerce_is_active() ? self::CAPABILITY : self::FALLBACK_CAPABILITY;
	}

	/**
	 * URL of the plugin page.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Tells whether the plugin page is being printed.
	 *
	 * @return bool
	 */
	public static function is_current_page(): bool {
		// The is_string() guard keeps ?page[]=x from reaching sanitize_key() as an array.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which screen is being printed.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read.
			? sanitize_key( wp_unslash( $_GET['page'] ) )
			: '';

		return self::PAGE_SLUG === $page;
	}
}
