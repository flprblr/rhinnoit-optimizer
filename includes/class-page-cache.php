<?php
/**
 * Clears the host's page cache after changes, the way Kinsta's own plugin does.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Clears the page cache when something visitors see has changed.
 *
 * Two hosts, two mechanisms, one list of changes:
 *
 * - cPanel's NGINX (the ea-nginx package, "NGINX Manager" in cPanel) caches
 *   pages for 60 minutes and has no per-URL purge: its only clear is cPanel's
 *   own NginxCaching::clear_cache, the "Clear Cache" button, which empties
 *   the whole account. PHP already runs as the account user there, so it can
 *   call that through /usr/local/cpanel/bin/uapi with no stored credential -
 *   an API token would give whoever reads it the whole hosting account.
 * - Kinsta's MU plugin already clears on posts, comments, terms, menus,
 *   stock and Elementor's CSS and maintenance mode. What it misses is what
 *   changes every page on these shops: Porto's theme options and header
 *   builder, the Customizer, Porto template builder posts, plugin updates,
 *   and the settings screens of the group. For those, and only those,
 *   Kinsta's own purge is called. On cPanel both lists clear, Elementor's
 *   included.
 *
 * Changes only record a reason; the clear happens once, on shutdown, after
 * every write of the request - a bulk edit of fifty products or a Redux save
 * that writes porto_settings forty times is one clear, after Porto has
 * recompiled its CSS. Because a cPanel clear empties everything, it never runs
 * on a shopper's request: stock changes and anything a visitor triggers are
 * deferred to a single scheduled event, at most one every WINDOW seconds.
 * What an editor does is cleared right away, after the response has been
 * sent, no more than once every SPACING seconds.
 *
 * One change clears right away from whatever request makes it: Elementor
 * deleting the CSS files every cached page links. Waiting would serve those
 * pages unstyled. Besides an editor's regenerate, Elementor Pro does it when
 * it refreshes its license data, from the first logged-in request - a
 * customer's account page included - about every 12 hours, or every 30
 * minutes while its license server cannot be reached; SPACING still holds.
 *
 * Saving the settings screen of any plugin of the group always clears, at
 * once, whether or not a value changed - on Kinsta even with its autopurge
 * off: every switch changes the HTML of every page, and a save is the admin
 * asking for it.
 */
final class Page_Cache {

	/**
	 * The command line API of cPanel, at the path cPanel documents for CloudLinux.
	 */
	const string UAPI = '/usr/local/cpanel/bin/uapi';

	/**
	 * Files the ea-nginx package installs: cPanel's NGINX cache is on this server.
	 *
	 * Read only to decide whether the settings screen offers the cache
	 * switches, never to decide whether to clear. A CloudLinux cage or a jailed
	 * cron can hide /etc/nginx from PHP while uapi still clears, and a clear
	 * that silently stopped would leave every edit stale for an hour; so
	 * either file counts, and the clear itself asks only for uapi. Whether the
	 * account has caching on cannot be read from the account at all: uapi
	 * answers success either way and the cache directory belongs to root (two
	 * servers, both states, 2026-10-16). With caching off a clear is a no-op.
	 *
	 * @var array<int, string>
	 */
	const array NGINX = array( '/etc/nginx/conf.d/ea-nginx.conf', '/usr/local/cpanel/scripts/ea-nginx' );

	/**
	 * Scheduled event that checks, after a save, that Kinsta's purge took.
	 */
	const string VERIFY = 'ropt_page_cache_verify';

	/**
	 * Seconds between a save's purge and its check.
	 *
	 * Kinsta documents that clearing the edge cache in every data centre may
	 * take 2 to 5 minutes, and allows one clear every 10 seconds; a check any
	 * sooner reads a copy that is still being cleared as one that never was.
	 */
	const int VERIFY_DELAY = 300;

	/**
	 * Seconds of clock difference forgiven between this server and Kinsta's cache.
	 */
	const int SKEW = 5;

	/**
	 * Scheduled event that runs a deferred cPanel clear.
	 */
	const string EVENT = 'ropt_page_cache_run';

	/**
	 * Group hook that reports a change: Install::DIRTY_HOOK, written out.
	 *
	 * Every plugin of the group fires it on an update, and the WooCommerce
	 * half for a product. Written out so that register(), which runs on
	 * every request, loads no other class to read it; a test holds the two
	 * equal.
	 */
	const string DIRTY = 'rhinnoit_page_cache_dirty';

	/**
	 * Option holding when the last cPanel clear ran and any failure backoff.
	 */
	const string STATE = 'ropt_page_cache_state';

	/**
	 * Least seconds between two clears an editor triggers.
	 */
	const int SPACING = 30;

	/**
	 * Least seconds between two clears stock or a visitor triggers.
	 */
	const int WINDOW = 900;

	/**
	 * Seconds to stop trying after cPanel refused or failed a clear.
	 */
	const int BACKOFF = 3600;

