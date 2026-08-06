<?php
/**
 * Local choices for this project's PHP and browser tests.
 *
 * Most projects do not need this file. Without it, the normal test command
 * selects installed plugin files recorded as active in the local WordPress
 * database, the active theme, and that theme's parent. Missing plugin files
 * recorded in the database are reported and skipped.
 *
 * Copy this example to the WordPress root only when those defaults need to be
 * changed:
 *
 *     cp .anyape-wp-test-tools/anyape-wp-test-tools-config-example.php .anyape-wp-test-tools.php
 *
 * Run every normally selected PHP and browser test with:
 *
 *     composer test
 *
 * Run only one plugin or theme's PHP tests with:
 *
 *     composer test:plugin -- plugin-folder-name
 *     composer test:theme -- theme-folder-name
 *
 * In this file, a plugin or theme "slug" means its folder name below
 * wp-content/plugins or wp-content/themes. For a plugin stored as one PHP file
 * directly below wp-content/plugins, use that filename without .php.
 *
 * Keep an empty array when no values are needed. Keep null when no preparation
 * file is needed. Paths are relative to the WordPress root unless they begin
 * with /. Guided setup keeps this file out of the parent Git repository and
 * remote uploads when those settings are available. Do not store passwords or
 * other secrets in it.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

return array(

	/*
	 * Plugins to test during a normal `composer test` run even when they are
	 * installed but inactive on the local site.
	 *
	 * Example: to include wp-content/plugins/akismet, remove the // below.
	 */
	'include_plugins'      => array(
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
		// 'akismet',
	),

	/*
	 * Themes to test during a normal run even when they are neither the active
	 * theme nor its parent.
	 *
	 * Example: to include wp-content/themes/twentytwentysix, remove the //.
	 */
	'include_themes'       => array(
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
		// 'twentytwentysix',
	),

	/*
	 * Installed plugins to leave out of normal and focused tests. This is useful
	 * when a plugin cannot run safely in the separate WordPress site created only
	 * for PHP tests, or when it should be tested separately. Asking for an
	 * excluded plugin directly is an error.
	 */
	'exclude_plugins'      => array(
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
		// 'plugin-to-test-separately',
	),

	/*
	 * Installed themes to leave out of normal and focused tests. Asking for an
	 * excluded theme directly is an error.
	 */
	'exclude_themes'       => array(
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
		// 'theme-to-test-separately',
	),

	/*
	 * Extra plugins that must be loaded before a directly requested plugin can
	 * run. The key is the plugin being tested. Its value is a list of installed
	 * plugins that it needs.
	 *
	 * This setting is used by `composer test:plugin -- plugin-folder-name`. The
	 * required plugins are loaded, but their own test files are not added.
	 * Normal `composer test` runs already select active plugins and do not use
	 * this list.
	 */
	'plugin_dependencies'  => array(
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
		// 'my-payment-extension' => array( 'woocommerce' ),
	),

	/*
	 * Extra plugins that must be loaded before a directly requested theme can
	 * run. The key is the theme being tested. Its value is a list of installed
	 * plugins that the theme needs. This does not name a parent theme; parent
	 * themes are found automatically.
	 *
	 * This setting is used by `composer test:theme -- theme-folder-name`. The
	 * required plugins are loaded, but their own test files are not added.
	 */
	'theme_dependencies'   => array(
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
		// 'my-storefront-theme' => array( 'woocommerce' ),
	),

	/*
	 * Advanced, optional PHP preparation file for every PHP test run.
	 *
	 * It runs after the WordPress PHP test helpers are available but before
	 * WordPress finishes starting. Use it to define fixed test values, load PHP
	 * files required by the project, or register code that must run during early
	 * WordPress startup. Do not use it for code that requires WordPress to have
	 * finished starting.
	 *
	 * Example file: tests/phpunit/project-bootstrap.php
	 */
	'bootstrap'            => null,
	// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
	// 'bootstrap' => 'tests/phpunit/project-bootstrap.php',

	/*
	 * Advanced, optional PHP preparation file for every browser test run.
	 *
	 * It runs with WordPress fully started after the browser command has saved
	 * the local database. Use it to create shared posts, users, options, or other
	 * local test data. Database changes made by this file are removed when the
	 * saved database is restored.
	 *
	 * Example file: tests/e2e/project-fixtures.php
	 */
	'e2e_bootstrap'        => null,
	// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
	// 'e2e_bootstrap' => 'tests/e2e/project-fixtures.php',

	/*
	 * Extra paths that browser tests might change and must restore afterward.
	 * Every value must be a narrow path below wp-content. Do not use .. or name
	 * the whole wp-content directory.
	 *
	 * wp-content/uploads and wp-content/mu-plugins are always saved and restored,
	 * so do not list them here. Add plugin-created caches, exports, or similar
	 * paths only when browser actions can change them.
	 */
	'e2e_filesystem_paths' => array(
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
		// 'wp-content/cache/my-plugin',
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Intentional configuration example.
		// 'wp-content/plugin-exports',
	),
);
