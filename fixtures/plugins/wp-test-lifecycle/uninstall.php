<?php

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

delete_option('wp_test_fixture_activation_count');
delete_option('wp_test_fixture_rewrite_flushed');
remove_role('wp_test_fixture_role');
wp_clear_scheduled_hook('wp_test_fixture_cron');

$wpdb->query(
	'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wp_test_fixture_items'
);
