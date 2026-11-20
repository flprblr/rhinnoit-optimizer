<?php
/**
 * Reporting product and shop changes to the page cache service.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Frontend\Shop_Cache;

#[CoversClass( Shop_Cache::class )]
final class ShopCacheTest extends TestCase {

	protected function setUp(): void {
		WP_Stub::reset();
	}

	public function test_every_shop_event_it_reports_is_hooked(): void {
		( new Shop_Cache() )->register();

		$this->assertSame(
			array(
				'woocommerce_product_set_stock_status:stock_changed',
				'woocommerce_variation_set_stock_status:stock_changed',
				'woocommerce_product_object_updated_props:props_updated',
				'woocommerce_before_product_object_save:note_visibility_change',
				'woocommerce_product_set_visibility:product_changed',
				'added_post_meta:store_stock_added',
				'update_post_meta:store_stock_updating',
				'delete_post_meta:store_stock_deleting',
				'woocommerce_after_product_ordering:products_changed',
				'woocommerce_attribute_updated:products_changed',
				'woocommerce_attribute_deleted:products_changed',
				'wc_product_start_scheduled_sale:sale_changed',
				'wc_product_end_scheduled_sale:sale_changed',
				'wc_after_products_starting_sales:sale_changed',
				'wc_after_products_ending_sales:sale_changed',
				'woocommerce_settings_saved:settings_changed',
				'update_option_woocommerce_permalinks:settings_changed',
			),
			WP_Stub::$callbacks
		);
		$this->assertNotContains( 'woocommerce_product_set_stock:stock_changed', WP_Stub::$callbacks, 'Every order lowers the count; only a status change shows on the page.' );
		$this->assertSame( 20, WP_Stub::$priorities['wc_product_start_scheduled_sale:sale_changed'], 'After WooCommerce has changed the price.' );
	}

	public function test_nothing_is_hooked_while_it_is_off(): void {
		WP_Stub::$options['ropt_page_cache_shop'] = 'no';

		( new Shop_Cache() )->register();

		$this->assertSame( array(), WP_Stub::$callbacks );
	}

	public function test_a_price_saved_without_a_post_update_names_the_product(): void {
		$shop = new Shop_Cache();

		$shop->props_updated( new WC_Product( false, 42 ), array( 'regular_price', 'stock_quantity' ) );
		$shop->props_updated( new WC_Product( false, 43 ), array( 'stock_quantity' ) );
		$shop->props_updated( new WC_Product( false, 44 ), array( 'stock_quantity', 'stock_status' ) );
		$shop->props_updated( new WC_Product( false, 45 ), 'not a list' );

		$this->assertSame( array( array( 'rhinnoit_page_cache_dirty', 'product', 42 ) ), WP_Stub::$fired, 'Only the price change; an order or an API stock sync never.' );
	}

	public function test_a_variation_is_reported_as_its_parent_product(): void {
		$shop = new Shop_Cache();

		$shop->stock_changed( 101, 'outofstock', new WC_Product( false, 101, 100 ) );
		WP_Stub::$parents[201] = 200;
		$shop->stock_changed( 201, 'instock' );
		$shop->props_updated( new WC_Product( false, 301, 300 ), array( 'sale_price' ) );

		$this->assertSame(
			array(
				array( 'rhinnoit_page_cache_dirty', 'stock', 100 ),
				array( 'rhinnoit_page_cache_dirty', 'stock', 200 ),
				array( 'rhinnoit_page_cache_dirty', 'product', 300 ),
			),
			WP_Stub::$fired,
			'A variation has no page of its own.'
		);
	}

	public function test_each_event_reports_its_reason_and_product(): void {
		$shop = new Shop_Cache();

		$featured                   = new WC_Product( false, 7 );
		$featured->changes['featured'] = true;
		$shop->note_visibility_change( $featured );
		$shop->product_changed( 7 );
		$shop->products_changed();
		$shop->sale_changed( 8 );
		$shop->sale_changed( array( 9, 10 ) );
		$shop->settings_changed();

		$this->assertSame(
			array(
				array( 'rhinnoit_page_cache_dirty', 'product', 7 ),
				array( 'rhinnoit_page_cache_dirty', 'product', 0 ),
				array( 'rhinnoit_page_cache_dirty', 'sale', 8 ),
				array( 'rhinnoit_page_cache_dirty', 'sale', 9 ),
				array( 'rhinnoit_page_cache_dirty', 'sale', 10 ),
				array( 'rhinnoit_page_cache_dirty', 'site', 0 ),
			),
			WP_Stub::$fired
		);
	}

	public function test_a_stock_status_change_is_not_also_a_visibility_change(): void {
		$shop    = new Shop_Cache();
		$product = new WC_Product( false, 42 );

		$product->changes['stock_status'] = 'outofstock';
		$shop->note_visibility_change( $product );
		$shop->product_changed( 42 );

		$this->assertSame( array(), WP_Stub::$fired, 'WooCommerce fires the visibility hook for stock status too; on cPanel it would skip the stock window.' );
	}

	/**
	 * Values as measured on the live shop: quantities stored as strings, and
	 * WooCommerce's objects passing the id as a numeric string to update_post_meta.
	 */
	public function test_a_store_going_in_or_out_of_stock_reports_its_product(): void {
		$shop = new Shop_Cache();
		$key  = Shop_Cache::STORE_STOCK_META;

		WP_Stub::$parents[41911]           = 41908;
		WP_Stub::$post_meta[41911][ $key ] = array( '280' => '1', '281' => '1', '368' => '1', '430' => '1' );

		// The online store's last pair sold; the total stays in stock.
		$shop->store_stock_updating( 7, '41911', $key, array( '280' => 0, '281' => '1', '368' => '1', '430' => '1' ) );

		WP_Stub::$post_meta[41911][ $key ] = array( '280' => '3', '281' => '1', '368' => '2', '430' => '2' );

		// A sale or a sync that leaves every store with stock.
		$shop->store_stock_updating( 7, 41911, $key, array( '280' => '2', '281' => '1', '368' => '2', '430' => '5' ) );
		// Another meta key.
		$shop->store_stock_updating( 8, 41911, '_stock', 0 );
		// Not a post id; core never sends one, and 0 would mean every product.
		$shop->store_stock_updating( 9, 'abc', $key, array( '280' => '1' ) );

		$this->assertSame( array( array( 'rhinnoit_page_cache_dirty', 'stock', 41908 ) ), WP_Stub::$fired, 'Only the store that emptied; the page shows nothing new for the rest.' );
	}

	public function test_which_store_has_stock_counts_not_how_many_stores(): void {
		$shop = new Shop_Cache();
		$key  = Shop_Cache::STORE_STOCK_META;

		// The last unit moves from the online store to a pickup store in one write.
		WP_Stub::$post_meta[60][ $key ] = array( '280' => '1', '281' => 0, '368' => '1', '430' => '1' );
		$shop->store_stock_updating( 1, 60, $key, array( '280' => 0, '281' => '1', '368' => '1', '430' => '1' ) );

		// The same stores in stock, rebuilt in another order.
		WP_Stub::$post_meta[61][ $key ] = array( '430' => '2', '280' => '1' );
		$shop->store_stock_updating( 2, 61, $key, array( '280' => '3', '430' => '1' ) );

		$this->assertSame( array( array( 'rhinnoit_page_cache_dirty', 'stock', 60 ) ), WP_Stub::$fired );
	}

	/**
	 * Multi Inventory draws a blank store as WooCommerce's own status, a zero
	 * as out of stock, and subtracts without clamping, so an oversold store
	 * goes below zero.
	 */
	public function test_blank_zero_and_below_zero_read_as_multi_inventory_shows_them(): void {
		$shop = new Shop_Cache();
		$key  = Shop_Cache::STORE_STOCK_META;
		$runs = array(
			70 => array( array( '280' => '', '281' => '2' ), array( '280' => 0, '281' => '2' ) ),
			71 => array( array( '281' => '2' ), array( '280' => '0', '281' => '2' ) ),
			72 => array( array( '280' => '0', '281' => '2' ), array( '280' => '', '281' => '2' ) ),
			73 => array( array( '280' => '1', '281' => '1' ), array( '280' => -1, '281' => '1' ) ),
			74 => array( array( '280' => '1', '281' => '1' ), array( '280' => '-1', '281' => '1' ) ),
			75 => array( array( '280' => '0', '281' => '2' ), array( '280' => '-1', '281' => '2' ) ),
			76 => array( array( '280' => '', '281' => '2' ), array( '281' => '2' ) ),
		);

		foreach ( $runs as $id => list( $before, $after ) ) {
			WP_Stub::$post_meta[ $id ][ $key ] = $before;
			$shop->store_stock_updating( 1, $id, $key, $after );
		}

		$this->assertSame(
			array(
				array( 'rhinnoit_page_cache_dirty', 'stock', 70 ),
				array( 'rhinnoit_page_cache_dirty', 'stock', 71 ),
				array( 'rhinnoit_page_cache_dirty', 'stock', 72 ),
				array( 'rhinnoit_page_cache_dirty', 'stock', 73 ),
				array( 'rhinnoit_page_cache_dirty', 'stock', 74 ),
			),
			WP_Stub::$fired,
			'Zero to below zero, and blank to missing, show the same page.'
		);
	}

	public function test_first_store_stock_reports_only_when_a_store_is_filled_in(): void {
		$shop = new Shop_Cache();
		$key  = Shop_Cache::STORE_STOCK_META;

		$shop->store_stock_added( 1, 50, $key, array( '280' => '', '430' => '' ) );
		$shop->store_stock_added( 2, 51, $key, 'not a list' );
		$shop->store_stock_added( 3, 52, $key, array( '280' => '2' ) );
		$shop->store_stock_added( 4, 53, '_upsell_ids', array( 12, 13 ) );

		$this->assertSame( array( array( 'rhinnoit_page_cache_dirty', 'stock', 52 ) ), WP_Stub::$fired );
	}

	public function test_removing_the_store_stock_reports_when_a_store_was_filled_in(): void {
		$shop = new Shop_Cache();
		$key  = Shop_Cache::STORE_STOCK_META;

		WP_Stub::$post_meta[80][ $key ] = array( '280' => '0', '281' => '1' );
		WP_Stub::$post_meta[81][ $key ] = array( '280' => '', '281' => '' );
		$shop->store_stock_deleting( array( 1 ), '80', $key, '' );
		$shop->store_stock_deleting( array( 2 ), 81, $key, '' );
		$shop->store_stock_deleting( array( 3 ), 80, '_stock', '' );

		$this->assertSame( array( array( 'rhinnoit_page_cache_dirty', 'stock', 80 ) ), WP_Stub::$fired, 'Without it the page shows WooCommerce\'s own status.' );
	}

	public function test_a_draft_is_on_no_page_and_reports_nothing(): void {
		WP_Stub::$post_status[42] = 'draft';

		( new Shop_Cache() )->props_updated( new WC_Product( false, 42 ), array( 'regular_price' ) );

		$this->assertSame( array(), WP_Stub::$fired );
	}

	public function test_kinstas_stock_purge_is_not_touched_away_from_kinsta(): void {
		WP_Stub::$options['ropt_kinsta_stock_status_only'] = 'yes';

		( new Shop_Cache() )->register();

		$this->assertNotContains( 'kinsta/kmp/cache/autopurge:no_kinsta_stock_purge', WP_Stub::$callbacks );
		$this->assertFalse( ( new Shop_Cache() )->no_kinsta_stock_purge( null, 'woocommerce_controller' ) );
		$this->assertNull( ( new Shop_Cache() )->no_kinsta_stock_purge( null, 'wp_post_controller' ), 'Only the stock controller.' );
	}
}
