<?php
/**
 * Add approved local paths to Git and SFTP ignore files.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone host command runs before WordPress is loaded.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command errors must preserve exact local paths and file types.

require_once __DIR__ . '/file-tools.php';

/**
 * Update one supported ignore file.
 *
 * @param string $project_root WordPress root directory.
 * @param string $type         Either git or sftp.
 * @param bool   $check_only   Report a pending change without writing.
 * @return array<string, mixed>
 * @throws RuntimeException When the file type or contents are unsupported.
 */
function anyape_wp_test_tools_update_ignore_file( string $project_root, string $type, bool $check_only = false ): array {
	$project_root = rtrim( $project_root, DIRECTORY_SEPARATOR );
	if ( 'git' === $type ) {
		$path    = $project_root . '/.gitignore';
		$entries = array( '.anyape-wp-test-tools.php', 'wp-config-ddev.php', 'wp-config.php.before-ddev', 'wp-config.php.before-anyape-wp-test-tools-*', 'composer.json.before-anyape-wp-test-tools-*', '.gitignore.before-anyape-wp-test-tools-*' );
		$modules = $project_root . '/.gitmodules';
		if ( ! is_file( $modules ) || ! str_contains( (string) file_get_contents( $modules ), '.anyape-wp-test-tools' ) ) {
			array_unshift( $entries, '.anyape-wp-test-tools/' );
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
		$data = anyape_wp_test_tools_read_json_object( $path );
		if ( ! isset( $data['ignore'] ) || ! is_array( $data['ignore'] ) ) {
			throw new RuntimeException( 'SFTP configuration must be a JSON object with an ignore array: ' . $path );
		}
		$original = (string) file_get_contents( $path );
		$entries  = array( '.vscode', '.ddev', '.anyape-wp-test-tools', '.anyape-wp-test-tools.php', 'wp-config-ddev.php', 'wp-config.php.before-ddev', 'wp-config.php.before-anyape-wp-test-tools-*', 'composer.json', 'composer.lock', 'composer.json.before-anyape-wp-test-tools-*' );
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

	$backup      = null;
	$permissions = null;
	if ( is_file( $path ) ) {
		$file_permissions = fileperms( $path );
		$permissions      = false !== $file_permissions ? $file_permissions & 0777 : null;
		if ( 'sftp' === $type ) {
			$backup_directory = $project_root . '/.anyape-wp-test-tools/runtime/setup-backups';
			if ( ! is_dir( $backup_directory ) && ! mkdir( $backup_directory, 0700, true ) ) {
				throw new RuntimeException( 'Could not create the private setup backup directory.' );
			}
			$backup = anyape_wp_test_tools_unused_backup_path( $backup_directory . '/sftp.json' );
			anyape_wp_test_tools_atomic_write( $backup, $original, 0600 );
		} else {
			$backup = anyape_wp_test_tools_unused_backup_path( $path );
			if ( ! copy( $path, $backup ) ) {
				throw new RuntimeException( 'Could not back up ignore file: ' . $path );
			}
		}
	}
	anyape_wp_test_tools_atomic_write( $path, $updated, $permissions );
	return array(
		'changed' => true,
		'backup'  => $backup,
		'path'    => $path,
	);
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
		echo json_encode( anyape_wp_test_tools_update_ignore_file( $arguments[0], $arguments[1], $check_only ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), PHP_EOL;
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
