<?php
/**
 * Test bootstrap: the slice of WordPress the plugin touches, plus a sandbox.
 *
 * ROPT_PATH points at a temporary directory whose includes/ is a symlink to the
 * real source, so tests can create and corrupt build/ files without touching
 * the repository.
 *
 * @package RhinnoIT\Optimizer
 */

$plugin_dir = dirname( __DIR__ );
$sandbox    = sys_get_temp_dir() . '/ropt-tests-' . getmypid();

mkdir( $sandbox . '/build', 0777, true );
symlink( $plugin_dir . '/includes', $sandbox . '/includes' );

register_shutdown_function(
	static function () use ( $sandbox ) {
		array_map( 'unlink', glob( $sandbox . '/build/*' ) ?: array() );
		@rmdir( $sandbox . '/build' );
		@unlink( $sandbox . '/includes' );
		@rmdir( $sandbox );
	}
);

define( 'ABSPATH', $sandbox . '/' );
define( 'ROPT_PATH', $sandbox . '/' );
define( 'ROPT_URL', 'https://shop.test/wp-content/plugins/rhinnoit-optimizer/' );
define( 'ROPT_VERSION', '1.0.0' );
define( 'ROPT_FILE', $sandbox . '/rhinnoit-optimizer.php' );
define( 'ROPT_BASENAME', 'rhinnoit-optimizer/rhinnoit-optimizer.php' );
define( 'ROPT_MENU_PARENT_SLUG', 'rhinnoit-tools' );
define( 'ROPT_MENU_PARENT_TITLE', 'RHINNO IT' );
define( 'ROPT_MENU_TITLE', 'Optimizer' );
/*
 * What WooCommerce reports, and whether it is there at all. Both come from the
 * environment so a test in its own process can exercise the support gate in
 * Plugin, which is the only thing between a shop without WooCommerce and a
 * fatal error. Unset means a supported WooCommerce, which is what every other
 * case wants.
 */
if ( 'none' !== getenv( 'ROPT_TESTS_WC' ) ) {
	define( 'WC_VERSION', (string) ( getenv( 'ROPT_TESTS_WC' ) ?: '11.0.1' ) );
}
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WP_DEBUG', false );

/**
 * Mutable state the stubs read. Reset it from setUp().
 */
final class WP_Stub {

	/** @var array<string, mixed> */
	public static array $options = array();
	/** @var array<string, mixed> */
	public static array $transients = array();
	/** @var array<int, array<string, mixed>> */
	public static array $enqueued = array();
	/** @var array<int, string> */
	public static array $inline = array();
	/** @var array<int, string> */
	public static array $logs = array();
	/** @var array<int, string> */
	public static array $hooks = array();
	/**
	 * Hook and callback of every add_action()/add_filter(), as "hook:method".
	 *
	 * $hooks only says a hook was used; two services on the same hook need
	 * this to tell which one registered.
	 *
	 * @var array<int, string>
	 */
	public static array $callbacks = array();
	/**
	 * Priority of every hooked callback, keyed "hook:method".
	 *
	 * A callback that has to run before or after another plugin's says so with
	 * its priority, and nothing else would notice that number falling back to
	 * 10: the menu_order filter that keeps the node below Dashboard is one.
	 *
	 * @var array<string, int>
	 */
	public static array $priorities = array();
	/**
	 * Every do_action() call: the hook, then its arguments.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public static array $fired = array();
	/**
	 * Every add_action()/add_filter(): hook, class of the object, method, accepted args.
	 *
	 * @var array<int, array{0: string, 1: string|null, 2: string, 3: int}>
	 */
	public static array $registered = array();
	/** @var array<int, array<string, mixed>> */
	public static array $menu = array();
	/**
	 * Core's $submenu table: what the node actually shows, back-link included.
	 *
	 * $menu records the calls the plugin makes; this one records what WordPress
	 * ends up with, which is what decides the href of the top-level node.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public static array $submenu = array();
	/** @var array<string, string> */
	public static array $page_hooks = array();
	public static string $page_type = 'product';
	/**
	 * Capabilities the code under test asked about, in order.
	 *
	 * The stub answers with $can whatever it is handed, so this is the only way
	 * a test can tell that the guard checks the capability it should.
	 *
	 * @var array<int, string>
	 */
	public static array $caps_checked = array();
	public static bool $json_fails = false;
	public static bool $can = true;
	public static bool $nonce_ok = true;
	public static ?string $redirect = null;
	public static string $nonce_action = '';

	/**
	 * Restores a clean slate.
	 *
	 * @return void
	 */
	public static function reset(): void {
		/*
		 * A configured plugin: on, and loading on the WooCommerce pages. The
		 * shipped defaults are empty on purpose - nothing loads until somebody
		 * picks where - so the cases about manifests and enqueueing would have
		 * nothing to enqueue without this.
		 */
		self::$options = array(
			'ropt_enabled'           => 'yes',
			'ropt_scope_woocommerce' => array( 'shop', 'product', 'cart', 'checkout', 'account' ),
		);
		self::$transients = array();
		self::$enqueued   = array();
		self::$inline     = array();
		self::$logs       = array();
		self::$hooks      = array();
		self::$callbacks  = array();
		self::$priorities = array();
		self::$fired      = array();
		self::$registered = array();
		self::$menu       = array();
		self::$submenu    = array();
		self::$page_hooks = array();
		self::$page_type  = 'product';

		self::$caps_checked = array();
		self::$json_fails = false;
		self::$can        = true;
		self::$nonce_ok   = true;
		self::$redirect   = null;
		self::$nonce_action = '';
		$_GET             = array();
		$_POST            = array();

		array_map( 'unlink', glob( ROPT_PATH . 'build/*' ) ?: array() );
	}
}

/** Thrown in place of wp_die()/exit so tests can assert on it. */
final class WP_Halt extends RuntimeException {}

function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return str_replace( array( '"', '<', '>' ), '', (string) $url ); }
function wp_unslash( $value ) { return $value; }
/**
 * Strict on purpose.
 *
 * Modern WordPress guards sanitize_key() with is_scalar(), but the sniff is
 * filterable and older cores were not. Making the stub assume a string proves
 * the plugin never hands it anything else, instead of leaning on core.
 *
 * @param mixed $key Raw value.
 * @return string
 */
function sanitize_key( $key ) {
	if ( ! is_string( $key ) ) {
		throw new TypeError( 'sanitize_key() received ' . get_debug_type( $key ) );
	}

	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}
function sanitize_title( $title ) { return strtolower( str_replace( ' ', '-', (string) $title ) ); }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . "$key=$value"; }
function is_admin() { return true; }
function load_plugin_textdomain( $domain, $abs, $path ) { return true; }

function get_option( $key, $default_value = false ) { return WP_Stub::$options[ $key ] ?? $default_value; }
function add_option( $key, $value ) { WP_Stub::$options[ $key ] ??= $value; return true; }
function update_option( $key, $value ) { WP_Stub::$options[ $key ] = $value; return true; }
function get_transient( $key ) { return WP_Stub::$transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { WP_Stub::$transients[ $key ] = $value; return true; }

function ropt_stub_callback_name( $cb ) { return is_array( $cb ) ? (string) $cb[1] : ( is_string( $cb ) ? $cb : 'closure' ); }
function ropt_stub_register( $hook, $cb, $priority, $args ) {
	WP_Stub::$hooks[]      = $hook;
	WP_Stub::$callbacks[]  = $hook . ':' . ropt_stub_callback_name( $cb );
	WP_Stub::$priorities[ $hook . ':' . ropt_stub_callback_name( $cb ) ] = (int) $priority;
	WP_Stub::$registered[] = array( (string) $hook, is_array( $cb ) && is_object( $cb[0] ) ? get_class( $cb[0] ) : null, ropt_stub_callback_name( $cb ), (int) $args );
}
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { ropt_stub_register( $hook, $cb, $priority, $args ); }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { ropt_stub_register( $hook, $cb, $priority, $args ); }

function wp_json_encode( $data ) { return WP_Stub::$json_fails ? false : json_encode( $data ); }
function wp_enqueue_script( $handle, $src, $deps, $ver, $args ) { WP_Stub::$enqueued[] = compact( 'handle', 'deps', 'ver' ); }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {}
function wp_style_add_data( $handle, $key, $value ) {}
function wp_set_script_translations( $handle, $domain, $path ) { WP_Stub::$enqueued[] = array( 'handle' => $handle . ':i18n' ); }
function wp_add_inline_script( $handle, $js, $position ) { WP_Stub::$inline[] = $js; }
function wp_nonce_field( $action ) { WP_Stub::$nonce_action = (string) $action; echo '<input type="hidden" name="_wpnonce" value="stub"/>'; }
function submit_button( $text = '', $type = 'primary', $name = 'submit' ) { echo '<input type="submit" value="Save Changes"/>'; }

function current_user_can( $cap ) { WP_Stub::$caps_checked[] = (string) $cap; return WP_Stub::$can; }
function check_admin_referer( $action ) {
	if ( ! WP_Stub::$nonce_ok ) { throw new WP_Halt( 'nonce' ); }
	return true;
}
function wp_die( $message ) { throw new WP_Halt( $message ); }
function do_action( $hook, ...$args ) { WP_Stub::$fired[] = array_merge( array( $hook ), $args ); }
function wp_safe_redirect( $url ) { WP_Stub::$redirect = $url; throw new WP_Halt( 'redirect' ); }

function add_menu_page( $page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $position = null ) {
	WP_Stub::$page_hooks[ $slug ] = sanitize_title( $menu_title );
	WP_Stub::$menu[] = array( 'type' => 'parent', 'slug' => $slug, 'label' => $menu_title, 'cap' => $cap, 'position' => $position, 'icon' => $icon, 'callable' => is_callable( $cb ) );
}

/**
 * Records the child and, as core does, the back-link to the parent.
 *
 * Core inserts the parent as the first child of a node that had none, and
 * menu-header.php then takes the href of the node from that first child. That
 * is the whole failure: with a callback-less parent the link goes nowhere.
 *
 * @param string $parent     Parent slug.
 * @param string $page_title Page title.
 * @param string $menu_title Menu label.
 * @param string $cap        Capability.
 * @param string $slug       Page slug.
 * @param mixed  $cb         Render callback.
 * @return string|false Hook name, which is also the screen id.
 */
function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $cb = '' ) {
	if ( ! WP_Stub::$can ) {
		return false;
	}

	if ( ! isset( WP_Stub::$submenu[ $parent ] ) && $slug !== $parent ) {
		foreach ( WP_Stub::$menu as $item ) {
			if ( 'parent' === $item['type'] && $item['slug'] === $parent ) {
				WP_Stub::$submenu[ $parent ][0] = array( 'slug' => $parent, 'label' => $item['label'], 'callable' => $item['callable'] );
			}
		}
	}

	WP_Stub::$submenu[ $parent ][] = array( 'slug' => $slug, 'label' => $menu_title, 'callable' => is_callable( $cb ) );
	WP_Stub::$menu[]               = array( 'type' => 'child', 'slug' => $slug, 'label' => $menu_title, 'cap' => $cap, 'parent' => $parent, 'callable' => is_callable( $cb ) );

	// get_plugin_page_hookname(): the parent's own hook, then the page slug.
	// It is also the screen id, which is what WooCommerce matches against.
	return ( WP_Stub::$page_hooks[ $parent ] ?? 'admin' ) . '_page_' . $slug;
}

