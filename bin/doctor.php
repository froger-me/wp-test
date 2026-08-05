<?php

declare(strict_types=1);

$toolkitRoot = dirname(__DIR__);
$projectRoot = dirname($toolkitRoot);
$config      = require $toolkitRoot . '/config.php';
$quiet       = in_array('--quiet', $argv, true);

$errors = [];
$warnings = [];

/**
 * @param bool $condition
 */
$check = static function (
	bool $condition,
	string $success,
	string $failure
) use (&$errors, $quiet): void {
	if ($condition) {
		if (! $quiet) {
			printf("[OK] %s\n", $success);
		}
		return;
	}

	$errors[] = $failure;
	printf("[ERROR] %s\n", $failure);
};

$warning = static function (string $message) use (&$warnings, $quiet): void {
	$warnings[] = $message;

	if (! $quiet) {
		printf("[WARN] %s\n", $message);
	}
};

$check(
	getenv('IS_DDEV_PROJECT') === 'true',
	'Execution is inside a DDEV project.',
	'IS_DDEV_PROJECT is not true; run the command through the active DDEV project.'
);

foreach (['wp-admin', 'wp-content', 'wp-includes'] as $directory) {
	$path = $projectRoot . '/' . $directory;
	$check(
		is_dir($path),
		sprintf('WordPress directory exists: %s', $directory),
		sprintf('WordPress root is missing required directory: %s', $path)
	);
}

$workingDatabase = (string) ($config['working_database'] ?? '');
$testDatabase    = (string) ($config['test_database'] ?? '');
$databaseHost    = (string) ($config['database_host'] ?? '');
$tablePrefix     = (string) ($config['table_prefix'] ?? '');

$check(
	$workingDatabase === 'db',
	'Working database is configured as db.',
	sprintf('Unsafe working database configuration: expected db, got %s.', $workingDatabase)
);

$check(
	$testDatabase === 'wp_tests',
	'PHPUnit database is configured as wp_tests.',
	sprintf('Unsafe PHPUnit database configuration: expected wp_tests, got %s.', $testDatabase)
);

$check(
	$databaseHost === 'db',
	'Database host is configured as DDEV service db.',
	sprintf('Unsafe database host configuration: expected db, got %s.', $databaseHost)
);

$check(
	$tablePrefix === 'wptests_',
	'PHPUnit table prefix is configured as wptests_.',
	sprintf('Unsafe PHPUnit table prefix: expected wptests_, got %s.', $tablePrefix)
);

$check(
	$workingDatabase !== $testDatabase,
	'Working and PHPUnit databases are distinct.',
	'The working and PHPUnit database names must never be equal.'
);

$environmentDatabase = getenv('DB_NAME');
$environmentHost     = getenv('DB_HOST');

$check(
	$environmentDatabase === $workingDatabase,
	'DDEV exposes the expected working database.',
	sprintf(
		'DDEV DB_NAME mismatch: expected %s, got %s.',
		$workingDatabase,
		$environmentDatabase === false ? '(unset)' : $environmentDatabase
	)
);

$check(
	$environmentHost === $databaseHost,
	'DDEV exposes the expected database host.',
	sprintf(
		'DDEV DB_HOST mismatch: expected %s, got %s.',
		$databaseHost,
		$environmentHost === false ? '(unset)' : $environmentHost
	)
);

$minimumPhp = (int) ($config['minimum_php_version'] ?? 0);

$check(
	PHP_VERSION_ID >= $minimumPhp,
	sprintf('PHP %s meets the toolkit minimum.', PHP_VERSION),
	sprintf(
		'PHP %s is unsupported; the toolkit requires PHP %s or later.',
		PHP_VERSION,
		$minimumPhp > 0
			? sprintf('%d.%d', intdiv($minimumPhp, 10000), intdiv($minimumPhp % 10000, 100))
			: '(unknown)'
	)
);

foreach ((array) ($config['required_extensions'] ?? []) as $extension) {
	$check(
		extension_loaded((string) $extension),
		sprintf('PHP extension is available: %s', $extension),
		sprintf('Required PHP extension is unavailable: %s', $extension)
	);
}

foreach ((array) ($config['required_commands'] ?? []) as $command) {
	$output = [];
	$status = 0;
	exec(
		sprintf('command -v %s >/dev/null 2>&1', escapeshellarg((string) $command)),
		$output,
		$status
	);

	$check(
		$status === 0,
		sprintf('Container command is available: %s', $command),
		sprintf('Required container command is unavailable: %s', $command)
	);
}

$autoload = $toolkitRoot . '/vendor/autoload.php';

$check(
	is_file($autoload),
	'Composer dependencies are installed.',
	'Composer dependencies are missing; run Composer install in .test-tools.'
);

$phpunitBinary = $toolkitRoot . '/vendor/bin/phpunit';
$phpunitVersion = null;

if (is_file($phpunitBinary)) {
	$output = [];
	$status = 0;
	exec(
		escapeshellarg(PHP_BINARY) . ' ' .
		escapeshellarg($phpunitBinary) . ' --version 2>&1',
		$output,
		$status
	);

	if (
		$status === 0 &&
		preg_match('/PHPUnit\s+(\d+\.\d+\.\d+)/', implode("\n", $output), $matches) === 1
	) {
		$phpunitVersion = $matches[1];
	}
}

$check(
	is_string($phpunitVersion) &&
	version_compare($phpunitVersion, '9.6.0', '>=') &&
	version_compare($phpunitVersion, '10.0.0', '<'),
	is_string($phpunitVersion)
		? sprintf('PHPUnit %s matches the supported 9.6 line.', $phpunitVersion)
		: 'PHPUnit version is readable.',
	sprintf(
		'Unsupported PHPUnit version: expected 9.6.x, got %s.',
		$phpunitVersion ?? '(unreadable)'
	)
);

