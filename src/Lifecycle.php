<?php

declare(strict_types=1);

namespace WpTest;

use RuntimeException;
use WP_Error;

final class Lifecycle
{
	public static function activate(string $plugin, bool $networkWide = false): void
	{
		self::assertSafeDatabase();
		self::loadPluginAdminFunctions();
		$result = activate_plugin($plugin, '', $networkWide, false);

		if ($result instanceof WP_Error) {
			$message = implode('; ', $result->get_error_messages());
			$data = $result->get_error_data();
			if (is_string($data) && trim($data) !== '') {
				$message .= sprintf(' Output: %s', trim($data));
			}
			throw new RuntimeException(sprintf('Activation failed for plugin "%s": %s', $plugin, $message));
		}

		$active = $networkWide ? is_plugin_active_for_network($plugin) : is_plugin_active($plugin);
		if (! $active) {
			throw new RuntimeException(sprintf('Activation did not mark plugin active: %s', $plugin));
		}
	}

	public static function deactivate(string $plugin, bool $networkWide = false): void
	{
		self::assertSafeDatabase();
		self::loadPluginAdminFunctions();
		deactivate_plugins($plugin, false, $networkWide);
	}

	public static function uninstall(string $plugin): void
	{
		self::assertSafeDatabase();
		self::loadPluginAdminFunctions();
		if (is_plugin_active($plugin)) {
			self::deactivate($plugin);
		}
		$result = uninstall_plugin($plugin);
		if ($result instanceof WP_Error) {
			throw new RuntimeException(sprintf('Uninstall failed for plugin "%s": %s', $plugin, implode('; ', $result->get_error_messages())));
		}
	}

	public static function assertSafeDatabase(): void
	{
		global $wpdb;
		if (! defined('DB_NAME') || DB_NAME !== 'wp_tests') {
			throw new RuntimeException(sprintf('Lifecycle operation refused: expected DB_NAME wp_tests, got %s.', defined('DB_NAME') ? DB_NAME : '(undefined)'));
		}
		if (! isset($wpdb) || $wpdb->prefix !== 'wptests_') {
			throw new RuntimeException(sprintf('Lifecycle operation refused: expected table prefix wptests_, got %s.', isset($wpdb) ? $wpdb->prefix : '(unavailable)'));
		}
	}

	private static function loadPluginAdminFunctions(): void
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
}
