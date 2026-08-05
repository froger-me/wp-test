<?php

declare(strict_types=1);

namespace WpTest;

use RuntimeException;

final class ManifestBuilder
{
	private string $projectRoot;
	private string $toolkitRoot;
	private string $pluginsDir;
	private string $themesDir;
	/** @var array<string, mixed> */
	private array $configuration;

	/** @param array<string, mixed> $configuration */
	public function __construct(string $projectRoot, string $toolkitRoot, array $configuration = [])
	{
		$this->projectRoot = rtrim($projectRoot, '/');
		$this->toolkitRoot = rtrim($toolkitRoot, '/');
		$this->pluginsDir = $this->projectRoot . '/wp-content/plugins';
		$this->themesDir = $this->projectRoot . '/wp-content/themes';
		$this->configuration = array_replace_recursive([
			'include_plugins' => [],
			'exclude_plugins' => [],
			'include_themes' => [],
			'exclude_themes' => [],
			'plugin_dependencies' => [],
			'theme_dependencies' => [],
			'bootstrap' => null,
		], $configuration);
	}

	/**
	 * @param list<string> $activePluginFiles
	 * @return array<string, mixed>
	 */
	public function build(string $profile, ?string $target, array $activePluginFiles, string $stylesheet, string $template): array
	{
		$profile = trim($profile);
		$target = $target !== null ? trim($target) : null;
		$plugins = [];
		$themes = [];

		switch ($profile) {
			case 'default':
			case 'multisite':
				foreach ($activePluginFiles as $pluginFile) {
					$plugins[] = $this->pluginFromFile($pluginFile, true);
				}
				foreach ($this->stringList('include_plugins') as $slug) {
					$plugins[] = $this->pluginFromSlug($slug, true);
				}
				$themes = $this->workingThemes($stylesheet, $template, true);
				foreach ($this->stringList('include_themes') as $slug) {
					$themes[] = $this->themeFromSlug($slug, true);
				}
				break;

			case 'plugin':
				if ($target === null || $target === '') {
					throw new RuntimeException('The plugin profile requires a plugin slug.');
				}
				foreach ($this->dependencySlugs('plugin_dependencies', $target) as $slug) {
					$plugins[] = $this->pluginFromSlug($slug, false);
				}
				$plugins[] = $this->pluginFromSlug($target, true);
				$themes = $this->workingThemes($stylesheet, $template, false);
				break;

			case 'theme':
				if ($target === null || $target === '') {
					throw new RuntimeException('The theme profile requires a theme slug.');
				}
				foreach ($this->dependencySlugs('theme_dependencies', $target) as $slug) {
					$plugins[] = $this->pluginFromSlug($slug, false);
				}
				$themes = $this->themeWithParent($target, true);
				$stylesheet = $target;
				$template = count($themes) > 1 ? (string) $themes[0]['slug'] : $target;
				break;

			case 'harness':
				$plugins[] = $this->fixturePlugin();
				$themes[] = $this->fixtureTheme();
				$stylesheet = 'wp-test-theme';
				$template = 'wp-test-theme';
				break;

			default:
				throw new RuntimeException(sprintf('Unknown test profile: %s', $profile));
		}

		$plugins = $this->deduplicateExtensions($this->filterExcluded($plugins, 'exclude_plugins'));
		$themes = $this->deduplicateExtensions($this->filterExcluded($themes, 'exclude_themes'));

		if ($profile === 'plugin' && ! in_array($target, array_column($plugins, 'slug'), true)) {
			throw new RuntimeException(sprintf('Focused plugin is excluded by configuration: %s', $target));
		}
		if ($profile === 'theme' && ! in_array($target, array_column($themes, 'slug'), true)) {
			throw new RuntimeException(sprintf('Focused theme is excluded by configuration: %s', $target));
		}
		if ($themes === []) {
			$themes = $this->workingThemes($stylesheet, $template, false);
		}

		$siteBootstrap = $this->configuration['bootstrap'] ?? null;
		if (is_string($siteBootstrap) && $siteBootstrap !== '') {
			$siteBootstrap = $this->resolveProjectPath($siteBootstrap);
			if (! is_file($siteBootstrap)) {
				throw new RuntimeException(sprintf('Configured site bootstrap does not exist: %s', $siteBootstrap));
			}
		} else {
			$siteBootstrap = null;
		}

		return [
			'profile' => $profile,
			'target' => $target,
			'multisite' => $profile === 'multisite',
			'plugins' => $plugins,
			'themes' => $themes,
			'stylesheet' => $stylesheet,
			'template' => $template,
			'site_bootstrap' => $siteBootstrap,
		];
	}

