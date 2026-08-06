<?php
/**
 * Return a repeatable digest of a DDEV SQL export.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone CLI script runs before WordPress loads.

$export_path = $argv[1] ?? '';
if ( '' === $export_path || ! is_file( $export_path ) ) {
	fwrite( STDERR, "ERROR: SQL export does not exist.\n" );
	exit( 1 );
}

$handle = fopen( $export_path, 'rb' );
$hash   = hash_init( 'sha256' );
if ( false === $handle ) {
	fwrite( STDERR, "ERROR: Could not read SQL export.\n" );
	exit( 1 );
}
while ( true ) {
	$line = fgets( $handle );
	if ( false === $line ) {
		break;
	}
	if ( str_starts_with( $line, '-- Dump completed on ' ) ) {
		continue;
	}
	hash_update( $hash, $line );
}
fclose( $handle );
fwrite( STDOUT, hash_final( $hash ) );
