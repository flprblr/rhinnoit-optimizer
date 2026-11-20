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
	// A switch retired by a split, or removed outright, stays listed here: the
	// update keeps its row so a rollback still finds the choice made there.
	// Settings::retired_option_ids() names them, and a test holds this to it.
);

foreach ( $ropt_options as $ropt_option ) {
	delete_option( $ropt_option );
}

/*
 * One throttle transient per asset bundle. A plugin that adds an entry point
 * adds its key here too: Assets appends the entry point name to the prefix.
 */
delete_transient( 'ropt_broken_build_admin' );
