<?php
/**
 * Shared base class for WordPress integration tests.
 *
 * @package WpTest
 */

declare(strict_types=1);

namespace WpTest;

use WP_REST_Request;
use WP_REST_Response;

/** Supplies state-safe helpers to project integration tests. */
abstract class IntegrationTestCase extends \WP_UnitTestCase {

	/**
	 * Option names to delete during cleanup.
	 *
	 * @var list<string>
	 */
	private array $tracked_options = array();

	/**
	 * File paths to delete during cleanup.
	 *
	 * @var list<string>
	 */
	private array $tracked_files = array();

	/** Prepare isolated helper state before each test. */
	protected function setUp(): void {
		parent::setUp();
		HttpMock::reset();
		MailCapture::reset();
	}

	/** Clean tracked state after each test. */
	protected function tearDown(): void {
		$this->cleanup_tracked_state();
		HttpMock::reset();
		MailCapture::reset();
		parent::tearDown();
	}

	/**
	 * Update an option and track it for cleanup.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Option value.
	 * @param bool   $autoload Whether to autoload the option.
	 */
	protected function set_tracked_option( string $name, $value, bool $autoload = false ): void {
		if ( ! in_array( $name, $this->tracked_options, true ) ) {
			$this->tracked_options[] = $name;
		}

		update_option( $name, $value, $autoload );
	}

	/**
	 * Track a file for cleanup.
	 *
	 * @param string $path File path.
	 */
	protected function track_file( string $path ): void {
		if ( ! in_array( $path, $this->tracked_files, true ) ) {
			$this->tracked_files[] = $path;
		}
	}

	/** Return the ID of a newly created current administrator. */
	protected function create_administrator(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Assert that a database table exists.
	 *
	 * @param string $table Table name.
	 */
	protected function assert_table_exists( string $table ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This helper explicitly asserts database schema state.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( $table, $found, sprintf( 'Expected database table to exist: %s', $table ) );
	}

	/**
	 * Assert that a database table contains a column.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 */
	protected function assert_table_has_column( string $table, string $column ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This helper explicitly asserts database schema state.
		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);
		$this->assertContains( $column, $columns, sprintf( 'Expected table %s to contain column %s.', $table, $column ) );
	}

	/**
	 * Assert that a cron event is scheduled.
	 *
	 * @param string $hook Cron hook.
	 */
	protected function assert_cron_event_scheduled( string $hook ): void {
		$this->assertNotFalse( wp_next_scheduled( $hook ), sprintf( 'Expected cron hook to be scheduled: %s', $hook ) );
	}

	/**
	 * Assert that a cron event is not scheduled.
	 *
	 * @param string $hook Cron hook.
	 */
	protected function assert_cron_event_not_scheduled( string $hook ): void {
		$this->assertFalse( wp_next_scheduled( $hook ), sprintf( 'Expected cron hook not to be scheduled: %s', $hook ) );
	}

	/**
	 * Dispatch a REST API request.
	 *
	 * @param string               $method     HTTP method.
	 * @param string               $route      REST route.
	 * @param array<string, mixed> $parameters Query parameters.
	 * @return WP_REST_Response
	 */
	protected function rest_request( string $method, string $route, array $parameters = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_query_params( $parameters );
		return rest_ensure_response( rest_do_request( $request ) );
	}

	/**
	 * Create and track an upload file.
	 *
	 * @param string $name     File name.
	 * @param string $contents File contents.
	 * @return string Created file path.
	 */
	protected function create_upload_file( string $name, string $contents ): string {
		$uploads = wp_upload_dir();
		$this->assertFalse( $uploads['error'] );
		$path = trailingslashit( $uploads['path'] ) . basename( $name );
		if ( ! is_dir( dirname( $path ) ) ) {
			wp_mkdir_p( dirname( $path ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The helper creates an exact test fixture file in wp_upload_dir().
		file_put_contents( $path, $contents );
		$this->track_file( $path );
		return $path;
	}

	/** Enable mail capture for the current test. */
	protected function enable_mail_capture(): void {
		MailCapture::enable();
	}

	/**
	 * Return mail captured during the current test.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function captured_mail(): array {
		return MailCapture::messages();
	}

	/**
	 * Activate a plugin for the current test.
	 *
	 * @param string $plugin       Plugin basename.
	 * @param bool   $network_wide Whether to activate network-wide.
	 */
	protected function activate_plugin( string $plugin, bool $network_wide = false ): void {
		Lifecycle::activate( $plugin, $network_wide );
	}

	/**
	 * Deactivate a plugin for the current test.
	 *
	 * @param string $plugin       Plugin basename.
	 * @param bool   $network_wide Whether to deactivate network-wide.
	 */
	protected function deactivate_plugin( string $plugin, bool $network_wide = false ): void {
		Lifecycle::deactivate( $plugin, $network_wide );
	}

	/**
	 * Uninstall a plugin for the current test.
	 *
	 * @param string $plugin Plugin basename.
	 */
	protected function uninstall_plugin( string $plugin ): void {
		Lifecycle::uninstall( $plugin );
	}

	/** Remove all options and files tracked by the current test. */
	protected function cleanup_tracked_state(): void {
		foreach ( $this->tracked_options as $option ) {
			delete_option( $option );
		}
		foreach ( $this->tracked_files as $file ) {
			if ( is_file( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup removes only paths explicitly tracked by this test case.
				unlink( $file );
			}
		}
		$this->tracked_options = array();
		$this->tracked_files   = array();
	}
}
