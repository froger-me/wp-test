<?php
/**
 * Build runtime test manifests from project configuration.
 *
 * @package WpTest
 */

declare(strict_types=1);

namespace WpTest;

use RuntimeException;

/** Builds an isolated test manifest from project state and configuration. */
final class ManifestBuilder {

	/**
	 * Project root path.
	 *
	 * @var string
	 */
	private string $project_root;

	/**
	 * Toolkit root path.
	 *
	 * @var string
	 */
	private string $toolkit_root;

	/**
	 * Project plugin directory.
	 *
	 * @var string
	 */
	private string $plugins_dir;

	/**
	 * Project theme directory.
	 *
	 * @var string
	 */
	private string $themes_dir;

	/**
	 * Effective toolkit configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $configuration;

	/**
	 * Create a manifest builder.
	 *
	 * @param string               $project_root  Project root path.
	 * @param string               $toolkit_root  Toolkit root path.
	 * @param array<string, mixed> $configuration Project configuration.
	 */
	public function __construct( string $project_root, string $toolkit_root, array $configuration = array() ) {
		$this->project_root  = rtrim( $project_root, '/' );
		$this->toolkit_root  = rtrim( $toolkit_root, '/' );
		$this->plugins_dir   = $this->project_root . '/wp-content/plugins';
		$this->themes_dir    = $this->project_root . '/wp-content/themes';
		$this->configuration = array_replace_recursive(
			array(
				'include_plugins'     => array(),
				'exclude_plugins'     => array(),
				'include_themes'      => array(),
				'exclude_themes'      => array(),
				'plugin_dependencies' => array(),
				'theme_dependencies'  => array(),
				'bootstrap'           => null,
			),
			$configuration
		);
	}

