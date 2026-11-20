<?php
/**
 * Plugin container.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use RhinnoIT\Optimizer\Admin\Menu;
use RhinnoIT\Optimizer\Admin\Plugin_Links;
use RhinnoIT\Optimizer\Frontend\Cart_Fragments;
use RhinnoIT\Optimizer\Frontend\First_Row;
use RhinnoIT\Optimizer\Frontend\Gallery_Strip;
use RhinnoIT\Optimizer\Frontend\Product_Image;
use RhinnoIT\Optimizer\Frontend\Shop_Cache;
use RhinnoIT\Optimizer\Frontend\Shop_Prefetch;

defined( 'ABSPATH' ) || exit;

/**
 * Boots and wires every service of the plugin.
 */
final class Plugin {

	/**
	 * Lowest supported WooCommerce version.
	 */
	const string MIN_WC_VERSION = '8.0';

	/**
	 * Constant defined by the plugin this one replaces, RHINNO IT - WP Optimizer.
	 *
	 * Written out rather than derived, like Install::LEGACY_PREFIX: it names
	 * that plugin, not this one, so it must NOT follow a rename. Renamed to
	 * this plugin's own constant, which is always defined, it would keep every
	 * site waiting.
	 */
	const string LEGACY_CONSTANT = 'RWPO_VERSION';

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Boots the plugin once.
	 *
	 * Not while WP Optimizer is active: this plugin replaces part of it, and
	 * with both running every optimization would run twice. Until it is
	 * deactivated this one only says it is waiting: no service, no menu, no
	 * version check. The activation hook does not come through here, so the
	 * copy of WP Optimizer's settings still runs beside it, which is when the
	 * hand-over makes it. The question is asked here, on plugins_loaded, and
	 * never at file scope: WordPress loads the active plugins sorted by path,
	 * so this one loads before rhinnoit-wp-optimizer/ defines its constant.
	 *
	 * Returns nothing on purpose: it runs as an action callback, and WordPress
	 * discards whatever an action returns.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( defined( self::LEGACY_CONSTANT ) ) {
			add_action( 'admin_notices', array( self::class, 'render_waiting_notice' ) );

			return;
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	/**
	 * Declares compatibility with the WooCommerce features.
	 *
	 * Set `cart_checkout_blocks` to false only if the plugin breaks the block
	 * checkout; a hook on the classic (shortcode) checkout that just does
	 * nothing on the block one stays compatible.
	 *
	 * @return void
	 */
	public static function declare_woocommerce_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', ROPT_FILE, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ROPT_FILE, true );
	}

	/**
	 * Registers the services.
	 *
	 * Two halves, and only one of them needs WooCommerce. The WordPress half
	 * works on any site, so it registers first and always; the WooCommerce half
	 * registers only on a version this plugin supports. A shop running an older
	 * WooCommerce still gets the WordPress half and a notice saying why the other
	 * half is missing, rather than nothing at all.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		( new Delivery() )->register();
		( new Viewport_Hero() )->register();
		( new Housekeeping() )->register();
		( new Databot_Loader() )->register();
		( new Page_Cache() )->register();
		( new Canonical_Redirect() )->register();
		( new Assets() )->register();

		if ( self::woocommerce_is_supported() ) {
			// A service whose job needs WooCommerce registers here and nowhere
			// else, and lives in includes/frontend/. The WordPress half above
			// touches WooCommerce only behind a guard.
			( new Product_Image() )->register();
			( new First_Row() )->register();
			( new Gallery_Strip() )->register();
			( new Cart_Fragments() )->register();
			( new Shop_Prefetch() )->register();
			( new Shop_Cache() )->register();
		} elseif ( self::woocommerce_is_active() ) {
			add_action( 'admin_notices', array( $this, 'render_unsupported_notice' ) );
		}

		if ( is_admin() ) {
			add_action( 'admin_init', array( Install::class, 'check_version' ) );

			( new Menu() )->register();
			( new Plugin_Links() )->register();
		}
	}

	/**
	 * Loads the plugin translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'rhinnoit-optimizer', false, dirname( ROPT_BASENAME ) . '/languages' );
	}

	/**
	 * Shows the notice when WooCommerce is present but too old for its half.
	 *
	 * @return void
	 */
	public function render_unsupported_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum WooCommerce version. */
					__( 'Optimizer is running, but its WooCommerce half needs WooCommerce %s or higher.', 'rhinnoit-optimizer' ),
					self::MIN_WC_VERSION
				)
			)
		);
	}

	/**
	 * Shows the notice while WP Optimizer is active and this plugin waits.
	 *
	 * It loads the text domain itself: nothing else of the plugin boots in
	 * the meantime, the init callback that loads it included, and the notices
	 * print well after init, where loading it is allowed.
	 *
	 * @return void
	 */
	public static function render_waiting_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		load_plugin_textdomain( 'rhinnoit-optimizer', false, dirname( ROPT_BASENAME ) . '/languages' );

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Optimizer waits until WP Optimizer is deactivated, and does nothing until then.', 'rhinnoit-optimizer' )
		);
	}

	/**
	 * Whether the site runs on Kinsta, read from the constants of its MU plugin.
	 *
	 * Kinsta caches every anonymous page and skips its cache for a visitor
	 * with a cart, and its MU plugin clears that cache on content changes:
	 * the mini cart correction relies on the cart rule, and Page_Cache leaves
	 * content to Kinsta. Must-use plugins
	 * load before this one, so the constants are there when this is asked.
	 * KINSTAMU_VERSION is deprecated since the MU plugin's 3.6 but still set.
	 *
	 * A site moved from Kinsta to cPanel sometimes keeps the MU plugin; where
	 * cPanel's uapi is present, the host is cPanel whatever the constants say.
	 *
	 * @return bool
	 */
	public static function kinsta_is_active(): bool {
		static $active = null;

		if ( null === $active ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Under open_basedir a path outside it is a warning, not a false.
			$active = ( defined( 'KINSTAMU_VERSION' ) || defined( 'Kinsta\KMP\VERSION' ) ) && ! @is_executable( Page_Cache::UAPI );
		}

		return $active;
	}

	/**
	 * Whether WooCommerce is loaded at all, whatever its version.
	 *
	 * @return bool
	 */
	public static function woocommerce_is_active(): bool {
		return defined( 'WC_VERSION' );
	}

	/**
	 * Whether WooCommerce is loaded on a version the WooCommerce half supports.
	 *
	 * Public because the settings screen asks the same question to decide
	 * whether to show the WooCommerce section: one answer, read from one place.
	 *
	 * @return bool
	 */
	public static function woocommerce_is_supported(): bool {
		return self::woocommerce_is_active() && version_compare( (string) WC_VERSION, self::MIN_WC_VERSION, '>=' );
	}
}
