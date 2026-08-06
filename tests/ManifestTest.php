<?php
/**
 * Generated manifest tests.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests create and remove private fixture projects.

use AnyapeWPTestTools\IntegrationTestCase;
use AnyapeWPTestTools\Manifest;
use AnyapeWPTestTools\ManifestBuilder;

/** Tests generated manifest and PHPUnit configuration behavior. */
final class ManifestTest extends IntegrationTestCase {

	/** Private project used for manifest-builder behavior tests. */
	private string $manifest_project;

	/** Create a private project for each test. */
	public function setUp(): void {
		parent::setUp();
		$this->manifest_project = sys_get_temp_dir() . '/anyape-wp-test-tools-manifest-' . wp_generate_uuid4();
		mkdir( $this->manifest_project . '/wp-content/plugins', 0777, true );
		mkdir( $this->manifest_project . '/wp-content/themes', 0777, true );
	}

	/** Remove only the private manifest project. */
	public function tearDown(): void {
		$this->remove_directory( $this->manifest_project );
		parent::tearDown();
	}

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

	/** Duplicate plugins and themes keep their first position and accept later test paths. */
	public function test_duplicate_extensions_keep_first_position_and_enable_later_tests(): void {
		$builder = new ManifestBuilder( $this->manifest_project, dirname( __DIR__ ) );
		$method  = new ReflectionMethod( $builder, 'deduplicate_extensions' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$builder,
			array(
				array(
					'type'          => 'plugin',
					'slug'          => 'first-plugin',
					'tests_enabled' => false,
					'tests_path'    => null,
					'bootstrap'     => null,
				),
				array(
					'type'          => 'plugin',
					'slug'          => 'second-plugin',
					'tests_enabled' => true,
					'tests_path'    => '/second-plugin-tests',
					'bootstrap'     => '/second-plugin-bootstrap.php',
				),
				array(
					'type'          => 'plugin',
					'slug'          => 'first-plugin',
					'tests_enabled' => true,
					'tests_path'    => '/first-plugin-tests',
					'bootstrap'     => '/first-plugin-bootstrap.php',
				),
				array(
					'type'          => 'theme',
					'slug'          => 'first-theme',
					'tests_enabled' => false,
					'tests_path'    => null,
					'bootstrap'     => null,
				),
				array(
					'type'          => 'theme',
					'slug'          => 'second-theme',
					'tests_enabled' => true,
					'tests_path'    => '/second-theme-tests',
					'bootstrap'     => '/second-theme-bootstrap.php',
				),
				array(
					'type'          => 'theme',
					'slug'          => 'first-theme',
					'tests_enabled' => true,
					'tests_path'    => '/first-theme-tests',
					'bootstrap'     => '/first-theme-bootstrap.php',
				),
			)
		);

		$this->assertSame(
			array( 'plugin:first-plugin', 'plugin:second-plugin', 'theme:first-theme', 'theme:second-theme' ),
			array_map(
				static fn ( array $extension ): string => $extension['type'] . ':' . $extension['slug'],
				$result
			)
		);
		$this->assertTrue( $result[0]['tests_enabled'] );
		$this->assertSame( '/first-plugin-tests', $result[0]['tests_path'] );
		$this->assertSame( '/first-plugin-bootstrap.php', $result[0]['bootstrap'] );
		$this->assertTrue( $result[2]['tests_enabled'] );
		$this->assertSame( '/first-theme-tests', $result[2]['tests_path'] );
		$this->assertSame( '/first-theme-bootstrap.php', $result[2]['bootstrap'] );
	}

