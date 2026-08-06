<?php
/**
 * Remove Anyape WP Test Tools changes from shared project files.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone host command runs without WordPress.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Errors must preserve exact local paths.

/**
 * Return the public root Composer commands owned by Anyape WP Test Tools.
 *
 * @param string $composer_file Anyape WP Test Tools composer.json path.
 * @return array<string, string>
 * @throws RuntimeException When the scripts object is missing.
 */
function anyape_wp_test_tools_uninstall_composer_commands( string $composer_file ): array {
	$data    = json_decode( (string) file_get_contents( $composer_file ), true, 512, JSON_THROW_ON_ERROR );
	$scripts = $data['scripts'] ?? null;
	if ( ! is_array( $scripts ) ) {
		throw new RuntimeException( 'Anyape WP Test Tools composer.json does not contain a scripts object.' );
	}

	$commands = array();
	foreach ( $scripts as $name => $command ) {
		if ( is_string( $name ) && is_string( $command ) && str_starts_with( $command, 'bash ' ) ) {
			$commands[ $name ] = 'bash .anyape-wp-test-tools/' . substr( $command, 5 );
		}
	}
	return $commands;
}

/**
 * Reverse the recognized DDEV arrangement without using an installation backup.
 *
 * @param string $contents Current wp-config.php contents.
 * @return string
 * @throws RuntimeException When the file is not the arrangement created by guided setup.
 */
function anyape_wp_test_tools_uninstall_wp_config_contents( string $contents ): string {
	$database_pattern = '~\$is_ddev = getenv\( \'IS_DDEV_PROJECT\' \) === \'true\';\R\Rif \( ! \$is_ddev \) \{\R(.*?)\R\}\R~s';
	if ( 1 !== preg_match( $database_pattern, $contents, $database_match ) ) {
		throw new RuntimeException( 'wp-config.php does not contain the recognized guided-setup database arrangement.' );
	}
	$database_block = (string) preg_replace( '/^\\t/m', '', $database_match[1] );
	$contents       = (string) preg_replace( $database_pattern, $database_block . "\n", $contents, 1 );

	$debug_block = <<<'PHP'
defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );
defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', $is_ddev );
defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', false );
PHP;
	if ( 1 !== substr_count( $contents, $debug_block ) ) {
		throw new RuntimeException( 'wp-config.php does not contain the recognized guided-setup debug arrangement.' );
	}
	$contents = str_replace( $debug_block, "define( 'WP_DEBUG', false );", $contents );

	$error_with_remote = '~if \( \$is_ddev \) \{\R\tini_set\( \'log_errors\', \'1\' \);\R\tini_set\( \'error_log\', __DIR__ \. \'/wp-content/debug\.log\' \);\R\} else \{\R\t(ini_set\([^\r\n]+\);)\R\}\R?~';
	if ( 1 === preg_match( $error_with_remote, $contents, $error_match ) ) {
		$contents = (string) preg_replace( $error_with_remote, $error_match[1] . "\n", $contents, 1 );
	} else {
		$error_without_remote = '~\R?if \( \$is_ddev \) \{\R\tini_set\( \'log_errors\', \'1\' \);\R\tini_set\( \'error_log\', __DIR__ \. \'/wp-content/debug\.log\' \);\R\}\R?~';
		if ( 1 !== preg_match( $error_without_remote, $contents ) ) {
			throw new RuntimeException( 'wp-config.php does not contain the recognized guided-setup error-log arrangement.' );
		}
		$contents = (string) preg_replace( $error_without_remote, "\n", $contents, 1 );
	}

	$ddev_include = <<<'PHP'
if ( $is_ddev ) {
	require_once __DIR__ . '/wp-config-ddev.php';
}

PHP;
	if ( 1 !== substr_count( $contents, $ddev_include ) ) {
		throw new RuntimeException( 'wp-config.php does not contain the recognized DDEV startup include.' );
	}
	$contents = str_replace( $ddev_include, '', $contents );

	foreach ( array( '$is_ddev', 'IS_DDEV_PROJECT', 'wp-config-ddev.php', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY' ) as $removed_value ) {
		if ( str_contains( $contents, $removed_value ) ) {
			throw new RuntimeException( 'wp-config.php still contains a DDEV-only value after reversal: ' . $removed_value );
		}
	}
	foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ) as $constant ) {
		if ( 1 !== preg_match_all( "/define\\s*\\(\\s*['\"]{$constant}['\"]/", $contents ) ) {
			throw new RuntimeException( 'Restored wp-config.php must contain exactly one ' . $constant . ' definition.' );
		}
	}
	return $contents;
}

