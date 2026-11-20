<?php
/**
 * WordPress's canonical redirect of a request to itself.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Canonical_Redirect;

/**
 * Covers RhinnoIT\Optimizer\Canonical_Redirect.
 */
#[CoversClass( Canonical_Redirect::class )]
final class CanonicalRedirectTest extends TestCase {

	/**
	 * The shop the redirect was measured on.
	 */
	const string HOME = 'https://cl.hoka.com';

	/**
	 * Starts from a site with the defaults.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * It hooks late, with both arguments core passes, and ships on.
	 *
	 * @return void
	 */
	public function test_it_hooks_redirect_canonical_late(): void {
		( new Canonical_Redirect() )->register();

		$this->assertSame( array( 'redirect_canonical:drop_same_url' ), WP_Stub::$callbacks );
		$this->assertSame( 99, WP_Stub::$priorities['redirect_canonical:drop_same_url'] );
		$this->assertSame( array( array( 'redirect_canonical', Canonical_Redirect::class, 'drop_same_url', 2 ) ), WP_Stub::$registered );
	}

	/**
	 * Its switch, and the master switch, turn it off.
	 *
	 * @return void
	 */
	public function test_the_switches_turn_it_off(): void {
		WP_Stub::$options['ropt_same_url_redirect'] = 'no';
		( new Canonical_Redirect() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks );

		WP_Stub::reset();
		WP_Stub::$options['ropt_enabled'] = 'no';
		( new Canonical_Redirect() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks );
	}

	/**
	 * Requests WordPress redirected to themselves on the home page.
	 *
	 * The first pair is what hoka's cache served for its home page on
	 * 2026-10-05: the Location header of the 301 is the value the filter
	 * returned, and the request is the ad link with the "=" it dropped. The
	 * rest were measured on hoka the same day with the cache bypassed, each
	 * target the Location WordPress sent. The request is written as core
	 * hands it to the filter, %XX octets in lower case.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function same_request(): array {
		$home = self::HOME;

		return array(
			'the ad link served as the home page' => array(
				"$home/?utm_source=google&utm_medium=cpc&utm_campaign=20485384492&utm_content=153048865575&utm_term=hoka&utm_matchtype=b&utm_device=m&utm_adposition&gad_source=1&gad_campaignid=20485384492&gbraid=0AAAAApywI5bmfvFDqJrBN48XFGUeKXI67",
				"$home/?utm_source=google&utm_medium=cpc&utm_campaign=20485384492&utm_content=153048865575&utm_term=hoka&utm_matchtype=b&utm_device=m&utm_adposition=&gad_source=1&gad_campaignid=20485384492&gbraid=0AAAAApywI5bmfvFDqJrBN48XFGUeKXI67",
			),
			'one empty parameter'                 => array( "$home/?ropttest", "$home/?ropttest=" ),
			'empty parameters among others'       => array( "$home/?utm_content&utm_term&utm_source=ropttest", "$home/?utm_content=&utm_term=&utm_source=ropttest" ),
			'a space sent as +'                   => array( "$home/?utm_term=hoka%20ropttest", "$home/?utm_term=hoka+ropttest" ),
			'octets in lower case'                => array( "$home/?q=%C3%B1&ropttest=1", "$home/?q=%c3%b1&ropttest=1" ),
			'page 2 of a category'                => array( "$home/c/zapatillas-mujer/page/2/?ropttest", "$home/c/zapatillas-mujer/page/2/?ropttest=" ),
			'page 2 of a landing'                 => array( "$home/e/cyber/page/2/?utm_content&utm_source=ropttest", "$home/e/cyber/page/2/?utm_content=&utm_source=ropttest" ),
			'an accented path, octets lowered'    => array( "$home/c/ni%C3%B1os/page/2/?ropttest", "$home/c/ni%c3%b1os/page/2/?ropttest=" ),
			'the same parameters in another order' => array( "$home/?b=2&a=1", "$home/?a=1&b=2" ),
			'the host in capitals'                => array( "$home/?a", 'HTTPS://CL.HOKA.COM/?a=' ),
		);
	}

	/**
	 * A redirect to the request itself is dropped, and the page served.
	 *
	 * @param string $target    Where WordPress would redirect.
	 * @param string $requested The request.
	 * @return void
	 */
	#[DataProvider( 'same_request' )]
	public function test_a_redirect_to_the_request_itself_is_dropped( string $target, string $requested ): void {
		$this->assertFalse( ( new Canonical_Redirect() )->drop_same_url( $target, $requested ) );
	}

	/**
	 * Canonical redirects that change the address.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function another_address(): array {
		$home = self::HOME;

		return array(
			'page_id to the front page'     => array( "$home/", "$home/?page_id=2231" ),
			'page_id dropped, utm kept'     => array( "$home/?utm_source=x", "$home/?page_id=2231&utm_source=x" ),
			'a missing trailing slash'      => array( "$home/c/zapatillas-mujer/", "$home/c/zapatillas-mujer" ),
			'http to https'                 => array( "$home/?a", 'http://cl.hoka.com/?a=' ),
			'another host'                  => array( "$home/?a", 'https://www.cl.hoka.com/?a=' ),
			'another port'                  => array( "$home/?a", 'https://cl.hoka.com:8443/?a=' ),
			'?p= to a permalink'            => array( "$home/p/hoka-clifton-10/", "$home/?p=123" ),
			'paged to its pretty form'      => array( "$home/page/2/", "$home/?paged=2" ),
			'a path in other capitals'      => array( "$home/p/x/", "$home/P/X/" ),
			'an encoded slash'              => array( "$home/c/a/b/page/2/?x", "$home/c/a%2fb/page/2/?x=" ),
			'another value'                 => array( "$home/?a=2", "$home/?a=1" ),
			'a value only loosely equal'    => array( "$home/?x=1e1", "$home/?x=10" ),
			'a parameter dropped'           => array( "$home/", "$home/?a=" ),
			'a target that does not parse'  => array( 'http:///?a', "$home/?a=" ),
			'a request that does not parse' => array( "$home/?a", 'http:///?a=' ),
		);
	}

	/**
	 * Every redirect that changes the address is left as it was.
	 *
	 * @param string $target    Where WordPress would redirect.
	 * @param string $requested The request.
	 * @return void
	 */
	#[DataProvider( 'another_address' )]
	public function test_a_redirect_to_another_address_stays( string $target, string $requested ): void {
		$this->assertSame( $target, ( new Canonical_Redirect() )->drop_same_url( $target, $requested ) );
	}

	/**
	 * Whatever another filter left in place passes through untouched.
	 *
	 * @return void
	 */
	public function test_values_that_are_not_urls_pass_through(): void {
		$service = new Canonical_Redirect();

		$this->assertFalse( $service->drop_same_url( false, self::HOME . '/' ), 'No redirect stays no redirect.' );
		$this->assertSame( '', $service->drop_same_url( '', self::HOME . '/' ) );
		$this->assertNull( $service->drop_same_url( null, null ) );
		$this->assertSame( array( 'x' ), $service->drop_same_url( array( 'x' ), self::HOME . '/' ) );
		$this->assertSame( self::HOME . '/?a', $service->drop_same_url( self::HOME . '/?a', null ) );
		$this->assertSame( self::HOME . '/?a', $service->drop_same_url( self::HOME . '/?a' ), 'A plugin applying the filter with one argument gets no fatal.' );
	}
}