	/** Focused plugin and theme profiles keep dependency and parent selection behavior. */
	public function test_focused_profiles_keep_current_selection_behavior(): void {
		$this->create_plugin( 'dependency' );
		$this->create_plugin( 'target' );
		$this->create_theme( 'parent' );
		$this->create_theme( 'child', 'parent' );

		$builder = new ManifestBuilder(
			$this->manifest_project,
			dirname( __DIR__ ),
			array(
				'plugin_dependencies' => array( 'target' => array( 'dependency' ) ),
				'theme_dependencies'  => array( 'child' => array( 'dependency' ) ),
			)
		);

		$plugin = $builder->build( 'plugin', 'target', array(), 'child', 'parent' );
		$this->assertSame( array( 'dependency', 'target' ), array_column( $plugin['plugins'], 'slug' ) );
		$this->assertFalse( $plugin['plugins'][0]['tests_enabled'] );
		$this->assertTrue( $plugin['plugins'][1]['tests_enabled'] );
		$this->assertSame( array( 'parent', 'child' ), array_column( $plugin['themes'], 'slug' ) );
		$this->assertFalse( $plugin['themes'][0]['tests_enabled'] );
		$this->assertFalse( $plugin['themes'][1]['tests_enabled'] );

		$theme = $builder->build( 'theme', 'child', array(), 'child', 'parent' );
		$this->assertSame( array( 'dependency' ), array_column( $theme['plugins'], 'slug' ) );
		$this->assertFalse( $theme['plugins'][0]['tests_enabled'] );
		$this->assertSame( array( 'parent', 'child' ), array_column( $theme['themes'], 'slug' ) );
		$this->assertFalse( $theme['themes'][0]['tests_enabled'] );
		$this->assertTrue( $theme['themes'][1]['tests_enabled'] );
		$this->assertSame( 'child', $theme['stylesheet'] );
		$this->assertSame( 'parent', $theme['template'] );
	}

	/** Harness and multisite manifests keep their current profile values. */
	public function test_harness_and_multisite_values_remain_unchanged(): void {
		$this->create_plugin( 'active' );
		$this->create_theme( 'parent' );
		$this->create_theme( 'child', 'parent' );
		$builder = new ManifestBuilder( $this->manifest_project, dirname( __DIR__ ) );

		$harness = $builder->build( 'harness', null, array(), '', '' );
		$this->assertSame( 'harness', $harness['profile'] );
		$this->assertFalse( $harness['multisite'] );
		$this->assertSame( array( 'anyape-wp-test-tools-lifecycle' ), array_column( $harness['plugins'], 'slug' ) );
		$this->assertSame( array( 'anyape-wp-test-tools-theme' ), array_column( $harness['themes'], 'slug' ) );

		$multisite = $builder->build( 'multisite', null, array( 'active/active.php' ), 'child', 'parent' );
		$this->assertSame( 'multisite', $multisite['profile'] );
		$this->assertTrue( $multisite['multisite'] );
		$this->assertSame( array( 'active' ), array_column( $multisite['plugins'], 'slug' ) );
		$this->assertTrue( $multisite['plugins'][0]['tests_enabled'] );
		$this->assertSame( array( 'parent', 'child' ), array_column( $multisite['themes'], 'slug' ) );
		$this->assertTrue( $multisite['themes'][0]['tests_enabled'] );
		$this->assertTrue( $multisite['themes'][1]['tests_enabled'] );
	}

	/** Create a plugin with test files in the private project. */
	private function create_plugin( string $slug ): void {
		$directory = $this->manifest_project . '/wp-content/plugins/' . $slug;
		mkdir( $directory . '/tests/phpunit', 0777, true );
		file_put_contents( $directory . '/' . $slug . '.php', "<?php\n/*\nPlugin Name: {$slug}\n*/\n" );
		file_put_contents( $directory . '/tests/phpunit/bootstrap.php', "<?php\n" );
	}

	/** Create a theme with optional parent and test files in the private project. */
	private function create_theme( string $slug, ?string $parent = null ): void {
		$directory = $this->manifest_project . '/wp-content/themes/' . $slug;
		mkdir( $directory . '/tests/phpunit', 0777, true );
		$header = "/*\nTheme Name: {$slug}\n";
		if ( null !== $parent ) {
			$header .= "Template: {$parent}\n";
		}
		file_put_contents( $directory . '/style.css', $header . "*/\n" );
		file_put_contents( $directory . '/tests/phpunit/bootstrap.php', "<?php\n" );
	}

	/** Recursively remove the private manifest project. */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $directory );
	}
}
