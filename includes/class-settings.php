<?php
/**
 * Definition and reading of the plugin options.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Single place where the options are declared. The UI lives in Admin\Settings_Page.
 */
final class Settings {

	/**
	 * Prefix of the options in wp_options.
	 */
	const string OPTION_PREFIX = 'ropt_';

	/**
	 * Value of 'requires' on every row of the WooCommerce section.
	 */
	const string REQUIRES_WOOCOMMERCE = 'woocommerce';

	/**
	 * Marks the rows of the Porto section: WooCommerce corrections to Porto's own markup.
	 */
	const string REQUIRES_PORTO = 'porto';

	/**
	 * A switch that needs Kinsta: it acts on Kinsta's cache and nowhere else.
	 */
	const string NEEDS_KINSTA = 'kinsta';

	/**
	 * A switch that needs a page cache the plugin can clear: Kinsta, or cPanel's uapi with ea-nginx.
	 *
	 * The one mark that is not the correction's own stop: Page_Cache clears
	 * wherever uapi runs, as a cage can hide ea-nginx from PHP. See Page_Cache::NGINX.
	 */
	const string NEEDS_HOST_CACHE = 'host_cache';

	/**
	 * A switch that needs a classic theme: block themes are built on what it removes.
	 */
	const string NEEDS_CLASSIC_THEME = 'classic_theme';

	/**
	 * A switch that needs a theme without theme.json: with one, core adds nothing to remove.
	 */
	const string NEEDS_NO_THEME_JSON = 'no_theme_json';

	/**
	 * A switch that needs core's speculative loading, from WordPress 6.8.
	 */
	const string NEEDS_SPECULATION_RULES = 'speculation_rules';

	/**
	 * Keys of the switches removed outright, without the prefix.
	 *
	 * A switch removed with nothing to inherit it goes here, so its row stays
	 * listed for uninstall.php and a form drawn before the update is refused.
	 *
	 * @var array<int, string>
	 */
	const array REMOVED = array();

