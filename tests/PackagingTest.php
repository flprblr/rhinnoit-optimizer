<?php
/**
 * Guards the identity WordPress and the zip read out of the plugin header.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\TestCase;

final class PackagingTest extends TestCase {

	/**
	 * Path of the main plugin file, found the same way bin/build-zip.sh finds it.
	 *
	 * @return string
	 */
	private function main_file_path(): string {
		foreach ( glob( dirname( __DIR__ ) . '/*.php' ) ?: array() as $file ) {
			if ( str_contains( (string) file_get_contents( $file ), ' * Plugin Name:' ) ) {
				return $file;
			}
		}

		$this->fail( 'No plugin header found in the repository root.' );
	}

	/**
	 * Contents of the main plugin file.
	 *
	 * @return string
	 */
	private function main_file(): string {
		return (string) file_get_contents( $this->main_file_path() );
	}

	public function test_the_header_version_matches_the_runtime_constant(): void {
		$source = $this->main_file();

		preg_match( '/^ \* Version: *(\S+)/m', $source, $header );
		preg_match( "/define\( '[A-Z0-9_]+_VERSION', '([^']+)' \)/", $source, $constant );

		$this->assertNotEmpty( $header[1] ?? '', 'The plugin header has no Version.' );
		$this->assertNotEmpty( $constant[1] ?? '', 'No *_VERSION constant is defined.' );
		$this->assertSame(
			$header[1],
			$constant[1],
			'The header version and the runtime constant drifted. WordPress and the zip read the header; '
			. 'Install::check_version() reads the constant.'
		);
	}

	public function test_the_plugin_name_carries_the_group_prefix(): void {
		preg_match( '/^ \* Plugin Name: *(.+?) *$/m', $this->main_file(), $name );

		$this->assertNotEmpty( $name[1] ?? '', 'The plugin header has no Plugin Name.' );
		$this->assertStringStartsWith(
			'RHINNO IT - ',
			$name[1],
			'Every plugin of the group is listed as "RHINNO IT - <what it does>", in English.'
		);
	}

	public function test_the_menu_entry_does_not_repeat_the_group_name(): void {
		$source = $this->main_file();

		preg_match( "/define\( '[A-Z0-9_]+_MENU_PARENT_TITLE', '([^']+)' \)/", $source, $node );
		preg_match( "/define\( '[A-Z0-9_]+_MENU_TITLE', '([^']+)' \)/", $source, $entry );

		// Without this the assertion below reads an empty string, which starts
		// with nothing and would pass on any declaration the pattern stops
		// matching.
		$this->assertNotEmpty( $entry[1] ?? '', 'No *_MENU_TITLE constant is declared.' );
		$this->assertSame( 'RHINNO IT', $node[1] ?? '', 'The shared node is named the same in every plugin of the group.' );
		$this->assertStringStartsNotWith(
			'RHINNO IT',
			$entry[1],
			'The entry hangs from a node that already says RHINNO IT.'
		);
	}

	public function test_the_package_manifest_declares_no_version_to_drift(): void {
		$manifest = dirname( __DIR__ ) . '/package.json';

		if ( ! is_readable( $manifest ) ) {
			$this->markTestSkipped( 'This plugin ships no build, so there is no package.json to go stale.' );
		}

		$package = json_decode( (string) file_get_contents( $manifest ), true );

		$this->assertIsArray( $package );
		$this->assertArrayNotHasKey(
			'version',
			$package,
			'package.json must not carry a version: nothing reads it and it silently goes stale.'
		);
	}

	public function test_nothing_the_plugin_needs_at_runtime_is_excluded_from_the_zip(): void {
		$shipped = array( 'includes', 'languages', 'uninstall.php', basename( $this->main_file_path() ) );

		// build/ is gitignored and absent from the PHP job of the CI, so it is
		// only checked against the exclusions: the zip is the one place it has
		// to be, since there is no src/ in it to compile from.
		$packaged = is_readable( dirname( __DIR__ ) . '/package.json' )
			? array_merge( $shipped, array( 'build' ) )
			: $shipped;

		// The two bin/build-zip.sh adds by rule, plus what .distignore declares.
		$excluded = array( '.*', 'dist' );

		foreach ( file( dirname( __DIR__ ) . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: array() as $line ) {
			$line = trim( $line );

			if ( '' !== $line && ! str_starts_with( $line, '#' ) ) {
				$excluded[] = ltrim( $line, '/' );
			}
		}

		foreach ( $shipped as $path ) {
			$this->assertFileExists( dirname( __DIR__ ) . '/' . $path );
		}

		foreach ( $packaged as $path ) {
			$this->assertNotContains(
				$path,
				$excluded,
				sprintf( '.distignore drops %s, so the zip installs as a fatal require.', $path )
			);
		}
	}

	public function test_deactivation_and_uninstall_leave_no_page_cache_behind(): void {
		$root      = dirname( __DIR__ );
		$main      = (string) file_get_contents( $root . '/rhinnoit-optimizer.php' );
		$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
		$source    = (string) file_get_contents( $root . '/includes/class-page-cache.php' );

		$this->assertStringContainsString( "register_deactivation_hook( ROPT_FILE, array( Install::class, 'deactivate' ) );", $main, 'A pending clear would outlive the plugin.' );
		$this->assertStringContainsString( "'" . RhinnoIT\Optimizer\Page_Cache::STATE . "'", $uninstall );
		$this->assertStringContainsString( "wp_clear_scheduled_hook( '" . RhinnoIT\Optimizer\Page_Cache::EVENT . "' )", $uninstall );
		$this->assertStringContainsString( "wp_unschedule_hook( '" . RhinnoIT\Optimizer\Page_Cache::VERIFY . "' )", $uninstall, 'A pending check, scheduled with arguments, would outlive the plugin.' );

		// The failures run_uapi() returns, and the one logged by name.
		preg_match_all( "/return array\\( '([a-z_]+)', /", $source, $keys );
		preg_match_all( "/'ropt_page_cache_([a-z_]+)'/", $source, $direct );

		foreach ( array_unique( array_merge( array_filter( $keys[1] ), $direct[1] ) ) as $key ) {
			if ( in_array( $key, array( 'state', 'run', 'dirty', 'verify' ), true ) ) {
				continue;
			}

			$this->assertStringContainsString( "'" . $key . "'", $uninstall, sprintf( 'The ropt_page_cache_%s log window survives uninstall.', $key ) );
		}
	}
}
