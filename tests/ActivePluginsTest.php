<?php

declare(strict_types=1);

final class ActivePluginsTest extends WP_UnitTestCase
{
	public function test_locally_active_plugins_are_active_loaded_and_activated(): void
	{
		$project_root = dirname(__DIR__, 2);
		$list_file    = dirname(__DIR__) . '/active-plugins.json';

		$this->assertFileExists($list_file);

		$plugin_files = json_decode(
			(string) file_get_contents($list_file),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$this->assertIsArray($plugin_files);
		$this->assertSame(
			$plugin_files,
			get_option('active_plugins')
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$included_files = array_map(
			static fn (string $file): string =>
				(string) realpath($file),
			get_included_files()
		);

		foreach ($plugin_files as $plugin_file) {
			$expected_file = realpath(
				$project_root .
				'/wp-content/plugins/' .
				$plugin_file
			);

			$this->assertNotFalse(
				$expected_file,
				sprintf(
					'Plugin file does not exist: %s',
					$plugin_file
				)
			);

			$this->assertTrue(
				is_plugin_active($plugin_file),
				sprintf(
					'Plugin is not marked active: %s',
					$plugin_file
				)
			);

			$this->assertContains(
				$expected_file,
				$included_files,
				sprintf(
					'Plugin was not loaded: %s',
					$plugin_file
				)
			);

			$this->assertGreaterThan(
				0,
				did_action('activate_' . $plugin_file),
				sprintf(
					'Plugin activation hook was not run: %s',
					$plugin_file
				)
			);
		}
	}
}