	/** @return list<array<string, mixed>> */
	private function workingThemes(string $stylesheet, string $template, bool $testsEnabled): array
	{
		$themes = [];
		if ($template !== '') {
			$themes[] = $this->themeFromSlug($template, $testsEnabled);
		}
		if ($stylesheet !== '' && $stylesheet !== $template) {
			$themes[] = $this->themeFromSlug($stylesheet, $testsEnabled);
		}
		return $themes;
	}

	/** @return list<array<string, mixed>> */
	private function themeWithParent(string $slug, bool $testsEnabled): array
	{
		$theme = $this->themeFromSlug($slug, $testsEnabled);
		$parent = $this->readThemeParent((string) $theme['source_path']);
		if ($parent === null || $parent === $slug) {
			return [$theme];
		}
		return [$this->themeFromSlug($parent, false), $theme];
	}

	/** @return array<string, mixed> */
	private function pluginFromFile(string $pluginFile, bool $testsEnabled): array
	{
		$pluginFile = ltrim(trim($pluginFile), '/');
		if ($pluginFile === '' || str_contains($pluginFile, '..')) {
			throw new RuntimeException(sprintf('Invalid plugin file in active plugin list: %s', $pluginFile));
		}
		$parts = explode('/', $pluginFile);
		$slug = count($parts) > 1 ? $parts[0] : pathinfo($pluginFile, PATHINFO_FILENAME);
		$this->assertSlug($slug, 'plugin');
		$fullPath = $this->pluginsDir . '/' . $pluginFile;
		if (! is_file($fullPath)) {
			throw new RuntimeException(sprintf('Plugin file does not exist: %s', $fullPath));
		}
		$isSingleFile = count($parts) === 1;
		$sourcePath = $isSingleFile ? $fullPath : $this->pluginsDir . '/' . $slug;
		$testsPath = $isSingleFile ? null : $sourcePath . '/tests/phpunit';
		$bootstrap = $testsPath !== null ? $testsPath . '/bootstrap.php' : null;
		return [
			'type' => 'plugin',
			'slug' => $slug,
			'file' => $pluginFile,
			'source_path' => $sourcePath,
			'link_type' => $isSingleFile ? 'file' : 'directory',
			'tests_enabled' => $testsEnabled,
			'tests_path' => $testsPath !== null && is_dir($testsPath) ? $testsPath : null,
			'bootstrap' => $bootstrap !== null && is_file($bootstrap) ? $bootstrap : null,
		];
	}

	/** @return array<string, mixed> */
	private function pluginFromSlug(string $slug, bool $testsEnabled): array
	{
		$this->assertSlug($slug, 'plugin');
		return $this->pluginFromFile($this->findPluginMainFile($slug), $testsEnabled);
	}

	private function findPluginMainFile(string $slug): string
	{
		$singleFile = $this->pluginsDir . '/' . $slug . '.php';
		if (is_file($singleFile)) {
			return $slug . '.php';
		}
		$directory = $this->pluginsDir . '/' . $slug;
		if (! is_dir($directory)) {
			throw new RuntimeException(sprintf('Plugin slug does not exist: %s', $slug));
		}
		$candidates = glob($directory . '/*.php') ?: [];
		sort($candidates, SORT_STRING);
		foreach ($candidates as $candidate) {
			$header = (string) file_get_contents($candidate, false, null, 0, 8192);
			if (preg_match('/^[ \t*#@]*Plugin Name\s*:/mi', $header) === 1) {
				return $slug . '/' . basename($candidate);
			}
		}
		throw new RuntimeException(sprintf('Could not find a main plugin file for slug: %s', $slug));
	}

	/** @return array<string, mixed> */
	private function themeFromSlug(string $slug, bool $testsEnabled): array
	{
		$this->assertSlug($slug, 'theme');
		$sourcePath = $this->themesDir . '/' . $slug;
		if (! is_dir($sourcePath)) {
			throw new RuntimeException(sprintf('Theme slug does not exist: %s', $slug));
		}
		$testsPath = $sourcePath . '/tests/phpunit';
		$bootstrap = $testsPath . '/bootstrap.php';
		return [
			'type' => 'theme',
			'slug' => $slug,
			'source_path' => $sourcePath,
			'link_type' => 'directory',
			'tests_enabled' => $testsEnabled,
			'tests_path' => is_dir($testsPath) ? $testsPath : null,
			'bootstrap' => is_file($bootstrap) ? $bootstrap : null,
		];
	}

