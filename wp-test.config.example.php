<?php
/**
 * Example project test configuration.
 *
 * @package WpTest
 */

declare(strict_types=1);


/*
 * Copy this file to <wordpress-root>/.wp-test.php only when the default
 * active-plugin and active-theme selection needs adjustment.
 */
return array(

	/*
	 * Add installed extensions to the default profile even when they are not
	 * active on the working site.
	 */
	'include_plugins'      => array(),
	'include_themes'       => array(),

	/*
	 * Remove extensions from the default profile. Focused runs fail clearly
	 * when their requested slug is excluded.
	 */
	'exclude_plugins'      => array(),
	'exclude_themes'       => array(),

	/*
	 * Focused profiles load these plugin dependencies before the selected
	 * plugin or theme. Dependency tests are not selected automatically.
	 */
	'plugin_dependencies'  => array(
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- This is an intentional configuration example.
		// 'plugin-slug' => ['dependency-slug'],
	),
	'theme_dependencies'   => array(
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- This is an intentional configuration example.
		// 'theme-slug' => ['dependency-plugin-slug'],
	),

	/*
	 * Optional path relative to the WordPress root. It is loaded after the
	 * WordPress test functions are available and before WordPress boots.
	 */
	'bootstrap'            => null,

	/*
	 * Optional browser-test setup file relative to the WordPress root. It runs
	 * with WordPress fully loaded after the temporary database snapshot exists.
	 */
	'e2e_bootstrap'        => null,

	/*
	 * Additional paths below wp-content that browser tests may change. The
	 * browser command saves, restores, and compares each path. Uploads and
	 * must-use plugins are already included and do not need to be listed.
	 */
	'e2e_filesystem_paths' => array(),
);
