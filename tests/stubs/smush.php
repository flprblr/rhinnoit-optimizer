<?php
/**
 * The slice of Smush's settings API the plugin asks.
 *
 * Required by tests/bootstrap.php only when ROPT_TESTS_SMUSH is 'api'.
 *
 * @package RhinnoIT\Optimizer
 */

namespace Smush\Core;

/**
 * Smush 4.3.3's settings singleton: what its modules really run.
 */
class Settings {

	/**
	 * What is_cdn_active() answers.
	 *
	 * @var bool
	 */
	public static bool $cdn = false;

	/**
	 * What is_lcp_preload_enabled() answers.
	 *
	 * @var bool
	 */
	public static bool $preload = false;

	/**
	 * The instance.
	 *
	 * @return Settings
	 */
	public static function get_instance(): Settings {
		return new self();
	}

	/**
	 * Whether the CDN module runs; false on free Smush whatever is stored.
	 *
	 * @return bool
	 */
	public function is_cdn_active(): bool {
		return self::$cdn;
	}

	/**
	 * Whether the critical image preload runs.
	 *
	 * @return bool
	 */
	public function is_lcp_preload_enabled(): bool {
		return self::$preload;
	}
}
