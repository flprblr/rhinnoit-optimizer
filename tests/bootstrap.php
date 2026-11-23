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
define( 'ROPT_VERSION', '2.0.0' );
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
define( 'DAY_IN_SECONDS', 86400 );
define( 'YEAR_IN_SECONDS', 31536000 );

// Kinsta's MU plugin, for the cases that run on Kinsta: ROPT_TESTS_KINSTA=1.
if ( '1' === getenv( 'ROPT_TESTS_KINSTA' ) ) {
	define( 'KINSTAMU_VERSION', '3.7.0' );
}

// WP Optimizer, the plugin this one replaces, still active: ROPT_TESTS_WP_OPTIMIZER=1.
if ( '1' === getenv( 'ROPT_TESTS_WP_OPTIMIZER' ) ) {
	define( 'RWPO_VERSION', '2.8.13' );
}
define( 'WP_DEBUG', false );

// Smush, for the cases that run with it loaded: ROPT_TESTS_SMUSH=1, or =api to load its Settings class too.
if ( in_array( getenv( 'ROPT_TESTS_SMUSH' ), array( '1', 'api' ), true ) ) {
	define( 'WP_SMUSH_VERSION', '4.3.3' );
}

if ( 'api' === getenv( 'ROPT_TESTS_SMUSH' ) ) {
	require __DIR__ . '/stubs/smush.php';
}

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
	/** @var array<string, string> Text domain => languages path of each load_plugin_textdomain() call. */
	public static array $textdomains = array();
	public static bool $is_admin = true;
	/** @var array<int, string> */
	public static array $removed = array();
	/** @var array<string, int> Same as $priorities, for remove_action(). */
	public static array $removed_priorities = array();
	/** @var array<int, string> Style handles registered and enqueued, in order. */
	public static array $styles = array();
	/** @var array<string, string> Inline CSS by handle. */
	public static array $inline_styles = array();
	public static string $upload_baseurl = 'https://shop.test/wp-content/uploads';
	public static string $upload_basedir = '';
	/** @var array<int, string> */
	public static array $attached_files = array();
	/** @var array<string, mixed> Hook => value apply_filters() returns instead of the one passed. */
	public static array $filters = array();
	public static mixed $featured_id = 0;
	/** @var array<int, mixed> Post id => featured image id, for get_post_thumbnail_id( $post ). */
	public static array $thumbnails = array();
	/** What get_queried_object_id() answers. */
	public static int $queried_id = 0;
	/** What get_the_ID() answers: the post of the loop being rendered, or false. */
	public static mixed $the_id = false;
	public static bool $in_the_loop = false;
	public static bool $main_query = true;
	public static bool $is_search = false;
	/** @var array<string, mixed> Query vars get_query_var() answers. */
	public static array $query_vars = array();
	/** Columns of the product loop, as wc_get_loop_prop( 'columns' ) answers; null when no loop is set up. */
	public static mixed $loop_columns = 4;
	/** What woocommerce_get_loop_display_mode() answers: products, subcategories or both. */
	public static string $loop_display = 'products';
	/** What get_queried_object() answers. */
	public static mixed $queried_object = null;
	/** @var array<int, string> "hook:callback" pairs has_action() says yes to. */
	public static array $present_actions = array();
	/** @var array<string, array<int, array<string, mixed>>> Meta type => object id => key => value, for get_metadata(). */
	public static array $metadata = array();
	public static bool $block_theme = false;
	/** Whether the active theme ships a theme.json, for wp_theme_has_theme_json(). */
	public static bool $theme_json = false;
	/** @var array<int, string> Every _doing_it_wrong() call, as "function: message". */
	public static array $doing_it_wrong = array();
	/** @var array<string, int> Scheduled single events, hook => timestamp. */
	public static array $scheduled = array();
	/** @var array<string, array<int, mixed>> Arguments of the last event scheduled on each hook. */
	public static array $scheduled_args = array();
	/** @var array<int, string> Post types is_post_type_viewable() says yes to. */
	public static array $viewable_types = array( 'post', 'page', 'product', 'porto_builder' );
	/** @var array<int, string> Taxonomies is_taxonomy_viewable() says yes to. */
	public static array $viewable_taxonomies = array( 'category', 'product_cat' );
	/** @var array<string, array<int, string>> Taxonomy => the post types it belongs to. */
	public static array $taxonomy_types = array( 'pa_color' => array( 'product' ) );
	public static bool $doing_cron = false;
	/** What home_url( '/' ) answers. */
	public static string $home_url = 'https://shop.test/';
	/** @var array<int, mixed> Answers wp_remote_get() hands out in turn; an empty queue answers 200 with no headers. */
	public static array $remote_responses = array();
	/** @var array<int, string> URLs wp_remote_get() was asked for. */
	public static array $remote_requests = array();
	/** @var array<int, string> Handles passed to wp_dequeue_script(). */
	public static array $dequeued = array();
	/** @var array<string, int> WooCommerce page => page id, as wc_get_page_id() answers. */
	public static array $wc_pages = array();
	/** @var array<int, string> Page id => URI, as get_page_uri() answers. */
	public static array $page_uris = array();
	/** @var array<int, string> Post id => status, for get_post_status(); unlisted ids are published. */
	public static array $post_status = array();
	/** @var array<int, int> Post id => parent id, as wp_get_post_parent_id() answers. */
	public static array $parents = array();
	/** @var array<int, array<string, mixed>> Post id => meta key => value, for get_post_meta(). */
	public static array $post_meta = array();
	public static mixed $wc = null;
	/** @var array<int, array{0: string, 1: string, 2: int}> Name, value and expiry of each wc_setcookie() call. */
	public static array $cookies = array();
	/** What woocommerce_mini_cart() prints for an empty cart. */
	public static string $mini_cart = '<ul class="cart_list"><li class="empty">No products in the cart.</li></ul>';
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
		 * A configured plugin: the master switch on, whatever it ships as, so a
		 * case starts from a plugin that runs and one about a plugin switched
		 * off says so itself.
		 */
		self::$options    = array( 'ropt_enabled' => 'yes' );
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

		self::$textdomains        = array();
		self::$is_admin           = true;
		self::$removed            = array();
		self::$removed_priorities = array();
		self::$styles             = array();
		self::$inline_styles      = array();
		self::$filters            = array();
		self::$featured_id        = 0;
		self::$thumbnails         = array();
		self::$queried_id         = 0;
		self::$the_id             = false;
		self::$in_the_loop        = false;
		self::$main_query         = true;
		self::$is_search          = false;
		self::$query_vars         = array();
		self::$loop_columns       = 4;
		self::$loop_display       = 'products';
		self::$queried_object     = null;
		self::$present_actions    = array();
		self::$metadata           = array();
		unset( $GLOBALS['porto_woocommerce_loop'], $GLOBALS['porto_settings'] );
		self::$block_theme        = false;
		self::$theme_json         = false;
		self::$doing_it_wrong     = array();
		// Core's stack of running hooks, which apply_filters() pushes and pops.
		$GLOBALS['wp_current_filter'] = array();
		self::$scheduled           = array();
		self::$scheduled_args      = array();
		self::$viewable_types      = array( 'post', 'page', 'product', 'porto_builder' );
		self::$viewable_taxonomies = array( 'category', 'product_cat' );
		self::$taxonomy_types      = array( 'pa_color' => array( 'product' ) );
		self::$doing_cron          = false;
		self::$home_url            = 'https://shop.test/';
		self::$remote_responses    = array();
		self::$remote_requests     = array();
		self::$dequeued            = array();
		self::$wc_pages            = array();
		self::$page_uris           = array();
		self::$post_status         = array();
		self::$parents             = array();
		self::$post_meta           = array();
		self::$wc                  = null;
		self::$cookies             = array();
		self::$mini_cart           = '<ul class="cart_list"><li class="empty">No products in the cart.</li></ul>';

		self::$caps_checked = array();
		self::$json_fails = false;
		self::$can        = true;
		self::$nonce_ok   = true;
		self::$redirect   = null;
		self::$nonce_action = '';
		$_GET             = array();
		$_POST            = array();
		$_COOKIE          = array();

		array_map( 'unlink', glob( ROPT_PATH . 'build/*' ) ?: array() );
	}
}

