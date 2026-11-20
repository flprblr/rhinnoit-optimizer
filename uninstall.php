<?php
/**
 * Runs when the plugin is deleted from WordPress.
 *
 * On multisite, loop over the sites with get_sites() if the options are stored
 * per site.
 *
 * @package RhinnoIT\Optimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ropt_options = array(
	'ropt_version',
	'ropt_enabled',
	'ropt_srcset_prune',
	'ropt_lazy_first_only',
	'ropt_viewport_hero',
	'ropt_font_inline',
	'ropt_css_versioning',
	'ropt_global_styles',
	'ropt_classic_theme_styles',
	'ropt_speculative_moderate',
	'ropt_head_rsd',
	'ropt_head_shortlink',
	'ropt_head_oembed',
	'ropt_head_generator',
	'ropt_pingbacks',
	'ropt_page_cache_clear',
	'ropt_same_url_redirect',
	'ropt_product_image_eager',
	'ropt_gallery_strip_lazy',
	'ropt_loop_first_row',
	'ropt_page_cache_shop',
	'ropt_kinsta_stock_status_only',
	'ropt_cart_fragments',
	// When the last cPanel clear ran; not a setting.
	'ropt_page_cache_state',
	// A switch retired by a split, or removed outright, stays listed here: the
	// update keeps its row so a rollback still finds the choice made there.
	// Settings::retired_option_ids() names them, and a test holds this to it.
);

foreach ( $ropt_options as $ropt_option ) {
	delete_option( $ropt_option );
}

/*
 * The rwpo_ options of RHINNO IT - WP Optimizer, the plugin this one
 * replaces, are left alone on purpose. They belong to that plugin, whose own
 * uninstaller removes them; deleting them here would reset it on a site that
 * still runs it.
 */

/*
 * One throttle transient per asset bundle. A plugin that adds an entry point
 * adds its key here too: Assets appends the entry point name to the prefix.
 */
delete_transient( 'ropt_broken_build_admin' );

/*
 * One window per failure the font correction can hit, kept apart so neither
 * can silence the other.
 */
delete_transient( 'ropt_font_sheet_unreadable' );
delete_transient( 'ropt_font_rewrite_failed' );

/*
 * The page cache service: the deferred clear it may have scheduled, and one
 * log window per failure it can hit.
 */
wp_clear_scheduled_hook( 'ropt_page_cache_run' );
wp_unschedule_hook( 'ropt_page_cache_verify' );

foreach ( array( 'no_proc_open', 'spawn_failed', 'timeout', 'bad_output', 'uapi_error', 'kinsta_api' ) as $ropt_failure ) {
	delete_transient( 'ropt_page_cache_' . $ropt_failure );
}