	/**
	 * Most products purged one by one on Kinsta; more is one full purge.
	 */
	const int SINGLE_LIMIT = 20;

	/**
	 * Reasons a change can give.
	 *
	 * Content is what Kinsta already clears on its own, one post per request;
	 * site is what it misses. Stock, sale and product come from the
	 * WooCommerce half; product is a price, visibility or attribute change
	 * saved without a post update, which Kinsta misses too. Saved is a
	 * settings screen of the group being saved.
	 *
	 * @var array<int, string>
	 */
	const array REASONS = array( 'content', 'site', 'stock', 'sale', 'product', 'saved' );

	/**
	 * Group hook fired after every save of a settings screen of the group.
	 *
	 * Admin\Settings_Page::SAVED_HOOK, written out: register() runs on every
	 * storefront request, and reading the constant would load the admin
	 * class there. A test holds the two equal.
	 */
	const string SAVED = 'rhinnoit_settings_saved';

	/**
	 * Functions a cPanel clear calls; a host can disable any of them.
	 *
	 * @var array<int, string>
	 */
	const array PROCESS_FUNCTIONS = array( 'proc_open', 'proc_get_status', 'proc_terminate', 'proc_close', 'stream_select', 'stream_set_blocking', 'stream_get_contents', 'usleep' );

	/**
	 * Options Kinsta already clears on.
	 *
	 * Elementor's maintenance mode is one: Kinsta purges on the action
	 * Elementor fires when this option changes. While "Coming soon" is on, the
	 * page that says so answers 200 and is cached for every address a visitor
	 * opens, so launching the site needs a clear. The first save adds the row
	 * instead, which Kinsta misses: see option_added().
	 *
	 * @var array<int, string>
	 */
	const array CONTENT_OPTIONS = array( 'blogname', 'blogdescription', 'date_format', 'time_format', self::ELEMENTOR_MODE );

	/**
	 * Elementor's maintenance mode: '', 'coming_soon' or 'maintenance'.
	 */
	const string ELEMENTOR_MODE = 'elementor_maintenance_mode_mode';

	/**
	 * The page Elementor shows while its maintenance mode is on.
	 *
	 * Elementor applies the mode only with both rows set, and fires nothing
	 * when only this one changes - picking the page turns the mode on,
	 * clearing it turns it off - so neither Kinsta nor anyone else clears.
	 */
	const string ELEMENTOR_TEMPLATE = 'elementor_maintenance_mode_template_id';

	/**
	 * Options that change every page and that Kinsta does not clear on.
	 *
	 * WPLANG is here although Kinsta lists a "language" option: WordPress
	 * stores the locale as WPLANG, so Kinsta's entry never matches.
	 *
	 * @var array<int, string>
	 */
	const array SITE_OPTIONS = array(
		'WPLANG',
		'permalink_structure',
		'category_base',
		'tag_base',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'site_icon',
		'sidebars_widgets',
		'porto_settings',
		'porto_settings_optimize',
		'porto_header_builder',
		'porto_header_builder_layouts',
	);

	/**
	 * Reasons recorded in this request, as keys.
	 *
	 * @var array<string, bool>
	 */
	private array $pending = array();

	/**
	 * How many content changes this request recorded.
	 *
	 * Kinsta clears only the first post of a request, so on Kinsta a second
	 * one - a bulk edit - needs a full purge.
	 *
	 * @var int
	 */
	private int $content_changes = 0;

	/**
	 * Products behind the product, sale and stock reasons, by id.
	 *
	 * On Kinsta those purge one product and its archives each, not the site:
	 * an ERP pushing prices or stock through the API would otherwise empty
	 * the whole cache - and flush Redis - once per product.
	 *
	 * @var array<string, array<int, bool>>
	 */
	private array $products = array();

	/**
	 * Whether the switch was on when this request started.
	 *
	 * @var bool
	 */
	private bool $active = false;

	/**
	 * This plugin's own option ids, read once at register().
	 *
	 * @var array<int, string>
	 */
	private array $own_options = array();

	/**
	 * Sets where uapi lives and how long a clear may take.
	 *
	 * @param string             $uapi    Path to cPanel's uapi; tests point it at a fixture.
	 * @param int                $timeout Seconds before a clear is given up.
	 * @param bool|null          $as_root Whether PHP runs as root; null asks the system.
	 * @param array<int, string> $nginx Files ea-nginx installs; tests point it at a fixture.
	 */
	public function __construct(
		private string $uapi = self::UAPI,
		private int $timeout = 20,
		private ?bool $as_root = null,
		private array $nginx = self::NGINX
	) {}

