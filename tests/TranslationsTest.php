<?php
/**
 * The Spanish translations stay complete and compiled.
 *
 * WordPress does not fall back from es_CL to es_ES, so each shop's locale
 * needs its own catalogue, and a string added to the code without a
 * translation shows up in English on a Spanish screen. These cases read the
 * source, the catalogues and the compiled files, so a forgotten string or a
 * forgotten msgfmt fails here instead of on the shop.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslationsTest extends TestCase {

	/**
	 * The locales the plugin ships.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function locales(): array {
		return array(
			'Spain' => array( 'es_ES' ),
			'Chile' => array( 'es_CL' ),
		);
	}

	/**
	 * Every file that can hold a translatable string.
	 *
	 * The PHP, and the sources of the bundles: make-pot reads the built copies
	 * of those, whose strings are the same literals.
	 *
	 * @return array<int, string>
	 */
	private static function source_files(): array {
		$root  = dirname( __DIR__ );
		$files = array( $root . '/rhinnoit-optimizer.php', $root . '/uninstall.php' );

		foreach ( array( 'includes' => '.php', 'src' => '.js' ) as $dir => $extension ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}

			$tree = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );

			foreach ( $tree as $file ) {
				if ( str_ends_with( (string) $file, $extension ) ) {
					$files[] = (string) $file;
				}
			}
		}

		return $files;
	}

	/**
	 * Every string the code passes to a translation function, unescaped.
	 *
	 * @return array<int, string>
	 */
	private static function source_strings(): array {
		$root    = dirname( __DIR__ );
		$strings = array();

		foreach ( self::source_files() as $file ) {
			preg_match_all(
				"/\b(?:__|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_e)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'rhinnoit-optimizer'\s*\)/",
				(string) file_get_contents( $file ),
				$matches
			);

			foreach ( $matches[1] as $raw ) {
				$strings[] = stripcslashes( $raw );
			}
		}

		// The header fields wp i18n make-pot extracts; WordPress translates
		// the Plugins screen entry from them.
		preg_match_all(
			'/^\s*\*\s*(?:Plugin Name|Plugin URI|Description|Author|Author URI):\s*(.+)$/m',
			(string) file_get_contents( $root . '/rhinnoit-optimizer.php' ),
			$headers
		);

		foreach ( $headers[1] as $header ) {
			$strings[] = trim( $header );
		}

		return array_values( array_unique( $strings ) );
	}

	/**
	 * Message id => translation, read from a .po file.
	 *
	 * @param string $file Catalogue path.
	 * @return array<string, string>
	 */
	private static function read_po( string $file ): array {
		// gettext tools and PO editors wrap a long entry as `msgid ""` followed
		// by "..." lines; join them so a wrapped entry reads like a one-line one.
		$po = (string) preg_replace( '/"\R"/', '', (string) file_get_contents( $file ) );

		preg_match_all( '/^msgid "(.*)"\nmsgstr "(.*)"$/m', $po, $matches, PREG_SET_ORDER );

		$entries = array();

		foreach ( $matches as $match ) {
			if ( '' !== $match[1] ) {
				$entries[ stripcslashes( $match[1] ) ] = stripcslashes( $match[2] );
			}
		}

		return $entries;
	}

	/**
	 * Message id => translation, read from a compiled .mo file.
	 *
	 * @param string $file Compiled catalogue path.
	 * @return array<string, string>
	 */
	private static function read_mo( string $file ): array {
		$data   = (string) file_get_contents( $file );
		$header = unpack( 'Vmagic/Vrevision/Vcount/Voriginals/Vtranslations', substr( $data, 0, 20 ) );

		self::assertIsArray( $header );
		self::assertSame( 0x950412de, $header['magic'], basename( $file ) . ' is not a little-endian .mo file.' );

		$entries = array();

		for ( $i = 0; $i < $header['count']; $i++ ) {
			$original    = unpack( 'Vlength/Voffset', substr( $data, $header['originals'] + 8 * $i, 8 ) );
			$translation = unpack( 'Vlength/Voffset', substr( $data, $header['translations'] + 8 * $i, 8 ) );
			$id          = substr( $data, $original['offset'], $original['length'] );

			if ( '' !== $id ) {
				$entries[ $id ] = substr( $data, $translation['offset'], $translation['length'] );
			}
		}

		return $entries;
	}

	/**
	 * The template lists every string the code has, and nothing it lost.
	 *
	 * @return void
	 */
	public function test_the_template_matches_the_code(): void {
		$template = array_keys( self::read_po( dirname( __DIR__ ) . '/languages/rhinnoit-optimizer.pot' ) );
		$code     = self::source_strings();

		$this->assertSame( array(), array_values( array_diff( $code, $template ) ), 'Strings in the code but not in the .pot: regenerate it.' );
		$this->assertSame( array(), array_values( array_diff( $template, $code ) ), 'Strings in the .pot the code no longer has: regenerate it.' );
		// The four header fields are always there, so anything more is the
		// extractor reaching the code; a plugin stripped to the bone still
		// passes, one whose extractor broke does not.
		$this->assertGreaterThan( 4, count( $code ), 'The extractor found no string in the code, so this case checks nothing.' );
	}

	/**
	 * The code uses only the calls the cases here can read.
	 *
	 * A plural or a context is a different entry in a catalogue: msgid_plural
	 * and msgstr[n], or msgctxt. The extractor above does not see those calls
	 * and read_po() does not see those entries, so an untranslated plural would
	 * pass. Extend both before using one.
	 *
	 * @return void
	 */
	public function test_the_code_uses_only_the_calls_these_cases_read(): void {
		foreach ( self::source_files() as $file ) {
			$this->assertDoesNotMatchRegularExpression(
				'/\b(?:_n|_x|_nx|_n_noop|_nx_noop|esc_html_x|esc_attr_x)\(/',
				(string) file_get_contents( $file ),
				sprintf( '%s uses a plural or a context, which this suite cannot check yet.', basename( $file ) )
			);
		}
	}

	/**
	 * Each locale translates every string of the template.
	 *
	 * @param string $locale Locale code.
	 * @return void
	 */
	#[DataProvider( 'locales' )]
	public function test_every_string_is_translated( string $locale ): void {
		$template = array_keys( self::read_po( dirname( __DIR__ ) . '/languages/rhinnoit-optimizer.pot' ) );
		$catalog  = self::read_po( dirname( __DIR__ ) . "/languages/rhinnoit-optimizer-{$locale}.po" );

		foreach ( $template as $id ) {
			$this->assertNotSame( '', $catalog[ $id ] ?? '', sprintf( '"%s" has no %s translation.', $id, $locale ) );
		}

		foreach ( $catalog as $id => $translation ) {
			$this->assertSame(
				substr_count( $id, '%s' ),
				substr_count( $translation, '%s' ),
				sprintf( 'The %s translation of "%s" drops or adds a placeholder; sprintf() would fatal or print garbage.', $locale, $id )
			);
		}
	}

	/**
	 * Each catalogue uses its own country's words.
	 *
	 * The two differ on purpose where Spain and Chile do; a word copied from
	 * one into the other reads as foreign on that shop's screen.
	 *
	 * @return void
	 */
	public function test_each_catalogue_speaks_its_own_spanish(): void {
		$words = array(
			'es_ES' => '/\bagreg/iu',
			'es_CL' => '/\bañad/iu',
		);

		foreach ( $words as $locale => $foreign ) {
			foreach ( self::read_po( dirname( __DIR__ ) . "/languages/rhinnoit-optimizer-{$locale}.po" ) as $id => $translation ) {
				$this->assertDoesNotMatchRegularExpression( $foreign, $translation, sprintf( 'The %s translation of "%s" uses the other country\'s word.', $locale, $id ) );
			}
		}
	}

	/**
	 * The compiled file says what the catalogue says.
	 *
	 * WordPress reads only the .mo, so editing the .po without running msgfmt
	 * changes nothing on the shop.
	 *
	 * @param string $locale Locale code.
	 * @return void
	 */
	#[DataProvider( 'locales' )]
	public function test_the_compiled_file_is_up_to_date( string $locale ): void {
		$base = dirname( __DIR__ ) . "/languages/rhinnoit-optimizer-{$locale}";

		$this->assertFileExists( $base . '.mo' );

		// A .mo stores its entries sorted by id; the .po keeps source order.
		$source   = self::read_po( $base . '.po' );
		$compiled = self::read_mo( $base . '.mo' );
		ksort( $source );
		ksort( $compiled );

		$this->assertSame( $source, $compiled, 'Recompile it: msgfmt -o ' . basename( $base ) . '.mo ' . basename( $base ) . '.po' );
	}
}
