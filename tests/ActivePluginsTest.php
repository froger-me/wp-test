<?php

declare(strict_types=1);

use WpTest\IntegrationTestCase;
use WpTest\Manifest;

final class ActivePluginsTest extends IntegrationTestCase
{
	public function test_selected_plugins_are_active_loaded_and_activated(): void
	{
		$manifest = Manifest::fromFile(
			dirname(__DIR__) . '/runtime/manifest.json'
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$includedFiles = array_map(
			static fn (string $file): string =>
				(string) realpath($file),
			get_included_files()
		);

		foreach ($manifest->plugins() as $plugin) {
			$pluginFile = (string) $plugin['file'];
			$runtimeFile = WP_PLUGIN_DIR . '/' . $pluginFile;
			$realFile = realpath($runtimeFile);

			$this->assertNotFalse(
				$realFile,
				sprintf('Selected plugin file does not exist: %s', $pluginFile)
			);
			$this->assertTrue(
				is_plugin_active($pluginFile),
				sprintf('Selected plugin is not active: %s', $pluginFile)
			);
			$this->assertContains(
				$realFile,
				$includedFiles,
				sprintf('Selected plugin was not loaded: %s', $pluginFile)
			);
			$this->assertGreaterThan(
				0,
				did_action('activate_' . $pluginFile),
				sprintf('Activation hook was not run: %s', $pluginFile)
			);
		}
	}

	public function test_selected_themes_are_available_in_runtime_overlay(): void
	{
		$manifest = Manifest::fromFile(
			dirname(__DIR__) . '/runtime/manifest.json'
		);

		foreach ($manifest->themes() as $theme) {
			$this->assertDirectoryExists(
				WP_CONTENT_DIR . '/themes/' . (string) $theme['slug']
			);
		}
	}
}
