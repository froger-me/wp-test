<?php

declare(strict_types=1);

$project_root  = dirname(__DIR__);
$tests_dir     = __DIR__ . '/wordpress-tests-lib';
$plugins_dir   = $project_root . '/wp-content/plugins';
$muplugins_dir = $project_root . '/wp-content/mu-plugins';
$polyfills     = __DIR__ . '/vendor/yoast/phpunit-polyfills';
$plugins_file  = __DIR__ . '/active-plugins.json';

if (! is_file($tests_dir . '/includes/functions.php')) {
	throw new RuntimeException(
		'WordPress test library is missing. Run composer test again.'
	);
}

if (! is_dir($polyfills)) {
	throw new RuntimeException(
		'PHPUnit Polyfills are missing. Run Composer install in .test-tools.'
	);
}

if (! is_dir($plugins_dir)) {
	throw new RuntimeException(
		'The WordPress plugins directory does not exist.'
	);
}

putenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $polyfills);

defined('WP_PLUGIN_DIR') || define(
	'WP_PLUGIN_DIR',
	$plugins_dir
);

defined('WP_PLUGIN_URL') || define(
	'WP_PLUGIN_URL',
	'http://example.org/wp-content/plugins'
);

if (is_dir($muplugins_dir)) {
	defined('WPMU_PLUGIN_DIR') || define(
		'WPMU_PLUGIN_DIR',
		$muplugins_dir
	);

	defined('WPMU_PLUGIN_URL') || define(
		'WPMU_PLUGIN_URL',
		'http://example.org/wp-content/mu-plugins'
	);
}

require_once $tests_dir . '/includes/functions.php';

$plugin_files        = [];
$environment_plugins = getenv('WP_TEST_PLUGINS');

if (
	is_string($environment_plugins) &&
	trim($environment_plugins) !== ''
) {
	$plugin_files = array_map(
		'trim',
		explode(',', $environment_plugins)
	);
} elseif (is_file($plugins_file)) {
	$decoded_plugins = json_decode(
		(string) file_get_contents($plugins_file),
		true,
		512,
		JSON_THROW_ON_ERROR
	);

	if (! is_array($decoded_plugins)) {
		throw new RuntimeException(
			'The active plugin list must contain a JSON array.'
		);
	}

	$plugin_files = $decoded_plugins;
}

$plugin_files = array_values(
	array_filter(
		$plugin_files,
		static fn ($plugin_file): bool =>
			is_string($plugin_file) &&
			trim($plugin_file) !== ''
	)
);

$real_plugins_dir = realpath($plugins_dir);

if ($real_plugins_dir === false) {
	throw new RuntimeException(
		'Could not resolve the WordPress plugins directory.'
	);
}

foreach ($plugin_files as &$plugin_file) {
	$plugin_file = ltrim(trim($plugin_file), '/');
	$real_path   = realpath($plugins_dir . '/' . $plugin_file);

	if (
		$real_path === false ||
		! is_file($real_path) ||
		! str_starts_with(
			$real_path,
			$real_plugins_dir . DIRECTORY_SEPARATOR
		)
	) {
		throw new RuntimeException(
			sprintf(
				'Active plugin file does not exist: %s',
				$plugin_file
			)
		);
	}
}

unset($plugin_file);

/*
 * Load the same plugins as the working DDEV installation during the normal
 * WordPress bootstrap.
 */
$active_plugins_filter = static fn (): array => $plugin_files;

tests_add_filter(
	'pre_option_active_plugins',
	$active_plugins_filter
);

/*
 * Block real HTTP requests. Individual tests can return mocked responses
 * through pre_http_request at a priority lower than 10.
 */
tests_add_filter(
	'pre_http_request',
	static function (
		$preempt,
		array $parsed_args,
		string $url
	) {
		if ($preempt !== false) {
			return $preempt;
		}

		return new WP_Error(
			'unexpected_http_request',
			sprintf(
				'External HTTP request blocked during tests: %s',
				$url
			)
		);
	},
	10,
	3
);

require $tests_dir . '/includes/bootstrap.php';

/*
 * Persist the active-plugin list in the fresh test database. The temporary
 * pre-option filter is no longer needed after WordPress has bootstrapped.
 */
remove_filter(
	'pre_option_active_plugins',
	$active_plugins_filter
);

update_option(
	'active_plugins',
	$plugin_files
);

/*
 * The plugin files have already been loaded normally. Run each plugin's
 * registered activation hook so its test-database options and tables are
 * installed.
 */
foreach ($plugin_files as $plugin_file) {
	do_action(
		'activate_' . $plugin_file,
		false
	);
}
