<?php

declare(strict_types=1);

final class WordPressEnvironmentTest extends WP_UnitTestCase
{
	public function test_wordpress_test_environment_is_loaded(): void
	{
		global $wpdb;

		$this->assertTrue(function_exists('get_option'));
		$this->assertSame('wp_tests', DB_NAME);
		$this->assertSame('wptests_', $wpdb->prefix);

		update_option('shared_test_environment_check', 'working');

		$this->assertSame(
			'working',
			get_option('shared_test_environment_check')
		);
	}
}
