<?php
/**
 * Plugin Name: WP Test Lifecycle Fixture
 * Description: Internal fixture plugin used to validate the shared PHPUnit harness.
 * Version: 1.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

const WP_TEST_FIXTURE_OPTION = 'wp_test_fixture_activation_count';
const WP_TEST_FIXTURE_CRON   = 'wp_test_fixture_cron';

function wp_test_fixture_table_name(): string
{
	global $wpdb;

	return $wpdb->prefix . 'wp_test_fixture_items';
}

register_activation_hook(
	__FILE__,
	static function (): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = wp_test_fixture_table_name();
		$charsetCollate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(191) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY label (label)
			) {$charsetCollate};"
		);

		$count = (int) get_option(WP_TEST_FIXTURE_OPTION, 0);
		update_option(WP_TEST_FIXTURE_OPTION, $count + 1, false);

		add_role(
			'wp_test_fixture_role',
			'WP Test Fixture Role',
			['read' => true]
		);

		if (! wp_next_scheduled(WP_TEST_FIXTURE_CRON)) {
			wp_schedule_event(
				time() + HOUR_IN_SECONDS,
				'hourly',
				WP_TEST_FIXTURE_CRON
			);
		}

		update_option('wp_test_fixture_rewrite_flushed', 'yes', false);
		flush_rewrite_rules(false);
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook(WP_TEST_FIXTURE_CRON);
	}
);

function wp_test_fixture_register_rest_route(): void
{
	register_rest_route(
		'wp-test/v1',
		'/protected',
		[
			'methods'             => 'GET',
			'permission_callback' => static fn (): bool =>
				current_user_can('manage_options'),
			'callback'            => static fn (): WP_REST_Response =>
				new WP_REST_Response(['ok' => true], 200),
		]
	);
}

add_action('rest_api_init', 'wp_test_fixture_register_rest_route');
