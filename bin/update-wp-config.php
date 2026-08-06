<?php
/**
 * Safely adapt a standard wp-config.php for local DDEV use.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.DiscouragedPHPFunctions -- Standalone setup runs before WordPress is loaded and must validate a PHP file.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Command errors must preserve exact local paths and validation details.

require_once __DIR__ . '/inspect-setup.php';
require_once __DIR__ . '/file-tools.php';

/**
 * Update wp-config.php or report why it cannot be updated.
 *
 * @param string $path       wp-config.php path.
 * @param bool   $check_only Report a pending change without writing.
 * @return array<string, mixed>
 * @throws RuntimeException When the file cannot be safely recognized, validated, backed up, or written.
 */
function anyape_wp_test_tools_update_wp_config( string $path, bool $check_only = false ): array {
	if ( ! is_file( $path ) ) {
		throw new RuntimeException( 'wp-config.php does not exist: ' . $path );
	}
	$original   = (string) file_get_contents( $path );
	$inspection = anyape_wp_test_tools_inspect_wp_config( $original );
	if ( 'ready' === $inspection['status'] ) {
		return array(
			'changed' => false,
			'status'  => 'ready',
			'backup'  => null,
		);
	}
	if ( 'update' !== $inspection['status'] ) {
		throw new RuntimeException( implode( ' ', (array) $inspection['reasons'] ) . ' Leave wp-config.php unchanged and apply the manual block from SETUP.md.' );
	}

	$updated = ! empty( $inspection['has_ddev_flag'] )
		? anyape_wp_test_tools_complete_ddev_wp_config( $original )
		: anyape_wp_test_tools_build_wp_config( $original );
	if ( $updated === $original ) {
		return array(
			'changed' => false,
			'status'  => 'ready',
			'backup'  => null,
		);
	}
	if ( $check_only ) {
		return array(
			'changed' => true,
			'status'  => 'update',
			'backup'  => null,
		);
	}

	$backup = anyape_wp_test_tools_unused_backup_path( $path );
	if ( ! copy( $path, $backup ) ) {
		throw new RuntimeException( 'Could not create wp-config.php backup: ' . $backup );
	}
	$file_permissions = fileperms( $path );
	$permissions      = false !== $file_permissions ? $file_permissions & 0777 : null;
	$validation_path  = null;

	try {
		$validation_path = tempnam( dirname( $path ), '.wp-config-anyape-wp-test-tools-' );
		if ( false === $validation_path ) {
			throw new RuntimeException( 'Could not create a temporary file beside wp-config.php.' );
		}
		anyape_wp_test_tools_atomic_write( $validation_path, $updated, $permissions );
		anyape_wp_test_tools_assert_php_syntax( $validation_path );
		anyape_wp_test_tools_atomic_write( $path, $updated, $permissions );
		anyape_wp_test_tools_assert_php_syntax( $path );
		$final = anyape_wp_test_tools_inspect_wp_config( (string) file_get_contents( $path ) );
		if ( 'ready' !== $final['status'] ) {
			throw new RuntimeException( 'The updated wp-config.php did not pass the DDEV structure check.' );
		}
	} catch ( Throwable $error ) {
		anyape_wp_test_tools_atomic_write( $path, (string) file_get_contents( $backup ), $permissions );
		throw new RuntimeException( $error->getMessage() . ' The original wp-config.php was restored from ' . $backup . '.', 0, $error );
	} finally {
		if ( is_string( $validation_path ) && file_exists( $validation_path ) ) {
			unlink( $validation_path );
		}
	}

	return array(
		'changed' => true,
		'status'  => 'ready',
		'backup'  => $backup,
	);
}

/**
 * Complete missing debug settings in an otherwise supported DDEV configuration.
 *
 * @param string $contents wp-config.php contents.
 * @return string
 * @throws RuntimeException When a safe insertion point cannot be found.
 */
function anyape_wp_test_tools_complete_ddev_wp_config( string $contents ): string {
	$settings = array(
		'WP_DEBUG'         => "defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );",
		'WP_DEBUG_LOG'     => "defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', \$is_ddev );",
		'WP_DEBUG_DISPLAY' => "defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', false );",
	);
	$missing  = array();
	foreach ( $settings as $constant => $line ) {
		if ( ! str_contains( $contents, $constant ) ) {
			$missing[] = $line;
		}
	}
	if ( array() === $missing ) {
		return $contents;
	}
	if ( 1 !== preg_match( '/^.*(?:WP_DEBUG_LOG|WP_DEBUG).*$(?:\R|$)/m', $contents, $anchor, PREG_OFFSET_CAPTURE ) ) {
		throw new RuntimeException( 'Could not locate the existing WordPress debug settings.' );
	}
	$position = $anchor[0][1] + strlen( $anchor[0][0] );
	return substr( $contents, 0, $position ) . implode( "\n", $missing ) . "\n" . substr( $contents, $position );
}

