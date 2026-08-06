<?php
/**
 * Generated manifest tests.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

use AnyapeWPTestTools\IntegrationTestCase;
use AnyapeWPTestTools\Manifest;

/** Tests generated manifest and PHPUnit configuration behavior. */
final class ManifestTest extends IntegrationTestCase {

	/** Verifies generated testsuites and selected extension paths. */
	public function test_generated_phpunit_configuration_contains_harness_and_selected_tests(): void {
		$anyape_wp_test_tools = dirname( __DIR__ );
		$manifest             = Manifest::from_file( $anyape_wp_test_tools . '/runtime/manifest.json' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Assert a local generated file's contents.
		$config = (string) file_get_contents( $anyape_wp_test_tools . '/runtime/phpunit.xml' );

		$this->assertStringContainsString(
			'<testsuite name="Harness">',
			$config
		);

		foreach ( array_merge( $manifest->plugins(), $manifest->themes() ) as $extension ) {
			if (
				empty( $extension['tests_enabled'] ) ||
				empty( $extension['tests_path'] )
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

	/** Verifies optional group exclusions for the requested mode. */
	public function test_optional_groups_match_requested_modes(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Assert a local generated file's contents.
		$config = (string) file_get_contents(
			dirname( __DIR__ ) . '/runtime/phpunit.xml'
		);

		if ( getenv( 'ANYAPE_WP_TEST_TOOLS_INCLUDE_DESTRUCTIVE' ) === '1' ) {
			$this->assertStringNotContainsString( '<group>destructive</group>', $config );
		} else {
			$this->assertStringContainsString( '<group>destructive</group>', $config );
		}

		if ( getenv( 'ANYAPE_WP_TEST_TOOLS_COVERAGE' ) === '1' ) {
			$this->assertStringNotContainsString( '<group>coverage</group>', $config );
		} else {
			$this->assertStringContainsString( '<group>coverage</group>', $config );
		}
	}

	/** Verifies that fixture self-tests are limited to the harness profile. */
	public function test_fixture_self_tests_are_limited_to_harness_profile(): void {
		$anyape_wp_test_tools = dirname( __DIR__ );
		$manifest             = Manifest::from_file( $anyape_wp_test_tools . '/runtime/manifest.json' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Assert a local generated file's contents.
		$config = (string) file_get_contents( $anyape_wp_test_tools . '/runtime/phpunit.xml' );

		if ( $manifest->profile() === 'harness' ) {
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