	/**
	 * Field definition.
	 *
	 * Four sections: the master switch alone in an untitled card, then
	 * WordPress, then WooCommerce - the platform before the addition - then
	 * Porto, for corrections to the theme's own WooCommerce markup. Every row
	 * of the WooCommerce section, its title and sectionend included, carries
	 * 'requires' => self::REQUIRES_WOOCOMMERCE, and every row of the Porto
	 * section 'requires' => self::REQUIRES_PORTO; available_fields() drops
	 * both on a site without a supported WooCommerce, and Porto's on a site
	 * without Porto too.
	 *
	 * An option that can only act with something its section does not promise
	 * - a plugin, the host, the kind of theme, a core version - also carries
	 * 'needs', a list of marks, and is left off the screen where any of them is
	 * missing, so the screen only offers what can apply on the site. The mark
	 * is the condition the feature itself stops at, read from what is
	 * installed, never from another plugin's settings. See conditions().
	 *
	 * The structural rows carry 'is_option' => false, which is the flag
	 * Settings_Page::sanitize_submission() checks. Without it a crafted post
	 * stores the section id in wp_options, where nothing reads it and
	 * uninstall.php never deletes it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			array(
				'id'        => self::OPTION_PREFIX . 'general',
				'type'      => 'title',
				'is_option' => false,
			),
			array(
				'id'      => self::OPTION_PREFIX . 'enabled',
				'type'    => 'checkbox',
				'title'   => __( 'Apply the optimizations', 'rhinnoit-optimizer' ),
				'desc'    => __( 'If you turn it off, everything below stops, without deactivating the plugin.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'        => self::OPTION_PREFIX . 'general',
				'type'      => 'sectionend',
				'is_option' => false,
			),

			array(
				'id'        => self::OPTION_PREFIX . 'wordpress',
				'type'      => 'title',
				'title'     => __( 'WordPress', 'rhinnoit-optimizer' ),
				'is_option' => false,
			),
			array(
				'id'      => self::OPTION_PREFIX . 'srcset_prune',
				'type'    => 'checkbox',
				'group'   => __( 'Images', 'rhinnoit-optimizer' ),
				'title'   => __( 'Drop heavier image copies', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Stops browsers from downloading an image copy that weighs more than a larger one.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'lazy_first_only',
				'type'    => 'checkbox',
				'group'   => __( 'Images', 'rhinnoit-optimizer' ),
				'title'   => __( 'Load images later, except the first', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Makes only the first image load right away, not the first three.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'viewport_hero',
				'type'    => 'checkbox',
				'group'   => __( 'Images', 'rhinnoit-optimizer' ),
				'title'   => __( 'Preload each screen\'s own banner', 'rhinnoit-optimizer' ),
				'desc'    => __( 'If a page starts with separate banners for phones and larger screens, each screen downloads only its own.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'font_inline',
				'type'    => 'checkbox',
				'group'   => __( 'Fonts and CSS', 'rhinnoit-optimizer' ),
				'title'   => __( 'Embed font stylesheets in the page', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Saves a download per small sheet and shows text while the font loads.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'css_versioning',
				'type'    => 'checkbox',
				'group'   => __( 'Fonts and CSS', 'rhinnoit-optimizer' ),
				'title'   => __( 'Avoid outdated styles in browsers', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Browsers download generated styles again when they change, instead of using old copies.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'global_styles',
				'type'    => 'checkbox',
				'group'   => __( 'Block styles', 'rhinnoit-optimizer' ),
				'title'   => __( 'Remove global styles', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Removes some block editor styles from every page, so pages weigh less. Check pages made with blocks.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
				'needs'   => array( self::NEEDS_CLASSIC_THEME ),
			),
			array(
				'id'      => self::OPTION_PREFIX . 'classic_theme_styles',
				'type'    => 'checkbox',
				'group'   => __( 'Block styles', 'rhinnoit-optimizer' ),
				'title'   => __( 'Remove block button styles', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Removes a small stylesheet WordPress adds for buttons made with blocks. Check pages with buttons.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
				'needs'   => array( self::NEEDS_CLASSIC_THEME, self::NEEDS_NO_THEME_JSON ),
			),
			array(
				'id'      => self::OPTION_PREFIX . 'speculative_moderate',
				'type'    => 'checkbox',
				'group'   => __( 'Navigation', 'rhinnoit-optimizer' ),
				'title'   => __( 'Preload pages on hover', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Starts loading a link when the pointer rests on it, so the page often opens faster.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
				'needs'   => array( self::NEEDS_SPECULATION_RULES ),
			),
			array(
				'id'      => self::OPTION_PREFIX . 'head_rsd',
				'type'    => 'checkbox',
				'group'   => __( 'Page head', 'rhinnoit-optimizer' ),
				'title'   => __( 'Remove the RSD link', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Removes from the page code a link that old blogging apps used.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'head_shortlink',
				'type'    => 'checkbox',
				'group'   => __( 'Page head', 'rhinnoit-optimizer' ),
				'title'   => __( 'Remove the shortlink', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Removes a short version of the page\'s address from the page code.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'head_oembed',
				'type'    => 'checkbox',
				'group'   => __( 'Page head', 'rhinnoit-optimizer' ),
				'title'   => __( 'Remove the oEmbed links', 'rhinnoit-optimizer' ),
				'desc'    => __( 'If another WordPress site embeds one of your pages, it shows a plain link, not a card.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'head_generator',
				'type'    => 'checkbox',
				'group'   => __( 'Page head', 'rhinnoit-optimizer' ),
				'title'   => __( 'Remove the version tags', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Removes the tags with your WordPress and WooCommerce versions from the page code and feeds.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'pingbacks',
				'type'    => 'checkbox',
				'group'   => __( 'Page head', 'rhinnoit-optimizer' ),
				'title'   => __( 'Disable pingbacks and trackbacks', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Blocks the automatic notices other blogs send when they link to you, a common spam route.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'      => self::OPTION_PREFIX . 'page_cache_clear',
				'type'    => 'checkbox',
				'group'   => __( 'Page cache', 'rhinnoit-optimizer' ),
				'title'   => __( 'Clear the cache after changes', 'rhinnoit-optimizer' ),
				'desc'    => __( 'When you change content, menus or theme options, or update plugins, visitors soon see it.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
				'needs'   => array( self::NEEDS_HOST_CACHE ),
			),
			array(
				'id'      => self::OPTION_PREFIX . 'same_url_redirect',
				'type'    => 'checkbox',
				'group'   => __( 'Page cache', 'rhinnoit-optimizer' ),
				'title'   => __( 'Avoid redirects to the same page', 'rhinnoit-optimizer' ),
				'desc'    => __( 'Keeps the cache from sending every visitor to an ad\'s link.', 'rhinnoit-optimizer' ),
				'default' => 'yes',
			),
			array(
				'id'        => self::OPTION_PREFIX . 'wordpress',
				'type'      => 'sectionend',
				'is_option' => false,
			),

			array(
				'id'        => self::OPTION_PREFIX . 'woocommerce',
				'type'      => 'title',
				'title'     => __( 'WooCommerce', 'rhinnoit-optimizer' ),
				'is_option' => false,
				'requires'  => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'product_image_eager',
				'type'     => 'checkbox',
				'group'    => __( 'Product page', 'rhinnoit-optimizer' ),
				'title'    => __( 'Load the product image first', 'rhinnoit-optimizer' ),
				'desc'     => __( 'The main image of a product page loads right away and with priority.', 'rhinnoit-optimizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'gallery_strip_lazy',
				'type'     => 'checkbox',
				'group'    => __( 'Product page', 'rhinnoit-optimizer' ),
				'title'    => __( 'Load the gallery thumbnails later', 'rhinnoit-optimizer' ),
				'desc'     => __( 'That way they do not compete with the product\'s main image.', 'rhinnoit-optimizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'loop_first_row',
				'type'     => 'checkbox',
				'group'    => __( 'Shop pages', 'rhinnoit-optimizer' ),
				'title'    => __( 'Prioritize the first product row', 'rhinnoit-optimizer' ),
				'desc'     => __( 'On shop, category and product search pages, the first row\'s images load right away.', 'rhinnoit-optimizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'page_cache_shop',
				'type'     => 'checkbox',
				'group'    => __( 'Page cache', 'rhinnoit-optimizer' ),
				'title'    => __( 'Clear the cache when products change', 'rhinnoit-optimizer' ),
				'desc'     => __( 'Visitors soon see new prices, scheduled sales and products that sell out or come back in stock. Requires "Clear the cache after changes".', 'rhinnoit-optimizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
				'needs'    => array( self::NEEDS_HOST_CACHE ),
			),
			array(
				'id'       => self::OPTION_PREFIX . 'kinsta_stock_status_only',
				'type'     => 'checkbox',
				'group'    => __( 'Page cache', 'rhinnoit-optimizer' ),
				'title'    => __( 'Cut cache clears on stock changes', 'rhinnoit-optimizer' ),
				'desc'     => __( 'Clears the cache only when a product sells out or comes back; unit counts shown may lag. Requires "Clear the cache when products change".', 'rhinnoit-optimizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_WOOCOMMERCE,
				'needs'    => array( self::NEEDS_KINSTA ),
			),
			array(
				'id'        => self::OPTION_PREFIX . 'woocommerce',
				'type'      => 'sectionend',
				'is_option' => false,
				'requires'  => self::REQUIRES_WOOCOMMERCE,
			),

			array(
				'id'        => self::OPTION_PREFIX . 'porto',
				'type'      => 'title',
				'title'     => __( 'Porto', 'rhinnoit-optimizer' ),
				'is_option' => false,
				'requires'  => self::REQUIRES_PORTO,
			),
			array(
				'id'       => self::OPTION_PREFIX . 'cart_fragments',
				'type'     => 'checkbox',
				'group'    => __( 'Cart and checkout', 'rhinnoit-optimizer' ),
				'title'    => __( 'Avoid refreshing an empty mini cart', 'rhinnoit-optimizer' ),
				'desc'     => __( 'Saves the server one request per visit while the cart is empty.', 'rhinnoit-optimizer' ),
				'default'  => 'yes',
				'requires' => self::REQUIRES_PORTO,
				'needs'    => array( self::NEEDS_KINSTA ),
			),
			array(
				'id'        => self::OPTION_PREFIX . 'porto',
				'type'      => 'sectionend',
				'is_option' => false,
				'requires'  => self::REQUIRES_PORTO,
			),
		);
	}

	/**
	 * The fields this site can use: fields() minus what it lacks.
	 *
	 * The form draws and saves this list, so on a site without WooCommerce the
	 * WooCommerce section is neither shown nor writable, and neither is an
	 * option whose 'needs' the site does not meet - both keep whatever value
	 * they had, ready for the day what they need is installed. See
	 * fields_for_marks() for the rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function available_fields(): array {
		return self::fields_for_marks( self::fields(), self::conditions() );
	}

	/**
	 * The fields a site with these marks can use, in order.
	 *
	 * A field goes when its 'requires' or any of its 'needs' names a mark the
	 * site lacks, and a mark nobody declared counts as missing. Then a section
	 * left with no option goes whole, its title and sectionend included: a
	 * card with nothing to switch is only noise on the screen, and the options
	 * it would hold keep their rows anyway.
	 *
	 * Pure, so the cases can hand it fields and marks no plugin ships.
	 *
	 * @param array<int, array<string, mixed>> $fields  Field definitions, in order.
	 * @param array<string, bool>              $present Mark => whether the site has it.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields_for_marks( array $fields, array $present ): array {
		$kept    = array();
		$section = array();
		$options = 0;

		foreach ( $fields as $field ) {
			if ( ! self::has_marks( $field, $present ) ) {
				continue;
			}

			$type = $field['type'] ?? '';

			if ( 'title' === $type ) {
				// A section nobody closed keeps what it holds rather than losing it.
				if ( 0 < $options ) {
					array_push( $kept, ...$section );
				}

				$section = array( $field );
				$options = 0;

				continue;
			}

			if ( array() === $section ) {
				$kept[] = $field;

				continue;
			}

			$section[] = $field;

			if ( false !== ( $field['is_option'] ?? true ) ) {
				++$options;
			}

			if ( 'sectionend' === $type ) {
				if ( 0 < $options ) {
					array_push( $kept, ...$section );
				}

				$section = array();
				$options = 0;
			}
		}

		if ( 0 < $options ) {
			array_push( $kept, ...$section );
		}

		return $kept;
	}

	/**
	 * Whether the site has every mark a field names.
	 *
	 * @param array<string, mixed> $field   One field definition.
	 * @param array<string, bool>  $present Mark => whether the site has it.
	 * @return bool
	 */
	private static function has_marks( array $field, array $present ): bool {
		$marks   = is_array( $field['needs'] ?? null ) ? $field['needs'] : array();
		$marks[] = $field['requires'] ?? '';

		foreach ( $marks as $mark ) {
			if ( ! is_string( $mark ) || ! ( $present[ $mark ] ?? false ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Mark => whether this site has it, for every 'requires' and 'needs' mark.
	 *
	 * Read when the screen is drawn or saved, long after the theme and every
	 * plugin loaded, and from what is installed: a plugin's constant, the host,
	 * the theme's kind, a core function. Each answer is the one the feature
	 * itself stops at, so a field is hidden exactly where it would do nothing.
	 * The empty mark is the one a field without 'requires' carries. Porto's
	 * section holds WooCommerce corrections, so it needs WooCommerce too.
	 *
	 * The template ships no 'needs' mark. A plugin declares its own as NEEDS_*
	 * constants and answers them here, in the same change as the first field
	 * that names one: a mark this list does not answer hides its field on
	 * every site.
	 *
	 * @return array<string, bool>
	 */
	public static function conditions(): array {
		return array(
			''                            => true,
			self::REQUIRES_WOOCOMMERCE    => Plugin::woocommerce_is_supported(),
			self::REQUIRES_PORTO          => Plugin::woocommerce_is_supported() && 'porto' === get_template(),
			self::NEEDS_KINSTA            => Plugin::kinsta_is_active(),
			self::NEEDS_HOST_CACHE        => ( new Page_Cache() )->can_clear(),
			self::NEEDS_CLASSIC_THEME     => ! wp_is_block_theme(),
			self::NEEDS_NO_THEME_JSON     => ! wp_theme_has_theme_json(),
			self::NEEDS_SPECULATION_RULES => function_exists( 'wp_get_speculation_rules_configuration' ),
		);
	}

	/**
	 * Ids of every option the settings form can write.
	 *
	 * Read from the declaration, not from the defaults: a field without a
	 * 'default' is still an option, so deriving the list from defaults() left
	 * one invisible to the uninstall guard.
	 *
	 * @return array<int, string>
	 */
	public static function option_ids(): array {
		$ids = array();

		foreach ( self::fields() as $field ) {
			// The same rows Settings_Page::sanitize_submission() skips.
			if ( ! isset( $field['id'], $field['type'] ) || false === ( $field['is_option'] ?? true ) ) {
				continue;
			}

			$ids[] = (string) $field['id'];
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Ids of the options an older version had and this one does not.
	 *
	 * Every 'inherits' key that is no longer an option of its own, plus the
	 * switches removed outright. Nothing deletes them on the update, so a
	 * rollback still finds the choice made there; uninstall.php lists them, and
	 * a test holds it to that.
	 *
	 * @return array<int, string>
	 */
	public static function retired_option_ids(): array {
		$current = self::option_ids();
		$retired = array_map(
			static fn ( string $key ): string => self::OPTION_PREFIX . $key,
			self::REMOVED
		);

		foreach ( self::fields() as $field ) {
			if ( ! isset( $field['inherits'] ) || ! is_string( $field['inherits'] ) ) {
				continue;
			}

			$id = self::OPTION_PREFIX . $field['inherits'];

			if ( ! in_array( $id, $current, true ) ) {
				$retired[] = $id;
			}
		}

		return array_values( array_unique( $retired ) );
	}

	/**
	 * Default values derived from the field definition.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		// fields() is a literal, so this never changes within a request, and
		// feature() asks for it once per feature on every storefront hit.
		static $defaults = null;

		if ( null !== $defaults ) {
			return $defaults;
		}

		$defaults = array();

		foreach ( self::fields() as $field ) {
			if ( isset( $field['id'], $field['default'] ) ) {
				$defaults[ $field['id'] ] = $field['default'];
			}
		}

		return $defaults;
	}

	/**
	 * Reads one of the plugin options.
	 *
	 * @param string $key           Key without the prefix.
	 * @param mixed  $default_value Value returned when the option does not exist.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default_value = '' ): mixed {
		return get_option( self::OPTION_PREFIX . $key, $default_value );
	}

	/**
	 * Tells whether the plugin should apply its changes.
	 *
	 * A row never saved reads as the master switch's declared default, the
	 * same fallback feature() makes for every other switch: a plugin that
	 * ships it on runs before anyone saves the screen, and one that ships it
	 * off does not. Only the field says which, so this file stays the same in
	 * every plugin of the group.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 'yes' === self::get( 'enabled', self::defaults()[ self::OPTION_PREFIX . 'enabled' ] ?? 'no' );
	}

	/**
	 * Tells whether one feature is switched on.
	 *
	 * Every feature answers no while the master switch is off, so a caller
	 * never has to test both.
	 *
	 * @param string $feature Key without the prefix.
	 * @return bool
	 */
	public static function feature( string $feature ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$missing = self::missing();
		$value   = self::get( $feature, $missing );

		if ( $missing === $value ) {
			$value = self::inherited( $feature );
		}

		if ( $missing === $value ) {
			$defaults = self::defaults();
			$value    = $defaults[ self::OPTION_PREFIX . $feature ] ?? 'no';
		}

		return 'yes' === $value;
	}

	/**
	 * The stored value of the wide switch a split one came from, if any.
	 *
	 * Install hands that value down, but only when it runs: on activation and
	 * on the first admin page after an update. Until then the new row does not
	 * exist, and falling back to the declared default would briefly undo what
	 * the site had chosen, on every storefront page. So the read makes the same
	 * lookup Install makes.
	 *
	 * @param string $feature Key without the prefix.
	 * @return mixed The stored value, or the missing marker.
	 */
	private static function inherited( string $feature ): mixed {
		$old = self::inherits()[ self::OPTION_PREFIX . $feature ] ?? '';

		if ( '' === $old ) {
			return self::missing();
		}

		return get_option( self::OPTION_PREFIX . $old, self::missing() );
	}

	/**
	 * Option id => the key of the wide switch it was split from.
	 *
	 * @return array<string, string>
	 */
	public static function inherits(): array {
		static $inherits = null;

		if ( null !== $inherits ) {
			return $inherits;
		}

		$inherits = array();

		foreach ( self::fields() as $field ) {
			if ( isset( $field['id'], $field['inherits'] ) && is_string( $field['inherits'] ) ) {
				$inherits[ (string) $field['id'] ] = $field['inherits'];
			}
		}

		return $inherits;
	}

	/**
	 * Marker for "no such row", distinct from anything an option can hold.
	 *
	 * @return object
	 */
	private static function missing(): object {
		static $missing = null;

		if ( null === $missing ) {
			$missing = new \stdClass();
		}

		return $missing;
	}
}
