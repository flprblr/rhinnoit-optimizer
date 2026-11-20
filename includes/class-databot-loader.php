<?php
/**
 * Loads the Databot chat without holding up the page.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the Databot plugin's chat so its stylesheet never blocks the paint.
 *
 * The Databot plugin (databot_window() on wp_footer) prints its script with
 * `defer`, which is fine, and its stylesheet as a plain `<link>` served from
 * Heroku in 0.6 to 0.9 seconds. When the parser reaches that link before the
 * first paint, the paint waits for it: measured on a shop, 3 to 4 of every 10
 * mobile runs fell from the low 80s to 51-58, and all six pages gained 3 to 15
 * points once the chat was switched off.
 *
 * This takes the plugin's place on wp_footer. The chat itself still held the
 * page: its script, deferred, ran before DOMContentLoaded, and the two iframes
 * it builds from Heroku kept the browser's load event - and with it the
 * progress bar and Porto's product carousels, which start on load - waiting.
 * Measured on a shop's home: load at 1.74 s on desktop and 2.05 s on phones
 * with the chat, 0.8 s once it is inserted after load. So the same script and
 * stylesheet are inserted by a few bytes of inline script once the page has
 * loaded and the browser is idle (two seconds at most). The script keeps the
 * plugin's exact src, id and bot attributes: the chat finds itself through
 * document.currentScript, which a script inserted this way also sets, and
 * derives its server from that src. The chat bubble appears about half a
 * second later than before. Where the plugin is not active, or not
 * configured, nothing is printed; if the settings cannot be encoded, the
 * plugin's tags are printed instead, with the stylesheet applied on load.
 *
 * It has no switch on the settings screen, by the owner's decision: only the
 * master switch and the ropt_databot_async filter turn it off.
 */
final class Databot_Loader {

	/**
	 * The chat's script, as the Databot plugin prints it.
	 *
	 * @var string
	 */
	const string SCRIPT = 'https://databot-api.herokuapp.com/dist_files/databot.js';

	/**
	 * The chat's stylesheet, as the Databot plugin prints it.
	 *
	 * @var string
	 */
	const string STYLESHEET = 'https://databot-api.herokuapp.com/dist_files/databot.css';

	/**
	 * Inserts the chat's stylesheet and script once the page has loaded and the browser is idle.
	 *
	 * The %s is the JSON of src, id, bot and css.
	 *
	 * @var string
	 */
	const string AFTER_LOAD = '(function(d,w){var c=%s,n=0;function g(){if(n)return;n=1;'
		. 'var l=d.createElement("link");l.rel="stylesheet";l.href=c.css;d.head.appendChild(l);'
		. 'var s=d.createElement("script");s.type="text/javascript";s.src=c.src;s.id=c.id;s.setAttribute("bot",c.bot);d.body.appendChild(s)}'
		. 'function a(){w.requestIdleCallback?w.requestIdleCallback(g,{timeout:2e3}):setTimeout(g,1)}'
		. '"complete"===d.readyState?a():w.addEventListener("load",a)})(document,window);';

	/**
	 * Hooks the replacement, one step ahead of the plugin's own callback at 10.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}

		/**
		 * Whether to print the Databot chat with a stylesheet that does not block the page.
		 *
		 * @since 1.0.0
		 * @param bool $async Whether to take over the Databot plugin's output.
		 */
		if ( ! apply_filters( 'ropt_databot_async', true ) ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'print_chat' ), 9 );
	}

	/**
	 * Replaces the Databot plugin's footer output with the non-blocking one.
	 *
	 * @return void
	 */
	public function print_chat(): void {
		$priority = has_action( 'wp_footer', 'databot_window' );

		if ( false === $priority ) {
			return;
		}

		remove_action( 'wp_footer', 'databot_window', (int) $priority );

		$options = get_option( 'databot_option_name' );
		$id      = is_array( $options ) && is_string( $options['id_del_bot_0'] ?? null ) ? $options['id_del_bot_0'] : '';
		$bot     = is_array( $options ) && is_string( $options['api_key_del_bot_1'] ?? null ) ? $options['api_key_del_bot_1'] : '';

		// The plugin prints nothing until both are set.
		if ( '' === $id || '' === $bot ) {
			return;
		}

		$chat = wp_json_encode(
			array(
				'src' => self::SCRIPT,
				'id'  => $id,
				'bot' => $bot,
				'css' => self::STYLESHEET,
			)
		);

		if ( is_string( $chat ) ) {
			wp_print_inline_script_tag( sprintf( self::AFTER_LOAD, $chat ), array( 'id' => 'ropt-databot-loader' ) );

			return;
		}

		// phpcs:disable WordPress.WP.EnqueuedResources -- The chat reads its own script tag's attributes; it must be printed as the Databot plugin prints it.
		printf(
			"<script defer type='text/javascript' src='%s' id='%s' bot='%s'></script>\n<link rel='stylesheet' href='%s' media='print' onload=\"this.media='all'\">\n<noscript><link rel='stylesheet' href='%s'></noscript>\n",
			esc_url( self::SCRIPT ),
			esc_attr( $id ),
			esc_attr( $bot ),
			esc_url( self::STYLESHEET ),
			esc_url( self::STYLESHEET )
		);
		// phpcs:enable WordPress.WP.EnqueuedResources
	}
}
