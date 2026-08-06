<?php
/**
 * Merge Anyape WP Test Tools commands into the WordPress root composer.json.
 *
 * @package AnyapeWPTestTools
 */
declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone host command runs before WordPress is loaded.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command errors must preserve exact command names and local paths.

require_once __DIR__ . '/file-tools.php';

/**
 * Merge root Composer commands without replacing unrelated content.
 *
 * @param string $root_file    Root composer.json path.
 * @param string $anyape_wp_test_tools_file Anyape WP Test Tools composer.json path.
 * @param bool   $check_only   Report a pending change without writing.
 * @return array<string, mixed>
 * @throws RuntimeException When JSON is invalid or a command conflicts.
 */
function anyape_wp_test_tools_update_root_composer( string $root_file, string $anyape_wp_test_tools_file, bool $check_only = false ): array {
	$anyape_wp_test_tools = anyape_wp_test_tools_read_json_object( $anyape_wp_test_tools_file );
	$root                 = is_file( $root_file ) ? anyape_wp_test_tools_read_json_object( $root_file ) : array();
	$scripts              = $anyape_wp_test_tools['scripts'] ?? null;
	if ( ! is_array( $scripts ) ) {
		throw new RuntimeException( 'Anyape WP Test Tools composer.json does not contain a scripts object.' );
	}
	$expected = array();
	foreach ( $scripts as $name => $command ) {
		if ( ! is_string( $name ) || ! is_string( $command ) || ! str_starts_with( $command, 'bash ' ) ) {
			continue;
		}
		$expected[ $name ] = 'bash .anyape-wp-test-tools/' . substr( $command, strlen( 'bash ' ) );
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
				throw new RuntimeException( "Root composer.json command '{$name}' conflicts with Anyape WP Test Tools command. Rename or remove it before setup." );
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

	$backup      = null;
	$permissions = null;
	if ( is_file( $root_file ) ) {
		$backup = anyape_wp_test_tools_unused_backup_path( $root_file );
		if ( ! copy( $root_file, $backup ) ) {
			throw new RuntimeException( 'Could not back up root composer.json.' );
		}
		$file_permissions = fileperms( $root_file );
		$permissions      = false !== $file_permissions ? $file_permissions & 0777 : null;
	}
	anyape_wp_test_tools_atomic_write( $root_file, $encoded, $permissions );
	anyape_wp_test_tools_read_json_object( $root_file );
	return array(
		'changed' => true,
		'backup'  => $backup,
	);
}

if ( realpath( (string) ( $argv[0] ?? '' ) ) === __FILE__ ) {
	$arguments  = array_slice( $argv, 1 );
	$check_only = in_array( '--check', $arguments, true );
	$arguments  = array_values( array_diff( $arguments, array( '--check' ) ) );
	if ( 2 !== count( $arguments ) ) {
		fwrite( STDERR, "ERROR: Usage: php update-root-composer.php [--check] <root-composer.json> <anyape-wp-test-tools-composer.json>\n" );
		exit( 1 );
	}
	try {
		echo json_encode( anyape_wp_test_tools_update_root_composer( $arguments[0], $arguments[1], $check_only ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), PHP_EOL;
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
