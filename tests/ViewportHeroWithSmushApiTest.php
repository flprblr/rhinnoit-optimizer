<?php
/**
 * With Smush's settings API loaded: its own answer wins over the stored row.
 *
 * Its own process: tests/bootstrap.php defines WP_SMUSH_VERSION and loads
 * the Settings stub from ROPT_TESTS_SMUSH=api, and the child inherits the
 * environment set before it is spawned.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Viewport_Hero;

#[CoversClass( Viewport_Hero::class )]
final class ViewportHeroWithSmushApiTest extends TestCase {

	/**
	 * A page that opens with a banner pair.
	 */
	private const PAIR = '<div class="d-md-none"><img src="https://shop.test/phone.webp" srcset="https://shop.test/phone.webp 1080w" sizes="100vw" width="1080" height="1920"></div><div class="d-none d-md-block"><img src="https://shop.test/desk.webp" srcset="https://shop.test/desk.webp 1920w" sizes="100vw" width="1920" height="1080"></div>';

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_SMUSH=api' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_SMUSH' );
	}

	/**
	 * Renders the pair page with a stored row and what Smush's API answers.
	 *
	 * @param array<string, bool> $stored  The wp-smush-settings row.
	 * @param bool                $cdn     What is_cdn_active() answers.
	 * @param bool                $preload What is_lcp_preload_enabled() answers.
	 * @return mixed
	 */
	private function render_with( array $stored, bool $cdn, bool $preload ): mixed {
		$this->assertTrue( class_exists( '\\Smush\\Core\\Settings' ), 'The child has to boot with the API or this case means nothing.' );

		WP_Stub::reset();
		WP_Stub::$options['wp-smush-settings'] = $stored;
		WP_Stub::$page_type                    = 'page';
		WP_Stub::$queried_id                   = 7;
		WP_Stub::$the_id                       = 7;
		WP_Stub::$in_the_loop                  = true;
		$GLOBALS['wp_current_filter']          = array( 'the_content' );
		\Smush\Core\Settings::$cdn             = $cdn;
		\Smush\Core\Settings::$preload         = $preload;

		$service = new Viewport_Hero();
		$service->register();
		$service->remember_page();

		return $service->preload_heroes( self::PAIR );
	}

	/**
	 * A free Smush keeps the CDN setting a Pro licence left, and runs no CDN.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function test_a_stored_cdn_that_smush_no_longer_runs_does_not_stop_it(): void {
		$this->assertStringStartsWith( '<link rel="preload"', (string) $this->render_with( array( 'cdn' => true ), false, false ) );
	}

	#[RunInSeparateProcess]
	public function test_what_smush_runs_makes_it_step_aside(): void {
		$this->assertSame( self::PAIR, $this->render_with( array(), true, false ), 'The CDN runs.' );
		$this->assertSame( self::PAIR, $this->render_with( array(), false, true ), 'Its critical image preload runs.' );
	}
}
