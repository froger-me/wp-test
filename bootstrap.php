<?php

declare(strict_types=1);

use WpTest\HttpMock;
use WpTest\Lifecycle;
use WpTest\Manifest;

$toolkitRoot = __DIR__;
$projectRoot = dirname(__DIR__);
$testsDir    = $toolkitRoot . '/wordpress-tests-lib';
$runtimeDir  = $toolkitRoot . '/runtime';
$contentDir  = $runtimeDir . '/wp-content';
$polyfills   = $toolkitRoot . '/vendor/yoast/phpunit-polyfills';

require $toolkitRoot . '/vendor/autoload.php';
require $toolkitRoot . '/autoload.php';

$config   = require $toolkitRoot . '/config.php';
$manifest = Manifest::fromFile($runtimeDir . '/manifest.json');

if (! is_file($testsDir . '/includes/functions.php')) {
	throw new RuntimeException(
		'WordPress test library is missing. Run composer test again.'
	);
}

if (! is_dir($polyfills)) {
	throw new RuntimeException(
		'PHPUnit Polyfills are missing. Run Composer install in .test-tools.'
	);
}

if (
	($config['test_database'] ?? null) !== 'wp_tests' ||
	($config['database_host'] ?? null) !== 'db' ||
	($config['table_prefix'] ?? null) !== 'wptests_'
) {
	throw new RuntimeException(
		'Unsafe test database configuration; expected wp_tests on db with prefix wptests_.'
	);
}

putenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $polyfills);

defined('WP_ADMIN') || define('WP_ADMIN', true);
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', $contentDir);
defined('WP_CONTENT_URL') || define('WP_CONTENT_URL', 'http://example.org/wp-content');
defined('WP_PLUGIN_DIR') || define('WP_PLUGIN_DIR', $contentDir . '/plugins');
defined('WP_PLUGIN_URL') || define('WP_PLUGIN_URL', WP_CONTENT_URL . '/plugins');
defined('WPMU_PLUGIN_DIR') || define('WPMU_PLUGIN_DIR', $contentDir . '/mu-plugins');
defined('WPMU_PLUGIN_URL') || define('WPMU_PLUGIN_URL', WP_CONTENT_URL . '/mu-plugins');

if ($manifest->isMultisite()) {
	defined('WP_TESTS_MULTISITE') || define('WP_TESTS_MULTISITE', true);
	defined('WP_NETWORK_ADMIN') || define('WP_NETWORK_ADMIN', true);
}

require_once $testsDir . '/includes/functions.php';

$siteBootstrap = $manifest->siteBootstrap();

if ($siteBootstrap !== null) {
	try {
		require $siteBootstrap;
	} catch (Throwable $exception) {
		throw new RuntimeException(
			sprintf(
				'Site test bootstrap failed at "%s": %s',
				$siteBootstrap,
				$exception->getMessage()
			),
			0,
			$exception
		);
	}
}

foreach ($manifest->extensionBootstraps() as $bootstrap) {
	try {
		require $bootstrap['path'];
	} catch (Throwable $exception) {
		throw new RuntimeException(
			sprintf(
				'%s "%s" test bootstrap failed at "%s": %s',
				ucfirst($bootstrap['type']),
				$bootstrap['slug'],
				$bootstrap['path'],
				$exception->getMessage()
			),
			0,
			$exception
		);
	}
}

$pluginFiles = $manifest->pluginFiles();
$stylesheet  = $manifest->stylesheet();
$template    = $manifest->template();

$activePluginsFilter = static fn (): array => $pluginFiles;
$stylesheetFilter    = static fn (): string => $stylesheet;
$templateFilter      = static fn (): string => $template;

tests_add_filter('pre_option_active_plugins', $activePluginsFilter);
tests_add_filter('pre_option_stylesheet', $stylesheetFilter);
tests_add_filter('pre_option_template', $templateFilter);
tests_add_filter('pre_http_request', [HttpMock::class, 'intercept'], 5, 3);
tests_add_filter('pre_http_request', [HttpMock::class, 'blockUnexpected'], 10, 3);

require $testsDir . '/includes/bootstrap.php';

remove_filter('pre_option_active_plugins', $activePluginsFilter);
remove_filter('pre_option_stylesheet', $stylesheetFilter);
remove_filter('pre_option_template', $templateFilter);

update_option('active_plugins', []);
update_option('stylesheet', $stylesheet);
update_option('template', $template);

$administratorId = wp_insert_user(
	[
		'user_login' => 'wp-test-administrator',
		'user_pass'  => wp_generate_password(32, true, true),
		'user_email' => 'wp-test-administrator@example.test',
		'role'       => 'administrator',
	]
);

if (is_wp_error($administratorId)) {
	throw new RuntimeException(
		'Could not create the PHPUnit administrator: ' .
		implode('; ', $administratorId->get_error_messages())
	);
}

wp_set_current_user((int) $administratorId);

foreach ($pluginFiles as $pluginFile) {
	try {
		Lifecycle::activate($pluginFile, $manifest->isMultisite());
	} catch (Throwable $exception) {
		throw new RuntimeException(
			sprintf(
				'Plugin lifecycle bootstrap failed for "%s": %s',
				$pluginFile,
				$exception->getMessage()
			),
			0,
			$exception
		);
	}
}
