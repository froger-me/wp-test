<?php
/**
 * Remove data owned by the lifecycle test fixture.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'anyape_wp_test_tools_fixture_activation_count' );
delete_option( 'anyape_wp_test_tools_fixture_rewrite_flushed' );
remove_role( 'anyape_wp_test_tools_fixture_role' );
wp_clear_scheduled_hook( 'anyape_wp_test_tools_fixture_cron' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Uninstall must remove the fixture-owned table.
$wpdb->query(
	'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'anyape_wp_test_tools_fixture_items'
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery
