<?php
/**
 * Remove data owned by the lifecycle test fixture.
 *
 * @package WpTest
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'wp_test_fixture_activation_count' );
delete_option( 'wp_test_fixture_rewrite_flushed' );
remove_role( 'wp_test_fixture_role' );
wp_clear_scheduled_hook( 'wp_test_fixture_cron' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Uninstall must remove the fixture-owned table.
$wpdb->query(
	'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wp_test_fixture_items'
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery
