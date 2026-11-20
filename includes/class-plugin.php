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
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Boots the plugin once.
	 *
	 * Returns nothing on purpose: it runs as an action callback, and WordPress
	 * discards whatever an action returns.
	 *
	 * @return void
	 */
	public static function boot(): void {
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

		( new Assets() )->register();

		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf -- The WooCommerce half of the shell, empty until a plugin has one.
		if ( self::woocommerce_is_supported() ) {
			// A service whose job needs WooCommerce registers here and nowhere
			// else, and lives in includes/frontend/. The WordPress half above
			// touches WooCommerce only behind a guard.
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
