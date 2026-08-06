<?php
/**
 * Merge toolkit commands into the WordPress root composer.json.
 *
 * @package WpTest
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone host command runs before WordPress is loaded.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command errors must preserve exact command names and local paths.

/**
 * Merge root Composer commands without replacing unrelated content.
 *
 * @param string $root_file    Root composer.json path.
 * @param string $toolkit_file Toolkit composer.json path.
 * @param bool   $check_only   Report a pending change without writing.
 * @return array<string, mixed>
 * @throws RuntimeException When JSON is invalid or a command conflicts.
 */
function wp_test_update_root_composer( string $root_file, string $toolkit_file, bool $check_only = false ): array {
	$toolkit = wp_test_read_json_file( $toolkit_file );
	$root    = is_file( $root_file ) ? wp_test_read_json_file( $root_file ) : array();
	$scripts = $toolkit['scripts'] ?? null;
	if ( ! is_array( $scripts ) ) {
		throw new RuntimeException( 'Toolkit composer.json does not contain a scripts object.' );
	}
	$expected = array();
	foreach ( $scripts as $name => $command ) {
		if ( ! is_string( $name ) || ! is_string( $command ) || ! str_starts_with( $command, 'bash ' ) ) {
			continue;
		}
		$expected[ $name ] = 'bash .test-tools/' . substr( $command, strlen( 'bash ' ) );
	}

	$root['name']    = $root['name'] ?? 'local/wordpress-development-site';
	$root['version'] = $root['version'] ?? '1.0.0';
	$root['private'] = $root['private'] ?? true;
	if ( ! is_array( $root['config'] ?? null ) ) {
		$root['config'] = array();
	}
	if ( isset( $root['config']['process-timeout'] ) && 0 !== $root['config']['process-timeout'] ) {
		throw new RuntimeException( 'Root composer.json has a conflicting config.process-timeout value. Set it to 0 before setup.' );
	}
	$root['config']['process-timeout'] = 0;
	if ( ! is_array( $root['scripts'] ?? null ) ) {
		$root['scripts'] = array();
	}
	foreach ( $expected as $name => $command ) {
		if ( isset( $root['scripts'][ $name ] ) && $root['scripts'][ $name ] !== $command ) {
			$without_bash = str_starts_with( $command, 'bash ' ) ? substr( $command, 5 ) : $command;
			if ( $root['scripts'][ $name ] !== $without_bash ) {
				throw new RuntimeException( "Root composer.json command '{$name}' conflicts with the toolkit command. Rename or remove it before setup." );
			}
		}
		$root['scripts'][ $name ] = $command;
	}

	$encoded  = json_encode( $root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
	$original = is_file( $root_file ) ? (string) file_get_contents( $root_file ) : '';
	if ( $encoded === $original ) {
		return array(
			'changed' => false,
			'backup'  => null,
		);
	}
	if ( $check_only ) {
		return array(
			'changed' => true,
			'backup'  => null,
		);
	}

	$backup = null;
	if ( is_file( $root_file ) ) {
		$backup = wp_test_composer_backup_name( $root_file );
		if ( ! copy( $root_file, $backup ) ) {
			throw new RuntimeException( 'Could not back up root composer.json.' );
		}
	}
	wp_test_composer_atomic_write( $root_file, $encoded );
	wp_test_read_json_file( $root_file );
	return array(
		'changed' => true,
		'backup'  => $backup,
	);
}

/**
 * Read a JSON object.
 *
 * @param string $path JSON file path.
 * @return array<string, mixed>
 * @throws RuntimeException When the file is missing or does not contain an object.
 */
function wp_test_read_json_file( string $path ): array {
	if ( ! is_file( $path ) ) {
		throw new RuntimeException( 'JSON file does not exist: ' . $path );
	}
	$data = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'JSON file must contain an object: ' . $path );
	}
	return $data;
}

/**
 * Write a complete file through a temporary file in the same directory.
 *
 * @param string $path     Destination path.
 * @param string $contents Complete JSON contents.
 * @throws RuntimeException When the destination cannot be written atomically.
 */
function wp_test_composer_atomic_write( string $path, string $contents ): void {
	$directory = dirname( $path );
	if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
		throw new RuntimeException( 'Composer destination directory is not writable: ' . $directory );
	}
	$temp = tempnam( $directory, '.composer-test-tools-' );
	if ( false === $temp || false === file_put_contents( $temp, $contents ) || ! rename( $temp, $path ) ) {
		if ( is_string( $temp ) && file_exists( $temp ) ) {
			unlink( $temp );
		}
		throw new RuntimeException( 'Could not write root composer.json safely.' );
	}
}

/**
 * Return an unused dated backup name.
 *
 * @param string $path File being backed up.
 * @return string
 */
function wp_test_composer_backup_name( string $path ): string {
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
		fwrite( STDERR, "ERROR: Usage: php update-root-composer.php [--check] <root-composer.json> <toolkit-composer.json>\n" );
		exit( 1 );
	}
	try {
		echo json_encode( wp_test_update_root_composer( $arguments[0], $arguments[1], $check_only ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), PHP_EOL;
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
