<?php
/**
 * Unit test bootstrap: Composer autoloader only, WordPress is not loaded.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

// Plugin files exit when loaded outside WordPress (direct access guard); unit tests load them without WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
}

require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';
