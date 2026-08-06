<?php
/**
 * Read clean-install defaults from a DDEV project configuration.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone host command runs before WordPress is loaded.

if ( 2 !== $argc || ! is_file( $argv[1] ) ) {
	fwrite( STDERR, "ERROR: Expected an existing .ddev/config.yaml file.\n" );
	exit( 1 );
}

$configuration = (string) file_get_contents( $argv[1] );
if ( 1 !== preg_match( '/^name:\s*[\'\"]?([A-Za-z0-9][A-Za-z0-9_-]*)[\'\"]?\s*(?:#.*)?$/m', $configuration, $name_match ) ) {
	fwrite( STDERR, "ERROR: Could not read the project name from .ddev/config.yaml.\n" );
	exit( 1 );
}

$name        = $name_match[1];
$project_tld = 'ddev.site';
if ( preg_match( '/^project_tld:\s*[\'\"]?([A-Za-z0-9.-]+)[\'\"]?\s*(?:#.*)?$/m', $configuration, $tld_match ) === 1 ) {
	$project_tld = trim( $tld_match[1], '.' );
}

$host = $name . '.' . $project_tld;
foreach ( array( $name, 'https://' . $host, 'admin', 'admin@' . $host ) as $value ) {
	echo $value, "\0"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Null-delimited values are passed to the host setup script.
}
