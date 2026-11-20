<?php
/**
 * Booting while WP Optimizer, the plugin this one replaces, is still active.
 *
 * Its own process: ROPT_TESTS_WP_OPTIMIZER makes tests/bootstrap.php define
 * the constant WP Optimizer's main file defines, the rest of the suite must
 * not see it, and Plugin::boot() is a singleton. BootTest is the other half:
 * without that constant, everything boots.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Install;
use RhinnoIT\Optimizer\Plugin;
use RhinnoIT\Optimizer\Settings;

#[CoversClass( Plugin::class )]
#[CoversClass( Install::class )]
final class BootWithWpOptimizerTest extends TestCase {

	public static function setUpBeforeClass(): void {
		putenv( 'ROPT_TESTS_WP_OPTIMIZER=1' );
	}

	public static function tearDownAfterClass(): void {
		putenv( 'ROPT_TESTS_WP_OPTIMIZER' );
	}

	#[RunInSeparateProcess]
	public function test_only_the_notice_registers_while_wp_optimizer_is_active(): void {
		$this->assertTrue( defined( 'RWPO_VERSION' ), 'The child has to boot beside WP Optimizer or this case means nothing.' );

		WP_Stub::reset();

		foreach ( Settings::option_ids() as $option ) {
			WP_Stub::$options[ $option ] = 'yes';
		}

		$options = WP_Stub::$options;

		Plugin::boot();

		// No service, no menu, no version check, no init callback: with both
		// running, every optimization would run twice.
		$this->assertSame( array( 'admin_notices:render_waiting_notice' ), WP_Stub::$callbacks );
		$this->assertSame( array(), WP_Stub::$fired, 'Nothing is reported either.' );
		$this->assertSame( $options, WP_Stub::$options, 'Nothing is written: the copy is the activation\'s job.' );
		$this->assertSame( 0, ( new ReflectionMethod( Plugin::class, 'render_waiting_notice' ) )->getNumberOfRequiredParameters(), 'admin_notices passes nothing.' );
	}

	#[RunInSeparateProcess]
	public function test_activation_beside_wp_optimizer_copies_its_settings(): void {
		// The hand-over activates this plugin while WP Optimizer still runs,
		// and its activation hook is registered at file scope, not from
		// boot(): the copy is made then, from rows that are still current.
		WP_Stub::reset();
		WP_Stub::$options = array(
			'rwpo_srcset_prune'   => 'no',
			'rwpo_cart_fragments' => 'no',
		);

		Install::activate();

		$this->assertSame( 'no', WP_Stub::$options['ropt_srcset_prune'], 'The guard holds back the services, not the copy.' );
		$this->assertSame( 'no', WP_Stub::$options['ropt_cart_fragments'] );
		$this->assertSame( ROPT_VERSION, WP_Stub::$options[ Install::VERSION_OPTION ], 'Recorded, so the first dashboard after WP Optimizer goes reseeds nothing.' );
	}

	#[RunInSeparateProcess]
	public function test_the_notice_names_the_plugin_to_deactivate_in_the_shop_language(): void {
		WP_Stub::reset();

		$html = $this->render_notice();

		$this->assertStringStartsWith( '<div class="notice notice-warning"><p>', $html );
		// In two pieces: whole, a rename pass would turn it into this
		// plugin's own name, and the case would still pass.
		$this->assertStringContainsString( 'until WP ' . 'Optimizer is deactivated', $html, 'It says which plugin it waits for.' );
		$this->assertSame(
			array( 'rhinnoit-optimizer' => 'rhinnoit-optimizer/languages' ),
			WP_Stub::$textdomains,
			'Nothing else loads the translations while the plugin waits.'
		);
	}

	#[RunInSeparateProcess]
	public function test_the_notice_stays_hidden_from_who_cannot_act_on_it(): void {
		WP_Stub::reset();
		WP_Stub::$can = false;

		$this->assertSame( '', $this->render_notice(), 'Only who can deactivate WP Optimizer is told to wait for it.' );
		$this->assertSame( array( 'activate_plugins' ), WP_Stub::$caps_checked );
	}

	/**
	 * Captures the notice.
	 *
	 * @return string
	 */
	private function render_notice(): string {
		ob_start();
		Plugin::render_waiting_notice();

		return (string) ob_get_clean();
	}
}
