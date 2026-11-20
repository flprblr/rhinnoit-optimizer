<?php
/**
 * The settings form, which is the whole plugin page.
 *
 * @package RhinnoIT\Optimizer
 */

namespace RhinnoIT\Optimizer\Admin;

use RhinnoIT\Optimizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Prints and saves the settings form.
 *
 * The form draws its own fields: WC_Admin_Settings is the right tool on a shop
 * and does not exist anywhere else, and the plugin runs on plain WordPress too.
 * It knows four types - title, sectionend, checkbox and multicheck - and
 * adding one means adding it to render_field() and to sanitize_submission()
 * together; a type one knows and the other does not is either unsaveable or
 * unsafe.
 *
 * The layout is the card-and-row pattern of @wordpress/components - the one
 * Site Health and the newer WooCommerce screens use - drawn in PHP and styled
 * by src/admin.scss, so it looks current without a React app. A section is a
 * card, a field is a row with its title and one line of description on the
 * left and its control on the right. A checkbox is drawn as an on/off switch:
 * still a real checkbox, so the form posts exactly what it did, with
 * role="switch" for screen readers. A multicheck is a group of those, one per
 * choice, posted as one list. Without the stylesheet everything degrades to
 * plain labels and checkboxes that work the same.
 */
final class Settings_Page {

	/**
	 * Action on admin-post.php that receives the form.
	 */
	const string SAVE_ACTION = 'ropt_save_settings';

	/**
	 * Name of the hidden list of fields the form drew.
	 */
	const string DRAWN_FIELD = 'ropt_drawn';

	/**
	 * Group hook fired after every save of this screen.
	 *
	 * No plugin prefix, like the menu constants: every plugin of the group
	 * fires this same hook, so whichever plugin clears the page cache hears a
	 * save in any of them. With no listener it does nothing. Must hold the same
	 * value in every plugin of the group.
	 */
	const string SAVED_HOOK = 'rhinnoit_settings_saved';

	/**
	 * Group label printed last in the current card.
	 *
	 * @var string
	 */
	private string $group = '';

	/**
	 * Hooks the save handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
	}

	/**
	 * Prints the form.
	 *
	 * @return void
	 */
	public function render(): void {
		$this->render_saved_notice();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ropt-settings">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>"/>
			<?php
			wp_nonce_field( self::SAVE_ACTION );

			foreach ( Settings::available_fields() as $field ) {
				$this->render_field( $field );
			}

			// Without the empty name the button is called "submit", which
			// shadows form.submit() for any script touching this form.
			submit_button( '', 'primary', '' );
			?>
		</form>
		<?php
	}

	/**
	 * Prints one row of the field list.
	 *
	 * A title opens a card and its matching sectionend closes it; a field
	 * whose group differs from the one before it gets the group label first.
	 * Every value printed here is escaped: titles and descriptions come from
	 * the plugin, but the current value of an option comes from the database,
	 * where anything can have been written.
	 *
	 * @param array<string, mixed> $field One field definition.
	 * @return void
	 */
	private function render_field( array $field ): void {
		$type  = is_string( $field['type'] ?? null ) ? $field['type'] : '';
		$id    = is_string( $field['id'] ?? null ) ? $field['id'] : '';
		$title = is_string( $field['title'] ?? null ) ? $field['title'] : '';
		$desc  = is_string( $field['desc'] ?? null ) ? $field['desc'] : '';
		$group = is_string( $field['group'] ?? null ) ? $field['group'] : '';

		if ( 'title' === $type ) {
			$this->group = '';

			echo '<section class="ropt-card">';

			// An untitled card is the master switch, above both platforms.
			if ( '' !== $title ) {
				printf( '<h2 class="ropt-card__title">%s</h2>', esc_html( $title ) );
			}

			if ( '' !== $desc ) {
				printf( '<p class="ropt-card__desc">%s</p>', esc_html( $desc ) );
			}

			return;
		}

		if ( 'sectionend' === $type ) {
			echo '</section>';

			return;
		}

		if ( '' === $id ) {
			return;
		}

		if ( 'multicheck' === $type ) {
			$this->render_multicheck( $field, $id, $title, $desc );

			return;
		}

		if ( 'checkbox' !== $type ) {
			return;
		}

		if ( '' !== $group && $group !== $this->group ) {
			printf( '<h3 class="ropt-card__group">%s</h3>', esc_html( $group ) );
		}

		$this->group = $group;

		$default = $field['default'] ?? '';
		$value   = get_option( $id, $default );

		printf(
			'<div class="ropt-row"><div class="ropt-row__text"><label class="ropt-row__label" for="%1$s">%2$s</label><p class="ropt-row__desc" id="%1$s-desc">%3$s</p></div><div class="ropt-row__control">',
			esc_attr( $id ),
			esc_html( $title ),
			esc_html( $desc )
		);

		printf(
			'<input type="checkbox" role="switch" class="ropt-switch" id="%1$s" name="%1$s" value="yes" aria-describedby="%1$s-desc"%2$s/>',
			esc_attr( $id ),
			'yes' === $value ? ' checked="checked"' : ''
		);

		printf(
			'<input type="hidden" name="%1$s[]" value="%2$s"/></div></div>',
			esc_attr( self::DRAWN_FIELD ),
			esc_attr( $id )
		);
	}

