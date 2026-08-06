<?php
/**
 * Add approved local paths to Git and SFTP ignore files.
 *
 * @package WpTest
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone host command runs before WordPress is loaded.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command errors must preserve exact local paths and file types.

/**
 * Update one supported ignore file.
 *
 * @param string $project_root WordPress root directory.
 * @param string $type         Either git or sftp.
 * @param bool   $check_only   Report a pending change without writing.
 * @return array<string, mixed>
 * @throws RuntimeException When the file type or contents are unsupported.
 */
function wp_test_update_ignore_file( string $project_root, string $type, bool $check_only = false ): array {
	$project_root = rtrim( $project_root, DIRECTORY_SEPARATOR );
	if ( 'git' === $type ) {
		$path    = $project_root . '/.gitignore';
		$entries = array( '.wp-test.php', 'wp-config-ddev.php', 'wp-config.php.before-ddev', 'wp-config.php.before-test-tools-*', 'composer.json.before-test-tools-*', '.gitignore.before-test-tools-*' );
		$modules = $project_root . '/.gitmodules';
		if ( ! is_file( $modules ) || ! str_contains( (string) file_get_contents( $modules ), '.test-tools' ) ) {
			array_unshift( $entries, '.test-tools/' );
		}
		$original = is_file( $path ) ? (string) file_get_contents( $path ) : '';
		$lines    = preg_split( '/\R/', rtrim( $original, "\r\n" ) );
		$lines    = false === $lines ? array() : $lines;
		foreach ( $entries as $entry ) {
			if ( ! in_array( $entry, $lines, true ) ) {
				$lines[] = $entry;
			}
		}
		$updated = implode( PHP_EOL, $lines ) . PHP_EOL;
	} elseif ( 'sftp' === $type ) {
		$path = $project_root . '/.vscode/sftp.json';
		if ( ! is_file( $path ) ) {
			throw new RuntimeException( 'SFTP configuration does not exist: ' . $path );
		}
		$original = (string) file_get_contents( $path );
		$data     = json_decode( $original, true, 512, JSON_THROW_ON_ERROR );
		if ( ! is_array( $data ) || ! isset( $data['ignore'] ) || ! is_array( $data['ignore'] ) ) {
			throw new RuntimeException( 'SFTP configuration must be a JSON object with an ignore array.' );
		}
		$entries = array( '.vscode', '.ddev', '.test-tools', '.wp-test.php', 'wp-config-ddev.php', 'wp-config.php.before-ddev', 'wp-config.php.before-test-tools-*', 'composer.json', 'composer.lock', 'composer.json.before-test-tools-*' );
		foreach ( $entries as $entry ) {
			if ( ! in_array( $entry, $data['ignore'], true ) ) {
				$data['ignore'][] = $entry;
			}
		}
		$updated = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
	} else {
		throw new RuntimeException( "Unknown ignore-file type '{$type}'. Expected git or sftp." );
	}

	if ( $updated === $original ) {
		return array(
			'changed' => false,
			'backup'  => null,
			'path'    => $path,
		);
	}
	if ( $check_only ) {
		return array(
			'changed' => true,
			'backup'  => null,
			'path'    => $path,
		);
	}
	$backup = null;
	if ( is_file( $path ) ) {
		if ( 'sftp' === $type ) {
			$backup_directory = $project_root . '/.test-tools/runtime/setup-backups';
			if ( ! is_dir( $backup_directory ) && ! mkdir( $backup_directory, 0700, true ) ) {
				throw new RuntimeException( 'Could not create the private setup backup directory.' );
			}
			$backup = wp_test_ignore_backup_name( $backup_directory . '/sftp.json' );
			if ( false === file_put_contents( $backup, $original ) ) {
				throw new RuntimeException( 'Could not create the private SFTP configuration backup.' );
			}
			chmod( $backup, 0600 );
		} else {
			$backup = wp_test_ignore_backup_name( $path );
			if ( ! copy( $path, $backup ) ) {
				throw new RuntimeException( 'Could not back up ignore file: ' . $path );
			}
		}
	}
	wp_test_ignore_atomic_write( $path, $updated );
	return array(
		'changed' => true,
		'backup'  => $backup,
		'path'    => $path,
	);
}

/**
 * Write an ignore file through a temporary file.
 *
 * @param string $path     Destination path.
 * @param string $contents Complete file contents.
 * @throws RuntimeException When the destination cannot be written atomically.
 */
function wp_test_ignore_atomic_write( string $path, string $contents ): void {
	$directory = dirname( $path );
	if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
		throw new RuntimeException( 'Ignore-file directory is not writable: ' . $directory );
	}
	$temp = tempnam( $directory, '.ignore-test-tools-' );
	if ( false === $temp || false === file_put_contents( $temp, $contents ) || ! rename( $temp, $path ) ) {
		if ( is_string( $temp ) && file_exists( $temp ) ) {
			unlink( $temp );
		}
		throw new RuntimeException( 'Could not write ignore file safely: ' . $path );
	}
}

/**
 * Return an unused dated backup name.
 *
 * @param string $path File being backed up.
 * @return string
 */
function wp_test_ignore_backup_name( string $path ): string {
	$base   = $path . '.before-test-tools-' . gmdate( 'Ymd\THis\Z' );
	$backup = $base;
	$suffix = 1;
	while ( file_exists( $backup ) ) {
		$backup = $base . '-' . $suffix;
		++$suffix;
	}
	return $backup;
}

if ( realpath( (string) ( $argv[0] ?? '' ) ) === __FILE__ ) {
	$arguments  = array_slice( $argv, 1 );
	$check_only = in_array( '--check', $arguments, true );
	$arguments  = array_values( array_diff( $arguments, array( '--check' ) ) );
	if ( 2 !== count( $arguments ) ) {
		fwrite( STDERR, "ERROR: Usage: php update-ignore-files.php [--check] <wordpress-root> <git|sftp>\n" );
		exit( 1 );
	}
	try {
		echo json_encode( wp_test_update_ignore_file( $arguments[0], $arguments[1], $check_only ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), PHP_EOL;
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