/**
 * Remove exact Anyape WP Test Tools lines from a Git ignore file.
 *
 * @param string $contents Current ignore-file contents.
 * @return string
 */
function anyape_wp_test_tools_uninstall_gitignore_contents( string $contents ): string {
	$owned = array(
		'.anyape-wp-test-tools/',
		'.anyape-wp-test-tools.php',
		'wp-config-ddev.php',
		'wp-config.php.before-ddev',
		'wp-config.php.before-anyape-wp-test-tools-*',
		'composer.json.before-anyape-wp-test-tools-*',
		'.gitignore.before-anyape-wp-test-tools-*',
	);
	$lines = preg_split( '/\R/', rtrim( $contents, "\r\n" ) );
	$lines = false === $lines ? array() : array_values( array_filter( $lines, static fn ( string $line ): bool => ! in_array( $line, $owned, true ) ) );
	return array() === $lines ? '' : implode( PHP_EOL, $lines ) . PHP_EOL;
}

/**
 * Remove exact Anyape WP Test Tools entries from SFTP upload exclusions.
 *
 * @param string $contents              Current SFTP JSON contents.
 * @param bool   $root_composer_removed Whether the setup-created Composer file will be deleted.
 * @return string
 * @throws RuntimeException When the ignore list is missing.
 */
function anyape_wp_test_tools_uninstall_sftp_contents( string $contents, bool $root_composer_removed ): string {
	$data = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $data ) || ! isset( $data['ignore'] ) || ! is_array( $data['ignore'] ) ) {
		throw new RuntimeException( 'SFTP configuration must be a JSON object with an ignore array.' );
	}
	$owned = array(
		'.ddev',
		'.anyape-wp-test-tools',
		'.anyape-wp-test-tools.php',
		'wp-config-ddev.php',
		'wp-config.php.before-ddev',
		'wp-config.php.before-anyape-wp-test-tools-*',
		'composer.json.before-anyape-wp-test-tools-*',
	);
	if ( $root_composer_removed ) {
		$owned[] = 'composer.json';
		$owned[] = 'composer.lock';
	}
	$data['ignore'] = array_values( array_filter( $data['ignore'], static fn ( mixed $entry ): bool => ! is_string( $entry ) || ! in_array( $entry, $owned, true ) ) );
	return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
}

/**
 * Return root Composer contents after removing owned commands, or null for the setup-created empty file.
 *
 * @param string                $contents       Current root composer.json contents.
 * @param array<string, string> $owned_commands Exact commands owned by Anyape WP Test Tools.
 * @return string|null
 * @throws RuntimeException When Composer JSON is not an object.
 */
function anyape_wp_test_tools_uninstall_root_composer_contents( string $contents, array $owned_commands ): ?string {
	$data = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'Root composer.json must contain an object.' );
	}
	if ( is_array( $data['scripts'] ?? null ) ) {
		foreach ( $owned_commands as $name => $command ) {
			if ( ( $data['scripts'][ $name ] ?? null ) === $command ) {
				unset( $data['scripts'][ $name ] );
			}
		}
		if ( array() === $data['scripts'] ) {
			unset( $data['scripts'] );
		}
	}
	$setup_created = array(
		'name'    => 'local/wordpress-development-site',
		'version' => '1.0.0',
		'private' => true,
		'config'  => array( 'process-timeout' => 0 ),
	);
	if ( $data === $setup_created ) {
		return null;
	}
	return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
}

/**
 * Validate PHP contents using a temporary file beside the destination.
 *
 * @param string $path     Destination wp-config.php path.
 * @param string $contents Proposed restored contents.
 * @throws RuntimeException When temporary writing or PHP validation fails.
 */
function anyape_wp_test_tools_uninstall_validate_php( string $path, string $contents ): void {
	$temp = tempnam( dirname( $path ), '.anyape-wp-test-tools-uninstall-' );
	if ( false === $temp || false === file_put_contents( $temp, $contents ) ) {
		throw new RuntimeException( 'Could not create a temporary wp-config.php for validation.' );
	}
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $temp ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Validate a generated host-side PHP file before replacement.
	unlink( $temp );
	if ( 0 !== $status ) {
		throw new RuntimeException( 'Restored wp-config.php failed PHP syntax validation: ' . implode( ' ', $output ) );
	}
}

/**
 * Write a complete file atomically.
 *
 * @param string $path     Destination path.
 * @param string $contents Complete replacement contents.
 * @throws RuntimeException When the file cannot be replaced.
 */