/**
 * Drops one entry, leaving the parent key declared as core does.
 *
 * That detail is what keeps the back-link from coming back when the next plugin
 * of the group hangs its own page off the node.
 *
 * @param string $menu_slug    Parent slug.
 * @param string $submenu_slug Entry to remove.
 * @return array<string, mixed>|false
 */
function remove_submenu_page( $menu_slug, $submenu_slug ) {
	if ( ! isset( WP_Stub::$submenu[ $menu_slug ] ) ) {
		return false;
	}

	foreach ( WP_Stub::$submenu[ $menu_slug ] as $i => $item ) {
		if ( $submenu_slug === $item['slug'] ) {
			unset( WP_Stub::$submenu[ $menu_slug ][ $i ] );

			return $item;
		}
	}

	return false;
}

/*
 * The WooCommerce screens are WordPress pages too, and the shop is one whenever
 * its page is what got queried. Modelling that is the point: it is what the
 * exclusions in Assets::is_plain_page() exist for.
 */
function is_page() { return in_array( WP_Stub::$page_type, array( 'page', 'shop', 'cart', 'checkout', 'account' ), true ); }
/*
 * The bare flag is true on every single - a post, a page, a product, any CPT -
 * and only the argument narrows it. Modelling that is the point: with the flag
 * tied to 'post', simplifying is_singular( 'post' ) to is_singular() in the
 * code passed the suite while loading the bundle on every product.
 */
function is_singular( $type = '' ) {
	$singular = is_page() || in_array( WP_Stub::$page_type, array( 'post', 'product' ), true );

	if ( '' === $type || array() === $type ) {
		return $singular;
	}

	return $singular && in_array( WP_Stub::$page_type, (array) $type, true );
}

/*
 * WooCommerce exists only in the processes that define WC_VERSION. The
 * WooCommerce half of the plugin is tested with it; the processes that run
 * without it get none of its functions or classes, exactly as a plain
 * WordPress site would, so code of the WordPress half that calls into
 * WooCommerce by mistake fails its test with a fatal instead of passing on a
 * stub.
 */
if ( defined( 'WC_VERSION' ) ) {
	require_once __DIR__ . '/stubs/woocommerce.php';
}

$GLOBALS['admin_page_hooks'] = &WP_Stub::$page_hooks;

require_once $plugin_dir . '/includes/autoload.php';
