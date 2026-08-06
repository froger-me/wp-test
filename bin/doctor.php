<?php
/**
 * Diagnose the local test environment without mutating it.
 *
 * @package WpTest
 */

declare(strict_types=1);

$toolkit_root = dirname( __DIR__ );
$project_root = dirname( $toolkit_root );
$config       = require $toolkit_root . '/config.php';
$quiet        = in_array( '--quiet', $argv, true );

$diagnostic_errors   = array();
$diagnostic_warnings = array();

$check = static function (
	bool $condition,
	string $success,
	string $failure
) use (
	&$diagnostic_errors,
	$quiet
): void {
	if ( $condition ) {
		if ( ! $quiet ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI diagnostic output, not HTML.
			printf( "[OK] %s\n", $success );
		}
		return;
	}

	$diagnostic_errors[] = $failure;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI diagnostic output, not HTML.
	printf( "[ERROR] %s\n", $failure );
};

$warning = static function ( string $message ) use ( &$diagnostic_warnings, $quiet ): void {
	$diagnostic_warnings[] = $message;

	if ( ! $quiet ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI diagnostic output, not HTML.
		printf( "[WARN] %s\n", $message );
	}
};

$check(
	getenv( 'IS_DDEV_PROJECT' ) === 'true',
	'Execution is inside a DDEV project.',
	'IS_DDEV_PROJECT is not true; run the command through the active DDEV project.'
);

foreach ( array( 'wp-admin', 'wp-content', 'wp-includes' ) as $directory ) {
	$required_path = $project_root . '/' . $directory;
	$check(
		is_dir( $required_path ),
		sprintf( 'WordPress directory exists: %s', $directory ),
		sprintf( 'WordPress root is missing required directory: %s', $required_path )
	);
}

$working_database  = (string) ( $config['working_database'] ?? '' );
$test_database     = (string) ( $config['test_database'] ?? '' );
$database_host     = (string) ( $config['database_host'] ?? '' );
$test_table_prefix = (string) ( $config['table_prefix'] ?? '' );

$check(
	'db' === $working_database,
	'Working database is configured as db.',
	sprintf( 'Unsafe working database configuration: expected db, got %s.', $working_database )
);

$check(
	'wp_tests' === $test_database,
	'PHPUnit database is configured as wp_tests.',
	sprintf( 'Unsafe PHPUnit database configuration: expected wp_tests, got %s.', $test_database )
);

$check(
	'db' === $database_host,
	'Database host is configured as DDEV service db.',
	sprintf( 'Unsafe database host configuration: expected db, got %s.', $database_host )
);

$check(
	'wptests_' === $test_table_prefix,
	'PHPUnit table prefix is configured as wptests_.',
	sprintf( 'Unsafe PHPUnit table prefix: expected wptests_, got %s.', $test_table_prefix )
);

$check(
	$working_database !== $test_database,
	'Working and PHPUnit databases are distinct.',
	'The working and PHPUnit database names must never be equal.'
);

$ddev_config_file   = $project_root . '/wp-config-ddev.php';
$ddev_config_loaded = false;

$check(
	is_file( $ddev_config_file ),
	'DDEV WordPress database configuration exists.',
	sprintf( 'DDEV WordPress database configuration is missing: %s', $ddev_config_file )
);

if ( is_file( $ddev_config_file ) ) {
	defined( 'ABSPATH' ) || define( 'ABSPATH', $project_root . '/' );

	try {
		require $ddev_config_file;
		$ddev_config_loaded = true;
	} catch ( Throwable $exception ) {
		$check(
			false,
			'DDEV WordPress database configuration loaded.',
			sprintf(
				'Could not load %s: %s',
				$ddev_config_file,
				$exception->getMessage()
			)
		);
	}
}

if ( $ddev_config_loaded ) {
	foreach ( array( 'DB_NAME', 'DB_HOST', 'DB_USER', 'DB_PASSWORD' ) as $constant ) {
		$check(
			defined( $constant ),
			sprintf( 'DDEV defines %s.', $constant ),
			sprintf( '%s was not defined by %s.', $constant, $ddev_config_file )
		);
	}

	if ( defined( 'DB_NAME' ) ) {
		$actual_database = (string) constant( 'DB_NAME' );
		$check(
			$working_database === $actual_database,
			'DDEV WordPress uses working database db.',
			sprintf(
				'DDEV DB_NAME mismatch: expected %s, got %s.',
				$working_database,
				$actual_database
			)
		);
	}

	if ( defined( 'DB_HOST' ) ) {
		$actual_host = (string) constant( 'DB_HOST' );
		$check(
			$actual_host === $database_host,
			'DDEV WordPress uses database host db.',
			sprintf(
				'DDEV DB_HOST mismatch: expected %s, got %s.',
				$database_host,
				$actual_host
			)
		);
	}
}