	/**
	 * Prints a multicheck: a labelled group of switches, one per choice.
	 *
	 * The field title is the group's label and the choices are its rows, all
	 * posted under one name as a list. What is printed of the stored value is
	 * only which choices it holds, compared as strings, so markup written into
	 * the row never reaches the page.
	 *
	 * @param array<string, mixed> $field One field definition.
	 * @param string               $id    Option id.
	 * @param string               $title Field title.
	 * @param string               $desc  Field description.
	 * @return void
	 */
	private function render_multicheck( array $field, string $id, string $title, string $desc ): void {
		$this->group = '';

		$choices = is_array( $field['options'] ?? null ) ? $field['options'] : array();
		$stored  = get_option( $id, $field['default'] ?? array() );
		$stored  = is_array( $stored ) ? array_filter( $stored, 'is_string' ) : array();

		printf(
			'<div class="ropt-group" role="group" aria-labelledby="%1$s-label"%2$s><h3 class="ropt-card__group" id="%1$s-label">%3$s</h3>',
			esc_attr( $id ),
			'' !== $desc ? sprintf( ' aria-describedby="%s-desc"', esc_attr( $id ) ) : '',
			esc_html( $title )
		);

		if ( '' !== $desc ) {
			printf( '<p class="ropt-group__desc" id="%1$s-desc">%2$s</p>', esc_attr( $id ), esc_html( $desc ) );
		}

		foreach ( $choices as $choice => $label ) {
			$choice = (string) $choice;

			printf(
				'<div class="ropt-row"><div class="ropt-row__text"><label class="ropt-row__label" for="%1$s-%2$s">%3$s</label></div><div class="ropt-row__control"><input type="checkbox" role="switch" class="ropt-switch" id="%1$s-%2$s" name="%1$s[]" value="%2$s"%4$s/></div></div>',
				esc_attr( $id ),
				esc_attr( $choice ),
				esc_html( is_string( $label ) ? $label : $choice ),
				in_array( $choice, $stored, true ) ? ' checked="checked"' : ''
			);
		}

		printf(
			'<input type="hidden" name="%1$s[]" value="%2$s"/></div>',
			esc_attr( self::DRAWN_FIELD ),
			esc_attr( $id )
		);
	}

