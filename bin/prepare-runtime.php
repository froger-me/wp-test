<?php

declare(strict_types=1);

use WpTest\Manifest;

$toolkitRoot = dirname(__DIR__);
$projectRoot = dirname($toolkitRoot);
$runtimeRoot = $toolkitRoot . '/runtime';
$contentRoot = $runtimeRoot . '/wp-content';

require $toolkitRoot . '/vendor/autoload.php';
require $toolkitRoot . '/autoload.php';

$manifest = Manifest::fromFile($runtimeRoot . '/manifest.json');

$removeTree = static function (string $path) use (&$removeTree): void {
	if (is_link($path) || is_file($path)) {
		unlink($path);
		return;
	}

	if (! is_dir($path)) {
		return;
	}

	foreach (scandir($path) ?: [] as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$removeTree($path . '/' . $entry);
	}

	rmdir($path);
};

$removeTree($contentRoot);

foreach (['plugins', 'mu-plugins', 'themes', 'uploads'] as $directory) {
	$path = $contentRoot . '/' . $directory;

	if (! mkdir($path, 0777, true) && ! is_dir($path)) {
		throw new RuntimeException(
			sprintf('Could not create runtime directory: %s', $path)
		);
	}
}

$relativePath = static function (string $fromDirectory, string $toPath): string {
	$fromParts = explode('/', trim($fromDirectory, '/'));
	$toParts   = explode('/', trim($toPath, '/'));

	while (
		$fromParts !== [] &&
		$toParts !== [] &&
		$fromParts[0] === $toParts[0]
	) {
		array_shift($fromParts);
		array_shift($toParts);
	}

	return implode(
		'/',
		array_merge(
			array_fill(0, count($fromParts), '..'),
			$toParts
		)
	);
};

$link = static function (
	string $source,
	string $target
) use ($relativePath): void {
	if (! file_exists($source) && ! is_link($source)) {
		throw new RuntimeException(
			sprintf('Runtime source does not exist: %s', $source)
		);
	}

	if (file_exists($target) || is_link($target)) {
		throw new RuntimeException(
			sprintf('Runtime target already exists: %s', $target)
		);
	}

	$relativeSource = $relativePath(dirname($target), $source);

	if (! symlink($relativeSource, $target)) {
		throw new RuntimeException(
			sprintf('Could not create runtime link %s -> %s', $target, $source)
		);
	}
};

foreach ($manifest->plugins() as $plugin) {
	$source = (string) $plugin['source_path'];
	$target = (string) $plugin['link_type'] === 'file'
		? $contentRoot . '/plugins/' . basename($source)
		: $contentRoot . '/plugins/' . (string) $plugin['slug'];

	$link($source, $target);
}

$fixturePlugin = $toolkitRoot . '/fixtures/plugins/wp-test-lifecycle';
$fixtureTarget = $contentRoot . '/plugins/wp-test-lifecycle';

if (! file_exists($fixtureTarget) && ! is_link($fixtureTarget)) {
	$link($fixturePlugin, $fixtureTarget);
}

foreach ($manifest->themes() as $theme) {
	$link(
		(string) $theme['source_path'],
		$contentRoot . '/themes/' . (string) $theme['slug']
	);
}

$fixtureTheme = $toolkitRoot . '/fixtures/themes/wp-test-theme';
$fixtureThemeTarget = $contentRoot . '/themes/wp-test-theme';

if (! file_exists($fixtureThemeTarget) && ! is_link($fixtureThemeTarget)) {
	$link($fixtureTheme, $fixtureThemeTarget);
}

$realMuPlugins = $projectRoot . '/wp-content/mu-plugins';

if (is_dir($realMuPlugins)) {
	foreach (scandir($realMuPlugins) ?: [] as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$link(
			$realMuPlugins . '/' . $entry,
			$contentRoot . '/mu-plugins/' . $entry
		);
	}
}

$realLanguages = $projectRoot . '/wp-content/languages';

if (is_dir($realLanguages)) {
	$link($realLanguages, $contentRoot . '/languages');
}
