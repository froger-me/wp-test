<?php
/**
 * Plugin Name: Anyape WP Test Tools Lifecycle Fixture
 * Description: Internal fixture plugin used to validate the shared PHPUnit harness.
 * Version: 1.0.0
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

const ANYAPE_WP_TEST_TOOLS_FIXTURE_OPTION = 'anyape_wp_test_tools_fixture_activation_count';
const ANYAPE_WP_TEST_TOOLS_FIXTURE_CRON   = 'anyape_wp_test_tools_fixture_cron';

/** Return the fixture plugin's database table name. */
function anyape_wp_test_tools_fixture_table_name(): string {
	global $wpdb;

	return $wpdb->prefix . 'anyape_wp_test_tools_fixture_items';
}

register_activation_hook(
	__FILE__,
	static function (): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = anyape_wp_test_tools_fixture_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(191) NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY label (label)
			) {$charset_collate};"
		);

		$count = (int) get_option( ANYAPE_WP_TEST_TOOLS_FIXTURE_OPTION, 0 );
		update_option( ANYAPE_WP_TEST_TOOLS_FIXTURE_OPTION, $count + 1, false );

		add_role(
			'anyape_wp_test_tools_fixture_role',
			'Anyape WP Test Tools Fixture Role',
			array( 'read' => true )
		);

		if ( ! wp_next_scheduled( ANYAPE_WP_TEST_TOOLS_FIXTURE_CRON ) ) {
			wp_schedule_event(
				time() + HOUR_IN_SECONDS,
				'hourly',
				ANYAPE_WP_TEST_TOOLS_FIXTURE_CRON
			);
		}

		update_option( 'anyape_wp_test_tools_fixture_rewrite_flushed', 'yes', false );
		flush_rewrite_rules( false );
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( ANYAPE_WP_TEST_TOOLS_FIXTURE_CRON );
	}
);

/** Register the fixture plugin's protected REST route. */
function anyape_wp_test_tools_fixture_register_rest_route(): void {
	register_rest_route(
		'anyape-wp-test-tools/v1',
		'/protected',
		array(
			'methods'             => 'GET',
			'permission_callback' => static fn (): bool =>
				current_user_can( 'manage_options' ),
			'callback'            => static fn (): WP_REST_Response =>
				new WP_REST_Response( array( 'ok' => true ), 200 ),
		)
	);
}

add_action( 'rest_api_init', 'anyape_wp_test_tools_fixture_register_rest_route' );
