<?php
/**
 * Validate the local WordPress debug log configuration.
 *
 * @package WpTest
 */

declare(strict_types=1);

if ( 3 !== $argc ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI validator.
	fwrite( STDERR, "Usage: validate-debug-log.php <wp-config.php> <debug.log>\n" );
	exit( 1 );
}

[, $config_file, $log_file] = $argv;

if ( ! is_file( $config_file ) || ! is_readable( $config_file ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI validator.
	fwrite( STDERR, sprintf( "ERROR: WordPress configuration is not readable: %s\n", $config_file ) );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a local config before WordPress loads.
$source = file_get_contents( $config_file );

if ( false === $source ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI validator.
	fwrite( STDERR, sprintf( "ERROR: Could not read WordPress configuration: %s\n", $config_file ) );
	exit( 1 );
}

$tokens      = token_get_all( $source );
$definitions = array();

$decode_string = static function ( string $literal ): string {
	$quote = $literal[0] ?? '';
	$value = substr( $literal, 1, -1 );

	return "'" === $quote
		? str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $value )
		: stripcslashes( $value );
};

$compact = static function ( array $items ): array {
	return array_values(
		array_filter(
			$items,
			static fn ( $token ): bool => ! is_array( $token ) || ! in_array(
				$token[0],
				array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ),
				true
			)
		)
	);
};

for ( $index = 0, $count = count( $tokens ); $index < $count; $index++ ) {
	$token = $tokens[ $index ];

	if ( ! is_array( $token ) || T_STRING !== $token[0] || 'define' !== strtolower( $token[1] ) ) {
		continue;
	}

	$cursor = $index + 1;
	while ( $cursor < $count && is_array( $tokens[ $cursor ] ) && T_WHITESPACE === $tokens[ $cursor ][0] ) {
		++$cursor;
	}

	if ( ( $tokens[ $cursor ] ?? null ) !== '(' ) {
		continue;
	}

	$arguments = array( array(), array() );
	$argument  = 0;
	$depth     = 1;

	for ( $cursor++; $cursor < $count && $depth > 0; $cursor++ ) {
		$current = $tokens[ $cursor ];

		if ( '(' === $current ) {
			++$depth;
		} elseif ( ')' === $current ) {
			--$depth;
			if ( 0 === $depth ) {
				break;
			}
		} elseif ( ',' === $current && 1 === $depth ) {
			++$argument;
			if ( 1 < $argument ) {
				break;
			}
			continue;
		}

		if ( $argument <= 1 ) {
			$arguments[ $argument ][] = $current;
		}
	}

	$name_tokens  = $compact( $arguments[0] );
	$value_tokens = $compact( $arguments[1] );

	if (
		count( $name_tokens ) !== 1 ||
		! is_array( $name_tokens[0] ) ||
		T_CONSTANT_ENCAPSED_STRING !== $name_tokens[0][0]
	) {
		continue;
	}

	$name                   = $decode_string( $name_tokens[0][1] );
	$definitions[ $name ][] = $value_tokens;
}

$value_matches = static function ( array $value_tokens, array $accepted_strings = array() ) use ( $decode_string, $config_file ): bool {
	if ( count( $value_tokens ) === 1 && is_array( $value_tokens[0] ) ) {
		[$type, $text] = $value_tokens[0];

		if ( T_STRING === $type && 'true' === strtolower( $text ) ) {
			return true;
		}

		if ( T_VARIABLE === $type && '$is_ddev' === $text ) {
			return true;
		}

		if ( T_CONSTANT_ENCAPSED_STRING === $type ) {
			return in_array( $decode_string( $text ), $accepted_strings, true );
		}
	}

	if (
		count( $value_tokens ) === 3 &&
		is_array( $value_tokens[0] ) &&
		T_DIR === $value_tokens[0][0] &&
		'.' === $value_tokens[1] &&
		is_array( $value_tokens[2] ) &&
		T_CONSTANT_ENCAPSED_STRING === $value_tokens[2][0]
	) {
		$value = dirname( $config_file ) . $decode_string( $value_tokens[2][1] );
		return in_array( $value, $accepted_strings, true );
	}

	return false;
};

$debug_definitions     = $definitions['WP_DEBUG'] ?? array();
$debug_log_definitions = $definitions['WP_DEBUG_LOG'] ?? array();

if ( ! array_filter( $debug_definitions, $value_matches ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI validator.
	fwrite( STDERR, "ERROR: Local WordPress logging requires WP_DEBUG to be true in DDEV.\n" );
	exit( 1 );
}

$accepted_log_paths = array(
	$log_file,
	'/var/www/html/wp-content/debug.log',
);

if ( ! array_filter(
	$debug_log_definitions,
	static fn ( array $value ): bool => $value_matches( $value, $accepted_log_paths )
) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI validator.
	fwrite(
		STDERR,
		"ERROR: WP_DEBUG_LOG must be true or the exact local path wp-content/debug.log.\n"
	);
	exit( 1 );
}

$directory = dirname( $log_file );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WordPress is not loaded by this CLI validator.
if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI validator.
	fwrite( STDERR, sprintf( "ERROR: WordPress content directory is missing or not writable: %s\n", $directory ) );
	exit( 1 );
}

if ( file_exists( $log_file ) && ! is_file( $log_file ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI validator.
	fwrite( STDERR, sprintf( "ERROR: WordPress debug log path is not a regular file: %s\n", $log_file ) );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- WordPress is not loaded by this CLI validator.
if ( ! file_exists( $log_file ) && ! touch( $log_file ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI validator.
	fwrite( STDERR, sprintf( "ERROR: Could not create the WordPress debug log: %s\n", $log_file ) );
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WordPress is not loaded by this CLI validator.
if ( ! is_writable( $log_file ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI validator.
	fwrite( STDERR, sprintf( "ERROR: WordPress debug log is not writable: %s\n", $log_file ) );
	exit( 1 );
}
