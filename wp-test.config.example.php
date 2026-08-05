<?php

declare(strict_types=1);

/*
 * Copy this file to <wordpress-root>/.wp-test.php only when the default
 * active-plugin and active-theme selection needs adjustment.
 */
return [
	/*
	 * Add installed extensions to the default profile even when they are not
	 * active on the working site.
	 */
	'include_plugins' => [],
	'include_themes'  => [],

	/*
	 * Remove extensions from the default profile. Focused runs fail clearly
	 * when their requested slug is excluded.
	 */
	'exclude_plugins' => [],
	'exclude_themes'  => [],

	/*
	 * Focused profiles load these plugin dependencies before the selected
	 * plugin or theme. Dependency tests are not selected automatically.
	 */
	'plugin_dependencies' => [
		// 'plugin-slug' => ['dependency-slug'],
	],
	'theme_dependencies' => [
		// 'theme-slug' => ['dependency-plugin-slug'],
	],

	/*
	 * Optional path relative to the WordPress root. It is loaded after the
	 * WordPress test functions are available and before WordPress boots.
	 */
	'bootstrap' => null,
];
