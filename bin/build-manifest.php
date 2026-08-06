<?php
/**
 * Build the runtime test manifest.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

use AnyapeWPTestTools\ManifestBuilder;

$anyape_wp_test_tools_root = dirname( __DIR__ );
$project_root              = dirname( $anyape_wp_test_tools_root );

require $anyape_wp_test_tools_root . '/vendor/autoload.php';
require $anyape_wp_test_tools_root . '/autoload.php';

$options = getopt( '', array( 'profile:', 'target::' ) );
$profile = isset( $options['profile'] ) ? (string) $options['profile'] : 'default';
$target  = isset( $options['target'] ) ? (string) $options['target'] : null;

$runtime_dir    = $anyape_wp_test_tools_root . '/runtime';
$state_file     = $runtime_dir . '/working-site.json';
$active_plugins = array();
$stylesheet     = '';
$template       = '';

if ( 'harness' !== $profile ) {
	if ( ! is_file( $state_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI builder.
		fwrite( STDERR, sprintf( "ERROR: Missing working-site state file: %s\n", $state_file ) );
		exit( 1 );
	}

	try {
		$state = json_decode(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local captured state before WordPress loads.
			(string) file_get_contents( $state_file ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
	} catch ( JsonException $exception ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI builder.
		fwrite( STDERR, 'ERROR: Invalid working-site state: ' . $exception->getMessage() . "\n" );
		exit( 1 );
	}

	if ( ! is_array( $state ) || ! is_array( $state['active_plugins'] ?? null ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI builder.
		fwrite( STDERR, "ERROR: Working-site state must contain an active_plugins array.\n" );
		exit( 1 );
	}

	$active_plugins = $state['active_plugins'];
	$stylesheet     = is_string( $state['stylesheet'] ?? null ) ? trim( $state['stylesheet'] ) : '';
	$template       = is_string( $state['template'] ?? null ) ? trim( $state['template'] ) : '';
}

$configuration      = array();
$configuration_file = $project_root . '/.anyape-wp-test-tools.php';

if ( is_file( $configuration_file ) ) {
	$configuration = require $configuration_file;

	if ( ! is_array( $configuration ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI builder.
		fwrite(
			STDERR,
			sprintf( "ERROR: Configuration file must return an array: %s\n", $configuration_file )
		);
		exit( 1 );
	}
}

try {
	$builder  = new ManifestBuilder( $project_root, $anyape_wp_test_tools_root, $configuration );
	$manifest = $builder->build(
		$profile,
		$target,
		array_values(
			array_filter(
				$active_plugins,
				static fn ( $plugin ): bool => is_string( $plugin )
			)
		),
		$stylesheet,
		$template
	);
} catch ( Throwable $exception ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI builder.
	fwrite( STDERR, 'ERROR: ' . $exception->getMessage() . "\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- WordPress is not loaded by this CLI builder.
if ( ! is_dir( $runtime_dir ) && ! mkdir( $runtime_dir, 0777, true ) && ! is_dir( $runtime_dir ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI builder.
	fwrite( STDERR, "ERROR: Could not create runtime directory.\n" );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WordPress is not loaded by this CLI builder.
file_put_contents(
	$runtime_dir . '/manifest.json',
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() cannot throw on encoding failure.
	json_encode(
		$manifest,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	) . PHP_EOL
);

printf(
	"Test profile: %s%s; %d plugin(s), %d theme(s).\n",
	$profile, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI status output, not HTML.
	null !== $target && '' !== $target ? sprintf( ' (%s)', $target ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI status output, not HTML.
	count( $manifest['plugins'] ),
	count( $manifest['themes'] )
);
