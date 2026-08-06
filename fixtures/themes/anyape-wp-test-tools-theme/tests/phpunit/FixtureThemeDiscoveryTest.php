<?php
/**
 * Verify theme fixture test discovery.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

use AnyapeWPTestTools\IntegrationTestCase;

/** Verifies discovery of tests supplied by a theme fixture. */
final class FixtureThemeDiscoveryTest extends IntegrationTestCase {

	/** Verify that the theme fixture test and bootstrap were discovered. */
	public function test_fixture_theme_test_was_discovered(): void {
		$this->assertTrue(
			defined( 'ANYAPE_WP_TEST_TOOLS_FIXTURE_THEME_BOOTSTRAP_LOADED' )
		);
		$this->assertSame( 'anyape-wp-test-tools-theme', get_stylesheet() );
	}
}
