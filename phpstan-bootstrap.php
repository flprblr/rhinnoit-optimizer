<?php
/**
 * Constants for the static analyser.
 *
 * They are defined at run time in the main plugin file, which PHPStan cannot
 * resolve across files. This file is never loaded by WordPress.
 *
 * @package RhinnoIT\Optimizer
 */

define( 'ROPT_VERSION', '2.0.0' );
define( 'ROPT_FILE', __FILE__ );
define( 'ROPT_PATH', __DIR__ . '/' );
define( 'ROPT_URL', 'https://example.test/wp-content/plugins/rhinnoit-optimizer/' );
define( 'ROPT_BASENAME', 'rhinnoit-optimizer/rhinnoit-optimizer.php' );
define( 'ROPT_MENU_PARENT_SLUG', 'rhinnoit-tools' );
define( 'ROPT_MENU_PARENT_TITLE', 'RHINNO IT' );
define( 'ROPT_MENU_TITLE', 'Optimizer' );
