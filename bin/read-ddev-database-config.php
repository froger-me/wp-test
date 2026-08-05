<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$configFile  = $projectRoot . '/wp-config-ddev.php';

if (getenv('IS_DDEV_PROJECT') !== 'true') {
	fwrite(STDERR, "ERROR: DDEV database configuration can only be read inside the active DDEV project.\n");
	exit(1);
}

if (! is_file($configFile)) {
	fwrite(
		STDERR,
		sprintf("ERROR: DDEV configuration file does not exist: %s\n", $configFile)
	);
	exit(1);
}

defined('ABSPATH') || define('ABSPATH', $projectRoot . '/');

require $configFile;

foreach (['DB_NAME', 'DB_HOST', 'DB_USER', 'DB_PASSWORD'] as $constant) {
	if (! defined($constant)) {
		fwrite(
			STDERR,
			sprintf("ERROR: %s was not defined by %s.\n", $constant, $configFile)
		);
		exit(1);
	}
}

printf(
	"%s\n%s\n%s\n%s\n",
	(string) DB_NAME,
	(string) DB_HOST,
	(string) DB_USER,
	(string) DB_PASSWORD
);
