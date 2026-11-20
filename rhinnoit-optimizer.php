<?php
/**
 * Plugin Name:          RHINNO IT - Optimizer
 * Description:          Base template for private WordPress plugins, with a WooCommerce half when WooCommerce is active.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         8.3
 * Author:               RHINNO IT
 * Author URI:           mailto:dev@rhinnoit.cloud
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rhinnoit-optimizer
 * Domain Path:          /languages
 * Update URI:           false
 * WC requires at least: 8.0
 * WC tested up to:      11.0
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

define( 'ROPT_VERSION', '1.0.0' );
define( 'ROPT_FILE', __FILE__ );
define( 'ROPT_PATH', plugin_dir_path( __FILE__ ) );
define( 'ROPT_URL', plugin_dir_url( __FILE__ ) );
define( 'ROPT_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Admin menu.
 *
 * Every plugin in the group shares the parent node: keep the same slug and
 * title in all of them and change only ROPT_MENU_TITLE. The first one to load
 * creates the node and the rest hang off it.
 */
define( 'ROPT_MENU_PARENT_SLUG', 'rhinnoit-tools' );
define( 'ROPT_MENU_PARENT_TITLE', 'RHINNO IT' );
define( 'ROPT_MENU_TITLE', 'Optimizer' );

require_once ROPT_PATH . 'includes/autoload.php';

register_activation_hook( ROPT_FILE, array( Install::class, 'activate' ) );

// Feature flags must be declared before WooCommerce boots.
add_action( 'before_woocommerce_init', array( Plugin::class, 'declare_woocommerce_compatibility' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