/** Thrown in place of wp_die()/exit so tests can assert on it. */
final class WP_Halt extends RuntimeException {}

/** Stands in for the purge object Kinsta's MU plugin hangs off $kinsta_cache. */
final class Kinsta_Purge_Stub {
	public int $calls = 0;
	/** @var array<int, array<int, mixed>> Arguments of each call. */
	public array $arguments = array();
	/** Kinsta's own one-full-purge-per-request flag. */
	public bool $purge_all_happened = false;
	/** @var array<int, int> Post ids passed to initiate_purge(). */
	public array $single = array();
	public function initiate_purge( $post_id ) { $this->single[] = (int) $post_id; return true; }
	public function purge_complete_caches(): void {
		$this->arguments[] = func_get_args();
		if ( $this->purge_all_happened ) { return; }
		$this->purge_all_happened = true;
		++$this->calls;
	}
}

/** A Kinsta purge object from a version without the per-post purge. */
final class Kinsta_Full_Purge_Only_Stub {
	public int $calls = 0;
	public bool $purge_all_happened = false;
	public function purge_complete_caches(): void { ++$this->calls; }
}

/** The slice of WP_Taxonomy the page cache reads. */
final class WP_Taxonomy { /** @var array<int, string> */ public array $object_type = array(); }

/** The slice of WP_Post the page cache and the product image correction read. */
class WP_Post { public int $ID = 0; public string $post_type = 'post'; public function __construct( int $id = 0, string $post_type = 'post' ) { $this->ID = $id; $this->post_type = $post_type; } }

