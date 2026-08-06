<?php
/**
 * Read and validate DDEV's machine-readable project description.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone CLI script runs before WordPress loads.

$project_root = dirname( __DIR__, 2 );
$input        = stream_get_contents( STDIN );

try {
	$data = json_decode( $input, true, 512, JSON_THROW_ON_ERROR );
	$raw  = is_array( $data['raw'] ?? null ) ? $data['raw'] : array();
	if ( 'running' !== ( $raw['status'] ?? null ) ) {
		throw new RuntimeException( 'DDEV is not already running for this project.' );
	}
	$reported_root = realpath( (string) ( $raw['approot'] ?? '' ) );
	$expected_root = realpath( $project_root );
	if ( false === $reported_root || false === $expected_root || $reported_root !== $expected_root ) {
		throw new RuntimeException( 'DDEV reported a different project root.' );
	}
	$url = (string) ( $raw['primary_url'] ?? '' );
	if ( ! in_array( parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) || ! is_string( parse_url( $url, PHP_URL_HOST ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded.
		throw new RuntimeException( 'DDEV did not report a valid local project URL.' );
	}
	fwrite( STDOUT, rtrim( $url, '/' ) );
} catch ( Throwable $exception ) {
	fwrite( STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL );
	exit( 1 );
}
