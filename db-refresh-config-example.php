<?php
/**
 * Example local configuration for composer db:pull.
 *
 * Copy this file to db-refresh.local.php. The copied file is ignored by Git.
 * Store connection details in your SSH configuration, not in this file.
 *
 * @package WpTest
 */

declare(strict_types=1);

return array(
	'ssh_alias'   => 'staging-wordpress',
	'remote_path' => '/var/www/example/htdocs',
	'remote_url'  => 'https://staging.example.com',
	'local_url'   => 'https://project-name.ddev.site',
);
