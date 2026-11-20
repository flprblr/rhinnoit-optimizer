<?php
/**
 * Tests for the WooCommerce support gate on a supported shop.
 *
 * The two unsupported cases live in their own files and their own processes:
 * the gate reads a constant, and a constant cannot be changed once the suite
 * has defined it.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Admin\Settings_Page;
use RhinnoIT\Optimizer\Plugin;

#[CoversClass( Plugin::class )]
final class BootTest extends TestCase {

	protected function setUp(): void {
		WP_Stub::reset();
	}

	/**
	 * Boots on a supported shop.
	 *
	 * Plugin::boot() is a singleton, so this is the only case allowed to call it
	 * in the shared process: a second call would silently do nothing, and the
	 * case asserting on it would pass while testing air.
	 *
	 * @return void
	 */
	public function test_a_supported_woocommerce_gets_every_service(): void {
		Plugin::boot();

		// One callback per service, so dropping any registration goes red.
		foreach (
			array(
				'init:load_textdomain',
				'wp_enqueue_scripts:enqueue_frontend',
				'admin_enqueue_scripts:enqueue_admin',
				'admin_init:check_version',
				'admin_menu:register_pages',
				'admin_post_' . Settings_Page::SAVE_ACTION . ':save',
				'plugin_action_links_' . ROPT_BASENAME . ':add_settings_link',
			) as $callback
		) {
			$this->assertContains( $callback, WP_Stub::$callbacks );
		}

		$this->assertNotContains( 'admin_notices', WP_Stub::$hooks, 'Nothing is wrong, so nothing is announced.' );
	}

	public function test_the_unsupported_notice_names_the_minimum_and_escapes_itself(): void {
		$html = $this->render_notice();

		$this->assertStringContainsString( Plugin::MIN_WC_VERSION, $html );
		$this->assertDoesNotMatchRegularExpression( '/<(script|img|iframe)\b/i', $html );
	}

	public function test_the_unsupported_notice_stays_hidden_from_who_cannot_act_on_it(): void {
		WP_Stub::$can = false;

		$this->assertSame( '', $this->render_notice(), 'A customer cannot install WooCommerce.' );
		$this->assertContains( 'activate_plugins', WP_Stub::$caps_checked );
	}

	/**
	 * Captures the notice without booting the plugin.
	 *
	 * Without the constructor on purpose: it registers the services, and the
	 * notice does not read any instance state.
	 *
	 * @return string
	 */
	private function render_notice(): string {
		$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();

		ob_start();
		$plugin->render_unsupported_notice();

		return (string) ob_get_clean();
	}
}
