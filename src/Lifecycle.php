<?php
/**
 * Plugin lifecycle helpers for integration tests.
 *
 * @package WpTest
 */

declare(strict_types=1);

namespace WpTest;

use RuntimeException;
use WP_Error;

/** Manages plugin lifecycle operations against the isolated test database. */
final class Lifecycle {

	/**
	 * Activate a plugin.
	 *
	 * @param string $plugin       Plugin basename.
	 * @param bool   $network_wide Whether to activate network-wide.
	 * @throws RuntimeException When activation fails or the test database is unsafe.
	 */
	public static function activate( string $plugin, bool $network_wide = false ): void {
		self::assert_safe_database();
		self::load_plugin_admin_functions();
		$result = activate_plugin( $plugin, '', $network_wide, false );

		if ( $result instanceof WP_Error ) {
			$message = implode( '; ', $result->get_error_messages() );
			$data    = $result->get_error_data();
			if ( is_string( $data ) && trim( $data ) !== '' ) {
				$message .= sprintf( ' Output: %s', trim( $data ) );
			}
			throw new RuntimeException( sprintf( 'Activation failed for plugin "%s": %s', $plugin, $message ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve plugin and WordPress error details.
		}

		$active = $network_wide ? is_plugin_active_for_network( $plugin ) : is_plugin_active( $plugin );
		if ( ! $active ) {
			throw new RuntimeException( sprintf( 'Activation did not mark plugin active: %s', $plugin ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the plugin basename.
		}
	}

	/**
	 * Deactivate a plugin.
	 *
	 * @param string $plugin       Plugin basename.
	 * @param bool   $network_wide Whether to deactivate network-wide.
	 */
	public static function deactivate( string $plugin, bool $network_wide = false ): void {
		self::assert_safe_database();
		self::load_plugin_admin_functions();
		deactivate_plugins( $plugin, false, $network_wide );
	}

	/**
	 * Uninstall a plugin.
	 *
	 * @param string $plugin Plugin basename.
	 * @throws RuntimeException When uninstall fails or the test database is unsafe.
	 */
	public static function uninstall( string $plugin ): void {
		self::assert_safe_database();
		self::load_plugin_admin_functions();
		if ( is_plugin_active( $plugin ) ) {
			self::deactivate( $plugin );
		}
		$result = uninstall_plugin( $plugin );
		if ( $result instanceof WP_Error ) {
			throw new RuntimeException( sprintf( 'Uninstall failed for plugin "%s": %s', $plugin, implode( '; ', $result->get_error_messages() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve plugin and WordPress error details.
		}
	}

	/**
	 * Verify that lifecycle operations target only the isolated test database.
	 *
	 * @throws RuntimeException When the database name or table prefix is unsafe.
	 */
	public static function assert_safe_database(): void {
		global $wpdb;
		if ( ! defined( 'DB_NAME' ) || 'wp_tests' !== DB_NAME ) {
			throw new RuntimeException( sprintf( 'Lifecycle operation refused: expected DB_NAME wp_tests, got %s.', defined( 'DB_NAME' ) ? DB_NAME : '(undefined)' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the exact database name.
		}
		if ( ! isset( $wpdb ) || 'wptests_' !== $wpdb->prefix ) {
			throw new RuntimeException( sprintf( 'Lifecycle operation refused: expected table prefix wptests_, got %s.', isset( $wpdb ) ? $wpdb->prefix : '(unavailable)' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the exact table prefix.
		}
	}

	/** Load WordPress plugin administration functions. */
	private static function load_plugin_admin_functions(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
}