function anyape_wp_test_tools_uninstall_write( string $path, string $contents ): void {
	$temp = tempnam( dirname( $path ), '.anyape-wp-test-tools-uninstall-' );
	if ( false === $temp || false === file_put_contents( $temp, $contents ) || ! rename( $temp, $path ) ) {
		if ( is_string( $temp ) && file_exists( $temp ) ) {
			unlink( $temp );
		}
		throw new RuntimeException( 'Could not safely update ' . $path );
	}
}

/**
 * Validate or apply removal from shared project files.
 *
 * @param string $project_root  WordPress project root.
 * @param string $tool_composer Anyape WP Test Tools composer.json path.
 * @param bool   $check_only    Validate without changing files.
 * @return array<string, bool>
 * @throws RuntimeException When safe restoration cannot be proven.
 */
function anyape_wp_test_tools_uninstall_project_files( string $project_root, string $tool_composer, bool $check_only ): array {
	$project_root = rtrim( $project_root, DIRECTORY_SEPARATOR );
	$wp_config    = $project_root . '/wp-config.php';
	if ( ! is_file( $wp_config ) || ! is_file( $tool_composer ) ) {
		throw new RuntimeException( 'The WordPress or Anyape WP Test Tools Composer file is missing.' );
	}

	$restored_wp_config = anyape_wp_test_tools_uninstall_wp_config_contents( (string) file_get_contents( $wp_config ) );
	anyape_wp_test_tools_uninstall_validate_php( $wp_config, $restored_wp_config );

	$owned_commands       = anyape_wp_test_tools_uninstall_composer_commands( $tool_composer );
	$root_composer        = $project_root . '/composer.json';
	$root_composer_result = is_file( $root_composer )
		? anyape_wp_test_tools_uninstall_root_composer_contents( (string) file_get_contents( $root_composer ), $owned_commands )
		: null;
	$remove_root_composer = is_file( $root_composer ) && null === $root_composer_result;

	$gitignore        = $project_root . '/.gitignore';
	$gitignore_result = is_file( $gitignore ) ? anyape_wp_test_tools_uninstall_gitignore_contents( (string) file_get_contents( $gitignore ) ) : null;
	$sftp             = $project_root . '/.vscode/sftp.json';
	$sftp_result      = is_file( $sftp ) ? anyape_wp_test_tools_uninstall_sftp_contents( (string) file_get_contents( $sftp ), $remove_root_composer ) : null;
	foreach ( array_filter( array( $root_composer, $gitignore, $sftp ), 'is_file' ) as $shared_file ) {
		if ( ! is_writable( dirname( $shared_file ) ) ) {
			throw new RuntimeException( 'Shared project directory is not writable: ' . dirname( $shared_file ) );
		}
	}

	if ( ! $check_only ) {
		anyape_wp_test_tools_uninstall_write( $wp_config, $restored_wp_config );
		if ( is_file( $root_composer ) ) {
			if ( null === $root_composer_result ) {
				unlink( $root_composer );
				is_file( $project_root . '/composer.lock' ) && unlink( $project_root . '/composer.lock' );
			} else {
				anyape_wp_test_tools_uninstall_write( $root_composer, $root_composer_result );
			}
		}
		if ( null !== $gitignore_result ) {
			'' === $gitignore_result ? unlink( $gitignore ) : anyape_wp_test_tools_uninstall_write( $gitignore, $gitignore_result );
		}
		if ( null !== $sftp_result ) {
			anyape_wp_test_tools_uninstall_write( $sftp, $sftp_result );
		}
	}

	return array(
		'wp_config_restored'    => true,
		'root_composer_removed' => $remove_root_composer,
		'gitignore_present'     => is_file( $gitignore ),
		'sftp_present'          => is_file( $sftp ),
	);
}

if ( realpath( (string) ( $argv[0] ?? '' ) ) === __FILE__ ) {
	$arguments  = array_slice( $argv, 1 );
	$check_only = in_array( '--check', $arguments, true );
	$arguments  = array_values( array_diff( $arguments, array( '--check' ) ) );
	if ( 2 !== count( $arguments ) ) {
		fwrite( STDERR, "ERROR: Usage: php uninstall-project.php [--check] <wordpress-root> <tool-composer.json>\n" );
		exit( 1 );
	}
	try {
		echo json_encode( anyape_wp_test_tools_uninstall_project_files( $arguments[0], $arguments[1], $check_only ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), PHP_EOL;
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