/** The slice of WP_Term the first row correction reads. */
class WP_Term { public int $term_id = 0; public string $taxonomy = ''; public string $name = ''; public function __construct( int $id = 0, string $taxonomy = 'product_cat' ) { $this->term_id = $id; $this->taxonomy = $taxonomy; } }

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Core's error object: the page cache only asks whether an answer is one.
	 */
	class WP_Error {}
}

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
function remove_query_arg( $key, $url ) { return (string) strtok( $url, '?' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_parse_str( $input_string, &$result ) { parse_str( (string) $input_string, $result ); }
function wp_basename( $path ) { return basename( $path ); }
function wp_upload_dir() { return array( 'baseurl' => WP_Stub::$upload_baseurl, 'basedir' => WP_Stub::$upload_basedir ); }
function get_attached_file( $id ) { return WP_Stub::$attached_files[ $id ] ?? false; }
function is_admin() { return WP_Stub::$is_admin; }
function load_plugin_textdomain( $domain, $abs, $path ) { WP_Stub::$textdomains[ (string) $domain ] = (string) $path; return true; }

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
function remove_action( $hook, $cb, $priority = 10 ) { WP_Stub::$removed[] = $hook . ':' . ( is_string( $cb ) ? $cb : 'closure' ); WP_Stub::$removed_priorities[ $hook . ':' . ( is_string( $cb ) ? $cb : 'closure' ) ] = $priority; return true; }
function has_action( $hook, $callback = false ) { return in_array( $hook . ':' . ( is_string( $callback ) ? $callback : '' ), WP_Stub::$present_actions, true ) ? 10 : false; }
function apply_filters( $hook, $value, ...$args ) { return array_key_exists( $hook, WP_Stub::$filters ) ? WP_Stub::$filters[ $hook ] : $value; }
function __return_empty_string() { return ''; }

function wp_json_encode( $data ) { return WP_Stub::$json_fails ? false : json_encode( $data ); }
function wp_enqueue_script( $handle, $src, $deps, $ver, $args ) { WP_Stub::$enqueued[] = compact( 'handle', 'deps', 'ver' ); }
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) { WP_Stub::$styles[] = 'enqueue:' . $handle; }
function wp_register_style( $handle, $src, $deps = array(), $ver = false ) { WP_Stub::$styles[] = 'register:' . $handle . ':' . var_export( $src, true ); return true; }
function wp_add_inline_style( $handle, $data ) { WP_Stub::$inline_styles[ (string) $handle ] = (string) $data; return true; }
function wp_style_add_data( $handle, $key, $value ) {}
function wp_set_script_translations( $handle, $domain, $path ) { WP_Stub::$enqueued[] = array( 'handle' => $handle . ':i18n' ); }
function wp_add_inline_script( $handle, $js, $position ) { WP_Stub::$inline[] = $js; }
function wp_dequeue_script( $handle ) { WP_Stub::$dequeued[] = (string) $handle; }
function get_page_uri( $page = 0 ) { return WP_Stub::$page_uris[ (int) $page ] ?? false; }
function wp_print_inline_script_tag( $data, $attributes = array() ) {
	$attrs = '';
	foreach ( (array) $attributes as $name => $value ) {
		$attrs .= ' ' . $name . '="' . esc_attr( $value ) . '"';
	}
	echo '<script' . $attrs . '>' . $data . '</script>';
}
function wp_nonce_field( $action ) { WP_Stub::$nonce_action = (string) $action; echo '<input type="hidden" name="_wpnonce" value="stub"/>'; }
function submit_button( $text = '', $type = 'primary', $name = 'submit' ) { echo '<input type="submit" value="Save Changes"/>'; }

function current_user_can( $cap ) { WP_Stub::$caps_checked[] = (string) $cap; return WP_Stub::$can; }
function check_admin_referer( $action ) {
	if ( ! WP_Stub::$nonce_ok ) { throw new WP_Halt( 'nonce' ); }
	return true;
}
function wp_die( $message ) { throw new WP_Halt( $message ); }
function do_action( $hook, ...$args ) { WP_Stub::$fired[] = array_merge( array( $hook ), $args ); }
function is_post_type_viewable( $type ) { return in_array( $type, WP_Stub::$viewable_types, true ); }
function is_taxonomy_viewable( $taxonomy ) { return in_array( $taxonomy, WP_Stub::$viewable_taxonomies, true ); }
function get_stylesheet() { return 'porto-child'; }
function get_post_status( $post = null ) { return WP_Stub::$post_status[ (int) $post ] ?? 'publish'; }
function wp_get_post_parent_id( $post = null ) { return WP_Stub::$parents[ (int) $post ] ?? 0; }
function get_post_meta( $post_id, $key = '', $single = false ) {
	$value = WP_Stub::$post_meta[ (int) $post_id ][ $key ] ?? null;
	// As core: one value when $single, else the list of every row's value.
	return $single ? ( $value ?? '' ) : ( null === $value ? array() : array( $value ) );
}
function get_taxonomy( $taxonomy ) {
	if ( ! isset( WP_Stub::$taxonomy_types[ $taxonomy ] ) ) { return false; }
	$object              = new WP_Taxonomy();
	$object->object_type = WP_Stub::$taxonomy_types[ $taxonomy ];
	return $object;
}
function wp_doing_cron() { return WP_Stub::$doing_cron; }
function wp_remote_get( $url, $args = array() ) { WP_Stub::$remote_requests[] = (string) $url; return array() !== WP_Stub::$remote_responses ? array_shift( WP_Stub::$remote_responses ) : array( 'response' => array( 'code' => 200 ), 'headers' => array() ); }
function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? ( $response['response']['code'] ?? '' ) : ''; }
function wp_remote_retrieve_header( $response, $header ) { return is_array( $response ) ? ( array_change_key_case( (array) ( $response['headers'] ?? array() ) )[ strtolower( (string) $header ) ] ?? '' ) : ''; }
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
function home_url( $path = '' ) { return rtrim( WP_Stub::$home_url, '/' ) . '/' . ltrim( (string) $path, '/' ); }
function wp_next_scheduled( $hook ) { return WP_Stub::$scheduled[ $hook ] ?? false; }
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { WP_Stub::$scheduled[ $hook ] = (int) $timestamp; WP_Stub::$scheduled_args[ $hook ] = $args; return true; }
function wp_unschedule_event( $timestamp, $hook ) { unset( WP_Stub::$scheduled[ $hook ] ); return true; }
// As core does, only the events whose arguments match are cleared.
function wp_clear_scheduled_hook( $hook, $args = array() ) { if ( ( WP_Stub::$scheduled_args[ $hook ] ?? array() ) === $args ) { unset( WP_Stub::$scheduled[ $hook ], WP_Stub::$scheduled_args[ $hook ] ); } return 0; }
function wp_unschedule_hook( $hook ) { unset( WP_Stub::$scheduled[ $hook ], WP_Stub::$scheduled_args[ $hook ] ); return 0; }
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

