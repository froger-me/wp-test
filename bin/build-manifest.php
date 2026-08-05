<?php

declare(strict_types=1);

use WpTest\ManifestBuilder;

$toolkitRoot = dirname(__DIR__);
$projectRoot = dirname($toolkitRoot);

require $toolkitRoot . '/vendor/autoload.php';
require $toolkitRoot . '/autoload.php';

$options = getopt('', ['profile:', 'target::']);
$profile = isset($options['profile']) ? (string) $options['profile'] : 'default';
$target  = isset($options['target']) ? (string) $options['target'] : null;

$runtimeDir = $toolkitRoot . '/runtime';
$pluginsFile = $runtimeDir . '/working-active-plugins.json';
$stylesheetFile = $runtimeDir . '/working-stylesheet.txt';
$templateFile = $runtimeDir . '/working-template.txt';

foreach ([$pluginsFile, $stylesheetFile, $templateFile] as $requiredFile) {
	if (! is_file($requiredFile)) {
		fwrite(
			STDERR,
			sprintf("ERROR: Missing working-site state file: %s\n", $requiredFile)
		);
		exit(1);
	}
}

$activePlugins = json_decode(
	(string) file_get_contents($pluginsFile),
	true,
	512,
	JSON_THROW_ON_ERROR
);

if (! is_array($activePlugins)) {
	fwrite(STDERR, "ERROR: The active plugin list must be a JSON array.\n");
	exit(1);
}

$stylesheet = trim((string) file_get_contents($stylesheetFile));
$template   = trim((string) file_get_contents($templateFile));

$configuration = [];
$configurationFile = $projectRoot . '/.wp-test.php';

if (is_file($configurationFile)) {
	$configuration = require $configurationFile;

	if (! is_array($configuration)) {
		fwrite(
			STDERR,
			sprintf("ERROR: Configuration file must return an array: %s\n", $configurationFile)
		);
		exit(1);
	}
}

try {
	$builder  = new ManifestBuilder($projectRoot, $toolkitRoot, $configuration);
	$manifest = $builder->build(
		$profile,
		$target,
		array_values(
			array_filter(
				$activePlugins,
				static fn ($plugin): bool => is_string($plugin)
			)
		),
		$stylesheet,
		$template
	);
} catch (Throwable $exception) {
	fwrite(STDERR, "ERROR: " . $exception->getMessage() . "\n");
	exit(1);
}

if (! is_dir($runtimeDir) && ! mkdir($runtimeDir, 0777, true) && ! is_dir($runtimeDir)) {
	fwrite(STDERR, "ERROR: Could not create runtime directory.\n");
	exit(1);
}

file_put_contents(
	$runtimeDir . '/manifest.json',
	json_encode(
		$manifest,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	) . PHP_EOL
);

printf(
	"Test profile: %s%s; %d plugin(s), %d theme(s).\n",
	$profile,
	$target !== null && $target !== '' ? sprintf(' (%s)', $target) : '',
	count($manifest['plugins']),
	count($manifest['themes'])
);
