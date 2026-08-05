<?php

declare(strict_types=1);

use WpTest\IntegrationTestCase;

final class FixturePluginDiscoveryTest extends IntegrationTestCase
{
	public function test_fixture_plugin_test_was_discovered(): void
	{
		$this->assertTrue(
			defined('WP_TEST_FIXTURE_PLUGIN_BOOTSTRAP_LOADED')
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if (! is_plugin_active('wp-test-lifecycle/wp-test-lifecycle.php')) {
			$this->activatePlugin('wp-test-lifecycle/wp-test-lifecycle.php');
		}

		$this->assertGreaterThan(
			0,
			(int) get_option('wp_test_fixture_activation_count', 0)
		);
	}
}