function wp_is_block_theme() { return WP_Stub::$block_theme; }
function wp_theme_has_theme_json() { return WP_Stub::$theme_json; }
function _doing_it_wrong( $function_name, $message, $version ) { WP_Stub::$doing_it_wrong[] = $function_name . ': ' . $message; }

/*
 * The two core functions the HTML API's set_attribute() calls, as core has
 * them: the attributes whose value is a URL, and the Unicode noncharacters an
 * attribute name may not hold.
 */
function wp_kses_uri_attributes() {
	return array( 'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns' );
}
function wp_has_noncharacters( string $text ): bool {
	return 1 === preg_match( '~\xEF(?:\xB7[\x90-\xAF]|\xBF[\xBE\xBF])|(?:\xF0[\x9F\xAF\xBF]|[\xF1-\xF3][\x8F\x9F\xAF\xBF]|\xF4\x8F)\xBF[\xBE\xBF]~', $text );
}

/** The parent theme's directory: Porto's, unless the process runs with ROPT_TESTS_PORTO=none. */
function get_template() { return function_exists( 'porto_minicart' ) ? 'porto' : 'twentytwentyfive'; }

function get_post_thumbnail_id( $post = null ) { return null === $post ? WP_Stub::$featured_id : ( WP_Stub::$thumbnails[ (int) $post ] ?? WP_Stub::$featured_id ); }
function get_queried_object_id() { return WP_Stub::$queried_id; }
function get_queried_object() { return WP_Stub::$queried_object; }
function get_metadata( $type, $id, $key = '', $single = false ) { return WP_Stub::$metadata[ $type ][ (int) $id ][ $key ] ?? ( $single ? '' : array() ); }
// Core's auto sizes, from WordPress 6.7, and speculative loading, from 6.8;
// ROPT_TESTS_WP=6.6 boots a site from before both.
if ( '6.6' !== getenv( 'ROPT_TESTS_WP' ) ) {
	function wp_sizes_attribute_includes_valid_auto( string $sizes_attr ): bool {
		list( $first_size ) = explode( ',', $sizes_attr, 2 );
		return 'auto' === strtolower( trim( $first_size, " \t\f\r\n" ) );
	}

	function wp_get_speculation_rules_configuration() { return array( 'mode' => 'prefetch', 'eagerness' => 'conservative' ); }
}
function get_the_ID() { return WP_Stub::$the_id; }
function in_the_loop() { return WP_Stub::$in_the_loop; }
function is_main_query() { return WP_Stub::$main_query; }
function is_search() { return WP_Stub::$is_search; }
function get_query_var( $key, $default_value = '' ) { return WP_Stub::$query_vars[ $key ] ?? $default_value; }

