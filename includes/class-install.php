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
	 * Option prefix of the plugin this one replaces, RHINNO IT - WP Optimizer.
	 *
	 * Written out rather than derived, like Plugin::LEGACY_CONSTANT: it names
	 * that plugin, not this one, so it must NOT follow a rename.
	 */
	const string LEGACY_PREFIX = 'rwpo_';

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
	 * Runs on deactivation: drops the clear this plugin may have scheduled.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Page_Cache::EVENT );
		// Scheduled with arguments, which wp_clear_scheduled_hook() would have to match.
		wp_unschedule_hook( Page_Cache::VERIFY );
	}

	/**
	 * Writes any missing option and records the version.
	 *
	 * Existing values are left untouched: add_option() only writes when absent.
	 *
	 * @return void
	 */
	private static function seed_defaults(): void {
		// First: after the defaults, add_option() would keep those and copy nothing.
		self::migrate_legacy_options();
		self::inherit_split_options();

		foreach ( Settings::defaults() as $option => $value ) {
			add_option( $option, $value );
		}

		update_option( self::VERSION_OPTION, ROPT_VERSION );
	}

	/**
	 * Carries the settings of the plugin this one replaces.
	 *
	 * RHINNO IT - WP Optimizer, with the rwpo_ prefix, was split in two: its
	 * optimizations came here and the rest went to RHINNO IT - Customizer. A
	 * new slug means WordPress sees a new plugin, so without this every site
	 * would start over from the defaults. Only the ids this plugin declares
	 * are read, so the Customizer's switches stay with the Customizer. It
	 * copies, it never moves: WP Optimizer keeps its own options until its
	 * own uninstaller removes them, which is also what makes deleting it from
	 * the admin safe - this plugin's prefix is different, so that uninstaller
	 * cannot reach these.
	 *
	 * A value somebody set here is never overwritten by an older one from
	 * there, and it is add_option() that guarantees it: it writes nothing when
	 * the option already exists.
	 *
	 * @return void
	 */
	private static function migrate_legacy_options(): void {
		$missing = new \stdClass();

		foreach ( Settings::option_ids() as $option ) {
			$legacy = self::LEGACY_PREFIX . substr( $option, strlen( Settings::OPTION_PREFIX ) );
			$value  = get_option( $legacy, $missing );

			if ( $value === $missing ) {
				continue;
			}

			add_option( $option, $value );
		}
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