	/**
	 * Build a runtime manifest.
	 *
	 * @param string      $profile             Test profile.
	 * @param string|null $target              Optional focused extension slug.
	 * @param array       $active_plugin_files Active plugin basenames.
	 * @param string      $stylesheet          Active stylesheet slug.
	 * @param string      $template            Active template slug.
	 * @return array<string, mixed>
	 * @throws RuntimeException When configuration or project state is invalid.
	 */
	public function build( string $profile, ?string $target, array $active_plugin_files, string $stylesheet, string $template ): array {
		$profile = trim( $profile );
		$target  = null !== $target ? trim( $target ) : null;
		$plugins = array();
		$themes  = array();

		switch ( $profile ) {
			case 'default':
			case 'multisite':
				foreach ( $active_plugin_files as $plugin_file ) {
					$plugins[] = $this->plugin_from_file( $plugin_file, true );
				}
				foreach ( $this->string_list( 'include_plugins' ) as $slug ) {
					$plugins[] = $this->plugin_from_slug( $slug, true );
				}
				$themes = $this->working_themes( $stylesheet, $template, true );
				foreach ( $this->string_list( 'include_themes' ) as $slug ) {
					$themes[] = $this->theme_from_slug( $slug, true );
				}
				break;

			case 'plugin':
				if ( null === $target || '' === $target ) {
					throw new RuntimeException( 'The plugin profile requires a plugin slug.' );
				}
				foreach ( $this->dependency_slugs( 'plugin_dependencies', $target ) as $slug ) {
					$plugins[] = $this->plugin_from_slug( $slug, false );
				}
				$plugins[] = $this->plugin_from_slug( $target, true );
				$themes    = $this->working_themes( $stylesheet, $template, false );
				break;

			case 'theme':
				if ( null === $target || '' === $target ) {
					throw new RuntimeException( 'The theme profile requires a theme slug.' );
				}
				foreach ( $this->dependency_slugs( 'theme_dependencies', $target ) as $slug ) {
					$plugins[] = $this->plugin_from_slug( $slug, false );
				}
				$themes     = $this->theme_with_parent( $target, true );
				$stylesheet = $target;
				$template   = count( $themes ) > 1 ? (string) $themes[0]['slug'] : $target;
				break;

			case 'harness':
				$plugins[]  = $this->fixture_plugin();
				$themes[]   = $this->fixture_theme();
				$stylesheet = 'wp-test-theme';
				$template   = 'wp-test-theme';
				break;

			default:
				throw new RuntimeException( sprintf( 'Unknown test profile: %s', $profile ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the invalid CLI profile.
		}

		$plugins = $this->deduplicate_extensions( $this->filter_excluded( $plugins, 'exclude_plugins' ) );
		$themes  = $this->deduplicate_extensions( $this->filter_excluded( $themes, 'exclude_themes' ) );

		if ( 'plugin' === $profile && ! in_array( $target, array_column( $plugins, 'slug' ), true ) ) {
			throw new RuntimeException( sprintf( 'Focused plugin is excluded by configuration: %s', $target ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the requested slug.
		}
		if ( 'theme' === $profile && ! in_array( $target, array_column( $themes, 'slug' ), true ) ) {
			throw new RuntimeException( sprintf( 'Focused theme is excluded by configuration: %s', $target ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the requested slug.
		}
		if ( array() === $themes ) {
			$themes = $this->working_themes( $stylesheet, $template, false );
		}

		$site_bootstrap = $this->configuration['bootstrap'] ?? null;
		if ( is_string( $site_bootstrap ) && '' !== $site_bootstrap ) {
			$site_bootstrap = $this->resolve_project_path( $site_bootstrap );
			if ( ! is_file( $site_bootstrap ) ) {
				throw new RuntimeException( sprintf( 'Configured site bootstrap does not exist: %s', $site_bootstrap ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the exact local path.
			}
		} else {
			$site_bootstrap = null;
		}

		return array(
			'profile'        => $profile,
			'target'         => $target,
			'multisite'      => 'multisite' === $profile,
			'plugins'        => $plugins,
			'themes'         => $themes,
			'stylesheet'     => $stylesheet,
			'template'       => $template,
			'site_bootstrap' => $site_bootstrap,
		);
	}

	/**
	 * Build entries for the working themes.
	 *
	 * @param string $stylesheet   Stylesheet slug.
	 * @param string $template     Template slug.
	 * @param bool   $tests_enabled Whether tests are enabled.
	 * @return list<array<string, mixed>>
	 */
	private function working_themes( string $stylesheet, string $template, bool $tests_enabled ): array {
		$themes = array();
		if ( '' !== $template ) {
			$themes[] = $this->theme_from_slug( $template, $tests_enabled );
		}
		if ( '' !== $stylesheet && $template !== $stylesheet ) {
			$themes[] = $this->theme_from_slug( $stylesheet, $tests_enabled );
		}
		return $themes;
	}

	/**
	 * Build a theme entry with its parent.
	 *
	 * @param string $slug          Theme slug.
	 * @param bool   $tests_enabled Whether tests are enabled.
	 * @return list<array<string, mixed>>
	 */
	private function theme_with_parent( string $slug, bool $tests_enabled ): array {
		$theme  = $this->theme_from_slug( $slug, $tests_enabled );
		$parent = $this->read_theme_parent( (string) $theme['source_path'] );
		if ( null === $parent || $slug === $parent ) {
			return array( $theme );
		}
		return array( $this->theme_from_slug( $parent, false ), $theme );
	}

	/**
	 * Build a plugin entry from its basename.
	 *
	 * @param string $plugin_file  Plugin basename.
	 * @param bool   $tests_enabled Whether tests are enabled.
	 * @return array<string, mixed>
	 * @throws RuntimeException When the plugin path is invalid.
	 */
	private function plugin_from_file( string $plugin_file, bool $tests_enabled ): array {
		$plugin_file = ltrim( trim( $plugin_file ), '/' );
		if ( '' === $plugin_file || str_contains( $plugin_file, '..' ) ) {
			throw new RuntimeException( sprintf( 'Invalid plugin file in active plugin list: %s', $plugin_file ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the invalid plugin basename.
		}
		$parts = explode( '/', $plugin_file );
		$slug  = count( $parts ) > 1 ? $parts[0] : pathinfo( $plugin_file, PATHINFO_FILENAME );
		$this->assert_slug( $slug, 'plugin' );
		$full_path = $this->plugins_dir . '/' . $plugin_file;
		if ( ! is_file( $full_path ) ) {
			throw new RuntimeException( sprintf( 'Plugin file does not exist: %s', $full_path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the exact local path.
		}
		$is_single_file = count( $parts ) === 1;
		$source_path    = $is_single_file ? $full_path : $this->plugins_dir . '/' . $slug;
		$tests_path     = $is_single_file ? null : $source_path . '/tests/phpunit';
		$bootstrap      = null !== $tests_path ? $tests_path . '/bootstrap.php' : null;
		return array(
			'type'          => 'plugin',
			'slug'          => $slug,
			'file'          => $plugin_file,
			'source_path'   => $source_path,
			'link_type'     => $is_single_file ? 'file' : 'directory',
			'tests_enabled' => $tests_enabled,
			'tests_path'    => null !== $tests_path && is_dir( $tests_path ) ? $tests_path : null,
			'bootstrap'     => null !== $bootstrap && is_file( $bootstrap ) ? $bootstrap : null,
		);
	}

	/**
	 * Build a plugin entry from its slug.
	 *
	 * @param string $slug          Plugin slug.
	 * @param bool   $tests_enabled Whether tests are enabled.
	 * @return array<string, mixed>
	 */
	private function plugin_from_slug( string $slug, bool $tests_enabled ): array {
		$this->assert_slug( $slug, 'plugin' );
		return $this->plugin_from_file( $this->find_plugin_main_file( $slug ), $tests_enabled );
	}

	/**
	 * Find a plugin's main file.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Plugin basename.
	 * @throws RuntimeException When the plugin cannot be found.
	 */
	private function find_plugin_main_file( string $slug ): string {
		$single_file = $this->plugins_dir . '/' . $slug . '.php';
		if ( is_file( $single_file ) ) {
			return $slug . '.php';
		}
		$directory = $this->plugins_dir . '/' . $slug;
		if ( ! is_dir( $directory ) ) {
			throw new RuntimeException( sprintf( 'Plugin slug does not exist: %s', $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the requested slug.
		}
		$matched_candidates = glob( $directory . '/*.php' );
		$candidates         = false !== $matched_candidates ? $matched_candidates : array();
		sort( $candidates, SORT_STRING );
		foreach ( $candidates as $candidate ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a bounded local plugin header before WordPress loads.
			$header = (string) file_get_contents( $candidate, false, null, 0, 8192 );
			if ( preg_match( '/^[ \t*#@]*Plugin Name\s*:/mi', $header ) === 1 ) {
				return $slug . '/' . basename( $candidate );
			}
		}
		throw new RuntimeException( sprintf( 'Could not find a main plugin file for slug: %s', $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the requested slug.
	}

	/**
	 * Build a theme entry from its slug.
	 *
	 * @param string $slug          Theme slug.
	 * @param bool   $tests_enabled Whether tests are enabled.
	 * @return array<string, mixed>
	 * @throws RuntimeException When the theme path is invalid.
	 */
	private function theme_from_slug( string $slug, bool $tests_enabled ): array {
		$this->assert_slug( $slug, 'theme' );
		$source_path = $this->themes_dir . '/' . $slug;
		if ( ! is_dir( $source_path ) ) {
			throw new RuntimeException( sprintf( 'Theme slug does not exist: %s', $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the requested slug.
		}
		$tests_path = $source_path . '/tests/phpunit';
		$bootstrap  = $tests_path . '/bootstrap.php';
		return array(
			'type'          => 'theme',
			'slug'          => $slug,
			'source_path'   => $source_path,
			'link_type'     => 'directory',
			'tests_enabled' => $tests_enabled,
			'tests_path'    => is_dir( $tests_path ) ? $tests_path : null,
			'bootstrap'     => is_file( $bootstrap ) ? $bootstrap : null,
		);
	}

	/**
	 * Read a theme's parent slug.
	 *
	 * @param string $theme_path Theme directory.
	 * @return string|null Parent slug.
	 */
	private function read_theme_parent( string $theme_path ): ?string {
		$style_file = rtrim( $theme_path, '/' ) . '/style.css';
		if ( ! is_file( $style_file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a bounded local theme header before WordPress loads.
		$header = (string) file_get_contents( $style_file, false, null, 0, 8192 );
		if ( preg_match( '/^[ \t*#@]*Template\s*:\s*(.+)$/mi', $header, $matches ) !== 1 ) {
			return null;
		}
		$parent = trim( $matches[1] );
		return '' !== $parent ? $parent : null;
	}

	/**
	 * Build the plugin fixture entry.
	 *
	 * @return array<string, mixed>
	 */
	private function fixture_plugin(): array {
		$source_path = $this->toolkit_root . '/fixtures/plugins/wp-test-lifecycle';
		return array(
			'type'          => 'plugin',
			'slug'          => 'wp-test-lifecycle',
			'file'          => 'wp-test-lifecycle/wp-test-lifecycle.php',
			'source_path'   => $source_path,
			'link_type'     => 'directory',
			'tests_enabled' => true,
			'tests_path'    => $source_path . '/tests/phpunit',
			'bootstrap'     => is_file( $source_path . '/tests/phpunit/bootstrap.php' ) ? $source_path . '/tests/phpunit/bootstrap.php' : null,
		);
	}

	/**
	 * Build the theme fixture entry.
	 *
	 * @return array<string, mixed>
	 */
	private function fixture_theme(): array {
		$source_path = $this->toolkit_root . '/fixtures/themes/wp-test-theme';
		return array(
			'type'          => 'theme',
			'slug'          => 'wp-test-theme',
			'source_path'   => $source_path,
			'link_type'     => 'directory',
			'tests_enabled' => true,
			'tests_path'    => $source_path . '/tests/phpunit',
			'bootstrap'     => is_file( $source_path . '/tests/phpunit/bootstrap.php' ) ? $source_path . '/tests/phpunit/bootstrap.php' : null,
		);
	}

	/**
	 * Remove configured extensions from a list.
	 *
	 * @param list<array<string, mixed>> $extensions Extension entries.
	 * @param string                     $key        Exclusion configuration key.
	 * @return list<array<string, mixed>>
	 */
	private function filter_excluded( array $extensions, string $key ): array {
		$excluded = array_fill_keys( $this->string_list( $key ), true );
		return array_values( array_filter( $extensions, static fn ( array $extension ): bool => ! isset( $excluded[ (string) $extension['slug'] ] ) ) );
	}

	/**
	 * Deduplicate extension entries.
	 *
	 * @param list<array<string, mixed>> $extensions Extension entries.
	 * @return list<array<string, mixed>>
	 */
	private function deduplicate_extensions( array $extensions ): array {
		$result = array();
		$seen   = array();
		foreach ( $extensions as $extension ) {
			$key = (string) $extension['type'] . ':' . (string) $extension['slug'];
			if ( isset( $seen[ $key ] ) ) {
				if ( ! empty( $extension['tests_enabled'] ) ) {
					foreach ( $result as &$existing ) {
						if ( (string) $existing['type'] === (string) $extension['type'] && (string) $existing['slug'] === (string) $extension['slug'] ) {
							$existing['tests_enabled'] = true;
							$existing['tests_path']    = $extension['tests_path'];
							$existing['bootstrap']     = $extension['bootstrap'];
						}
					}
					unset( $existing );
				}
				continue;
			}
			$seen[ $key ] = true;
			$result[]     = $extension;
		}
		return $result;
	}

	/**
	 * Return a string-list configuration value.
	 *
	 * @param string $key Configuration key.
	 * @return list<string>
	 * @throws RuntimeException When the value is not an array.
	 */
	private function string_list( string $key ): array {
		$value = $this->configuration[ $key ] ?? array();
		if ( ! is_array( $value ) ) {
			throw new RuntimeException( sprintf( 'Configuration key "%s" must be an array.', $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the invalid configuration key.
		}
		return array_values( array_map( static fn ( $item ): string => trim( (string) $item ), array_filter( $value, static fn ( $item ): bool => is_string( $item ) && trim( $item ) !== '' ) ) );
	}

	/**
	 * Return configured dependency slugs for a target.
	 *
	 * @param string $key    Dependency configuration key.
	 * @param string $target Target extension slug.
	 * @return list<string>
	 * @throws RuntimeException When dependency configuration is invalid.
	 */
	private function dependency_slugs( string $key, string $target ): array {
		$map = $this->configuration[ $key ] ?? array();
		if ( ! is_array( $map ) ) {
			throw new RuntimeException( sprintf( 'Configuration key "%s" must be an array.', $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the invalid configuration key.
		}
		$dependencies = $map[ $target ] ?? array();
		if ( ! is_array( $dependencies ) ) {
			throw new RuntimeException( sprintf( 'Dependencies for "%s" must be an array.', $target ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the invalid target slug.
		}
		return array_values( array_map( static fn ( $item ): string => trim( (string) $item ), $dependencies ) );
	}

	/**
	 * Resolve a path relative to the project.
	 *
	 * @param string $path Configured path.
	 * @return string Absolute path.
	 */
	private function resolve_project_path( string $path ): string {
		return str_starts_with( $path, '/' ) ? $path : $this->project_root . '/' . ltrim( $path, '/' );
	}

	/**
	 * Validate an extension slug.
	 *
	 * @param string $slug Extension slug.
	 * @param string $type Extension type.
	 * @throws RuntimeException When the slug is invalid.
	 */
	private function assert_slug( string $slug, string $type ): void {
		if ( preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) !== 1 ) {
			throw new RuntimeException( sprintf( 'Invalid %s slug: %s', $type, $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the invalid type and slug.
		}
	}
}
