<?php
/**
 * Fixture plugin test discovery.
 *
 * @package WpTest
 */

declare(strict_types=1);

use WpTest\IntegrationTestCase;

/** Tests discovery of fixture plugin tests. */
final class FixturePluginDiscoveryTest extends IntegrationTestCase {

	/** Verifies that the fixture plugin bootstrap and test were discovered. */
	public function test_fixture_plugin_test_was_discovered(): void {
		$this->assertTrue(
			defined( 'WP_TEST_FIXTURE_PLUGIN_BOOTSTRAP_LOADED' )
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! is_plugin_active( 'wp-test-lifecycle/wp-test-lifecycle.php' ) ) {
			$this->activate_plugin( 'wp-test-lifecycle/wp-test-lifecycle.php' );
		}

		$this->assertGreaterThan(
			0,
			(int) get_option( 'wp_test_fixture_activation_count', 0 )
		);
	}
}
