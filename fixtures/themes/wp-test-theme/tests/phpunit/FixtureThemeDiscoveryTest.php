<?php
/**
 * Verify theme fixture test discovery.
 *
 * @package WpTest
 */

declare(strict_types=1);

use WpTest\IntegrationTestCase;

/** Verifies discovery of tests supplied by a theme fixture. */
final class FixtureThemeDiscoveryTest extends IntegrationTestCase {

	/** Verify that the theme fixture test and bootstrap were discovered. */
	public function test_fixture_theme_test_was_discovered(): void {
		$this->assertTrue(
			defined( 'WP_TEST_FIXTURE_THEME_BOOTSTRAP_LOADED' )
		);
		$this->assertSame( 'wp-test-theme', get_stylesheet() );
	}
}
