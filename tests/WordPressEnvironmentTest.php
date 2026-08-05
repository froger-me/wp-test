<?php

declare(strict_types=1);

use WpTest\IntegrationTestCase;

final class WordPressEnvironmentTest extends IntegrationTestCase
{
	public function test_wordpress_test_environment_is_safely_loaded(): void
	{
		global $wpdb;

		$this->assertTrue(function_exists('get_option'));
		$this->assertSame('wp_tests', DB_NAME);
		$this->assertSame('db', DB_HOST);
		$this->assertSame('wptests_', $wpdb->prefix);
		$this->assertStringContainsString(
			'/.test-tools/runtime/wp-content',
			WP_CONTENT_DIR
		);

		$this->setTrackedOption(
			'shared_test_environment_check',
			'working'
		);

		$this->assertSame(
			'working',
			get_option('shared_test_environment_check')
		);
	}
}