$minimum_php = (int) ( $config['minimum_php_version'] ?? 0 );

$check(
	$minimum_php <= PHP_VERSION_ID,
	sprintf( 'PHP %s meets the toolkit minimum.', PHP_VERSION ),
	sprintf(
		'PHP %s is unsupported; the toolkit requires PHP %s or later.',
		PHP_VERSION,
		$minimum_php > 0
			? sprintf( '%d.%d', intdiv( $minimum_php, 10000 ), intdiv( $minimum_php % 10000, 100 ) )
			: '(unknown)'
	)
);

foreach ( (array) ( $config['required_extensions'] ?? array() ) as $extension ) {
	$check(
		extension_loaded( (string) $extension ),
		sprintf( 'PHP extension is available: %s', $extension ),
		sprintf( 'Required PHP extension is unavailable: %s', $extension )
	);
}

foreach ( (array) ( $config['required_commands'] ?? array() ) as $command ) {
	$output         = array();
	$command_status = 0;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The doctor explicitly verifies configured container commands.
	exec(
		sprintf( 'command -v %s >/dev/null 2>&1', escapeshellarg( (string) $command ) ),
		$output,
		$command_status
	);

	$check(
		0 === $command_status,
		sprintf( 'Container command is available: %s', $command ),
		sprintf( 'Required container command is unavailable: %s', $command )
	);
}

$autoload = $toolkit_root . '/vendor/autoload.php';

$check(
	is_file( $autoload ),
	'Composer dependencies are installed.',
	'Composer dependencies are missing; run Composer install in .test-tools.'
);

$phpunit_binary  = $toolkit_root . '/vendor/bin/phpunit';
$phpunit_version = null;

if ( is_file( $phpunit_binary ) ) {
	$output         = array();
	$phpunit_status = 0;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- The doctor reads the installed PHPUnit binary version.
	exec(
		escapeshellarg( PHP_BINARY ) . ' ' .
		escapeshellarg( $phpunit_binary ) . ' --version 2>&1',
		$output,
		$phpunit_status
	);

	if (
			0 === $phpunit_status &&
			1 === preg_match( '/PHPUnit\s+(\d+\.\d+\.\d+)/', implode( "\n", $output ), $matches )
	) {
		$phpunit_version = $matches[1];
	}
}

$check(
	is_string( $phpunit_version ) &&
	version_compare( $phpunit_version, '9.6.0', '>=' ) &&
	version_compare( $phpunit_version, '10.0.0', '<' ),
	is_string( $phpunit_version )
		? sprintf( 'PHPUnit %s matches the supported 9.6 line.', $phpunit_version )
		: 'PHPUnit version is readable.',
	sprintf(
		'Unsupported PHPUnit version: expected 9.6.x, got %s.',
		$phpunit_version ?? '(unreadable)'
	)
);

$check(
	is_dir( $toolkit_root . '/vendor/yoast/phpunit-polyfills' ),
	'PHPUnit Polyfills are installed.',
	'PHPUnit Polyfills are missing from vendor/yoast/phpunit-polyfills.'
);

$version_file                = $project_root . '/wp-includes/version.php';
$installed_wordpress_version = null;

if ( is_file( $version_file ) ) {
	require $version_file;

	if ( isset( $wp_version ) && is_string( $wp_version ) ) {
		$installed_wordpress_version = $wp_version;
	}
}

$check(
	is_string( $installed_wordpress_version ) && '' !== $installed_wordpress_version,
	'Installed WordPress version is readable.',
	'Could not determine the installed WordPress version.'
);

if ( is_string( $installed_wordpress_version ) && '' !== $installed_wordpress_version ) {
	$branch   = 1 === preg_match( '/^(\d+\.\d+)/', $installed_wordpress_version, $matches )
		? $matches[1]
		: null;
	$maximums = (array) ( $config['wordpress_php_maximums'] ?? array() );

	$check(
		null !== $branch && isset( $maximums[ $branch ] ),
		sprintf( 'WordPress %s is covered by the compatibility policy.', $installed_wordpress_version ),
		sprintf(
			'WordPress %s is not covered by the toolkit compatibility policy; update wp-test before running.',
			$installed_wordpress_version
		)
	);

	if ( null !== $branch && isset( $maximums[ $branch ] ) ) {
		$maximum_php = (int) $maximums[ $branch ];

		$check(
			PHP_VERSION_ID <= $maximum_php,
			sprintf( 'PHP %s is supported by WordPress %s.', PHP_VERSION, $branch ),
			sprintf(
				'PHP %s is newer than the supported maximum for WordPress %s.',
				PHP_VERSION,
				$branch
			)
		);
	}
}

$mysqli = null;

