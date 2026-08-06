<?php
/**
 * Generated manifest tests.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

use AnyapeWPTestTools\IntegrationTestCase;
use AnyapeWPTestTools\Manifest;
use AnyapeWPTestTools\ManifestBuilder;

/** Tests generated manifest and PHPUnit configuration behavior. */
final class ManifestTest extends IntegrationTestCase {

	/** Verifies generated testsuites and selected extension paths. */
	public function test_generated_phpunit_configuration_contains_harness_and_selected_tests(): void {
		$tool     = dirname( __DIR__ );
		$manifest = Manifest::from_file( $tool . '/runtime/manifest.json' );
		$config   = (string) file_get_contents( $tool . '/runtime/phpunit.xml' );

		$this->assertStringContainsString( '<testsuite name="Harness">', $config );
		foreach ( array_merge( $manifest->plugins(), $manifest->themes() ) as $extension ) {
			if ( empty( $extension['tests_enabled'] ) || empty( $extension['tests_path'] ) ) {
				continue;
			}
			$this->assertStringContainsString( (string) $extension['tests_path'], $config );
		}
	}

	/** Verifies optional group exclusions for the requested mode. */
	public function test_optional_groups_match_requested_modes(): void {
		$config = (string) file_get_contents( dirname( __DIR__ ) . '/runtime/phpunit.xml' );

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
		$tool     = dirname( __DIR__ );
		$manifest = Manifest::from_file( $tool . '/runtime/manifest.json' );
		$config   = (string) file_get_contents( $tool . '/runtime/phpunit.xml' );

		if ( $manifest->profile() === 'harness' ) {
			$this->assertStringNotContainsString( '<group>harness-fixture</group>', $config );
			return;
		}
		$this->assertStringContainsString( '<group>harness-fixture</group>', $config );
	}

	/** A later duplicate enables tests without moving the first entry. */
	public function test_duplicate_extensions_update_the_first_entry_in_place(): void {
		$method = new ReflectionMethod( ManifestBuilder::class, 'deduplicate_extensions' );
		$method->setAccessible( true );
		$extensions = array(
			$this->extension_entry( 'plugin', 'alpha' ),
			$this->extension_entry( 'theme', 'alpha' ),
			$this->extension_entry( 'plugin', 'alpha', '/plugin-tests', '/plugin-bootstrap.php' ),
			$this->extension_entry( 'theme', 'alpha', '/theme-tests', '/theme-bootstrap.php' ),
			$this->extension_entry( 'plugin', 'beta', '/beta-tests' ),
		);

		$result = $method->invoke( new ManifestBuilder( '/project', '/tool' ), $extensions );
		$keys   = array_map(
			static fn ( array $item ): string => $item['type'] . ':' . $item['slug'],
			$result
		);

		$this->assertSame( array( 'plugin:alpha', 'theme:alpha', 'plugin:beta' ), $keys );
		$this->assertSame( '/plugin-tests', $result[0]['tests_path'] );
		$this->assertSame( '/plugin-bootstrap.php', $result[0]['bootstrap'] );
		$this->assertSame( '/theme-tests', $result[1]['tests_path'] );
		$this->assertSame( '/theme-bootstrap.php', $result[1]['bootstrap'] );
	}

	/** Build one duplicate-handling fixture entry. */
	private function extension_entry(
		string $type,
		string $slug,
		?string $tests_path = null,
		?string $bootstrap = null
	): array {
		return array(
			'type'          => $type,
			'slug'          => $slug,
			'tests_enabled' => null !== $tests_path,
			'tests_path'    => $tests_path,
			'bootstrap'     => $bootstrap,
		);
	}
}
