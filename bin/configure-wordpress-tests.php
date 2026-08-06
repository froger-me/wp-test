<?php
/**
 * Configure the generated WordPress test library.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

if ( 6 !== $argc ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI configurator.
	fwrite(
		STDERR,
		"Usage: configure-wordpress-tests.php <config> <core-dir> <db-name> <db-host> <prefix>\n"
	);
	exit( 1 );
}

[, $file, $core_dir, $test_database, $database_host, $test_table_prefix] = $argv;
$core_dir = rtrim( $core_dir, '/' ) . '/';

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a local generated file before WordPress loads.
$config = file_get_contents( $file );

if ( false === $config ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI configurator.
	fwrite( STDERR, "ERROR: Could not read wp-tests-config.php.\n" );
	exit( 1 );
}

// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- var_export generates PHP literals; it is not diagnostic output.
$replacements = array(
	"/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/" =>
		"define( 'DB_NAME', " . var_export( $test_database, true ) . ' );',
	"/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/" =>
		"define( 'DB_USER', 'db' );",
	"/define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/" =>
		"define( 'DB_PASSWORD', 'db' );",
	"/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/" =>
		"define( 'DB_HOST', " . var_export( $database_host, true ) . ' );',
	'~\\$table_prefix\\s*=\\s*[\'\"][^\'\"]*[\'\"]\\s*;~' =>
		'$table_prefix = ' . var_export( $test_table_prefix, true ) . ';',
);

foreach ( $replacements as $pattern => $replacement ) {
	$updated = preg_replace( $pattern, $replacement, $config, 1, $count );

	if ( null === $updated || 1 !== $count ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI configurator.
		fwrite(
			STDERR,
			sprintf(
				"ERROR: Could not update generated WordPress test configuration using pattern %s.\n",
				$pattern
			)
		);
		exit( 1 );
	}

	$config = $updated;
}

$updated = preg_replace(
	"/define\(\s*['\"]ABSPATH['\"]\s*,\s*.+?\);/",
	"define( 'ABSPATH', " . var_export( $core_dir, true ) . ' );',
	$config,
	1,
	$count
);
if ( null === $updated || 1 !== $count ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI configurator.
	fwrite( STDERR, "ERROR: Could not update ABSPATH in the generated WordPress test configuration.\n" );
	exit( 1 );
}
$config = $updated;
// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_var_export

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WordPress is not loaded by this CLI configurator.
if ( false === file_put_contents( $file, $config ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WordPress is not loaded by this CLI configurator.
	fwrite( STDERR, "ERROR: Could not write wp-tests-config.php.\n" );
	exit( 1 );
}