/**
 * Build the supported DDEV form from a standard WordPress configuration.
 *
 * @param string $contents wp-config.php contents.
 * @return string
 * @throws RuntimeException When the standard configuration cannot be transformed safely.
 */
function anyape_wp_test_tools_build_wp_config( string $contents ): string {
	$markers = array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' );
	$matches = array();
	foreach ( $markers as $marker ) {
		if ( 1 !== preg_match( "/^.*define\s*\(\s*['\"]{$marker}['\"].*(?:\R|$)/m", $contents, $match, PREG_OFFSET_CAPTURE ) ) {
			throw new RuntimeException( 'Could not identify the single ' . $marker . ' definition.' );
		}
		$matches[ $marker ] = $match[0];
	}
	$start = $matches['DB_NAME'][1];
	$end   = $matches['DB_HOST'][1] + strlen( $matches['DB_HOST'][0] );
	if ( ! ( $start < $matches['DB_USER'][1] && $matches['DB_USER'][1] < $matches['DB_PASSWORD'][1] && $matches['DB_PASSWORD'][1] < $matches['DB_HOST'][1] ) ) {
		throw new RuntimeException( 'Database definitions are not in the supported DB_NAME, DB_USER, DB_PASSWORD, DB_HOST order.' );
	}
	$database_block = substr( $contents, $start, $end - $start );
	$database_block = preg_replace( '/^/m', "\t", rtrim( $database_block, "\r\n" ) );
	$replacement    = "\$is_ddev = getenv( 'IS_DDEV_PROJECT' ) === 'true';\n\nif ( ! \$is_ddev ) {\n{$database_block}\n}\n";
	$contents       = substr( $contents, 0, $start ) . $replacement . substr( $contents, $end );

	$debug_pattern = "/^[ \t]*define\s*\(\s*['\"]WP_DEBUG['\"].*(?:\R|$)/m";
	if ( 1 !== preg_match_all( $debug_pattern, $contents ) ) {
		throw new RuntimeException( 'A standard configuration must contain exactly one WP_DEBUG definition.' );
	}
	$debug_block = "defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );\n"
		. "defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', \$is_ddev );\n"
		. "defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', false );\n";
	$contents    = (string) preg_replace( $debug_pattern, $debug_block, $contents, 1 );

	$error_log_count = preg_match_all( "/^[ \t]*ini_set\s*\(\s*['\"]error_log['\"].*(?:\R|$)/m", $contents, $error_matches, PREG_OFFSET_CAPTURE );
	if ( $error_log_count > 1 ) {
		throw new RuntimeException( 'More than one PHP error-log setting requires manual review.' );
	}
	if ( 1 === $error_log_count ) {
		$remote_line = trim( $error_matches[0][0][0] );
		$error_block = "if ( \$is_ddev ) {\n\tini_set( 'log_errors', '1' );\n\tini_set( 'error_log', __DIR__ . '/wp-content/debug.log' );\n} else {\n\t{$remote_line}\n}\n";
		$contents    = substr_replace( $contents, $error_block, $error_matches[0][0][1], strlen( $error_matches[0][0][0] ) );
	} else {
		$error_block = "\nif ( \$is_ddev ) {\n\tini_set( 'log_errors', '1' );\n\tini_set( 'error_log', __DIR__ . '/wp-content/debug.log' );\n}\n";
		$debug_end   = strpos( $contents, $debug_block );
		if ( false === $debug_end ) {
			throw new RuntimeException( 'Could not place the local PHP error-log settings.' );
		}
		$debug_end += strlen( $debug_block );
		$contents   = substr( $contents, 0, $debug_end ) . $error_block . substr( $contents, $debug_end );
	}

	if ( 1 !== preg_match( '/^.*wp-settings\.php.*$/m', $contents, $settings, PREG_OFFSET_CAPTURE ) ) {
		throw new RuntimeException( 'Could not identify the wp-settings.php include.' );
	}
	$ddev_include = "if ( \$is_ddev ) {\n\trequire_once __DIR__ . '/wp-config-ddev.php';\n}\n\n";
	$contents     = substr_replace( $contents, $ddev_include, $settings[0][1], 0 );

	return $contents;
}

if ( realpath( (string) ( $argv[0] ?? '' ) ) === __FILE__ ) {
	$arguments  = array_slice( $argv, 1 );
	$check_only = false;
	if ( in_array( '--check', $arguments, true ) ) {
		$check_only = true;
		$arguments  = array_values( array_diff( $arguments, array( '--check' ) ) );
	}
	if ( 1 !== count( $arguments ) ) {
		fwrite( STDERR, "ERROR: Usage: php update-wp-config.php [--check] <wp-config.php>\n" );
		exit( 1 );
	}
	try {
		echo json_encode( anyape_wp_test_tools_update_wp_config( $arguments[0], $check_only ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), PHP_EOL;
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
