<?php
/**
 * Bootstrap the WordPress integration test environment.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

use AnyapeWPTestTools\HttpMock;
use AnyapeWPTestTools\Lifecycle;
use AnyapeWPTestTools\Manifest;

$anyape_wp_test_tools_root = __DIR__;
$project_root              = dirname( __DIR__ );
$tests_dir                 = $anyape_wp_test_tools_root . '/wordpress-tests-lib';
$runtime_dir               = $anyape_wp_test_tools_root . '/runtime';
$content_dir               = $runtime_dir . '/wp-content';
$polyfills                 = $anyape_wp_test_tools_root . '/vendor/yoast/phpunit-polyfills';

require $anyape_wp_test_tools_root . '/vendor/autoload.php';
require $anyape_wp_test_tools_root . '/autoload.php';

$config   = require $anyape_wp_test_tools_root . '/config.php';
$manifest = Manifest::from_file( $runtime_dir . '/manifest.json' );

if ( ! is_file( $tests_dir . '/includes/functions.php' ) ) {
	throw new RuntimeException(
		'WordPress test library is missing. Run composer test again.'
	);
}

if ( ! is_dir( $polyfills ) ) {
	throw new RuntimeException(
		'PHPUnit Polyfills are missing. Run Composer install in .anyape-wp-test-tools.'
	);
}

if (
	( $config['test_database'] ?? null ) !== 'anyape_wp_test_tools' ||
	( $config['database_host'] ?? null ) !== 'db' ||
	( $config['table_prefix'] ?? null ) !== 'anyape_wptt_'
) {
	throw new RuntimeException(
		'Unsafe test database configuration; expected anyape_wp_test_tools on db with prefix anyape_wptt_.'
	);
}

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Required by the WordPress test bootstrap contract.
putenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . $polyfills );

defined( 'WP_ADMIN' ) || define( 'WP_ADMIN', true );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', $content_dir );
defined( 'WP_CONTENT_URL' ) || define( 'WP_CONTENT_URL', 'http://example.org/wp-content' );
defined( 'WP_PLUGIN_DIR' ) || define( 'WP_PLUGIN_DIR', $content_dir . '/plugins' );
defined( 'WP_PLUGIN_URL' ) || define( 'WP_PLUGIN_URL', WP_CONTENT_URL . '/plugins' );
defined( 'WPMU_PLUGIN_DIR' ) || define( 'WPMU_PLUGIN_DIR', $content_dir . '/mu-plugins' );
defined( 'WPMU_PLUGIN_URL' ) || define( 'WPMU_PLUGIN_URL', WP_CONTENT_URL . '/mu-plugins' );

if ( $manifest->is_multisite() ) {
	defined( 'WP_TESTS_MULTISITE' ) || define( 'WP_TESTS_MULTISITE', true );
	defined( 'WP_NETWORK_ADMIN' ) || define( 'WP_NETWORK_ADMIN', true );
}

require_once $tests_dir . '/includes/functions.php';

$site_bootstrap = $manifest->site_bootstrap();

if ( null !== $site_bootstrap ) {
	try {
		require $site_bootstrap;
	} catch ( Throwable $exception ) {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve exact paths and chained exception context.
		throw new RuntimeException(
			sprintf(
				'Site test bootstrap failed at "%s": %s',
				$site_bootstrap,
				$exception->getMessage()
			),
			0,
			$exception
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}

foreach ( $manifest->extension_bootstraps() as $bootstrap ) {
	try {
		require $bootstrap['path'];
	} catch ( Throwable $exception ) {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve exact extension paths and chained exception context.
		throw new RuntimeException(
			sprintf(
				'%s "%s" test bootstrap failed at "%s": %s',
				ucfirst( $bootstrap['type'] ),
				$bootstrap['slug'],
				$bootstrap['path'],
				$exception->getMessage()
			),
			0,
			$exception
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}

$plugin_files = $manifest->plugin_files();
$stylesheet   = $manifest->stylesheet();
$template     = $manifest->template();

$active_plugins_filter = static fn (): array => $plugin_files;
$stylesheet_filter     = static fn (): string => $stylesheet;
$template_filter       = static fn (): string => $template;

tests_add_filter( 'pre_option_active_plugins', $active_plugins_filter );
tests_add_filter( 'pre_option_stylesheet', $stylesheet_filter );
tests_add_filter( 'pre_option_template', $template_filter );
tests_add_filter( 'pre_http_request', array( HttpMock::class, 'intercept' ), 5, 3 );
tests_add_filter( 'pre_http_request', array( HttpMock::class, 'block_unexpected' ), 10, 3 );

require $tests_dir . '/includes/bootstrap.php';

remove_filter( 'pre_option_active_plugins', $active_plugins_filter );
remove_filter( 'pre_option_stylesheet', $stylesheet_filter );
remove_filter( 'pre_option_template', $template_filter );

update_option( 'active_plugins', array() );
update_option( 'stylesheet', $stylesheet );
update_option( 'template', $template );

$administrator_id = wp_insert_user(
	array(
		'user_login' => 'anyape-wp-test-tools-administrator',
		'user_pass'  => wp_generate_password( 32, true, true ),
		'user_email' => 'anyape-wp-test-tools-administrator@example.test',
		'role'       => 'administrator',
	)
);

if ( is_wp_error( $administrator_id ) ) {
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve WordPress error messages in the thrown exception.
	throw new RuntimeException(
		'Could not create the PHPUnit administrator: ' .
		implode( '; ', $administrator_id->get_error_messages() )
	);
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}

wp_set_current_user( (int) $administrator_id );

foreach ( $plugin_files as $plugin_file ) {
	try {
		Lifecycle::activate( $plugin_file, $manifest->is_multisite() );
	} catch ( Throwable $exception ) {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the plugin basename and chained exception context.
		throw new RuntimeException(
			sprintf(
				'Plugin lifecycle bootstrap failed for "%s": %s',
				$plugin_file,
				$exception->getMessage()
			),
			0,
			$exception
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}
