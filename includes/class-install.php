<?php
/**
 * Installation and upgrade tasks.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the stored options in step with the shipped version.
 */
final class Install {

	/**
	 * Option holding the version the options were last seeded for.
	 */
	const string VERSION_OPTION = Settings::OPTION_PREFIX . 'version';

	/**
	 * Group hook that reports a change making cached pages stale.
	 *
	 * No plugin prefix, like the menu constants: every plugin of the group
	 * fires this same hook, with a reason and, for one product, its id, so
	 * whichever plugin clears the page cache hears all of them. With no
	 * listener it does nothing. Must hold the same value in every plugin of
	 * the group. The clearing plugin takes the reasons content, site, stock,
	 * sale and product, and drops any other without a word.
	 */
	const string DIRTY_HOOK = 'rhinnoit_page_cache_dirty';

	/**
	 * Runs on activation.
	 *
	 * Seeds the site it runs on. A network activation on multisite seeds only
	 * the current site; the others get their defaults the first time their
	 * dashboard loads, through check_version(). uninstall.php makes the same
	 * single-site assumption.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::seed_defaults();
	}

	/**
	 * Seeds defaults for options added since the stored version.
	 *
	 * Deploying by copying the plugin folder over an existing install does not
	 * re-run the activation hook, so without this a new option would never get
	 * its default written.
	 *
	 * @return void
	 */
	public static function check_version(): void {
		if ( ROPT_VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::seed_defaults();

		/**
		 * Reports that every page may print differently now.
		 *
		 * New code can change any page, and a build copied over the folder
		 * fires no upgrader hook for a page cache to see.
		 *
		 * @since 1.0.0
		 * @param string $reason A change to every page.
		 */
		do_action( self::DIRTY_HOOK, 'site' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- A group hook, unprefixed on purpose: see DIRTY_HOOK.
	}

	/**
	 * Writes any missing option and records the version.
	 *
	 * Existing values are left untouched: add_option() only writes when absent.
	 *
	 * @return void
	 */
	private static function seed_defaults(): void {
		self::inherit_split_options();

		foreach ( Settings::defaults() as $option => $value ) {
			add_option( $option, $value );
		}

		update_option( self::VERSION_OPTION, ROPT_VERSION );
	}

	/**
	 * Hands the value of a retired wide switch down to the switches split from it.
	 *
	 * The pieces of a split switch are new options, so without this they would
	 * get their defaults and a site that had turned the wide one off would find
	 * them back on after the update. add_option() keeps a value already set
	 * here, and Settings::feature() makes the same lookup until this has run.
	 *
	 * The retired rows are left in place. Nothing reads them while this version
	 * runs, but a rollback does, and without them it would reseed those
	 * switches from their defaults. uninstall.php removes them.
	 *
	 * @return void
	 */
	private static function inherit_split_options(): void {
		$missing = new \stdClass();

		foreach ( Settings::inherits() as $option => $old ) {
			$value = get_option( Settings::OPTION_PREFIX . $old, $missing );

			if ( $value !== $missing ) {
				add_option( $option, $value );
			}
		}
	}
}