if (
	extension_loaded( 'mysqli' ) &&
	defined( 'DB_HOST' ) &&
	defined( 'DB_USER' ) &&
	defined( 'DB_PASSWORD' )
) {
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- WordPress is not loaded; the doctor performs a read-only connectivity check.
	mysqli_report( MYSQLI_REPORT_OFF );
	// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- WordPress is not loaded; the doctor performs a read-only connectivity check.
	$mysqli = new mysqli(
		(string) constant( 'DB_HOST' ),
		(string) constant( 'DB_USER' ),
		(string) constant( 'DB_PASSWORD' )
	);
}

$check(
	$mysqli instanceof mysqli && 0 === $mysqli->connect_errno,
	'DDEV database service is reachable.',
	'Could not connect to the DDEV database service using wp-config-ddev.php credentials.'
);

if ( $mysqli instanceof mysqli && 0 === $mysqli->connect_errno ) {
	$database_exists = static function ( mysqli $connection, string $database ): bool {
		$statement = $connection->prepare(
			'SELECT SCHEMA_NAME
			FROM information_schema.SCHEMATA
			WHERE SCHEMA_NAME = ?'
		);

		if ( false === $statement ) {
			return false;
		}

		$statement->bind_param( 's', $database );
		$statement->execute();
		$result = $statement->get_result();
		$exists = false !== $result && null !== $result->fetch_row();
		$statement->close();

		return $exists;
	};

	$check(
		$database_exists( $mysqli, $working_database ),
		'Working database db exists.',
		'Working database db does not exist.'
	);

	$check(
		$database_exists( $mysqli, $test_database ),
		'PHPUnit database wp_tests exists.',
		'PHPUnit database wp_tests does not exist; create it before running tests.'
	);

	$mysqli->close();
}

$generated_paths = array(
	$toolkit_root,
	$toolkit_root . '/runtime',
	$toolkit_root . '/wordpress',
	$toolkit_root . '/wordpress-tests-lib',
);

foreach ( $generated_paths as $generated_path ) {
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WordPress is not loaded; the doctor checks local permissions.
	$writable = is_dir( $generated_path )
		? is_writable( $generated_path )
		: is_writable( dirname( $generated_path ) );
	// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable

	$check(
		$writable,
		sprintf( 'Generated path is writable: %s', $generated_path ),
		sprintf( 'Generated path is not writable: %s', $generated_path )
	);
}

$tests_config = $toolkit_root . '/wordpress-tests-lib/wp-tests-config.php';

if ( is_file( $tests_config ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a local generated config before WordPress loads.
	$content = (string) file_get_contents( $tests_config );

	$read_defined_string = static function ( string $name ) use ( $content ): ?string {
		$pattern = sprintf(
			'/define\(\s*[\'\"]%s[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\);/',
			preg_quote( $name, '/' )
		);

		return preg_match( $pattern, $content, $matches ) === 1
			? $matches[1]
			: null;
	};

	$read_prefix = static function () use ( $content ): ?string {
		return preg_match(
			'/\$table_prefix\s*=\s*[\'\"]([^\'\"]+)[\'\"]\s*;/',
			$content,
			$matches
		) === 1
			? $matches[1]
			: null;
	};

	$generated_database = $read_defined_string( 'DB_NAME' );
	$generated_host     = $read_defined_string( 'DB_HOST' );
	$generated_prefix   = $read_prefix();

	$check(
		$generated_database === $test_database,
		'Generated WordPress test configuration targets wp_tests.',
		sprintf(
			'Generated WordPress test DB_NAME mismatch: expected %s, got %s.',
			$test_database,
			$generated_database ?? '(unreadable)'
		)
	);

	$check(
		$generated_host === $database_host,
		'Generated WordPress test configuration targets host db.',
		sprintf(
			'Generated WordPress test DB_HOST mismatch: expected %s, got %s.',
			$database_host,
			$generated_host ?? '(unreadable)'
		)
	);

	$check(
		$generated_prefix === $test_table_prefix,
		'Generated WordPress test configuration uses prefix wptests_.',
		sprintf(
			'Generated WordPress test prefix mismatch: expected %s, got %s.',
			$test_table_prefix,
			$generated_prefix ?? '(unreadable)'
		)
	);
} else {
	$warning(
		'The generated WordPress test library is not present yet; composer test will synchronize it.'
	);
}

if ( array() !== $diagnostic_errors ) {
	printf(
		"\nDoctor failed with %d error(s) and %d warning(s).\n",
		count( $diagnostic_errors ),
		count( $diagnostic_warnings )
	);
	exit( 1 );
}

if ( ! $quiet ) {
	printf(
		"\nDoctor passed with %d warning(s).\n",
		count( $diagnostic_warnings )
	);
}
