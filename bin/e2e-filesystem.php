<?php
/**
 * Capture and restore file paths that browser tests are allowed to change.
 *
 * @package WpTest
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone CLI script runs before WordPress loads and must copy exact local files.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exceptions preserve the exact local path that failed.

$toolkit_root = dirname( __DIR__ );
$project_root = dirname( $toolkit_root );
$e2e_action   = $argv[1] ?? '';
$run_dir      = isset( $argv[2] ) ? rtrim( (string) $argv[2], '/' ) : '';

if ( ! in_array( $e2e_action, array( 'capture', 'restore', 'cleanup' ), true ) || '' === $run_dir ) {
	fwrite( STDERR, "Usage: php bin/e2e-filesystem.php <capture|restore|cleanup> <run-directory>\n" );
	exit( 2 );
}

$allowed_run_root = $toolkit_root . '/runtime/e2e-runs/';
$normalized_run   = str_replace( '\\', '/', $run_dir ) . '/';
if ( ! str_starts_with( $normalized_run, str_replace( '\\', '/', $allowed_run_root ) ) ) {
	fwrite( STDERR, "ERROR: The browser-test run directory must be inside .test-tools/runtime/e2e-runs.\n" );
	exit( 1 );
}

/**
 * Remove one path without following symbolic links.
 *
 * @param string $entry_path Path to remove.
 * @throws RuntimeException When the path cannot be removed.
 */
function wp_test_e2e_remove( string $entry_path ): void {
	if ( is_link( $entry_path ) || is_file( $entry_path ) ) {
		if ( ! unlink( $entry_path ) ) {
			throw new RuntimeException( 'Could not remove file: ' . $entry_path );
		}
		return;
	}
	if ( ! is_dir( $entry_path ) ) {
		return;
	}
	$items = scandir( $entry_path );
	if ( false === $items ) {
		throw new RuntimeException( 'Could not read directory: ' . $entry_path );
	}
	foreach ( $items as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			wp_test_e2e_remove( $entry_path . '/' . $item );
		}
	}
	if ( ! rmdir( $entry_path ) ) {
		throw new RuntimeException( 'Could not remove directory: ' . $entry_path );
	}
}

/**
 * Remove a directory's contents while preserving the directory itself.
 *
 * Keeping the top-level directory prevents an active DDEV mount from
 * remaining attached to a deleted directory.
 *
 * @param string $directory_path Directory whose contents should be removed.
 * @throws RuntimeException When the directory cannot be read or cleared.
 */
function wp_test_e2e_clear_directory( string $directory_path ): void {
	$items = scandir( $directory_path );
	if ( false === $items ) {
		throw new RuntimeException( 'Could not read directory: ' . $directory_path );
	}
	foreach ( $items as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			wp_test_e2e_remove( $directory_path . '/' . $item );
		}
	}
}

/**
 * Copy one path without following symbolic links.
 *
 * @param string $source      Source path.
 * @param string $destination Destination path.
 * @throws RuntimeException When the path cannot be copied.
 */
function wp_test_e2e_copy( string $source, string $destination ): void {
	if ( is_link( $source ) ) {
		$target = readlink( $source );
		if ( false === $target || ! symlink( $target, $destination ) ) {
			throw new RuntimeException( 'Could not copy symbolic link: ' . $source );
		}
		return;
	}
	if ( is_file( $source ) ) {
		$parent = dirname( $destination );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0777, true ) && ! is_dir( $parent ) ) {
			throw new RuntimeException( 'Could not create directory: ' . $parent );
		}
		if ( ! copy( $source, $destination ) ) {
			throw new RuntimeException( 'Could not copy file: ' . $source );
		}
		chmod( $destination, fileperms( $source ) & 0777 );
		return;
	}
	if ( ! is_dir( $source ) ) {
		throw new RuntimeException( 'Unsupported filesystem entry: ' . $source );
	}
	if ( ! is_dir( $destination ) && ! mkdir( $destination, fileperms( $source ) & 0777, true ) && ! is_dir( $destination ) ) {
		throw new RuntimeException( 'Could not create directory: ' . $destination );
	}
	$items = scandir( $source );
	if ( false === $items ) {
		throw new RuntimeException( 'Could not read directory: ' . $source );
	}
	foreach ( $items as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			wp_test_e2e_copy( $source . '/' . $item, $destination . '/' . $item );
		}
	}
}

