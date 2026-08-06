<?php
/**
 * Read clean-install defaults from a DDEV project configuration.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone host command runs before WordPress is loaded.

require_once __DIR__ . '/inspect-setup.php';

if ( 2 !== $argc || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "ERROR: Expected an existing .ddev/config.yaml file.\n" );
	exit( 1 );
}

$configuration = (string) file_get_contents( $argv[1] );
$name          = anyape_wp_test_tools_ddev_scalar( $configuration, 'name' );
if ( null === $name || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name ) ) {
	fwrite( STDERR, "ERROR: Could not read the project name from .ddev/config.yaml.\n" );
	exit( 1 );
}

$project_tld = anyape_wp_test_tools_ddev_scalar( $configuration, 'project_tld' );
if ( null === $project_tld || 1 !== preg_match( '/^[A-Za-z0-9.-]+$/', $project_tld ) ) {
	$project_tld = 'ddev.site';
}

$host = $name . '.' . trim( $project_tld, '.' );
foreach ( array( $name, 'https://' . $host, 'admin', 'admin@' . $host ) as $value ) {
	echo $value, "\0"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Null-delimited values are passed to the host setup script.
}