	private function readThemeParent(string $themePath): ?string
	{
		$styleFile = rtrim($themePath, '/') . '/style.css';
		if (! is_file($styleFile)) {
			return null;
		}
		$header = (string) file_get_contents($styleFile, false, null, 0, 8192);
		if (preg_match('/^[ \t*#@]*Template\s*:\s*(.+)$/mi', $header, $matches) !== 1) {
			return null;
		}
		$parent = trim($matches[1]);
		return $parent !== '' ? $parent : null;
	}

	/** @return array<string, mixed> */
	private function fixturePlugin(): array
	{
		$sourcePath = $this->toolkitRoot . '/fixtures/plugins/wp-test-lifecycle';
		return [
			'type' => 'plugin',
			'slug' => 'wp-test-lifecycle',
			'file' => 'wp-test-lifecycle/wp-test-lifecycle.php',
			'source_path' => $sourcePath,
			'link_type' => 'directory',
			'tests_enabled' => true,
			'tests_path' => $sourcePath . '/tests/phpunit',
			'bootstrap' => is_file($sourcePath . '/tests/phpunit/bootstrap.php') ? $sourcePath . '/tests/phpunit/bootstrap.php' : null,
		];
	}

	/** @return array<string, mixed> */
	private function fixtureTheme(): array
	{
		$sourcePath = $this->toolkitRoot . '/fixtures/themes/wp-test-theme';
		return [
			'type' => 'theme',
			'slug' => 'wp-test-theme',
			'source_path' => $sourcePath,
			'link_type' => 'directory',
			'tests_enabled' => true,
			'tests_path' => $sourcePath . '/tests/phpunit',
			'bootstrap' => is_file($sourcePath . '/tests/phpunit/bootstrap.php') ? $sourcePath . '/tests/phpunit/bootstrap.php' : null,
		];
	}

	/**
	 * @param list<array<string, mixed>> $extensions
	 * @return list<array<string, mixed>>
	 */
	private function filterExcluded(array $extensions, string $key): array
	{
		$excluded = array_fill_keys($this->stringList($key), true);
		return array_values(array_filter($extensions, static fn (array $extension): bool => ! isset($excluded[(string) $extension['slug']])));
	}

	/**
	 * @param list<array<string, mixed>> $extensions
	 * @return list<array<string, mixed>>
	 */
	private function deduplicateExtensions(array $extensions): array
	{
		$result = [];
		$seen = [];
		foreach ($extensions as $extension) {
			$key = (string) $extension['type'] . ':' . (string) $extension['slug'];
			if (isset($seen[$key])) {
				if (! empty($extension['tests_enabled'])) {
					foreach ($result as &$existing) {
						if ((string) $existing['type'] === (string) $extension['type'] && (string) $existing['slug'] === (string) $extension['slug']) {
							$existing['tests_enabled'] = true;
							$existing['tests_path'] = $extension['tests_path'];
							$existing['bootstrap'] = $extension['bootstrap'];
						}
					}
					unset($existing);
				}
				continue;
			}
			$seen[$key] = true;
			$result[] = $extension;
		}
		return $result;
	}

	/** @return list<string> */
	private function stringList(string $key): array
	{
		$value = $this->configuration[$key] ?? [];
		if (! is_array($value)) {
			throw new RuntimeException(sprintf('Configuration key "%s" must be an array.', $key));
		}
		return array_values(array_map(static fn ($item): string => trim((string) $item), array_filter($value, static fn ($item): bool => is_string($item) && trim($item) !== '')));
	}

	/** @return list<string> */
	private function dependencySlugs(string $key, string $target): array
	{
		$map = $this->configuration[$key] ?? [];
		if (! is_array($map)) {
			throw new RuntimeException(sprintf('Configuration key "%s" must be an array.', $key));
		}
		$dependencies = $map[$target] ?? [];
		if (! is_array($dependencies)) {
			throw new RuntimeException(sprintf('Dependencies for "%s" must be an array.', $target));
		}
		return array_values(array_map(static fn ($item): string => trim((string) $item), $dependencies));
	}

	private function resolveProjectPath(string $path): string
	{
		return str_starts_with($path, '/') ? $path : $this->projectRoot . '/' . ltrim($path, '/');
	}

	private function assertSlug(string $slug, string $type): void
	{
		if (preg_match('/^[A-Za-z0-9._-]+$/', $slug) !== 1) {
			throw new RuntimeException(sprintf('Invalid %s slug: %s', $type, $slug));
		}
	}
}
