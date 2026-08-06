<?php
/**
 * Build the runtime PHPUnit configuration.
 *
 * @package WpTest
 */

declare(strict_types=1);

use WpTest\Manifest;

$toolkit_root = dirname( __DIR__ );
$runtime_root = $toolkit_root . '/runtime';

require $toolkit_root . '/vendor/autoload.php';
require $toolkit_root . '/autoload.php';

$manifest = Manifest::from_file( $runtime_root . '/manifest.json' );

$escape = static fn ( string $value ): string =>
	htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );

$suites   = array();
$suites[] = sprintf(
	"\t\t<testsuite name=\"Harness\">\n\t\t\t<directory suffix=\"Test.php\">%s</directory>\n\t\t</testsuite>",
	$escape( $toolkit_root . '/tests' )
);

$coverage_directories = array();

foreach ( array_merge( $manifest->plugins(), $manifest->themes() ) as $extension ) {
	if ( ! empty( $extension['tests_enabled'] ) ) {
		$tests_path = $extension['tests_path'] ?? null;

		if ( is_string( $tests_path ) && is_dir( $tests_path ) ) {
			$standard_test_files = array();
			$iterator            = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $tests_path, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $test_file ) {
				if ( $test_file->isFile() && str_starts_with( $test_file->getBasename(), 'test-' ) && str_ends_with( $test_file->getBasename(), '.php' ) ) {
					$standard_test_files[] = "\t\t\t<file>" . $escape( $test_file->getPathname() ) . '</file>';
				}
			}
			sort( $standard_test_files, SORT_STRING );
			$test_entries = array_merge(
				array( "\t\t\t<directory suffix=\"Test.php\">" . $escape( $tests_path ) . '</directory>' ),
				$standard_test_files
			);
			$suites[]     = sprintf(
				"\t\t<testsuite name=\"%s: %s\">\n%s\n\t\t</testsuite>",
				$escape( ucfirst( (string) $extension['type'] ) ),
				$escape( (string) $extension['slug'] ),
				implode( "\n", $test_entries )
			);
		}
	}

	$source_path = $extension['source_path'] ?? null;

	if ( is_string( $source_path ) && is_dir( $source_path ) ) {
		$coverage_directories[] = $source_path;
	}
}

$excluded_groups = array();

if ( getenv( 'WP_TEST_INCLUDE_DESTRUCTIVE' ) !== '1' ) {
	$excluded_groups[] = 'destructive';
}

if ( 'harness' !== $manifest->profile() ) {
	$excluded_groups[] = 'harness-fixture';
}

if ( '1' !== getenv( 'WP_TEST_COVERAGE' ) ) {
	$excluded_groups[] = 'coverage';
}

$groups = '';

if ( array() !== $excluded_groups ) {
	$group_entries = implode(
		"\n",
		array_map(
			static fn ( string $group ): string =>
				"\t\t\t<group>" . $escape( $group ) . '</group>',
			$excluded_groups
		)
	);

	$groups = sprintf(
		"\t<groups>\n\t\t<exclude>\n%s\n\t\t</exclude>\n\t</groups>",
		$group_entries
	);
}

$coverage = '';

if ( '1' === getenv( 'WP_TEST_COVERAGE' ) ) {
	if ( array() === $coverage_directories ) {
		$coverage_directories[] = $toolkit_root . '/src';
	}

	$includes = array_map(
		static fn ( string $directory ): string => sprintf(
			"\t\t\t<directory suffix=\".php\">%s</directory>",
			$escape( $directory )
		),
		array_values( array_unique( $coverage_directories ) )
	);

	$coverage = sprintf(
		"\n\t<coverage processUncoveredFiles=\"false\">\n\t\t<include>\n%s\n\t\t</include>\n\t</coverage>",
		implode( "\n", $includes )
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
	$escape( $toolkit_root . '/bootstrap.php' ),
	implode( "\n", $suites ),
	'' !== $groups ? "\n" . $groups : '',
	$coverage
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WordPress is not loaded by this CLI builder.
file_put_contents( $runtime_root . '/phpunit.xml', $xml . PHP_EOL );
