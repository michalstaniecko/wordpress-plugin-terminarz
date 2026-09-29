<?php
/**
 * Checks the translation catalogues (ADR-047), used by CI:
 *
 * 1. languages/terminarz.pot contains exactly the strings of the current code (line references are ignored, so moving
 *    code does not require regenerating it);
 * 2. every languages/terminarz-*.po translates every string of the .pot (no fuzzy entries, all plural forms).
 *
 * Usage: php bin/i18n-check.php (after `npm run build`; needs `composer install` for WP-CLI).
 *
 * @package Terminarz
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script, not WordPress.

$root = dirname( __DIR__ );
$pot  = $root . '/languages/terminarz.pot';
$tmp  = sys_get_temp_dir() . '/trmz-i18n-check-' . getmypid() . '.pot';

/**
 * Parses a PO/POT file into entries keyed by "context\x04msgid".
 *
 * @param string $file Path.
 * @return array<string, array{plural: bool, msgstr: string[], fuzzy: bool}>
 */
function trmz_parse_po( string $file ): array {
	$entries = array();
	$current = array();
	$last    = null;
	$fuzzy   = false;
	$flush   = static function () use ( &$entries, &$current, &$fuzzy ): void {
		if ( isset( $current['msgid'] ) && '' !== $current['msgid'] ) {
			$key             = ( $current['msgctxt'] ?? '' ) . "\x04" . $current['msgid'];
			$entries[ $key ] = array(
				'plural' => isset( $current['msgid_plural'] ),
				'msgstr' => array_values(
					array_filter(
						$current,
						static fn( $name ) => str_starts_with( (string) $name, 'msgstr' ),
						ARRAY_FILTER_USE_KEY
					)
				),
				'fuzzy'  => $fuzzy,
			);
		}
		$current = array();
		$fuzzy   = false;
	};

	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( '' === trim( $line ) ) {
			$flush();
			$last = null;
			continue;
		}
		if ( str_starts_with( $line, '#' ) ) {
			if ( isset( $current['msgid'] ) ) {
				$flush();
			}
			if ( str_starts_with( $line, '#,' ) && str_contains( $line, 'fuzzy' ) ) {
				$fuzzy = true;
			}
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d\])?)\s+"(.*)"$/', $line, $m ) ) {
			if ( 'msgctxt' === $m[1] && isset( $current['msgid'] ) ) {
				$flush();
			}
			$last             = $m[1];
			$current[ $last ] = stripcslashes( $m[2] );
			continue;
		}
		if ( null !== $last && preg_match( '/^"(.*)"$/', $line, $m ) ) {
			$current[ $last ] .= stripcslashes( $m[1] );
		}
	}
	$flush();

	return $entries;
}

$errors = array();

// 1. The committed .pot matches the code.
$command = sprintf(
	'%s i18n make-pot %s %s --slug=terminarz --domain=terminarz --exclude=node_modules,vendor,build,tests,docs,artifacts,bin --quiet 2>&1',
	escapeshellarg( $root . '/vendor/bin/wp' ),
	escapeshellarg( $root ),
	escapeshellarg( $tmp )
);
exec( $command, $output, $status );
if ( 0 !== $status ) {
	fwrite( STDERR, "make-pot failed:\n" . implode( "\n", $output ) . "\n" );
	exit( 1 );
}
$fresh     = trmz_parse_po( $tmp );
$committed = trmz_parse_po( $pot );
unlink( $tmp );

$readable = static fn( string $key ): string => str_replace( "\x04", ' | ', ltrim( $key, "\x04" ) );
foreach ( array_diff_key( $fresh, $committed ) as $key => $unused ) {
	$errors[] = 'Missing in languages/terminarz.pot (run `npm run i18n`): ' . $readable( $key );
}
foreach ( array_diff_key( $committed, $fresh ) as $key => $unused ) {
	$errors[] = 'Obsolete in languages/terminarz.pot (run `npm run i18n`): ' . $readable( $key );
}

// 2. Every translation is complete.
foreach ( glob( $root . '/languages/terminarz-*.po' ) as $po ) {
	$name         = basename( $po );
	$translations = trmz_parse_po( $po );
	foreach ( $fresh as $key => $entry ) {
		$translation = $translations[ $key ] ?? null;
		if ( null === $translation ) {
			$errors[] = "{$name}: missing (run `npm run i18n:update-po`): " . $readable( $key );
			continue;
		}
		if ( $translation['fuzzy'] || in_array( '', $translation['msgstr'], true ) || array() === $translation['msgstr'] ) {
			$errors[] = "{$name}: untranslated or fuzzy: " . $readable( $key );
		}
	}
}

if ( array() !== $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

echo 'Translations OK: ' . count( $fresh ) . " strings.\n";