/**
 * Restore one saved path while preserving an existing top-level directory.
 *
 * @param string $backup_path Saved path.
 * @param string $target_path Working-site path.
 * @throws RuntimeException When the path cannot be restored.
 */
function wp_test_e2e_restore( string $backup_path, string $target_path ): void {
	if ( ! is_dir( $backup_path ) || is_link( $backup_path ) ) {
		wp_test_e2e_remove( $target_path );
		wp_test_e2e_copy( $backup_path, $target_path );
		return;
	}

	if ( is_link( $target_path ) || is_file( $target_path ) ) {
		wp_test_e2e_remove( $target_path );
	}
	if ( ! is_dir( $target_path ) ) {
		if ( ! mkdir( $target_path, fileperms( $backup_path ) & 0777, true ) && ! is_dir( $target_path ) ) {
			throw new RuntimeException( 'Could not create directory: ' . $target_path );
		}
	} else {
		wp_test_e2e_clear_directory( $target_path );
		chmod( $target_path, fileperms( $backup_path ) & 0777 );
	}

	$items = scandir( $backup_path );
	if ( false === $items ) {
		throw new RuntimeException( 'Could not read directory: ' . $backup_path );
	}
	foreach ( $items as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			wp_test_e2e_copy( $backup_path . '/' . $item, $target_path . '/' . $item );
		}
	}
}

/**
 * Return a repeatable digest for one path.
 *
 * @param string $entry_path Path to read.
 * @return string
 */
function wp_test_e2e_digest( string $entry_path ): string {
	$entries = array();
	$walk    = static function ( string $current, string $relative ) use ( &$walk, &$entries ): void {
		if ( is_link( $current ) ) {
			$entries[] = 'l ' . $relative . ' ' . readlink( $current );
			return;
		}
		if ( is_file( $current ) ) {
			$entries[] = 'f ' . $relative . ' ' . hash_file( 'sha256', $current );
			return;
		}
		if ( ! is_dir( $current ) ) {
			$entries[] = 'missing ' . $relative;
			return;
		}
		$entries[] = 'd ' . $relative;
		$items     = scandir( $current );
		if ( false === $items ) {
			throw new RuntimeException( 'Could not read directory: ' . $current );
		}
		foreach ( $items as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$walk( $current . '/' . $item, '' === $relative ? $item : $relative . '/' . $item );
			}
		}
	};
	$walk( $entry_path, '' );
	return hash( 'sha256', implode( "\n", $entries ) );
}

try {
	if ( 'cleanup' === $e2e_action ) {
		wp_test_e2e_remove( rtrim( $run_dir, '/' ) );
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

		$configuration_file = $project_root . '/.wp-test.php';
		$configuration      = is_file( $configuration_file ) ? require $configuration_file : array();
		if ( ! is_array( $configuration ) ) {
			throw new RuntimeException( 'The .wp-test.php file must return an array.' );
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
				wp_test_e2e_copy( $source, $backup );
			}
			$state[] = array(
				'path'   => $relative,
				'exists' => $exists,
				'digest' => wp_test_e2e_digest( $source ),
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
			wp_test_e2e_restore( $backup, $target );
		} else {
			wp_test_e2e_remove( $target );
		}
		$actual = wp_test_e2e_digest( $target );
		if ( ! hash_equals( (string) ( $entry['digest'] ?? '' ), $actual ) ) {
			throw new RuntimeException( 'Filesystem restoration did not match the saved state: ' . $relative );
		}
	}
	printf( "Restored and verified %d filesystem path(s).\n", count( $state ) );
} catch ( Throwable $exception ) {
	fwrite( STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL );
	exit( 1 );
}
