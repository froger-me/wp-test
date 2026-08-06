<?php
/**
 * Dependency-free local file operations used by host commands.
 *
 * @package AnyapeWPTestTools
 */
declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- These standalone host operations run before WordPress is loaded.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- PHP syntax checks require the host PHP executable.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Errors preserve exact local paths.

/**
 * Read a JSON object from a local file.
 *
 * @param string $path JSON file path.
 * @return array<string, mixed>
 * @throws RuntimeException When the file is missing or does not contain an object.
 */
function anyape_wp_test_tools_read_json_object( string $path ): array {
	if ( ! is_file( $path ) ) {
		throw new RuntimeException( 'JSON file does not exist: ' . $path );
	}

	try {
		$data = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	} catch ( JsonException $error ) {
		throw new RuntimeException( 'Invalid JSON file ' . $path . ': ' . $error->getMessage(), 0, $error );
	}

	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'JSON file must contain an object: ' . $path );
	}

	return $data;
}

/** Return an unused dated backup path. */
function anyape_wp_test_tools_unused_backup_path( string $path ): string {
	$base   = $path . '.before-anyape-wp-test-tools-' . gmdate( 'Ymd\THis\Z' );
	$backup = $base;
	$suffix = 1;

	while ( file_exists( $backup ) || is_link( $backup ) ) {
		$backup = $base . '-' . $suffix;
		++$suffix;
	}

	return $backup;
}

/**
 * Replace a complete local file through a temporary file beside it.
 *
 * @throws RuntimeException When the file cannot be written or replaced.
 */
function anyape_wp_test_tools_atomic_write(
	string $path,
	string $contents,
	?int $permissions = null
): void {
	$directory = dirname( $path );
	if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
		throw new RuntimeException( 'Destination directory is not writable: ' . $directory );
	}

	$temp = tempnam( $directory, '.anyape-wp-test-tools-' );

	try {
		if ( false === $temp || false === file_put_contents( $temp, $contents ) ) {
			throw new RuntimeException( 'Could not write temporary file for: ' . $path );
		}

		if ( null !== $permissions && ! chmod( $temp, $permissions ) ) {
			throw new RuntimeException( 'Could not preserve permissions for: ' . $path );
		}

		if ( ! rename( $temp, $path ) ) {
			throw new RuntimeException( 'Could not replace file safely: ' . $path );
		}

		$temp = null;
	} finally {
		if ( is_string( $temp ) && file_exists( $temp ) ) {
			unlink( $temp );
		}
	}
}

/**
 * Fail when a PHP file does not pass the host PHP syntax check.
 *
 * @throws RuntimeException When PHP reports invalid syntax.
 */
function anyape_wp_test_tools_assert_php_syntax( string $path ): void {
	$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $path ) . ' 2>&1';
	exec( $command, $output, $status );
	if ( 0 !== $status ) {
		throw new RuntimeException( 'PHP syntax check failed for ' . $path . ': ' . implode( ' ', $output ) );
	}
}

/** Remove one path without following symbolic links. */
function anyape_wp_test_tools_remove_path( string $path ): void {
	if ( is_link( $path ) || is_file( $path ) ) {
		if ( ! unlink( $path ) ) {
			throw new RuntimeException( 'Could not remove file: ' . $path );
		}
		return;
	}

	if ( ! is_dir( $path ) ) {
		return;
	}

	$items = scandir( $path );
	if ( false === $items ) {
		throw new RuntimeException( 'Could not read directory: ' . $path );
	}

	foreach ( $items as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			anyape_wp_test_tools_remove_path( $path . '/' . $item );
		}
	}

	if ( ! rmdir( $path ) ) {
		throw new RuntimeException( 'Could not remove directory: ' . $path );
	}
}

/** Remove a directory's contents while preserving the directory itself. */
function anyape_wp_test_tools_clear_directory( string $path ): void {
	if ( is_link( $path ) || ! is_dir( $path ) ) {
		throw new RuntimeException( 'Path is not a directory that can be cleared: ' . $path );
	}

	$items = scandir( $path );
	if ( false === $items ) {
		throw new RuntimeException( 'Could not read directory: ' . $path );
	}

	foreach ( $items as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			anyape_wp_test_tools_remove_path( $path . '/' . $item );
		}
	}
}

/** Copy one path without following symbolic links. */
function anyape_wp_test_tools_copy_path(
	string $source,
	string $destination
): void {
	$parent = dirname( $destination );
	if ( ! is_dir( $parent ) && ! mkdir( $parent, 0777, true ) && ! is_dir( $parent ) ) {
		throw new RuntimeException( 'Could not create directory: ' . $parent );
	}

	if ( is_link( $source ) ) {
		$target = readlink( $source );
		if ( false === $target || ! symlink( $target, $destination ) ) {
			throw new RuntimeException( 'Could not copy symbolic link: ' . $source );
		}
		return;
	}

	if ( is_file( $source ) ) {
		if ( ! copy( $source, $destination ) ) {
			throw new RuntimeException( 'Could not copy file: ' . $source );
		}
		$permissions = fileperms( $source );
		if ( false !== $permissions && ! chmod( $destination, $permissions & 0777 ) ) {
			throw new RuntimeException( 'Could not preserve file permissions: ' . $destination );
		}
		return;
	}

	if ( ! is_dir( $source ) ) {
		throw new RuntimeException( 'Unsupported filesystem entry: ' . $source );
	}

	$permissions = fileperms( $source );
	$mode        = false !== $permissions ? $permissions & 0777 : 0777;
	if ( is_link( $destination ) || is_file( $destination ) ) {
		throw new RuntimeException( 'Copy destination is not a directory: ' . $destination );
	}
	if ( ! is_dir( $destination ) && ! mkdir( $destination, $mode, true ) && ! is_dir( $destination ) ) {
		throw new RuntimeException( 'Could not create directory: ' . $destination );
	}
	if ( ! chmod( $destination, $mode ) ) {
		throw new RuntimeException( 'Could not preserve directory permissions: ' . $destination );
	}

	$items = scandir( $source );
	if ( false === $items ) {
		throw new RuntimeException( 'Could not read directory: ' . $source );
	}
	foreach ( $items as $item ) {
		if ( '.' !== $item && '..' !== $item ) {
			anyape_wp_test_tools_copy_path( $source . '/' . $item, $destination . '/' . $item );
		}
	}
}

/** Return a repeatable SHA-256 digest for one path. */
function anyape_wp_test_tools_path_digest( string $path ): string {
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

	$walk( $path, '' );
	return hash( 'sha256', implode( "\n", $entries ) );
}
