<?php
/**
 * Fixture plugin lifecycle tests.
 *
 * @package WpTest
 */

declare(strict_types=1);

use WpTest\IntegrationTestCase;

/**
 * Tests fixture activation, deactivation, and uninstall behavior.
 *
 * @group harness-fixture
 */
final class FixtureLifecycleTest extends IntegrationTestCase {

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

	/** Verifies safe activation, deactivation, and reactivation. */
	public function test_activation_deactivation_and_reactivation_are_safe(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( is_plugin_active( self::PLUGIN ) ) {
			$this->deactivate_plugin( self::PLUGIN );
		}

		$this->activate_plugin( self::PLUGIN );

		$table       = $GLOBALS['wpdb']->prefix . 'wp_test_fixture_items';
		$first_count = (int) get_option( 'wp_test_fixture_activation_count' );

		$this->assertGreaterThan( 0, $first_count );
		$this->assert_table_exists( $table );
		$this->assert_table_has_column( $table, 'label' );
		$this->assertNotNull( get_role( 'wp_test_fixture_role' ) );
		$this->assert_cron_event_scheduled( 'wp_test_fixture_cron' );

		$this->deactivate_plugin( self::PLUGIN );

		$this->assertSame(
			$first_count,
			(int) get_option( 'wp_test_fixture_activation_count' )
		);
		$this->assert_table_exists( $table );
		$this->assert_cron_event_not_scheduled( 'wp_test_fixture_cron' );

		$this->activate_plugin( self::PLUGIN );

		$this->assertSame(
			$first_count + 1,
			(int) get_option( 'wp_test_fixture_activation_count' )
		);
		$this->assert_table_exists( $table );
		$this->assert_cron_event_scheduled( 'wp_test_fixture_cron' );
	}

	/**
	 * Verifies uninstall cleanup without deleting unrelated data.
	 *
	 * @group destructive
	 */
	public function test_uninstall_removes_owned_data_and_preserves_other_data(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! is_plugin_active( self::PLUGIN ) ) {
			$this->activate_plugin( self::PLUGIN );
		}

		update_option( 'other_plugin_owned_data', 'preserve' );
		$table = $GLOBALS['wpdb']->prefix . 'wp_test_fixture_items';

		$this->uninstall_plugin( self::PLUGIN );

		$this->assertFalse( get_option( 'wp_test_fixture_activation_count', false ) );
		$this->assertNull( get_role( 'wp_test_fixture_role' ) );
		$this->assert_cron_event_not_scheduled( 'wp_test_fixture_cron' );
		$this->assertSame( 'preserve', get_option( 'other_plugin_owned_data' ) );

		$found = $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		$this->assertNull( $found );

		delete_option( 'other_plugin_owned_data' );
	}
}
