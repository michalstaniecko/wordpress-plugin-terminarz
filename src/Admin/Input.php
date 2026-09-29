<?php
/**
 * Sanitizing accessors for unslashed request arrays.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

defined( 'ABSPATH' ) || exit; // No direct access.

/**
 * Every value read by admin handlers passes through one of these helpers.
 */
final class Input {

	/**
	 * Single-line text.
	 *
	 * @param array<string, mixed> $request Request.
	 * @param string               $key     Field.
	 */
	public static function text( array $request, string $key ): string {
		$value = $request[ $key ] ?? '';
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Multi-line text.
	 *
	 * @param array<string, mixed> $request Request.
	 * @param string               $key     Field.
	 */
	public static function textarea( array $request, string $key ): string {
		$value = $request[ $key ] ?? '';
		return is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
	}

	/**
	 * Non-negative integer (0 when missing or invalid).
	 *
	 * @param array<string, mixed> $request Request.
	 * @param string               $key     Field.
	 */
	public static function absint( array $request, string $key ): int {
		$value = $request[ $key ] ?? 0;
		return is_scalar( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Integer given as a string of digits (optionally negative); null when missing or not an integer.
	 *
	 * @param array<string, mixed> $request Request.
	 * @param string               $key     Field.
	 */
	public static function int_or_null( array $request, string $key ): ?int {
		$value = self::text( $request, $key );
		return 1 === preg_match( '/^-?\d{1,9}$/', $value ) ? (int) $value : null;
	}

	/**
	 * Checkbox.
	 *
	 * @param array<string, mixed> $request Request.
	 * @param string               $key     Field.
	 */
	public static function flag( array $request, string $key ): bool {
		return isset( $request[ $key ] ) && in_array( $request[ $key ], array( '1', 1, 'on', true ), true );
	}

	/**
	 * Key-like value (lowercase letters, digits, dashes, underscores).
	 *
	 * @param array<string, mixed> $request Request.
	 * @param string               $key     Field.
	 */
	public static function key( array $request, string $key ): string {
		$value = $request[ $key ] ?? '';
		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	/**
	 * List of positive integers (e.g. checkboxes `ids[]`).
	 *
	 * @param array<string, mixed> $request Request.
	 * @param string               $key     Field.
	 * @return int[]
	 */
	public static function ids( array $request, string $key ): array {
		$values = $request[ $key ] ?? array();
		if ( ! is_array( $values ) ) {
			return array();
		}
		$ids = array();
		foreach ( $values as $value ) {
			$id = is_scalar( $value ) ? absint( $value ) : 0;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Local date `Y-m-d` or '' when missing/invalid.
	 *
	 * @param array<string, mixed> $request Request.
	 * @param string               $key     Field.
	 */
	public static function date( array $request, string $key ): string {
		$value  = self::text( $request, $key );
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $value ? $value : '';
	}
}
