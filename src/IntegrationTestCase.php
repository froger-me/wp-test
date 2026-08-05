<?php

declare(strict_types=1);

namespace WpTest;

use WP_REST_Request;
use WP_REST_Response;

abstract class IntegrationTestCase extends \WP_UnitTestCase
{
	/** @var list<string> */
	private array $trackedOptions = [];

	/** @var list<string> */
	private array $trackedFiles = [];

	protected function setUp(): void
	{
		parent::setUp();
		HttpMock::reset();
		MailCapture::reset();
	}

	protected function tearDown(): void
	{
		$this->cleanupTrackedState();
		HttpMock::reset();
		MailCapture::reset();
		parent::tearDown();
	}

	/** @param mixed $value */
	protected function setTrackedOption(string $name, $value, bool $autoload = false): void
	{
		if (! in_array($name, $this->trackedOptions, true)) {
			$this->trackedOptions[] = $name;
		}

		update_option($name, $value, $autoload);
	}

	protected function trackFile(string $path): void
	{
		if (! in_array($path, $this->trackedFiles, true)) {
			$this->trackedFiles[] = $path;
		}
	}

	protected function createAdministrator(): int
	{
		$userId = self::factory()->user->create(['role' => 'administrator']);
		wp_set_current_user($userId);
		return $userId;
	}

	protected function assertTableExists(string $table): void
	{
		global $wpdb;
		$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		$this->assertSame($table, $found, sprintf('Expected database table to exist: %s', $table));
	}

	protected function assertTableHasColumn(string $table, string $column): void
	{
		global $wpdb;
		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);
		$this->assertContains($column, $columns, sprintf('Expected table %s to contain column %s.', $table, $column));
	}

	protected function assertCronEventScheduled(string $hook): void
	{
		$this->assertNotFalse(wp_next_scheduled($hook), sprintf('Expected cron hook to be scheduled: %s', $hook));
	}

	protected function assertCronEventNotScheduled(string $hook): void
	{
		$this->assertFalse(wp_next_scheduled($hook), sprintf('Expected cron hook not to be scheduled: %s', $hook));
	}

	/** @param array<string, mixed> $parameters */
	protected function restRequest(string $method, string $route, array $parameters = []): WP_REST_Response
	{
		$request = new WP_REST_Request($method, $route);
		$request->set_query_params($parameters);
		return rest_ensure_response(rest_do_request($request));
	}

	protected function createUploadFile(string $name, string $contents): string
	{
		$uploads = wp_upload_dir();
		$this->assertFalse($uploads['error']);
		$path = trailingslashit($uploads['path']) . basename($name);
		if (! is_dir(dirname($path))) {
			wp_mkdir_p(dirname($path));
		}
		file_put_contents($path, $contents);
		$this->trackFile($path);
		return $path;
	}

	protected function enableMailCapture(): void
	{
		MailCapture::enable();
	}

	/** @return list<array<string, mixed>> */
	protected function capturedMail(): array
	{
		return MailCapture::messages();
	}

	protected function activatePlugin(string $plugin, bool $networkWide = false): void
	{
		Lifecycle::activate($plugin, $networkWide);
	}

	protected function deactivatePlugin(string $plugin, bool $networkWide = false): void
	{
		Lifecycle::deactivate($plugin, $networkWide);
	}

	protected function uninstallPlugin(string $plugin): void
	{
		Lifecycle::uninstall($plugin);
	}

	protected function cleanupTrackedState(): void
	{
		foreach ($this->trackedOptions as $option) {
			delete_option($option);
		}
		foreach ($this->trackedFiles as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		$this->trackedOptions = [];
		$this->trackedFiles   = [];
	}
}