$check(
	is_dir($toolkitRoot . '/vendor/yoast/phpunit-polyfills'),
	'PHPUnit Polyfills are installed.',
	'PHPUnit Polyfills are missing from vendor/yoast/phpunit-polyfills.'
);

$versionFile = $projectRoot . '/wp-includes/version.php';
$wpVersion   = null;

if (is_file($versionFile)) {
	require $versionFile;

	if (isset($wp_version) && is_string($wp_version)) {
		$wpVersion = $wp_version;
	}
}

$check(
	is_string($wpVersion) && $wpVersion !== '',
	'Installed WordPress version is readable.',
	'Could not determine the installed WordPress version.'
);

if (is_string($wpVersion) && $wpVersion !== '') {
	$branch = preg_match('/^(\d+\.\d+)/', $wpVersion, $matches) === 1
		? $matches[1]
		: null;
	$maximums = (array) ($config['wordpress_php_maximums'] ?? []);

	$check(
		$branch !== null && isset($maximums[$branch]),
		sprintf('WordPress %s is covered by the compatibility policy.', $wpVersion),
		sprintf(
			'WordPress %s is not covered by the toolkit compatibility policy; update wp-test before running.',
			$wpVersion
		)
	);

	if ($branch !== null && isset($maximums[$branch])) {
		$maximumPhp = (int) $maximums[$branch];

		$check(
			PHP_VERSION_ID <= $maximumPhp,
			sprintf('PHP %s is supported by WordPress %s.', PHP_VERSION, $branch),
			sprintf(
				'PHP %s is newer than the supported maximum for WordPress %s.',
				PHP_VERSION,
				$branch
			)
		);
	}
}

$mysqli = null;

if (extension_loaded('mysqli')) {
	mysqli_report(MYSQLI_REPORT_OFF);
	$mysqli = @new mysqli(
		$databaseHost,
		getenv('DB_USER') ?: 'db',
		getenv('DB_PASSWORD') ?: 'db'
	);
}

$check(
	$mysqli instanceof mysqli && $mysqli->connect_errno === 0,
	'DDEV database service is reachable.',
	'Could not connect to the DDEV database service using the project credentials.'
);

if ($mysqli instanceof mysqli && $mysqli->connect_errno === 0) {
	$statement = $mysqli->prepare(
		'SELECT SCHEMA_NAME
		FROM information_schema.SCHEMATA
		WHERE SCHEMA_NAME = ?'
	);

	if ($statement !== false) {
		$statement->bind_param('s', $testDatabase);
		$statement->execute();
		$result = $statement->get_result();
		$exists = $result !== false && $result->fetch_row() !== null;
		$statement->close();

		$check(
			$exists,
			'PHPUnit database wp_tests exists.',
			'PHPUnit database wp_tests does not exist; create it before running tests.'
		);
	}

	$mysqli->close();
}

$generatedPaths = [
	$toolkitRoot,
	$toolkitRoot . '/runtime',
	$toolkitRoot . '/wordpress',
	$toolkitRoot . '/wordpress-tests-lib',
];

foreach ($generatedPaths as $path) {
	$writable = is_dir($path)
		? is_writable($path)
		: is_writable(dirname($path));

	$check(
		$writable,
		sprintf('Generated path is writable: %s', $path),
		sprintf('Generated path is not writable: %s', $path)
	);
}

$testsConfig = $toolkitRoot . '/wordpress-tests-lib/wp-tests-config.php';

if (is_file($testsConfig)) {
	$content = (string) file_get_contents($testsConfig);

	$readDefinedString = static function (string $name) use ($content): ?string {
		$pattern = sprintf(
			'/define\(\s*[\'\"]%s[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\);/',
			preg_quote($name, '/')
		);

		return preg_match($pattern, $content, $matches) === 1
			? $matches[1]
			: null;
	};

	$readPrefix = static function () use ($content): ?string {
		return preg_match(
			'/\$table_prefix\s*=\s*[\'\"]([^\'\"]+)[\'\"]\s*;/',
			$content,
			$matches
		) === 1
			? $matches[1]
			: null;
	};

	$generatedDatabase = $readDefinedString('DB_NAME');
	$generatedHost     = $readDefinedString('DB_HOST');
	$generatedPrefix   = $readPrefix();

	$check(
		$generatedDatabase === $testDatabase,
		'Generated WordPress test configuration targets wp_tests.',
		sprintf(
			'Generated WordPress test DB_NAME mismatch: expected %s, got %s.',
			$testDatabase,
			$generatedDatabase ?? '(unreadable)'
		)
	);

	$check(
		$generatedHost === $databaseHost,
		'Generated WordPress test configuration targets host db.',
		sprintf(
			'Generated WordPress test DB_HOST mismatch: expected %s, got %s.',
			$databaseHost,
			$generatedHost ?? '(unreadable)'
		)
	);

	$check(
		$generatedPrefix === $tablePrefix,
		'Generated WordPress test configuration uses prefix wptests_.',
		sprintf(
			'Generated WordPress test prefix mismatch: expected %s, got %s.',
			$tablePrefix,
			$generatedPrefix ?? '(unreadable)'
		)
	);
} else {
	$warning(
		'The generated WordPress test library is not present yet; composer test will synchronize it.'
	);
}

if ($errors !== []) {
	printf(
		"\nDoctor failed with %d error(s) and %d warning(s).\n",
		count($errors),
		count($warnings)
	);
	exit(1);
}

if (! $quiet) {
	printf(
		"\nDoctor passed with %d warning(s).\n",
		count($warnings)
	);
}
