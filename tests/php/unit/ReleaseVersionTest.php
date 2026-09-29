<?php
/**
 * The release version is the same everywhere it is declared.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class ReleaseVersionTest extends TestCase {

	public function test_version_is_consistent(): void {
		$root   = dirname( __DIR__, 3 );
		$plugin = (string) file_get_contents( $root . '/terminarz.php' );
		$readme = (string) file_get_contents( $root . '/readme.txt' );

		$this->assertSame( 1, preg_match( '/^ \* Version:\s+(\S+)$/m', $plugin, $header ) );
		$version = $header[1];
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $version );

		$this->assertStringContainsString( "define( 'TRMZ_VERSION', '{$version}' );", $plugin );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s+(\S+)$/m', $readme, $stable ) );
		$this->assertSame( $version, $stable[1], 'readme.txt Stable tag' );
		$this->assertStringContainsString( "= {$version} =", $readme, 'readme.txt changelog entry' );

		foreach ( array( 'package.json', 'blocks/booking/block.json' ) as $file ) {
			$json = json_decode( (string) file_get_contents( $root . '/' . $file ), true );
			$this->assertSame( $version, $json['version'] ?? null, $file );
		}
		$lock = json_decode( (string) file_get_contents( $root . '/package-lock.json' ), true );
		$this->assertSame( $version, $lock['version'] ?? null, 'package-lock.json' );
		$this->assertSame( $version, $lock['packages']['']['version'] ?? null, 'package-lock.json (root package)' );
	}

	public function test_readme_headers_match_the_plugin_header(): void {
		$root   = dirname( __DIR__, 3 );
		$plugin = (string) file_get_contents( $root . '/terminarz.php' );
		$readme = (string) file_get_contents( $root . '/readme.txt' );

		$this->assertSame( 1, preg_match( '/^ \* Requires at least:\s+(\S+)$/m', $plugin, $wp ) );
		$this->assertSame( 1, preg_match( '/^ \* Requires PHP:\s+(\S+)$/m', $plugin, $php ) );
		$this->assertMatchesRegularExpression( '/^Requires at least: ' . preg_quote( $wp[1], '/' ) . '$/m', $readme );
		$this->assertMatchesRegularExpression( '/^Requires PHP: ' . preg_quote( $php[1], '/' ) . '$/m', $readme );
		$this->assertMatchesRegularExpression( '/^License: GPLv2 or later$/m', $readme );
	}
}
