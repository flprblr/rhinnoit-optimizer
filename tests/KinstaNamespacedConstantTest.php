<?php
/**
 * Kinsta detected through the constant its MU plugin sets since 3.6.
 *
 * Its own process: it defines Kinsta\\KMP\\VERSION, which the rest of the
 * suite must not see. KINSTAMU_VERSION is deprecated and may go.
 *
 * @package RhinnoIT\Optimizer
 */

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RhinnoIT\Optimizer\Plugin;

#[CoversClass( Plugin::class )]
final class KinstaNamespacedConstantTest extends TestCase {

	#[RunInSeparateProcess]
	public function test_the_namespaced_constant_alone_means_kinsta(): void {
		define( 'Kinsta\KMP\VERSION', '3.7.0' );

		$this->assertFalse( defined( 'KINSTAMU_VERSION' ) );
		$this->assertTrue( Plugin::kinsta_is_active() );
	}
}
