<?php
/**
 * Integration helper tests.
 *
 * @package WpTest
 */

declare(strict_types=1);

use WpTest\IntegrationTestCase;

/**
 * Tests the integration helper surface.
 *
 * @group harness-fixture
 */
final class IntegrationHelpersTest extends IntegrationTestCase {

	private const PLUGIN = 'wp-test-lifecycle/wp-test-lifecycle.php';

	/** Restores fixture state after each test. */
	protected function tearDown(): void {
		try {
			$this->restore_fixture_selection();
		} finally {
			parent::tearDown();
		}
	}

	/** Restores the fixture plugin selection from the manifest. */
	private function restore_fixture_selection(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$manifest = \WpTest\Manifest::from_file(
			dirname( __DIR__ ) . '/runtime/manifest.json'
		);
		$selected = in_array(
			self::PLUGIN,
			$manifest->plugin_files(),
			true
		);

		if ( $selected ) {
			if ( ! is_plugin_active( self::PLUGIN ) ) {
				$this->activate_plugin( self::PLUGIN );
			}

			return;
		}

		if (
			is_plugin_active( self::PLUGIN ) ||
			get_option( 'wp_test_fixture_activation_count', false ) !== false
		) {
			$this->uninstall_plugin( self::PLUGIN );
		}
	}

	/** Verifies REST, administrator, upload, and mail helpers. */
	public function test_rest_authorization_admin_fixture_uploads_and_mail_capture(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! is_plugin_active( self::PLUGIN ) ) {
			$this->activate_plugin( self::PLUGIN );
		}

		$route  = '/wp-test/v1/protected';
		$server = rest_get_server();

		if ( ! isset( $server->get_routes()[ $route ] ) ) {
			do_action( 'rest_api_init', $server );
		}

		$this->assertArrayHasKey( $route, $server->get_routes() );

		wp_set_current_user( 0 );

		$forbidden = $this->rest_request( 'GET', $route );
		$this->assertSame( 401, $forbidden->get_status() );

		$this->create_administrator();

		$allowed = $this->rest_request( 'GET', $route );
		$this->assertSame( 200, $allowed->get_status() );
		$this->assertSame( array( 'ok' => true ), $allowed->get_data() );

		$file = $this->create_upload_file(
			'wp-test-helper.txt',
			'fixture'
		);
		$this->assertFileExists( $file );

		$this->enable_mail_capture();
		$this->assertTrue(
			wp_mail(
				'recipient@example.test',
				'Fixture message',
				'Message body'
			)
		);
		$this->assertCount( 1, $this->captured_mail() );
	}

	/** Verifies explicit cleanup of tracked options. */
	public function test_tracked_options_can_be_cleaned_explicitly(): void {
		$this->set_tracked_option( 'wp_test_tracked_option', 'value' );
		$this->assertSame( 'value', get_option( 'wp_test_tracked_option' ) );

		$this->cleanup_tracked_state();

		$this->assertFalse( get_option( 'wp_test_tracked_option', false ) );
	}
}
