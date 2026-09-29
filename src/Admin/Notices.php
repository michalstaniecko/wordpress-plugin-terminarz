<?php
/**
 * Flash messages for admin screens (Post/Redirect/Get).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

/**
 * Messages and submitted form values survive the redirect after a POST in a short-lived, per-user transient.
 * Messages are translated plain text; they are escaped when printed.
 */
final class Notices {

	private const TTL = 300;

	public const SUCCESS = 'success';
	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const INFO    = 'info';

	/**
	 * Adds a message.
	 *
	 * @param string $type    One of the type constants.
	 * @param string $message Translated plain text.
	 */
	public static function add( string $type, string $message ): void {
		$data               = self::load();
		$data['messages'][] = array(
			'type'    => in_array( $type, array( self::SUCCESS, self::ERROR, self::WARNING, self::INFO ), true ) ? $type : self::INFO,
			'message' => $message,
		);
		self::store( $data );
	}

	/**
	 * Adds a success message.
	 *
	 * @param string $message Translated plain text.
	 */
	public static function success( string $message ): void {
		self::add( self::SUCCESS, $message );
	}

	/**
	 * Adds an error message.
	 *
	 * @param string $message Translated plain text.
	 */
	public static function error( string $message ): void {
		self::add( self::ERROR, $message );
	}

	/**
	 * Adds a warning.
	 *
	 * @param string $message Translated plain text.
	 */
	public static function warning( string $message ): void {
		self::add( self::WARNING, $message );
	}

	/**
	 * Keeps submitted (already sanitized) form values for the next request, so a form with errors can be re-filled.
	 *
	 * @param array<string, mixed> $input Values.
	 */
	public static function keep_input( array $input ): void {
		$data          = self::load();
		$data['input'] = $input;
		self::store( $data );
	}

	/**
	 * Messages waiting to be shown (without removing them).
	 *
	 * @return array<int, array{type: string, message: string}>
	 */
	public static function peek(): array {
		return self::load()['messages'];
	}

	/**
	 * Takes the kept form values (once).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function take_input(): ?array {
		$data  = self::load();
		$input = $data['input'];
		if ( null !== $input ) {
			$data['input'] = null;
			self::store( $data );
		}
		return $input;
	}

	/**
	 * Prints and clears the messages.
	 */
	public static function render(): void {
		$data = self::load();
		foreach ( $data['messages'] as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
		$data['messages'] = array();
		self::store( $data );
	}

	/**
	 * Removes everything (tests).
	 */
	public static function clear(): void {
		delete_transient( self::key() );
	}

	/**
	 * Stored data.
	 *
	 * @return array{messages: array<int, array{type: string, message: string}>, input: array<string, mixed>|null}
	 */
	private static function load(): array {
		$data = get_transient( self::key() );
		$data = is_array( $data ) ? $data : array();

		return array(
			'messages' => isset( $data['messages'] ) && is_array( $data['messages'] ) ? array_values( $data['messages'] ) : array(),
			'input'    => isset( $data['input'] ) && is_array( $data['input'] ) ? $data['input'] : null,
		);
	}

	/**
	 * Saves the data (or removes the transient when empty).
	 *
	 * @param array{messages: array<int, array{type: string, message: string}>, input: array<string, mixed>|null} $data Data.
	 */
	private static function store( array $data ): void {
		if ( array() === $data['messages'] && null === $data['input'] ) {
			delete_transient( self::key() );
			return;
		}
		set_transient( self::key(), $data, self::TTL );
	}

	/**
	 * Transient key of the current user.
	 */
	private static function key(): string {
		return 'trmz_notices_' . get_current_user_id();
	}
}
