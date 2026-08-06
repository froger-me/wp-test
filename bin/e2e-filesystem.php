<?php
/**
 * Capture and restore file paths that browser tests are allowed to change.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone CLI script runs before WordPress loads and must copy exact local files.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exceptions preserve the exact local path that failed.

require_once __DIR__ . '/file-tools.php';

$anyape_wp_test_tools_root = dirname( __DIR__ );
$project_root              = dirname( $anyape_wp_test_tools_root );
$e2e_action                = $argv[1] ?? '';
$run_dir                   = isset( $argv[2] ) ? rtrim( (string) $argv[2], '/' ) : '';

if ( ! in_array( $e2e_action, array( 'capture', 'restore', 'cleanup' ), true ) || '' === $run_dir ) {
	fwrite( STDERR, "Usage: php bin/e2e-filesystem.php <capture|restore|cleanup> <run-directory>\n" );
	exit( 2 );
}

$allowed_run_root = $anyape_wp_test_tools_root . '/runtime/e2e-runs/';
$normalized_run   = str_replace( '\\', '/', $run_dir ) . '/';
if ( ! str_starts_with( $normalized_run, str_replace( '\\', '/', $allowed_run_root ) ) ) {
	fwrite( STDERR, "ERROR: The browser-test run directory must be inside .anyape-wp-test-tools/runtime/e2e-runs.\n" );
	exit( 1 );
}

/**
 * Restore one saved path while preserving an existing top-level directory.
 *
 * @param string $backup_path Saved path.
 * @param string $target_path Working-site path.
 * @throws RuntimeException When the path cannot be restored.
 */
function anyape_wp_test_tools_e2e_restore( string $backup_path, string $target_path ): void {
	if ( ! is_dir( $backup_path ) || is_link( $backup_path ) ) {
		anyape_wp_test_tools_remove_path( $target_path );
		anyape_wp_test_tools_copy_path( $backup_path, $target_path );
		return;
	}

	if ( is_link( $target_path ) || is_file( $target_path ) ) {
		anyape_wp_test_tools_remove_path( $target_path );
	}
	$permissions = fileperms( $backup_path );
	$mode        = false !== $permissions ? $permissions & 0777 : 0777;
	if ( ! is_dir( $target_path ) ) {
		if ( ! mkdir( $target_path, $mode, true ) && ! is_dir( $target_path ) ) {
			throw new RuntimeException( 'Could not create directory: ' . $target_path );
		}
	} else {
		anyape_wp_test_tools_clear_directory( $target_path );
		if ( ! chmod( $target_path, $mode ) ) {
			throw new RuntimeException( 'Could not preserve directory permissions: ' . $target_path );
		}
	}

	$items = scandir( $backup_path );
	if ( false === $items ) {
		throw new RuntimeException( 'Could not read directory: ' . $backup_path );
	}
	foreach ( $items as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			anyape_wp_test_tools_copy_path( $backup_path . '/' . $item, $target_path . '/' . $item );
		}
	}
}

try {
	if ( 'cleanup' === $e2e_action ) {
		anyape_wp_test_tools_remove_path( rtrim( $run_dir, '/' ) );
		exit( 0 );
	}

	$state_file = $run_dir . '/filesystem-state.json';
	$backup_dir = $run_dir . '/filesystem-backup';

	if ( 'capture' === $e2e_action ) {
		if ( file_exists( $run_dir ) ) {
			throw new RuntimeException( 'Browser-test run directory already exists: ' . $run_dir );
		}
		if ( ! mkdir( $backup_dir, 0777, true ) && ! is_dir( $backup_dir ) ) {
			throw new RuntimeException( 'Could not create browser-test backup directory.' );
		}

		$configuration_file = $project_root . '/.anyape-wp-test-tools.php';
		$configuration      = is_file( $configuration_file ) ? require $configuration_file : array();
		if ( ! is_array( $configuration ) ) {
			throw new RuntimeException( 'The .anyape-wp-test-tools.php file must return an array.' );
		}
		$configured = $configuration['e2e_filesystem_paths'] ?? array();
		if ( ! is_array( $configured ) ) {
			throw new RuntimeException( 'Configuration key "e2e_filesystem_paths" must be an array.' );
		}
		$paths = array_merge(
			array( 'wp-content/uploads', 'wp-content/mu-plugins' ),
			$configured
		);
		$paths = array_values( array_unique( $paths ) );
		$state = array();

		foreach ( $paths as $index => $relative ) {
			if ( ! is_string( $relative ) ) {
				throw new RuntimeException( 'Every E2E filesystem path must be a string.' );
			}
			$relative = trim( str_replace( '\\', '/', $relative ), '/' );
			if ( ! str_starts_with( $relative, 'wp-content/' ) || 'wp-content' === $relative || str_contains( $relative, '..' ) || str_contains( $relative, "\n" ) ) {
				throw new RuntimeException( 'E2E filesystem paths must be narrow paths below wp-content: ' . $relative );
			}
			$source = $project_root . '/' . $relative;
			$exists = is_link( $source ) || file_exists( $source );
			$backup = $backup_dir . '/' . $index;
			if ( $exists ) {
				anyape_wp_test_tools_copy_path( $source, $backup );
			}
			$state[] = array(
				'path'   => $relative,
				'exists' => $exists,
				'digest' => anyape_wp_test_tools_path_digest( $source ),
				'backup' => (string) $index,
			);
		}

		file_put_contents( $state_file, json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL );
		printf( "Recorded %d filesystem path(s).\n", count( $state ) );
		exit( 0 );
	}

	$state = json_decode( (string) file_get_contents( $state_file ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $state ) ) {
		throw new RuntimeException( 'Invalid browser-test filesystem state.' );
	}
	foreach ( $state as $entry ) {
		$relative = (string) ( $entry['path'] ?? '' );
		$target   = $project_root . '/' . $relative;
		$backup   = $backup_dir . '/' . (string) ( $entry['backup'] ?? '' );
		if ( ! empty( $entry['exists'] ) ) {
			anyape_wp_test_tools_e2e_restore( $backup, $target );
		} else {
			anyape_wp_test_tools_remove_path( $target );
		}
		$actual = anyape_wp_test_tools_path_digest( $target );
		if ( ! hash_equals( (string) ( $entry['digest'] ?? '' ), $actual ) ) {
			throw new RuntimeException( 'Filesystem restoration did not match the saved state: ' . $relative );
		}
	}
	printf( "Restored and verified %d filesystem path(s).\n", count( $state ) );
} catch ( Throwable $exception ) {
	fwrite( STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL );
	exit( 1 );
}
