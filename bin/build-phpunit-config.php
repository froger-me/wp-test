<?php

declare(strict_types=1);

use WpTest\Manifest;

$toolkitRoot = dirname(__DIR__);
$runtimeRoot = $toolkitRoot . '/runtime';

require $toolkitRoot . '/vendor/autoload.php';
require $toolkitRoot . '/autoload.php';

$manifest = Manifest::fromFile($runtimeRoot . '/manifest.json');

$escape = static fn (string $value): string =>
	htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

$suites = [];
$suites[] = sprintf(
	"\t\t<testsuite name=\"Harness\">\n\t\t\t<directory suffix=\"Test.php\">%s</directory>\n\t\t</testsuite>",
	$escape($toolkitRoot . '/tests')
);

$coverageDirectories = [];

foreach (array_merge($manifest->plugins(), $manifest->themes()) as $extension) {
	if (! empty($extension['tests_enabled'])) {
		$testsPath = $extension['tests_path'] ?? null;

		if (is_string($testsPath) && is_dir($testsPath)) {
			$suites[] = sprintf(
				"\t\t<testsuite name=\"%s: %s\">\n\t\t\t<directory suffix=\"Test.php\">%s</directory>\n\t\t</testsuite>",
				$escape(ucfirst((string) $extension['type'])),
				$escape((string) $extension['slug']),
				$escape($testsPath)
			);
		}
	}

	$sourcePath = $extension['source_path'] ?? null;

	if (is_string($sourcePath) && is_dir($sourcePath)) {
		$coverageDirectories[] = $sourcePath;
	}
}

$groups = '';

if (getenv('WP_TEST_INCLUDE_DESTRUCTIVE') !== '1') {
	$groups = <<<'XML'
	<groups>
		<exclude>
			<group>destructive</group>
		</exclude>
	</groups>
XML;
}

$coverage = '';

if (getenv('WP_TEST_COVERAGE') === '1') {
	if ($coverageDirectories === []) {
		$coverageDirectories[] = $toolkitRoot . '/src';
	}

	$includes = array_map(
		static fn (string $directory): string => sprintf(
			"\t\t\t<directory suffix=\".php\">%s</directory>",
			$escape($directory)
		),
		array_values(array_unique($coverageDirectories))
	);

	$coverage = sprintf(
		"\n\t<coverage processUncoveredFiles=\"false\">\n\t\t<include>\n%s\n\t\t</include>\n\t</coverage>",
		implode("\n", $includes)
	);
}

$xml = sprintf(
	<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
	bootstrap="%s"
	colors="true"
	failOnRisky="true"
	failOnWarning="true"
	cacheResult="false"
	convertDeprecationsToExceptions="true"
	convertNoticesToExceptions="true"
	convertWarningsToExceptions="true"
>
	<testsuites>
%s
	</testsuites>
%s%s
</phpunit>
XML,
	$escape($toolkitRoot . '/bootstrap.php'),
	implode("\n", $suites),
	$groups !== '' ? "\n" . $groups : '',
	$coverage
);

file_put_contents($runtimeRoot . '/phpunit.xml', $xml . PHP_EOL);
