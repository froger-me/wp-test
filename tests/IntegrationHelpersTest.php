<?php

declare(strict_types=1);

use WpTest\IntegrationTestCase;

final class IntegrationHelpersTest extends IntegrationTestCase
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

	public function test_rest_authorization_admin_fixture_uploads_and_mail_capture(): void
	{
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if (! is_plugin_active(self::PLUGIN)) {
			$this->activatePlugin(self::PLUGIN);
		}

		do_action('rest_api_init');

		wp_set_current_user(0);

		$forbidden = $this->restRequest(
			'GET',
			'/wp-test/v1/protected'
		);
		$this->assertSame(401, $forbidden->get_status());

		$this->createAdministrator();

		$allowed = $this->restRequest(
			'GET',
			'/wp-test/v1/protected'
		);
		$this->assertSame(200, $allowed->get_status());
		$this->assertSame(['ok' => true], $allowed->get_data());

		$file = $this->createUploadFile(
			'wp-test-helper.txt',
			'fixture'
		);
		$this->assertFileExists($file);

		$this->enableMailCapture();
		$this->assertTrue(
			wp_mail(
				'recipient@example.test',
				'Fixture message',
				'Message body'
			)
		);
		$this->assertCount(1, $this->capturedMail());
	}

	public function test_tracked_options_can_be_cleaned_explicitly(): void
	{
		$this->setTrackedOption('wp_test_tracked_option', 'value');
		$this->assertSame('value', get_option('wp_test_tracked_option'));

		$this->cleanupTrackedState();

		$this->assertFalse(get_option('wp_test_tracked_option', false));
	}
}