	/**
	 * Validates and saves the form.
	 *
	 * @return void
	 */
	public function save(): void {
		if ( ! current_user_can( Menu::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'rhinnoit-optimizer' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		/*
		 * Unslashed as a whole and sanitized field by field below: the only
		 * keys ever read are the ids the field list declares, each through the
		 * sanitizer of its own type, so nothing else in $_POST is looked at.
		 */
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per declared field in sanitize_submission().
		$posted = wp_unslash( $_POST );

		$posted = is_array( $posted ) ? $posted : array();

		if ( self::drawn_by_an_older_version( $posted ) ) {
			wp_safe_redirect( add_query_arg( 'outdated', '1', Menu::url() ) );
			exit;
		}

		$fields = self::fields_to_save( Settings::available_fields(), $posted );

		foreach ( self::sanitize_submission( $fields, $posted ) as $option => $value ) {
			update_option( $option, $value );
		}

		/**
		 * Fires after the settings screen is saved, whether or not anything
		 * changed. A switch can change what every page prints, so the plugin
		 * of the group that clears the page cache clears on it.
		 *
		 * @since 1.0.0
		 */
		do_action( self::SAVED_HOOK ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- A group hook, unprefixed on purpose: see SAVED_HOOK.

		wp_safe_redirect( add_query_arg( 'updated', '1', Menu::url() ) );
		exit;
	}

	/**
	 * Whether the form was drawn by a version whose switches were different.
	 *
	 * A settings tab left open across an update posts the old field list. If a
	 * switch it drew no longer exists, saving the rest and saying "Settings
	 * saved" would quietly drop the one change the admin came to make. A form
	 * that names a retired switch is refused whole, and the screen asks for a
	 * reload.
	 *
	 * So is a form that names nothing. Every form this version draws lists the
	 * master switch at least, so an empty or missing list comes from a version
	 * that drew no list at all - WC_Admin_Settings drew the fields before - or
	 * from a hand-built post. fields_to_save() would save none of it, and the
	 * redirect would still say "Settings saved".
	 *
	 * @param array<mixed> $posted The unslashed submission.
	 * @return bool
	 */
	public static function drawn_by_an_older_version( array $posted ): bool {
		$drawn = $posted[ self::DRAWN_FIELD ] ?? null;
		$drawn = is_array( $drawn ) ? array_filter( $drawn, 'is_string' ) : array();

		if ( array() === $drawn ) {
			return true;
		}

		return array() !== array_intersect( $drawn, Settings::retired_option_ids() );
	}

	/**
	 * The fields of this site that were actually on the submitted form.
	 *
	 * A checkbox that is absent from a submission was unchecked - but only if
	 * it was on the screen to begin with. The field list is rebuilt when the
	 * form is saved, not when it was drawn, so a section that appeared in
	 * between (WooCommerce activated, or updated past the minimum, in another
	 * tab) would have every one of its boxes stored as unchecked. The form says
	 * which fields it drew, and only those are saved.
	 *
	 * The marker cannot widen anything: it is intersected with the fields this
	 * site can use, so a crafted post naming another id still gets nowhere.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields this site can use.
	 * @param array<mixed>                     $posted The unslashed submission.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields_to_save( array $fields, array $posted ): array {
		$drawn = $posted[ self::DRAWN_FIELD ] ?? array();

		if ( ! is_array( $drawn ) ) {
			return array();
		}

		$drawn = array_filter( $drawn, 'is_string' );

		return array_values(
			array_filter(
				$fields,
				static function ( array $field ) use ( $drawn ): bool {
					return in_array( $field['id'] ?? null, $drawn, true );
				}
			)
		);
	}

	/**
	 * Turns a submitted form into the values to store.
	 *
	 * Driven by the field list, never by the submission: a key nobody declared
	 * is never read, so a crafted post cannot create an option. Structural rows
	 * are skipped by the same 'is_option' flag the field list sets. A checkbox
	 * that is absent was unchecked, because browsers send nothing for an
	 * unchecked box; a multicheck with every box unchecked is likewise absent,
	 * and stores the empty list.
	 *
	 * @param array<int, array<string, mixed>> $fields Field definitions.
	 * @param array<mixed>                     $posted The unslashed submission.
	 * @return array<string, string|array<int, string>> Option id => value to store.
	 */
	public static function sanitize_submission( array $fields, array $posted ): array {
		$values = array();

		foreach ( $fields as $field ) {
			$id   = is_string( $field['id'] ?? null ) ? $field['id'] : '';
			$type = is_string( $field['type'] ?? null ) ? $field['type'] : '';

			if ( '' === $id || false === ( $field['is_option'] ?? true ) ) {
				continue;
			}

			if ( 'checkbox' === $type ) {
				$raw           = $posted[ $id ] ?? null;
				$values[ $id ] = is_string( $raw ) && 'yes' === $raw ? 'yes' : 'no';
			}

			if ( 'multicheck' === $type ) {
				$raw     = $posted[ $id ] ?? array();
				$choices = is_array( $field['options'] ?? null ) ? array_map( 'strval', array_keys( $field['options'] ) ) : array();

				// Only the ids this field offers, each once: anything else in the
				// list was not a box on this form.
				$values[ $id ] = is_array( $raw )
					? array_values( array_unique( array_intersect( array_filter( $raw, 'is_string' ), $choices ) ) )
					: array();
			}
		}

		return $values;
	}

	/**
	 * Notice shown after the save redirect.
	 *
	 * @return void
	 */
	private function render_saved_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which notice to show.
		if ( isset( $_GET['outdated'] ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'The plugin was updated while this screen was open, so nothing was saved. Check the settings below and save again.', 'rhinnoit-optimizer' )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which notice to show.
		if ( ! isset( $_GET['updated'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Settings saved.', 'rhinnoit-optimizer' )
		);
	}
}