	/**
	 * Hooks every change that can make a cached page stale, and the flush.
	 *
	 * @return void
	 */
	public function register(): void {
		/*
		 * Hooked whatever the switch says: the save that turns the switch, or
		 * the master switch, back on runs with the old values, and its pages
		 * still need clearing. flush() returns at once with nothing pending.
		 */
		add_action( self::SAVED, array( $this, 'settings_saved' ) );
		add_action( self::VERIFY, array( $this, 'verify_saved_purge' ), 10, 2 );

		// Late, so the writes of every other shutdown callback are in.
		add_action( 'shutdown', array( $this, 'flush' ), 100 );

		if ( ! Settings::feature( 'page_cache_clear' ) ) {
			return;
		}

		$this->active      = true;
		$this->own_options = Settings::option_ids();

		add_action( 'transition_post_status', array( $this, 'post_changed' ), 10, 3 );
		add_action( 'transition_comment_status', array( $this, 'comment_changed' ), 10, 2 );
		add_action( 'comment_post', array( $this, 'comment_posted' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'comment_edited' ), 10, 2 );
		add_action( 'edited_term', array( $this, 'term_changed' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'term_changed' ), 10, 3 );
		add_action( 'wp_update_nav_menu', array( $this, 'content_changed' ) );
		add_action( 'wp_delete_nav_menu', array( $this, 'content_changed' ) );
		add_action( 'switch_theme', array( $this, 'content_changed' ) );
		add_action( 'updated_option', array( $this, 'option_updated' ), 10, 3 );
		add_action( 'added_option', array( $this, 'option_added' ) );
		add_action( 'customize_save_after', array( $this, 'site_changed' ) );
		add_action( 'upgrader_process_complete', array( $this, 'site_changed' ) );
		add_action( 'activated_plugin', array( $this, 'site_changed' ) );
		add_action( 'deactivated_plugin', array( $this, 'site_changed' ) );

		// Elementor deletes the CSS files every page links when it regenerates
		// them, and a cached page never asks for new ones. Kinsta's plugin
		// purges on this action itself, so only elsewhere.
		if ( ! Plugin::kinsta_is_active() ) {
			add_action( 'elementor/core/files/clear_cache', array( $this, 'site_changed' ) );
		}

		add_action( self::DIRTY, array( $this, 'mark' ), 10, 2 );
		add_action( self::EVENT, array( $this, 'run_scheduled' ) );
	}

	/**
	 * A settings screen of the group was saved.
	 *
	 * The one callback that reads the switch again: the save is what changes
	 * it. Clears when it was on before the save or is on after it, so turning
	 * everything off clears the optimized pages, and turning it back on
	 * clears the plain ones.
	 *
	 * @return void
	 */
	public function settings_saved(): void {
		if ( $this->active || Settings::feature( 'page_cache_clear' ) ) {
			$this->mark( 'saved' );
		}
	}

	/**
	 * Records a reason to clear, and the product it is about, if any.
	 *
	 * @param mixed $reason     One of REASONS.
	 * @param mixed $product_id Product the change is about; 0 for many or none.
	 * @return void
	 */
	public function mark( mixed $reason, mixed $product_id = 0 ): void {
		if ( ! is_string( $reason ) || ! in_array( $reason, self::REASONS, true ) ) {
			return;
		}

		$this->pending[ $reason ] = true;

		if ( in_array( $reason, array( 'product', 'sale', 'stock' ), true ) ) {
			$id = is_numeric( $product_id ) ? (int) $product_id : 0;

			// Zero means a change to many products at once: that one is site-wide.
			$this->products[ $reason ][ 0 < $id ? $id : 0 ] = true;
		}

		if ( 'content' === $reason ) {
			++$this->content_changes;
		}
	}

	/**
	 * A change to content Kinsta clears on its own.
	 *
	 * @return void
	 */
	public function content_changed(): void {
		$this->mark( 'content' );
	}

	/**
	 * A change to every page that Kinsta does not clear on.
	 *
	 * @return void
	 */
	public function site_changed(): void {
		$this->mark( 'site' );
	}

