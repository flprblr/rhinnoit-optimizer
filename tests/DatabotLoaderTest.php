<?php
/**
 * Inserting the Databot chat once the page has loaded.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Databot_Loader;

/**
 * Covers RhinnoIT\Optimizer\Databot_Loader.
 */
#[CoversClass( Databot_Loader::class )]
final class DatabotLoaderTest extends TestCase {

	/**
	 * The Databot plugin active and configured, as on the shop.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		WP_Stub::reset();
		WP_Stub::$present_actions                = array( 'wp_footer:databot_window' );
		WP_Stub::$options['databot_option_name'] = array(
			'id_del_bot_0'      => '3855',
			'api_key_del_bot_1' => 'RONXXLHNOSCRXFSJ',
		);
	}

	/**
	 * Prints what the loader prints in the footer.
	 *
	 * @return string
	 */
	private function footer(): string {
		ob_start();
		( new Databot_Loader() )->print_chat();

		return (string) ob_get_clean();
	}

	/**
	 * The plugin's own output is removed and the same chat is inserted once the page has loaded.
	 *
	 * Its deferred script held DOMContentLoaded and its two Heroku iframes the
	 * load event, which Porto's product carousels wait for: 1.74 s to load on
	 * desktop with the chat, 0.8 s with it inserted after load.
	 *
	 * @return void
	 */
	public function test_the_chat_is_inserted_after_the_page_has_loaded(): void {
		$html = $this->footer();

		$this->assertStringStartsWith( '<script id="ropt-databot-loader">(function(d,w){var c={', $html );
		$this->assertStringContainsString( '"src":"https:\\/\\/databot-api.herokuapp.com\\/dist_files\\/databot.js"', $html, 'The plugin\'s exact src: the chat derives its server from it.' );
		$this->assertStringContainsString( '"id":"3855","bot":"RONXXLHNOSCRXFSJ"', $html );
		$this->assertStringContainsString( 's.id=c.id;s.setAttribute("bot",c.bot)', $html, 'The attributes the chat reads from its own tag.' );
		$this->assertStringContainsString( '"complete"===d.readyState?a():w.addEventListener("load",a)', $html, 'After load, even when printed late.' );
		$this->assertStringContainsString( 'requestIdleCallback(g,{timeout:2e3})', $html );
		$this->assertStringNotContainsString( 'databot.js\' id=', $html, 'No tag of its own: that one held the page.' );
		$this->assertContains( 'wp_footer:databot_window', WP_Stub::$removed, 'The plugin must not print its own tags as well.' );
		$this->assertSame( 10, WP_Stub::$removed_priorities['wp_footer:databot_window'] );
	}

	/**
	 * The settings travel as JSON, so no value can close the script or break out of a string.
	 *
	 * @return void
	 */
	public function test_the_settings_cannot_break_the_script(): void {
		WP_Stub::$options['databot_option_name'] = array(
			'id_del_bot_0'      => "38'55\"",
			'api_key_del_bot_1' => '</script><script>alert(1)</script>',
		);

		$html = $this->footer();

		$this->assertSame( 1, substr_count( $html, '</script>' ), 'Only the loader\'s own closing tag.' );
		$this->assertStringContainsString( '"id":"38\'55\\""', $html );
	}

	/**
	 * If the settings cannot be encoded, the plugin's tags are printed instead, never a broken script.
	 *
	 * @return void
	 */
	public function test_unencodable_settings_fall_back_to_the_plugins_tags(): void {
		WP_Stub::$json_fails = true;

		$this->assertSame(
			"<script defer type='text/javascript' src='https://databot-api.herokuapp.com/dist_files/databot.js' id='3855' bot='RONXXLHNOSCRXFSJ'></script>\n"
			. "<link rel='stylesheet' href='https://databot-api.herokuapp.com/dist_files/databot.css' media='print' onload=\"this.media='all'\">\n"
			. "<noscript><link rel='stylesheet' href='https://databot-api.herokuapp.com/dist_files/databot.css'></noscript>\n",
			$this->footer()
		);
	}

	/**
	 * In the fallback the attributes are escaped.
	 *
	 * @return void
	 */
	public function test_the_fallback_keeps_the_plugins_src_and_attributes_escaped(): void {
		WP_Stub::$json_fails                     = true;
		WP_Stub::$options['databot_option_name'] = array(
			'id_del_bot_0'      => "38'55",
			'api_key_del_bot_1' => 'a"b<c>',
		);

		$html = $this->footer();

		$this->assertStringContainsString( "src='https://databot-api.herokuapp.com/dist_files/databot.js'", $html );
		$this->assertStringContainsString( "id='38&#039;55'", $html );
		$this->assertStringContainsString( "bot='a&quot;b&lt;c&gt;'", $html );
	}

	/**
	 * Without the plugin, or before it is configured, nothing is printed.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_without_a_configured_plugin(): void {
		WP_Stub::$present_actions = array();
		$this->assertSame( '', $this->footer(), 'Not active.' );
		$this->assertSame( array(), WP_Stub::$removed );

		WP_Stub::$present_actions = array( 'wp_footer:databot_window' );

		foreach ( array( false, 'corrupt', array(), array( 'id_del_bot_0' => '3855' ), array( 'id_del_bot_0' => '', 'api_key_del_bot_1' => 'x' ), array( 'id_del_bot_0' => array( 'x' ), 'api_key_del_bot_1' => 'x' ) ) as $options ) {
			WP_Stub::$options['databot_option_name'] = $options;
			$this->assertSame( '', $this->footer(), 'The plugin prints nothing until both are set.' );
		}
	}

	/**
	 * Hooked ahead of the plugin, unless the master switch or the filter says no.
	 *
	 * @return void
	 */
	public function test_it_is_hooked_ahead_of_the_plugin_unless_turned_off(): void {
		( new Databot_Loader() )->register();
		$this->assertSame( 9, WP_Stub::$priorities['wp_footer:print_chat'] ?? null, 'One step ahead of the plugin\'s callback at 10.' );

		WP_Stub::reset();
		WP_Stub::$options['ropt_enabled'] = 'no';
		( new Databot_Loader() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks, 'The master switch stops it.' );

		WP_Stub::reset();
		WP_Stub::$filters['ropt_databot_async'] = false;
		( new Databot_Loader() )->register();
		$this->assertSame( array(), WP_Stub::$callbacks, 'The filter stops it.' );
	}
}
