<?php

declare(strict_types=1);

use WpTest\IntegrationTestCase;
use WpTest\Manifest;

final class ManifestTest extends IntegrationTestCase
{
	public function test_generated_phpunit_configuration_contains_harness_and_selected_tests(): void
	{
		$toolkit = dirname(__DIR__);
		$manifest = Manifest::fromFile($toolkit . '/runtime/manifest.json');
		$config = (string) file_get_contents($toolkit . '/runtime/phpunit.xml');

		$this->assertStringContainsString(
			'<testsuite name="Harness">',
			$config
		);

		foreach (array_merge($manifest->plugins(), $manifest->themes()) as $extension) {
			if (
				empty($extension['tests_enabled']) ||
				empty($extension['tests_path'])
			) {
				continue;
			}

			$this->assertStringContainsString(
				(string) $extension['tests_path'],
				$config,
				sprintf(
					'Discovered test path is missing for %s %s.',
					$extension['type'],
					$extension['slug']
				)
			);
		}
	}

	public function test_default_run_excludes_destructive_group(): void
	{
		if (getenv('WP_TEST_INCLUDE_DESTRUCTIVE') === '1') {
			$this->markTestSkipped('Destructive group was explicitly enabled.');
		}

		$config = (string) file_get_contents(
			dirname(__DIR__) . '/runtime/phpunit.xml'
		);

		$this->assertStringContainsString(
			'<group>destructive</group>',
			$config
		);
	}

	public function test_fixture_self_tests_are_limited_to_harness_profile(): void
	{
		$toolkit = dirname(__DIR__);
		$manifest = Manifest::fromFile($toolkit . '/runtime/manifest.json');
		$config = (string) file_get_contents($toolkit . '/runtime/phpunit.xml');

		if ($manifest->profile() === 'harness') {
			$this->assertStringNotContainsString(
				'<group>harness-fixture</group>',
				$config
			);
			return;
		}

		$this->assertStringContainsString(
			'<group>harness-fixture</group>',
			$config
		);
	}
}
