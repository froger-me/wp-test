<?php

declare(strict_types=1);

use WpTest\IntegrationTestCase;

final class FixtureLifecycleTest extends IntegrationTestCase
{
	private const PLUGIN = 'wp-test-lifecycle/wp-test-lifecycle.php';

	protected function tearDown(): void
	{
		try {
			$this->restoreFixtureSelection();
		} finally {
			parent::tearDown();
		}
	}

	private function restoreFixtureSelection(): void
	{
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$manifest = \WpTest\Manifest::fromFile(
			dirname(__DIR__) . '/runtime/manifest.json'
		);
		$selected = in_array(
			self::PLUGIN,
			$manifest->pluginFiles(),
			true
		);

		if ($selected) {
			if (! is_plugin_active(self::PLUGIN)) {
				$this->activatePlugin(self::PLUGIN);
			}

			return;
		}

		if (
			is_plugin_active(self::PLUGIN) ||
			get_option('wp_test_fixture_activation_count', false) !== false
		) {
			$this->uninstallPlugin(self::PLUGIN);
		}
	}

	public function test_activation_deactivation_and_reactivation_are_safe(): void
	{
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if (is_plugin_active(self::PLUGIN)) {
			$this->deactivatePlugin(self::PLUGIN);
		}

		$this->activatePlugin(self::PLUGIN);

		$table = $GLOBALS['wpdb']->prefix . 'wp_test_fixture_items';
		$firstCount = (int) get_option('wp_test_fixture_activation_count');

		$this->assertGreaterThan(0, $firstCount);
		$this->assertTableExists($table);
		$this->assertTableHasColumn($table, 'label');
		$this->assertNotNull(get_role('wp_test_fixture_role'));
		$this->assertCronEventScheduled('wp_test_fixture_cron');

		$this->deactivatePlugin(self::PLUGIN);

		$this->assertSame(
			$firstCount,
			(int) get_option('wp_test_fixture_activation_count')
		);
		$this->assertTableExists($table);
		$this->assertCronEventNotScheduled('wp_test_fixture_cron');

		$this->activatePlugin(self::PLUGIN);

		$this->assertSame(
			$firstCount + 1,
			(int) get_option('wp_test_fixture_activation_count')
		);
		$this->assertTableExists($table);
		$this->assertCronEventScheduled('wp_test_fixture_cron');
	}

	/**
	 * @group destructive
	 */
	public function test_uninstall_removes_owned_data_and_preserves_other_data(): void
	{
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if (! is_plugin_active(self::PLUGIN)) {
			$this->activatePlugin(self::PLUGIN);
		}

		update_option('other_plugin_owned_data', 'preserve');
		$table = $GLOBALS['wpdb']->prefix . 'wp_test_fixture_items';

		$this->uninstallPlugin(self::PLUGIN);

		$this->assertFalse(get_option('wp_test_fixture_activation_count', false));
		$this->assertNull(get_role('wp_test_fixture_role'));
		$this->assertCronEventNotScheduled('wp_test_fixture_cron');
		$this->assertSame('preserve', get_option('other_plugin_owned_data'));

		$found = $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare('SHOW TABLES LIKE %s', $table)
		);
		$this->assertNull($found);

		delete_option('other_plugin_owned_data');
	}
}
