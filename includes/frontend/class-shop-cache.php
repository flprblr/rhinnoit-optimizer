<?php
/**
 * Reports shop changes that make cached pages stale.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer\Frontend;

use RhinnoIT\Optimizer\Page_Cache;
use RhinnoIT\Optimizer\Plugin;
use RhinnoIT\Optimizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tells Page_Cache when a product or shop setting changes what pages show.
 *
 * The WooCommerce half of "Clear the page cache": the WordPress half never
 * calls into WooCommerce, so this reports through the rhinnoit_page_cache_dirty
 * action Page_Cache listens to.
 *
 * - Stock: only a change of stock status (in stock, out of stock, on
 *   backorder). WooCommerce saves the product on every order to lower the
 *   count, so hooking that would clear - on cPanel, the whole account - after
 *   every sale; a count shown on a page may lag the 60 minutes the cache
 *   keeps it, and checkout checks stock anyway. The one count that counts is
 *   zero in a single store, on a shop with WeLaunch Multi Inventory: with its
 *   "Modify Stock Quantity" option the page shows the default store's
 *   availability, which empties and refills while the product's total, and
 *   so WooCommerce's status, stays in stock.
 * - Product: a price, sale date or type saved through WooCommerce's objects
 *   without a post update - the variations panel, bulk price actions, REST,
 *   CSV imports, an ERP - and featured or catalog visibility, the product
 *   order, and attribute changes. None of those fires a post hook, so
 *   neither Page_Cache nor Kinsta would see them.
 * - Sale: a scheduled sale starting or ending.
 * - Site: WooCommerce's settings and product permalinks, which change every
 *   listing.
 */
final class Shop_Cache {

