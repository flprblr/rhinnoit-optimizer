<?php
/**
 * A write from an integration through the WooCommerce API waits its window.
 *
 * Its own process: it defines REST_REQUEST, which the rest of the suite must
 * not see.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Page_Cache;

#[CoversClass( Page_Cache::class )]
final class PageCacheIntegrationRequestTest extends TestCase {

	#[RunInSeparateProcess]
	public function test_an_erp_writing_through_the_api_never_clears_on_its_request(): void {
		define( 'REST_REQUEST', true );

		$dir  = sys_get_temp_dir() . '/ropt-uapi-' . uniqid();
		$uapi = $dir . '/uapi';
		mkdir( $dir );
		file_put_contents( $uapi, "#!/bin/sh\necho x >> " . escapeshellarg( $dir . '/called' ) . "\nprintf %s '{\"result\":{\"status\":1}}'\n" );
		chmod( $uapi, 0755 );
		touch( $dir . '/ea-nginx.conf' );

		foreach ( array( '/wc/v3/products/42' => 0, '/wp/v2/posts/7' => 1 ) as $route => $clears ) {
			WP_Stub::reset();
			WP_Stub::$can  = true;
			$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => $route ) );

			if ( is_file( $dir . '/called' ) ) {
				unlink( $dir . '/called' );
			}

			$cache = new Page_Cache( $uapi, 20, false, array( $dir . '/ea-nginx.conf' ) );
			$cache->register();
			$cache->mark( 'product', 42 );
			$cache->flush();

			$this->assertSame( $clears, is_file( $dir . '/called' ) ? 1 : 0, $route );
			$this->assertSame( 0 === $clears, isset( WP_Stub::$scheduled[ Page_Cache::EVENT ] ), $route . ': deferred to the window instead.' );
		}

		array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
		rmdir( $dir );
	}

	#[RunInSeparateProcess]
	public function test_the_legacy_woocommerce_api_counts_as_an_integration(): void {
		define( 'WC_API_REQUEST', true );

		$dir  = sys_get_temp_dir() . '/ropt-uapi-' . uniqid();
		$uapi = $dir . '/uapi';
		mkdir( $dir );
		file_put_contents( $uapi, "#!/bin/sh\necho x >> " . escapeshellarg( $dir . '/called' ) . "\nprintf %s '{\"result\":{\"status\":1}}'\n" );
		chmod( $uapi, 0755 );
		touch( $dir . '/ea-nginx.conf' );

		WP_Stub::reset();
		WP_Stub::$can = true;

		$cache = new Page_Cache( $uapi, 20, false, array( $dir . '/ea-nginx.conf' ) );
		$cache->register();
		$cache->mark( 'product', 42 );
		$cache->flush();

		$this->assertFileDoesNotExist( $dir . '/called' );
		$this->assertArrayHasKey( Page_Cache::EVENT, WP_Stub::$scheduled );

		array_map( 'unlink', glob( $dir . '/*' ) ?: array() );
		rmdir( $dir );
	}
}
