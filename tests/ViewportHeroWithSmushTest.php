<?php
/**
 * With Smush loaded: its CDN and its own preload make the correction step aside.
 *
 * Its own process: tests/bootstrap.php defines WP_SMUSH_VERSION from
 * ROPT_TESTS_SMUSH, and the child inherits the environment set before it is
 * spawned.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Viewport_Hero;

#[CoversClass( Viewport_Hero::class )]
final class ViewportHeroWithSmushTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_SMUSH=1' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_SMUSH' );
	}

	/**
	 * A page that opens with a banner pair.
	 */
	private const PAIR = '<div class="d-md-none"><img src="https://shop.test/phone.webp" srcset="https://shop.test/phone.webp 1080w" sizes="100vw" width="1080" height="1920"></div><div class="d-none d-md-block"><img src="https://shop.test/desk.webp" srcset="https://shop.test/desk.webp 1920w" sizes="100vw" width="1920" height="1080"></div>';

	/**
	 * Renders the pair page with these Smush settings, as WordPress would.
	 *
	 * @param mixed $smush The wp-smush-settings row.
	 * @return mixed The content after the correction.
	 */
	private function render_with( mixed $smush ): mixed {
		$this->assertTrue( defined( 'WP_SMUSH_VERSION' ), 'The child has to boot with Smush or this case means nothing.' );

		WP_Stub::reset();
		WP_Stub::$options['wp-smush-settings'] = $smush;
		WP_Stub::$page_type                    = 'page';
		WP_Stub::$queried_id                   = 7;
		WP_Stub::$the_id                       = 7;
		WP_Stub::$in_the_loop                  = true;
		$GLOBALS['wp_current_filter']          = array( 'the_content' );

		$service = new Viewport_Hero();
		$service->register();
		$service->remember_page();

		return $service->preload_heroes( self::PAIR );
	}

	#[RunInSeparateProcess]
	public function test_smush_local_webp_keeps_the_correction_and_learns_imagesrcset(): void {
		$this->assertStringStartsWith( '<link rel="preload"', (string) $this->render_with( array( 'webp_mod' => true, 'webp_direct_conversion' => true, 'cdn' => false, 'preload_images' => false ) ), 'easyrider\'s setup: Local WebP, no CDN.' );
		$this->assertContains( 'wp_smush_get_image_attribute_names:smush_rewrites_preloads', WP_Stub::$callbacks );
	}

	#[RunInSeparateProcess]
	public function test_smush_cdn_rebuilds_srcset_so_the_correction_steps_aside(): void {
		$this->assertSame( self::PAIR, $this->render_with( array( 'cdn' => true ) ) );
	}

	#[RunInSeparateProcess]
	public function test_smush_lcp_preload_would_print_a_second_one_so_the_correction_steps_aside(): void {
		$this->assertSame( self::PAIR, $this->render_with( array( 'preload_images' => true ) ) );
	}

	#[RunInSeparateProcess]
	public function test_a_broken_smush_row_counts_as_no_cdn(): void {
		$this->assertStringStartsWith( '<link rel="preload"', (string) $this->render_with( 'corrupt' ) );
	}
}