	/**
	 * Product properties whose change shows on product pages and listings.
	 *
	 * @var array<int, string>
	 */
	const array PRICE_PROPS = array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'product_type' );

	/**
	 * Meta key where WeLaunch Multi Inventory keeps each store's stock.
	 *
	 * @var string
	 */
	const string STORE_STOCK_META = 'woocommerce_multi_inventory_inventories_stock';

	/**
	 * Products whose featured flag or catalog visibility is changing in this save.
	 *
	 * WooCommerce fires woocommerce_product_set_visibility for a stock status
	 * change too; only these two make it a visibility change of its own.
	 *
	 * @var array<int, bool>
	 */
	private array $visibility_changes = array();

	/**
	 * Hooks the shop events when the switch is on.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'page_cache_shop' ) ) {
			return;
		}

		add_action( 'woocommerce_product_set_stock_status', array( $this, 'stock_changed' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'stock_changed' ), 10, 3 );
		add_action( 'woocommerce_product_object_updated_props', array( $this, 'props_updated' ), 10, 2 );
		add_action( 'woocommerce_before_product_object_save', array( $this, 'note_visibility_change' ) );
		add_action( 'woocommerce_product_set_visibility', array( $this, 'product_changed' ) );

		// Every writer reaches these: WooCommerce's objects, its REST API, update_post_meta().
		add_action( 'added_post_meta', array( $this, 'store_stock_added' ), 10, 4 );
		add_action( 'update_post_meta', array( $this, 'store_stock_updating' ), 10, 4 );
		add_action( 'delete_post_meta', array( $this, 'store_stock_deleting' ), 10, 4 );

		// These touch many products at once, so they are site-wide.
		add_action( 'woocommerce_after_product_ordering', array( $this, 'products_changed' ) );
		add_action( 'woocommerce_attribute_updated', array( $this, 'products_changed' ) );
		add_action( 'woocommerce_attribute_deleted', array( $this, 'products_changed' ) );

		// After WooCommerce's own handlers at 10 have changed the price.
		add_action( 'wc_product_start_scheduled_sale', array( $this, 'sale_changed' ), 20 );
		add_action( 'wc_product_end_scheduled_sale', array( $this, 'sale_changed' ), 20 );
		add_action( 'wc_after_products_starting_sales', array( $this, 'sale_changed' ) );
		add_action( 'wc_after_products_ending_sales', array( $this, 'sale_changed' ) );

		add_action( 'woocommerce_settings_saved', array( $this, 'settings_changed' ) );
		add_action( 'update_option_woocommerce_permalinks', array( $this, 'settings_changed' ) );

		// Kinsta's MU plugin builds its purge controllers on init, after this.
		if ( Plugin::kinsta_is_active() && Settings::feature( 'page_cache_clear' ) && Settings::feature( 'kinsta_stock_status_only' ) ) {
			add_filter( 'kinsta/kmp/cache/autopurge', array( $this, 'no_kinsta_stock_purge' ), 10, 2 );
		}
	}

	/**
	 * Switches off Kinsta's own purge on every stock change.
	 *
	 * Kinsta purges a product, its categories and the home page whenever its
	 * stock count changes - every sale and every stock sync through the API -
	 * although a shop that shows "In stock" rather than a count shows nothing
	 * new until the product, or one of its stores, sells out. With this, the
	 * purges on stock status and store stock above are the only stock purges.
	 * The quantity field's limit still follows the count and may lag the
	 * cache's hour; the cart refuses more than there is. Kinsta's settings
	 * screen has no switch for it; its documented filter does.
	 *
	 * @param mixed $status Whether Kinsta's controller is on; null for its default.
	 * @param mixed $name   Name of Kinsta's autopurge controller.
	 * @return mixed
	 */
	public function no_kinsta_stock_purge( mixed $status, mixed $name ): mixed {
		return 'woocommerce_controller' === $name ? false : $status;
	}

	/**
	 * Notes a save that changes the featured flag or catalog visibility.
	 *
	 * @param mixed $product The product about to be saved.
	 * @return void
	 */
	public function note_visibility_change( mixed $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$changes = array_keys( $product->get_changes() );

		$this->visibility_changes[ $product->get_id() ] = array() !== array_intersect( array( 'featured', 'catalog_visibility' ), $changes );
	}

	/**
	 * A product or a variation went in or out of stock.
	 *
	 * @param mixed $product_id   Product or variation id.
	 * @param mixed $stock_status New status.
	 * @param mixed $product      The product, when WooCommerce passes it.
	 * @return void
	 */
	public function stock_changed( mixed $product_id, mixed $stock_status = null, mixed $product = null ): void {
		unset( $stock_status );

		$this->report( 'stock', $product instanceof \WC_Product ? $product : $product_id );
	}

	/**
	 * Multi Inventory stored a product's store stock for the first time.
	 *
	 * @param mixed $meta_id    Meta row id.
	 * @param mixed $object_id  Product or variation id.
	 * @param mixed $meta_key   Meta key.
	 * @param mixed $meta_value The stock per store, store id => quantity.
	 * @return void
	 */
	public function store_stock_added( mixed $meta_id, mixed $object_id, mixed $meta_key, mixed $meta_value ): void {
		unset( $meta_id );

		if ( self::STORE_STOCK_META === $meta_key ) {
			$this->compare_store_stock( $object_id, array(), $meta_value );
		}
	}

	/**
	 * Multi Inventory is about to change a product's store stock.
	 *
	 * Core fires this before the write, so the stored value is still the old one.
	 *
	 * @param mixed $meta_id    Meta row id.
	 * @param mixed $object_id  Product or variation id; a numeric string from WooCommerce's objects.
	 * @param mixed $meta_key   Meta key.
	 * @param mixed $meta_value The new stock per store, store id => quantity.
	 * @return void
	 */
	public function store_stock_updating( mixed $meta_id, mixed $object_id, mixed $meta_key, mixed $meta_value ): void {
		unset( $meta_id );

		if ( self::STORE_STOCK_META === $meta_key && is_numeric( $object_id ) ) {
			$this->compare_store_stock( $object_id, get_post_meta( (int) $object_id, self::STORE_STOCK_META, true ), $meta_value );
		}
	}

	/**
	 * Multi Inventory is about to remove a product's store stock.
	 *
	 * Without it the page falls back to WooCommerce's own status. Core fires
	 * this before the delete, so the stored value is still readable.
	 *
	 * @param mixed $meta_ids   Meta row ids.
	 * @param mixed $object_id  Product or variation id.
	 * @param mixed $meta_key   Meta key.
	 * @param mixed $meta_value The value asked to delete, or empty for any.
	 * @return void
	 */
	public function store_stock_deleting( mixed $meta_ids, mixed $object_id, mixed $meta_key, mixed $meta_value ): void {
		unset( $meta_ids, $meta_value );

		if ( self::STORE_STOCK_META === $meta_key && is_numeric( $object_id ) ) {
			$this->compare_store_stock( $object_id, get_post_meta( (int) $object_id, self::STORE_STOCK_META, true ), array() );
		}
	}

	/**
	 * Reports the product when a store went in or out of stock.
	 *
	 * @param mixed $object_id Product or variation id.
	 * @param mixed $before    Stock per store before the write.
	 * @param mixed $after     Stock per store after it.
	 * @return void
	 */
	private function compare_store_stock( mixed $object_id, mixed $before, mixed $after ): void {
		if ( self::store_states( $before ) !== self::store_states( $after ) ) {
			$this->report( 'stock', $object_id );
		}
	}

	/**
	 * Whether each store has the product, from its stock per store.
	 *
	 * A blank store counts as no store, as Multi Inventory reads it: the page
	 * then falls back to WooCommerce's own status, while a zero - or less, since
	 * it subtracts without clamping - shows the store out of stock.
	 *
	 * @param mixed $stock Store id => quantity; quantities arrive as strings as often as numbers.
	 * @return array<int|string, bool> Store id => in stock, sorted by store.
	 */
	private static function store_states( mixed $stock ): array {
		$states = array();

		foreach ( is_array( $stock ) ? $stock : array() as $store => $quantity ) {
			if ( is_scalar( $quantity ) && '' !== trim( (string) $quantity ) ) {
				$states[ $store ] = is_numeric( $quantity ) && 0 < (float) $quantity;
			}
		}

		ksort( $states );

		return $states;
	}

	/**
	 * A product was saved; it counts only if its price or type changed.
	 *
	 * @param mixed $product The product.
	 * @param mixed $props   Names of the properties the save changed.
	 * @return void
	 */
	public function props_updated( mixed $product, mixed $props ): void {
		if ( is_array( $props ) && array() !== array_intersect( self::PRICE_PROPS, $props ) ) {
			$this->report( 'product', $product );
		}
	}

	/**
	 * A product's featured flag or catalog visibility changed.
	 *
	 * WooCommerce fires this for a stock status change as well; that one is
	 * reported by stock_changed(), and reporting it here too would clear on
	 * cPanel at once instead of in the stock window.
	 *
	 * @param mixed $product_id Product id.
	 * @return void
	 */
	public function product_changed( mixed $product_id ): void {
		$id = is_numeric( $product_id ) ? (int) $product_id : 0;

		if ( $this->visibility_changes[ $id ] ?? false ) {
			$this->report( 'product', $id );
		}
	}

	/**
	 * The product order, or an attribute every variable product can use, changed.
	 *
	 * @return void
	 */
	public function products_changed(): void {
		$this->report( 'product', 0 );
	}

	/**
	 * A scheduled sale started or ended, for one product or a list of them.
	 *
	 * @param mixed $products Product id, or the ids of the daily run.
	 * @return void
	 */
	public function sale_changed( mixed $products = 0 ): void {
		foreach ( is_array( $products ) ? $products : array( $products ) as $product ) {
			$this->report( 'sale', $product );
		}
	}

	/**
	 * WooCommerce settings or product permalinks were saved.
	 *
	 * @return void
	 */
	public function settings_changed(): void {
		$this->report( 'site', 0 );
	}

	/**
	 * Tells Page_Cache, naming the product page the change shows on.
	 *
	 * A variation has no page of its own; its parent's is the one to purge.
	 *
	 * @param string $reason  One of Page_Cache::REASONS.
	 * @param mixed  $product A product, a product id, or 0 for many products.
	 * @return void
	 */
	private function report( string $reason, mixed $product = 0 ): void {
		if ( $product instanceof \WC_Product ) {
			$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		} else {
			$id     = is_numeric( $product ) ? (int) $product : 0;
			$parent = 0 < $id ? wp_get_post_parent_id( $id ) : 0;
			$id     = is_int( $parent ) && 0 < $parent ? $parent : $id;
		}

		// A draft or private product is on no page a visitor can see.
		if ( 0 < $id && 'publish' !== get_post_status( $id ) ) {
			return;
		}

		/**
		 * Reports a change that makes cached pages stale; Page_Cache listens.
		 *
		 * @since 1.0.0
		 * @param string $reason     Why: stock, product, sale or site.
		 * @param int    $product_id The product whose page shows it; 0 for many.
		 */
		do_action( Page_Cache::DIRTY, $reason, $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- A group hook, unprefixed on purpose: see Install::DIRTY_HOOK.
	}
}
