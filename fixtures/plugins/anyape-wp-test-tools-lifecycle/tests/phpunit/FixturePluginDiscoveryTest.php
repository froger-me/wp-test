<?php
/**
 * Fixture plugin test discovery.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

use AnyapeWPTestTools\IntegrationTestCase;

/** Tests discovery of fixture plugin tests. */
final class FixturePluginDiscoveryTest extends IntegrationTestCase {

	/** Verifies that the fixture plugin bootstrap and test were discovered. */
	public function test_fixture_plugin_test_was_discovered(): void {
		$this->assertTrue(
			defined( 'ANYAPE_WP_TEST_TOOLS_FIXTURE_PLUGIN_BOOTSTRAP_LOADED' )
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! is_plugin_active( 'anyape-wp-test-tools-lifecycle/anyape-wp-test-tools-lifecycle.php' ) ) {
			$this->activate_plugin( 'anyape-wp-test-tools-lifecycle/anyape-wp-test-tools-lifecycle.php' );
		}

		$this->assertGreaterThan(
			0,
			(int) get_option( 'anyape_wp_test_tools_fixture_activation_count', 0 )
		);
	}
}
