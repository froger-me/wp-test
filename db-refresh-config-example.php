<?php
/**
 * Configuration example for copying a remote WordPress database into DDEV.
 *
 * Use this when the local site needs a recent copy of a staging or production
 * database. First copy this file:
 *
 *     cp .anyape-wp-test-tools/db-refresh-config-example.php .anyape-wp-test-tools/db-refresh-local.php
 *
 * Edit the four values in db-refresh-local.php, then run:
 *
 *     composer db:pull
 *
 * The command does not change the remote site. It connects to the remote
 * server over SSH, runs `wp db export` from the remote WordPress directory,
 * and downloads the compressed export. Before replacing the local DDEV
 * database named `db`, it saves a copy of every local database. It then
 * replaces the remote site URL with the local URL in the imported database.
 * If the import or URL replacement fails, it tries to restore that saved copy
 * automatically.
 *
 * Warning: a successful pull replaces the current local WordPress database.
 * The command shows the source and destination and asks for confirmation first.
 *
 * The copied db-refresh-local.php file is ignored by Git. Do not put passwords,
 * private-key contents, or database credentials in it. Put the SSH host name,
 * user name, port, and key path in ~/.ssh/config instead. For example:
 *
 *     Host staging-wordpress
 *         HostName staging.example.com
 *         User deploy
 *         IdentityFile ~/.ssh/example_deploy_key
 *
 * The remote server must have `wp`, `gzip`, and WordPress available. The SSH
 * user must be allowed to read and export the remote WordPress database.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

return array(
	// A Host name from ~/.ssh/config. Check it first with: ssh staging-wordpress.
	'ssh_alias'   => 'staging-wordpress',

	// The full remote path to the WordPress directory where `wp db export` works.
	'remote_path' => '/var/www/example/htdocs',

	// The current remote site URL as stored in WordPress, including http or https.
	'remote_url'  => 'https://staging.example.com',

	// The local URL shown by `ddev describe`, including http or https.
	'local_url'   => 'https://project-name.ddev.site',
);
