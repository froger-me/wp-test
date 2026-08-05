<?php

declare(strict_types=1);

if ($argc !== 6) {
	fwrite(
		STDERR,
		"Usage: configure-wordpress-tests.php <config> <core-dir> <db-name> <db-host> <prefix>\n"
	);
	exit(1);
}

[, $file, $coreDir, $testDatabase, $databaseHost, $tablePrefix] = $argv;
$coreDir = rtrim($coreDir, '/') . '/';

$config = file_get_contents($file);

if ($config === false) {
	fwrite(STDERR, "ERROR: Could not read wp-tests-config.php.\n");
	exit(1);
}

$replacements = [
	"/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/" =>
		"define( 'DB_NAME', " . var_export($testDatabase, true) . " );",
	"/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/" =>
		"define( 'DB_USER', 'db' );",
	"/define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/" =>
		"define( 'DB_PASSWORD', 'db' );",
	"/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"][^'\"]*['\"]\s*\);/" =>
		"define( 'DB_HOST', " . var_export($databaseHost, true) . " );",
	'~\\$table_prefix\\s*=\\s*[\'\"][^\'\"]*[\'\"]\\s*;~' =>
		'$table_prefix = ' . var_export($tablePrefix, true) . ';',
];

foreach ($replacements as $pattern => $replacement) {
	$updated = preg_replace($pattern, $replacement, $config, 1, $count);

	if ($updated === null || $count !== 1) {
		fwrite(
			STDERR,
			sprintf(
				"ERROR: Could not update generated WordPress test configuration using pattern %s.\n",
				$pattern
			)
		);
		exit(1);
	}

	$config = $updated;
}

$config = str_replace(
	[
		'dirname( __FILE__ ) . "/src/"',
		"dirname( __FILE__ ) . '/src/'",
	],
	var_export($coreDir, true),
	$config
);

if (file_put_contents($file, $config) === false) {
	fwrite(STDERR, "ERROR: Could not write wp-tests-config.php.\n");
	exit(1);
}