	/**
	 * A post entered or left publication, or a published post was updated.
	 *
	 * Core fires this on every save, publish to publish included. Porto's
	 * template builder posts are headers, footers and blocks shown on many
	 * pages, so they count as a site change.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       The post.
	 * @return void
	 */
	public function post_changed( mixed $new_status, mixed $old_status, mixed $post ): void {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		if ( ! $post instanceof \WP_Post || ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$this->mark( 'porto_builder' === $post->post_type ? 'site' : 'content' );
	}

	/**
	 * A comment entered or left the approved state.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @return void
	 */
	public function comment_changed( mixed $new_status, mixed $old_status ): void {
		if ( 'approved' === $new_status || 'approved' === $old_status ) {
			$this->mark( 'content' );
		}
	}

	/**
	 * A new comment was published straight away.
	 *
	 * @param mixed $comment_id Comment id.
	 * @param mixed $approved   1 when it went live.
	 * @return void
	 */
	public function comment_posted( mixed $comment_id, mixed $approved ): void {
		unset( $comment_id );

		if ( 1 === $approved || '1' === $approved ) {
			$this->mark( 'content' );
		}
	}

	/**
	 * The text of a comment changed; it matters only if it is live.
	 *
	 * The transition_comment_status hook fires only when the status changes,
	 * so an edit to an approved review would otherwise go unnoticed.
	 *
	 * @param mixed $comment_id Comment id.
	 * @param mixed $data       Comment data as saved.
	 * @return void
	 */
	public function comment_edited( mixed $comment_id, mixed $data ): void {
		unset( $comment_id );

		if ( is_array( $data ) && in_array( $data['comment_approved'] ?? null, array( 1, '1' ), true ) ) {
			$this->mark( 'content' );
		}
	}

	/**
	 * A term of a taxonomy visitors can see changed or went away.
	 *
	 * Seen either through its own archive or on the posts it belongs to:
	 * WooCommerce attributes (pa_*) have no archive by default, yet their
	 * names and swatches are on every variable product page.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $tt_id    Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy name.
	 * @return void
	 */
	public function term_changed( mixed $term_id, mixed $tt_id, mixed $taxonomy ): void {
		unset( $term_id, $tt_id );

		if ( ! is_string( $taxonomy ) ) {
			return;
		}

		$object       = get_taxonomy( $taxonomy );
		$object_types = $object instanceof \WP_Taxonomy ? (array) $object->object_type : array();

		if ( is_taxonomy_viewable( $taxonomy ) || array() !== array_filter( $object_types, 'is_post_type_viewable' ) ) {
			$this->mark( 'content' );
		}
	}

	/**
	 * An option was updated.
	 *
	 * Runs for every option write, transients included, so it only compares
	 * strings. Porto rewrites a _used_blocks key into the theme mods on
	 * ordinary post saves; a change to that key alone is not a site change.
	 *
	 * @param mixed $option    Option name.
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public function option_updated( mixed $option, mixed $old_value, mixed $value ): void {
		if ( ! is_string( $option ) ) {
			return;
		}

		if ( 'theme_mods_' . get_stylesheet() === $option ) {
			if ( is_array( $old_value ) && is_array( $value ) ) {
				unset( $old_value['_used_blocks'], $value['_used_blocks'] );

				if ( $old_value == $value ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Same keys and values in any order is the same theme mods.
					return;
				}
			}

			$this->mark( 'site' );

			return;
		}

		$this->option_changed( $option );
	}

	/**
	 * An option was added.
	 *
	 * Elementor creates its maintenance mode row the first time its Tools form
	 * is saved and fires the action Kinsta purges on only for later changes,
	 * so on Kinsta nothing would purge the pages cached before the site went
	 * into "Coming soon" or maintenance. That first save is a site change when
	 * it sets a mode; saved as "Disabled" - any other Tools setting saved for
	 * the first time - nothing visitors see has changed.
	 *
	 * @param mixed $option Option name.
	 * @return void
	 */
	public function option_added( mixed $option ): void {
		if ( ! is_string( $option ) ) {
			return;
		}

		if ( self::ELEMENTOR_MODE === $option ) {
			if ( self::elementor_mode_is_set() ) {
				$this->mark( 'site' );
			}

			return;
		}

		$this->option_changed( $option );
	}

	/**
	 * Whether Elementor's maintenance or coming-soon mode is set.
	 *
	 * Read from the row itself: when added_option or updated_option fires, the
	 * new value is already stored.
	 *
	 * @return bool
	 */
	private static function elementor_mode_is_set(): bool {
		$mode = get_option( self::ELEMENTOR_MODE );

		return is_string( $mode ) && '' !== $mode;
	}

	/**
	 * Records an option written with nothing special about it.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	private function option_changed( string $option ): void {
		if ( self::ELEMENTOR_TEMPLATE === $option ) {
			if ( self::elementor_mode_is_set() ) {
				$this->mark( 'site' );
			}

			return;
		}

		if ( in_array( $option, self::CONTENT_OPTIONS, true ) ) {
			$this->mark( 'content' );
		} elseif ( in_array( $option, self::SITE_OPTIONS, true ) || in_array( $option, $this->own_options, true ) || str_starts_with( $option, 'widget_' ) ) {
			$this->mark( 'site' );
		}
	}

	/**
	 * Clears once for everything this request changed.
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( array() === $this->pending ) {
			return;
		}

		$reasons               = $this->pending;
		$content_changes       = $this->content_changes;
		$products              = $this->products;
		$this->pending         = array();
		$this->content_changes = 0;
		$this->products        = array();

		if ( Plugin::kinsta_is_active() ) {
			$this->flush_kinsta( $reasons, $content_changes, $products );

			return;
		}

		if ( ! $this->uapi_is_available() ) {
			return;
		}

		// An integration writing through the WooCommerce API - an ERP syncing
		// stock or prices - waits for the window like stock: it can send one
		// request per product, and each clear here empties the whole account.
		// A connector plugin syncing prices from WP-Cron is no editor either;
		// a post published from cron is, and it counts as content.
		$trusted = self::is_trusted_request() && ! self::is_integration_request();
		$saved   = isset( $reasons['saved'] );
		$urgent  = $saved || isset( $reasons['site'] ) || isset( $reasons['sale'] )
			|| ( isset( $reasons['content'] ) && $trusted )
			|| ( isset( $reasons['product'] ) && $trusted && ! wp_doing_cron() );
		$last    = $this->state()['last'];
		$now     = time();

		if ( ! $urgent ) {
			$this->schedule( max( $now, $last + self::WINDOW ) );

			return;
		}

		if ( ! $saved && $now - $last < self::SPACING ) {
			$this->schedule( $last + self::SPACING );

			return;
		}

		self::finish_response();
		$this->clear_cpanel();
	}

	/**
	 * Runs a clear that was deferred.
	 *
	 * @return void
	 */
	public function run_scheduled(): void {
		if ( Plugin::kinsta_is_active() || ! $this->uapi_is_available() ) {
			return;
		}

		// This clear covers whatever the same cron request recorded so far.
		$this->pending         = array();
		$this->content_changes = 0;

		$this->clear_cpanel();
	}

	/**
	 * Clears on Kinsta what its own plugin leaves out.
	 *
	 * Kinsta purges one post per request itself - one: a shared flag stops
	 * its post and stock purges after the first, so the second product of an
	 * order that sells out two is never purged by Kinsta. Everything site-wide
	 * - a settings screen saved, Porto options, a scheduled sale, a bulk edit -
	 * is a full purge here. A product whose price or visibility changed without
	 * a post update, or that went in or out of stock, is a purge of that product
	 * and its archives, through Kinsta's per-post purge; a stock status change
	 * is purged here even though Kinsta may have purged the same product,
	 * because it may not have. More than SINGLE_LIMIT products is a full purge.
	 *
	 * @param array<string, bool>             $reasons         Reasons recorded.
	 * @param int                             $content_changes Posts changed.
	 * @param array<string, array<int, bool>> $products        Products by reason.
	 * @return void
	 */
	private function flush_kinsta( array $reasons, int $content_changes, array $products ): void {
		$ids = ( $products['product'] ?? array() ) + ( $products['stock'] ?? array() );

		$full = isset( $reasons['saved'] ) || isset( $reasons['site'] ) || isset( $reasons['sale'] )
			|| 1 < $content_changes || isset( $ids[0] ) || self::SINGLE_LIMIT < count( $ids );

		if ( ! $full && array() === $ids ) {
			return;
		}

		// Kinsta's purges are HTTP calls; the shopper whose order sold out a
		// size does not wait for them.
		self::finish_response();

		$purge = $full ? null : self::kinsta_single_purge();

		if ( null === $purge ) {
			// A save is the admin asking: it goes through even with autopurge off.
			$saved   = isset( $reasons['saved'] );
			$started = time();

			if ( $this->purge_kinsta( $saved ) && $saved ) {
				wp_schedule_single_event( $started + self::VERIFY_DELAY, self::VERIFY, array( $started, 0 ) );
			}

			return;
		}

		foreach ( array_keys( $ids ) as $id ) {
			// @phpstan-ignore method.notFound (Checked by kinsta_single_purge(): method present, one argument.)
			$purge->initiate_purge( $id );
		}
	}

	/**
	 * Checks, five minutes after a save, that its Kinsta purge took; purges once more if not.
	 *
	 * Kinsta's purge is two HTTP calls whose answers it ignores. Twice - on
	 * joia (2026-10-16) and tucasafacil (2026-10-19) - the home page kept a
	 * copy cached before the save for another hour, and a controlled test
	 * on 2026-10-19 cleared both times: something on Kinsta's side fails now
	 * and then. The check reads the home page through the same edge visitors
	 * use. A copy stored before the save means the purge never reached it,
	 * so one more forced purge is made and checked the same way; a copy still
	 * older than that one is logged. It reads only what the edge stored, so a
	 * copy the edge stored afresh from an origin copy older than the save
	 * passes: what it catches is the edge keeping its own.
	 *
	 * @param mixed $since   When the purge being checked began.
	 * @param mixed $attempt 0 after the save's purge, 1 after the retry; anything
	 *                       else, a lost argument included, can only log.
	 * @return void
	 */
	public function verify_saved_purge( mixed $since = 0, mixed $attempt = 1 ): void {
		if ( ! Plugin::kinsta_is_active() || ! is_numeric( $since ) || 0 >= (int) $since ) {
			return;
		}

		if ( true !== $this->home_is_stale( (int) $since ) ) {
			return;
		}

		if ( is_numeric( $attempt ) && 0 === (int) $attempt ) {
			$now = time();

			if ( $this->purge_kinsta( true ) ) {
				wp_schedule_single_event( $now + self::VERIFY_DELAY, self::VERIFY, array( $now, 1 ) );
			}

			return;
		}

		Logger::error( sprintf( 'Saving the settings purged Kinsta twice, and %s still came from a copy cached before the second purge.', home_url( '/' ) ) );
	}

	/**
	 * Whether the home page still comes from a copy cached before a moment.
	 *
	 * A cache HIT whose Age, or failing that whose Last-Modified, predates
	 * the moment is stale. Anything that cannot be told - a request that
	 * fails, a redirect, a bot challenge, no dates - answers null, so a host
	 * that hides its cache never gets a purge or a log line it did not earn.
	 *
	 * @param int $since When the purge began.
	 * @return bool|null
	 */
	private function home_is_stale( int $since ): ?bool {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'limit_response_size' => 1024,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$hit = false;

		foreach ( array( 'x-kinsta-cache', 'ki-cf-cache-status', 'cf-cache-status' ) as $name ) {
			$hit = $hit || 'HIT' === strtoupper( self::header( $response, $name ) );
		}

		if ( ! $hit ) {
			return false;
		}

		// Age is the cache's own count since it stored the copy. Kinsta's edge
		// sets Last-Modified to that same moment, but a page can send one of
		// its own, so that is only the fallback.
		$age = self::header( $response, 'age' );

		if ( is_numeric( $age ) ) {
			return time() - (int) $age < $since - self::SKEW;
		}

		$modified = strtotime( self::header( $response, 'last-modified' ) );

		return is_int( $modified ) && 0 < $modified ? $modified < $since - self::SKEW : null;
	}

	/**
	 * One response header as a string, the first when it came more than once.
	 *
	 * @param array<string, mixed> $response A wp_remote_get() response.
	 * @param string               $name     Header name.
	 * @return string
	 */
	private static function header( array $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Kinsta's purge object, when it offers the per-post purge WP Rocket uses.
	 *
	 * Checked for the method and for taking one argument, so a Kinsta update
	 * that moves or changes it falls back to one full purge, never to a fatal
	 * error or a full purge per product.
	 *
	 * @return object|null
	 */
	private static function kinsta_single_purge(): ?object {
		$kinsta = $GLOBALS['kinsta_cache'] ?? null;
		$purge  = is_object( $kinsta ) ? ( get_object_vars( $kinsta )['kinsta_cache_purge'] ?? null ) : null;

		if ( ! is_object( $purge ) || ! method_exists( $purge, 'initiate_purge' ) ) {
			return null;
		}

		return 1 >= ( new \ReflectionMethod( $purge, 'initiate_purge' ) )->getNumberOfRequiredParameters() ? $purge : null;
	}

	/**
	 * Sends the response to the visitor before a slow clear, as wp-cron.php does.
	 *
	 * @return void
	 */
	private static function finish_response(): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}

	/**
	 * Whether this is a write from an integration through the WooCommerce API.
	 *
	 * The block editor saves through the REST API too, but under /wp/v2; the
	 * WooCommerce API (/wc/v1 to /wc/v3, and the legacy /wc-api/) is what ERPs
	 * and marketplaces use.
	 *
	 * @return bool
	 */
	private static function is_integration_request(): bool {
		// The legacy WooCommerce API (/wc-api/), for connectors that still use it.
		if ( defined( 'WC_API_REQUEST' ) && WC_API_REQUEST ) {
			return true;
		}

		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$wp    = $GLOBALS['wp'] ?? null;
		$route = is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) ? $wp->query_vars['rest_route'] : '';

		return is_string( $route ) && 1 === preg_match( '#^/wc/v\d#', $route );
	}

