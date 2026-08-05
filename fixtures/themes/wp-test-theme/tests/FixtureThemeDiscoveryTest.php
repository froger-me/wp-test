<?php

declare(strict_types=1);

use WpTest\IntegrationTestCase;

final class FixtureThemeDiscoveryTest extends IntegrationTestCase
{
	public function test_fixture_theme_test_was_discovered(): void
	{
		$this->assertTrue(
			defined('WP_TEST_FIXTURE_THEME_BOOTSTRAP_LOADED')
		);
		$this->assertSame('wp-test-theme', get_stylesheet());
	}
}
