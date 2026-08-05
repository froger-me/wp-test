<?php

declare(strict_types=1);

namespace WpTest;

final class MailCapture
{
	/** @var list<array<string, mixed>> */
	private static array $messages = [];
	private static bool $enabled = false;

	public static function enable(): void
	{
		if (self::$enabled) {
			return;
		}
		add_filter('pre_wp_mail', [self::class, 'capture'], 10, 2);
		self::$enabled = true;
	}

	public static function reset(): void
	{
		if (self::$enabled) {
			remove_filter('pre_wp_mail', [self::class, 'capture'], 10);
		}
		self::$messages = [];
		self::$enabled  = false;
	}

	/** @return list<array<string, mixed>> */
	public static function messages(): array
	{
		return self::$messages;
	}

	/**
	 * @param mixed $return
	 * @param array<string, mixed> $attributes
	 */
	public static function capture($return, array $attributes): bool
	{
		self::$messages[] = $attributes;
		return true;
	}
}
