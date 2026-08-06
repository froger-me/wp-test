<?php
/**
 * Validate the ignored local database-refresh configuration for a host script.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone host command runs before WordPress is loaded and writes errors to STDERR.

if ( 2 !== $argc ) {
	fwrite( STDERR, "ERROR: Expected the database-refresh configuration path.\n" );
	exit( 1 );
}

$configuration_file = $argv[1];
if ( ! is_file( $configuration_file ) ) {
	fwrite( STDERR, "ERROR: Missing ignored local configuration: {$configuration_file}\nCopy db-refresh-config-example.php to db-refresh-local.php and fill in its four values.\n" );
	exit( 1 );
}

$configuration = require $configuration_file;
if ( ! is_array( $configuration ) ) {
	fwrite( STDERR, "ERROR: Database-refresh configuration must return an array.\n" );
	exit( 1 );
}

$keys   = array( 'ssh_alias', 'remote_path', 'remote_url', 'local_url' );
$values = array();
foreach ( $keys as $key ) {
	$value = $configuration[ $key ] ?? null;
	if ( ! is_string( $value ) || '' === trim( $value ) || str_contains( $value, "\0" ) || str_contains( $value, "\n" ) || str_contains( $value, "\r" ) ) {
		fwrite( STDERR, "ERROR: Database-refresh configuration value '{$key}' must be one non-empty line.\n" );
		exit( 1 );
	}
	$values[ $key ] = trim( $value );
}

if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9_.@:-]*\z/', $values['ssh_alias'] ) ) {
	fwrite( STDERR, "ERROR: Database-refresh ssh_alias contains unsupported characters. Use a host alias from your SSH configuration.\n" );
	exit( 1 );
}
if ( ! str_starts_with( $values['remote_path'], '/' ) ) {
	fwrite( STDERR, "ERROR: Database-refresh remote_path must be an absolute path.\n" );
	exit( 1 );
}
foreach ( array( 'remote_url', 'local_url' ) as $url_key ) {
	$url = parse_url( $values[ $url_key ] );
	if ( ! is_array( $url ) || ! in_array( $url['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] ) ) {
		fwrite( STDERR, "ERROR: Database-refresh {$url_key} must be a complete HTTP or HTTPS URL.\n" );
		exit( 1 );
	}
}
if ( rtrim( $values['remote_url'], '/' ) === rtrim( $values['local_url'], '/' ) ) {
	fwrite( STDERR, "ERROR: Database-refresh remote_url and local_url must be different.\n" );
	exit( 1 );
}

$values['remote_path_shell'] = escapeshellarg( $values['remote_path'] );
foreach ( array( 'ssh_alias', 'remote_path', 'remote_url', 'local_url', 'remote_path_shell' ) as $key ) {
	echo $values[ $key ], "\0"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Null-delimited values are passed to the host shell after strict validation.
}
