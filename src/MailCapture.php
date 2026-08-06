<?php
/**
 * In-memory WordPress mail capture for integration tests.
 *
 * @package WpTest
 */

declare(strict_types=1);

namespace WpTest;

/** Captures messages sent through wp_mail() during a test. */
final class MailCapture {

	/**
	 * Captured wp_mail() attributes.
	 *
	 * @var list<array<string, mixed>>
	 */
	private static array $messages = array();

	/**
	 * Whether mail capture is enabled.
	 *
	 * @var bool
	 */
	private static bool $enabled = false;

	/** Enable mail capture. */
	public static function enable(): void {
		if ( self::$enabled ) {
			return;
		}
		add_filter( 'pre_wp_mail', array( self::class, 'capture' ), 10, 2 );
		self::$enabled = true;
	}

	/** Disable mail capture and discard captured messages. */
	public static function reset(): void {
		if ( self::$enabled ) {
			remove_filter( 'pre_wp_mail', array( self::class, 'capture' ), 10 );
		}
		self::$messages = array();
		self::$enabled  = false;
	}

	/**
	 * Return captured messages.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function messages(): array {
		return self::$messages;
	}

	/**
	 * Capture a wp_mail() call.
	 *
	 * @param mixed                $mail_result Existing pre_wp_mail result.
	 * @param array<string, mixed> $attributes  Mail attributes.
	 * @return bool True to short-circuit delivery.
	 */
	public static function capture( $mail_result, array $attributes ): bool {
		self::$messages[] = $attributes;
		return true;
	}
}
