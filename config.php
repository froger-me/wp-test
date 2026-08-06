<?php
/**
 * Central Anyape WP Test Tools safety and compatibility configuration.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

return array(
	'working_database'       => 'db',
	'test_database'          => 'anyape_wp_test_tools',
	'database_host'          => 'db',
	'table_prefix'           => 'anyape_wptt_',
	'minimum_php_version'    => 80000,
	'required_extensions'    => array(
		'dom',
		'json',
		'mbstring',
		'mysqli',
	),
	'required_commands'      => array(
		'composer',
		'curl',
		'svn',
		'tar',
	),
	'wordpress_php_maximums' => array(
		'5.9' => 80199,
		'6.0' => 80199,
		'6.1' => 80299,
		'6.2' => 80299,
		'6.3' => 80299,
		'6.4' => 80399,
		'6.5' => 80399,
		'6.6' => 80399,
		'6.7' => 80499,
		'6.8' => 80499,
		'6.9' => 80599,
		'7.0' => 80599,
	),
);
