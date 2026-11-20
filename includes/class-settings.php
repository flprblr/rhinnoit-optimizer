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
	 * Three sections: the master switch alone in an untitled card, then
	 * WordPress, then WooCommerce - the platform before the addition. Every row
	 * of the WooCommerce section, its title and sectionend included, carries
	 * 'requires' => self::REQUIRES_WOOCOMMERCE, and available_fields() drops
	 * them on a site without a supported WooCommerce.
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
				'title'   => __( 'Enable', 'rhinnoit-optimizer' ),
				'desc'    => __( 'If you turn it off, everything below stops, without deactivating the plugin.', 'rhinnoit-optimizer' ),
				'default' => 'no',
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
				'id'      => self::OPTION_PREFIX . 'scope_wordpress',
				'type'    => 'multicheck',
				'title'   => __( 'Load the script on', 'rhinnoit-optimizer' ),
				'desc'    => __( 'The pages WordPress itself serves. While both lists are empty the script loads nowhere. Every page of the site wins over everything else, and covers what neither list names: archives, search, custom post types.', 'rhinnoit-optimizer' ),
				'options' => self::wordpress_scope_choices(),
				'default' => array(),
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
				'id'       => self::OPTION_PREFIX . 'scope_woocommerce',
				'type'     => 'multicheck',
				'title'    => __( 'Load the script on', 'rhinnoit-optimizer' ),
				'desc'     => __( 'The screens WooCommerce owns.', 'rhinnoit-optimizer' ),
				'options'  => self::woocommerce_scope_choices(),
				'default'  => array(),
				'requires' => self::REQUIRES_WOOCOMMERCE,
			),
			array(
				'id'        => self::OPTION_PREFIX . 'woocommerce',
				'type'      => 'sectionend',
				'is_option' => false,
				'requires'  => self::REQUIRES_WOOCOMMERCE,
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
	 * The empty mark is the one a field without 'requires' carries.
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
			''                         => true,
			self::REQUIRES_WOOCOMMERCE => Plugin::woocommerce_is_supported(),
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
	 * The WooCommerce contexts, as id => label.
	 *
	 * The ids are the contract: Assets::scope_matches() maps each one to a
	 * conditional tag, and anything not in this list is discarded when the
	 * option is read.
	 *
	 * @return array<string, string>
	 */
	public static function woocommerce_scope_choices(): array {
		return array(
			'shop'     => __( 'Shop and category pages', 'rhinnoit-optimizer' ),
			'product'  => __( 'Single product', 'rhinnoit-optimizer' ),
			'cart'     => __( 'Cart', 'rhinnoit-optimizer' ),
			'checkout' => __( 'Checkout', 'rhinnoit-optimizer' ),
			'account'  => __( 'My account', 'rhinnoit-optimizer' ),
		);
	}

	/**
	 * The WordPress contexts, as id => label.
	 *
	 * "Pages, WooCommerce ones aside" is worded that way because cart, checkout,
	 * my account and often the shop are WordPress pages too: without the
	 * exclusions in Assets::is_plain_page() that choice would load the bundle on
	 * screens the other list did not ask for.
	 *
	 * @return array<string, string>
	 */
	public static function wordpress_scope_choices(): array {
		return array(
			'posts'      => __( 'Blog posts', 'rhinnoit-optimizer' ),
			'pages'      => __( 'Pages, WooCommerce ones aside', 'rhinnoit-optimizer' ),
			'everywhere' => __( 'Every page of the site', 'rhinnoit-optimizer' ),
		);
	}

	/**
	 * Every context the shop manager picked, from both lists.
	 *
	 * The WooCommerce list is read only where its section is on the screen: on
	 * a site without a supported WooCommerce it is the WooCommerce half, its
	 * stored value is kept for the day WooCommerce comes back, and the
	 * conditional tags its ids map to may not exist at all.
	 *
	 * @return array<int, string>
	 */
	public static function frontend_contexts(): array {
		$woocommerce = Plugin::woocommerce_is_supported()
			? self::chosen( 'scope_woocommerce', self::woocommerce_scope_choices() )
			: array();

		return array_values(
			array_unique(
				array_merge(
					$woocommerce,
					self::chosen( 'scope_wordpress', self::wordpress_scope_choices() )
				)
			)
		);
	}

	/**
	 * One stored list, filtered to the ids its own field can produce.
	 *
	 * This runs on every storefront request, so it takes whatever the row holds.
	 * A value that is not an array - a corrupt row, another plugin writing the
	 * option - cannot be filtered at all, since array_filter() on a string is a
	 * TypeError on every page of the shop, so it falls back to whatever the
	 * field declares. In this template that is nothing, which is also what
	 * Settings_Page::sanitize_submission() stores when every box is unchecked:
	 * both mean the script loads nowhere. A plugin that declares a real default
	 * gets that default back instead.
	 *
	 * Each list is checked against its own choices, not against both, so an id
	 * that field could never have offered does not survive a crafted post.
	 *
	 * @param string                $key     Option key without the prefix.
	 * @param array<string, string> $choices The field's own choices.
	 * @return array<int, string>
	 */
	private static function chosen( string $key, array $choices ): array {
		$stored = self::get( $key, null );

		if ( ! is_array( $stored ) ) {
			$fallback = self::defaults()[ self::OPTION_PREFIX . $key ] ?? array();

			return is_array( $fallback ) ? array_values( array_filter( $fallback, 'is_string' ) ) : array();
		}

		// is_string() before comparing: an element can be anything, and casting
		// an array to string is a warning on a storefront page.
		return array_values( array_intersect( array_filter( $stored, 'is_string' ), array_keys( $choices ) ) );
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
