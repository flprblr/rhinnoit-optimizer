<?php
/**
 * Plugin links on the plugins list.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the shortcut to the settings.
 */
final class Plugin_Links {

	/**
	 * Hooks the plugin row filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'plugin_action_links_' . ROPT_BASENAME, array( $this, 'add_settings_link' ) );
	}

	/**
	 * Prepends the link to the plugin page.
	 *
	 * A named key rather than array_unshift(): unshifting renumbers any integer
	 * key another plugin may have added, and a named key lets other code target
	 * this link.
	 *
	 * Typed mixed with a guard, like every other filter callback here. Core
	 * applies the shop-wide plugin_action_links filter first and feeds its
	 * result into this one, so any plugin whose callback does a bare return
	 * hands us null - and a TypeError here ends wp-admin/plugins.php mid-table,
	 * taking away the screen you would use to deactivate that plugin.
	 *
	 * @param mixed $links Existing links.
	 * @return mixed
	 */
	public function add_settings_link( mixed $links ): mixed {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Menu::url() ),
			esc_html__( 'Settings', 'rhinnoit-optimizer' )
		);

		return array_merge( array( 'settings' => $settings ), $links );
	}
}