	/**
	 * Whether the request comes from someone trusted to change the site.
	 *
	 * @return bool
	 */
	private static function is_trusted_request(): bool {
		return wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'edit_posts' );
	}

	/**
	 * Whether this host has a page cache the plugin can clear.
	 *
	 * The same two answers flush() acts on: Kinsta, or a cPanel uapi PHP may
	 * start. Anywhere else both cache switches change nothing, so the settings
	 * screen asks this to leave them off it.
	 *
	 * @return bool
	 */
	public function can_clear(): bool {
		return Plugin::kinsta_is_active() || ( $this->uapi_is_available() && $this->nginx_is_installed() );
	}

	/**
	 * Whether any file the ea-nginx package installs is visible to PHP.
	 *
	 * Without one, uapi is on a server whose pages Apache or LiteSpeed serve,
	 * with no NGINX cache to clear. See NGINX for why only the screen asks.
	 *
	 * @return bool
	 */
	private function nginx_is_installed(): bool {
		foreach ( $this->nginx as $path ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Under open_basedir a path outside it is a warning, not a false.
			if ( @file_exists( $path ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether cPanel's uapi can be run from this PHP.
	 *
	 * A missing binary is simply another host, and says nothing. A present
	 * binary that PHP may not start is a cPanel server whose PHP disables
	 * proc_open, which the owner can fix, so it is logged once a day.
	 *
	 * @return bool
	 */
	private function uapi_is_available(): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Under open_basedir a path outside it is a warning, not a false.
		if ( ! @is_executable( $this->uapi ) ) {
			return false;
		}

		foreach ( self::PROCESS_FUNCTIONS as $function ) {
			if ( ! function_exists( $function ) ) {
				Logger::error_once(
					'ropt_page_cache_no_proc_open',
					sprintf( 'cPanel is here but PHP disables %s, so the NGINX cache cannot be cleared automatically.', $function ),
					DAY_IN_SECONDS
				);

				return false;
			}
		}

		return true;
	}

	/**
	 * The cPanel account to name, when PHP runs as root.
	 *
	 * An account user runs uapi as themselves; root has to name the account
	 * (WP-CLI with --allow-root, a root cron). The owner of the plugin files
	 * is that account.
	 *
	 * @return string|null Empty when not root; null when root and the owner is unusable.
	 */
	private function account_for_root(): ?string {
		$as_root = $this->as_root ?? ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() );

		if ( ! $as_root ) {
			return '';
		}

		$owner = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( (int) fileowner( ROPT_PATH ) ) : false;
		$name  = is_array( $owner ) ? $owner['name'] : '';

		return 'root' !== $name && 1 === preg_match( '/^[a-z0-9_][a-z0-9_.-]{0,31}$/', $name ) ? $name : null;
	}

	/**
	 * Schedules the deferred clear, keeping the earliest one.
	 *
	 * @param int $at Unix time to run at.
	 * @return void
	 */
	private function schedule( int $at ): void {
		$next = wp_next_scheduled( self::EVENT );

		if ( false !== $next && $next <= $at ) {
			return;
		}

		if ( false !== $next ) {
			wp_unschedule_event( $next, self::EVENT );
		}

		wp_schedule_single_event( $at, self::EVENT );
	}

	/**
	 * When the last clear ran, and until when to stop trying after a failure.
	 *
	 * @return array{last: int, failed_until: int}
	 */
	private function state(): array {
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			'last'         => is_int( $state['last'] ?? null ) ? $state['last'] : 0,
			'failed_until' => is_int( $state['failed_until'] ?? null ) ? $state['failed_until'] : 0,
		);
	}

	/**
	 * Empties the account's NGINX cache through cPanel.
	 *
	 * The time is written before the run, so a second request finishing at
	 * the same moment sees it and waits instead of clearing twice, and any
	 * clear already scheduled is dropped: this one covers it.
	 *
	 * @return void
	 */
	private function clear_cpanel(): void {
		if ( $this->state()['failed_until'] > time() ) {
			return;
		}

		$account = $this->account_for_root();

		// Root with no usable account: WP-Cron will run it as the site user.
		if ( null === $account ) {
			return;
		}

		wp_clear_scheduled_hook( self::EVENT );

		// A lease: while this run lasts, other requests see it and wait.
		$this->save_state( time() + $this->timeout + 5 );

		$failure = $this->run_uapi( $account );

		if ( array() === $failure ) {
			$this->save_state( 0 );

			return;
		}

		Logger::error_once( 'ropt_page_cache_' . $failure[0], $failure[1], DAY_IN_SECONDS );
		$this->save_state( time() + self::BACKOFF );
	}

	/**
	 * Records now as the last clear, and until when to wait before another.
	 *
	 * @param int $failed_until Unix time before which no clear starts.
	 * @return void
	 */
	private function save_state( int $failed_until ): void {
		update_option(
			self::STATE,
			array(
				'last'         => time(),
				'failed_until' => $failed_until,
			),
			false
		);
	}

	/**
	 * Runs uapi NginxCaching clear_cache and reads its answer.
	 *
	 * A fixed argument list and no shell, so nothing can be injected; a
	 * minimal environment, because PHP-FPM clears it; a deadline, because a
	 * stuck uapi must not hold a PHP worker - and after it a TERM, a short
	 * grace and a KILL, because proc_close() waits for the child without
	 * limit. Only result.status 1 counts as done.
	 *
	 * @param string $account Account to name when running as root, or empty.
	 * @return array<int, string> Empty on success, else a log key and a message.
	 */
	private function run_uapi( string $account ): array {
		$command = array( $this->uapi, '--output=json' );

		if ( '' !== $account ) {
			$command[] = '--user=' . $account;
		}

		$command[] = 'NginxCaching';
		$command[] = 'clear_cache';
		$pipes     = array();

		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Fixed argv, no shell: the only way to reach cPanel's own clear without a stored credential.
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array(
				'PATH' => '/usr/local/cpanel/bin:/usr/bin:/bin',
				'LANG' => 'C',
			)
		);

		if ( ! is_resource( $process ) ) {
			return array( 'spawn_failed', 'Could not start cPanel uapi to clear the NGINX cache.' );
		}

		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A process pipe, not a file.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$open      = array(
			1 => $pipes[1],
			2 => $pipes[2],
		);
		$output    = '';
		$deadline  = microtime( true ) + $this->timeout;
		$timed_out = false;

		while ( true ) {
			$running = proc_get_status( $process )['running'];

			if ( array() === $open ) {
				usleep( 50000 );
			} else {
				$read   = array_values( $open );
				$write  = null;
				$except = null;

				if ( false === stream_select( $read, $write, $except, 0, 100000 ) ) {
					usleep( 50000 );
					$read = array();
				}

				foreach ( $read as $pipe ) {
					$which = array_search( $pipe, $open, true );
					$chunk = fread( $pipe, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- A process pipe, not a file.

					// A closed pipe stays readable forever; drop it or the loop spins.
					if ( ( false === $chunk || '' === $chunk ) && feof( $pipe ) ) {
						unset( $open[ $which ] );
						continue;
					}

					if ( 1 === $which && is_string( $chunk ) && strlen( $output ) < 65536 ) {
						$output .= $chunk;
					}
				}
			}

			if ( ! $running ) {
				break;
			}

			if ( microtime( true ) >= $deadline ) {
				$timed_out = true;
				break;
			}
		}

		if ( ! $timed_out && isset( $open[1] ) ) {
			$output .= (string) stream_get_contents( $pipes[1], 65536 );
		}

		if ( $timed_out ) {
			proc_terminate( $process );

			$wait = 0;

			while ( $wait++ < 20 && proc_get_status( $process )['running'] ) {
				usleep( 100000 );
			}

			if ( proc_get_status( $process )['running'] ) {
				proc_terminate( $process, 9 );
			}
		}

		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A process pipe, not a file.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A process pipe, not a file.
		proc_close( $process );

		$answer = json_decode( $output, true );
		$result = is_array( $answer ) && is_array( $answer['result'] ?? null ) ? $answer['result'] : null;

		// A child that answered and then hung still cleared the cache.
		if ( null !== $result && 1 === (int) ( $result['status'] ?? 0 ) ) {
			return array();
		}

		if ( $timed_out ) {
			return array( 'timeout', sprintf( 'cPanel uapi did not finish clearing the NGINX cache within %d seconds.', $this->timeout ) );
		}

		if ( null === $result ) {
			return array( 'bad_output', 'cPanel uapi did not answer with JSON when asked to clear the NGINX cache.' );
		}

		$errors = is_array( $result['errors'] ?? null ) ? implode( ' ', array_filter( $result['errors'], 'is_string' ) ) : '';
		$errors = (string) preg_replace( '/[^\P{C}]+/u', ' ', $errors );
		$errors = function_exists( 'mb_strcut' ) ? mb_strcut( $errors, 0, 300, 'UTF-8' ) : (string) preg_replace( '/^(.{0,300}).*$/su', '$1', $errors );

		return array( 'uapi_error', 'cPanel refused to clear the NGINX cache: ' . ( '' !== $errors ? $errors : 'no reason given.' ) );
	}

	/**
	 * Asks Kinsta's MU plugin to clear its page and edge caches.
	 *
	 * Kinsta documents no PHP API. This is the object and method WP Rocket's
	 * Kinsta integration calls, checked before use so a Kinsta update that
	 * renames them costs a log line, never a fatal.
	 *
	 * @param bool $force Whether to go through Kinsta's autopurge switch.
	 * @return bool Whether Kinsta was asked to purge.
	 */
	private function purge_kinsta( bool $force ): bool {
		$kinsta = $GLOBALS['kinsta_cache'] ?? null;
		$purge  = is_object( $kinsta ) ? ( get_object_vars( $kinsta )['kinsta_cache_purge'] ?? null ) : null;

		if ( is_object( $purge ) && method_exists( $purge, 'purge_complete_caches' ) ) {
			// Kinsta allows one full purge per request. One it ran earlier -
			// a menu saved in the Customizer - came before Porto recompiled.
			$flag = 'purge_all_happened';

			if ( array_key_exists( $flag, get_object_vars( $purge ) ) ) {
				// @phpstan-ignore property.notFound (Kinsta's public flag, found through get_object_vars() just above.)
				$purge->$flag = false;
			}

			if ( $force ) {
				$purge->purge_complete_caches( true );
			} else {
				$purge->purge_complete_caches();
			}

			return true;
		}

		Logger::error_once(
			'ropt_page_cache_kinsta_api',
			'Kinsta\'s MU plugin did not expose its purge, so the page cache was not cleared after a site-wide change.',
			DAY_IN_SECONDS
		);

		return false;
	}
}