/*
 * The WooCommerce screens are WordPress pages too, and the shop is one whenever
 * its page is what got queried. Modelling that is the point: on a real shop a
 * bare is_page() is true on all of them, and code that means a plain page has
 * to rule them out itself.
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

// Porto, which every shop runs; ROPT_TESTS_PORTO=none boots a site without it.
if ( 'none' !== getenv( 'ROPT_TESTS_PORTO' ) ) {
	require_once __DIR__ . '/stubs/porto.php';
}

/*
 * WordPress's HTML API, which Viewport_Hero parses the page content with.
 * Core loads it on every request since 6.5; tests/stubs/html-api/ holds the
 * classes the Tag Processor needs, copied unchanged from WordPress 7.1.2
 * (wp-includes/html-api/ and wp-includes/class-wp-token-map.php), so the
 * cases run the real parser rather than a stand-in. Loaded only when missing:
 * a harness that loads a site's own core first is tested against that one.
 */
if ( ! class_exists( 'WP_HTML_Tag_Processor', false ) ) {
	foreach ( array( 'class-wp-token-map.php', 'html5-named-character-references.php', 'class-wp-html-decoder.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-tag-processor.php' ) as $ropt_html_api_file ) {
		require_once __DIR__ . '/stubs/html-api/' . $ropt_html_api_file;
	}
}

$GLOBALS['admin_page_hooks'] = &WP_Stub::$page_hooks;

require_once $plugin_dir . '/includes/autoload.php';
