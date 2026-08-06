# Setup

This guide adds `.anyape-wp-test-tools` to an existing WordPress directory served by DDEV.

The normal setup has four actions:

1. install the required programs;
2. create the basic DDEV files;
3. clone `.anyape-wp-test-tools`; and
4. run the guided setup command.

The guided command checks the project, explains every lasting change, creates backups before editing, and stops when it cannot make a safe choice. It never asks for or prints remote database passwords.

## What guided setup changes

The command handles:

- the standard DDEV arrangement in `wp-config.php`;
- local WordPress and PHP error logging;
- local-file exclusions in Git and supported VS Code SFTP files;
- required Subversion support for downloading the matching WordPress PHP test library;
- DDEV startup during this one-time setup;
- PHP packages, Node.js packages, and the Chromium browser;
- the separate `anyape_wp_test_tools` database;
- the root `composer.json` command list; and
- the first environment and Anyape WP Test Tools checks.

It does not choose remote credentials, hosting paths, deployment policy, plugin-specific test settings, or optional services. Those choices remain in the manual sections below.

The command is safe to run again. Completed work is reported without rewriting files or rebuilding DDEV.

Subversion is required, not an optional testing feature. On a new or stopped DDEV project, guided setup adds it before DDEV builds and starts the web container. If DDEV is already running without Subversion, setup explains that the existing web container must be rebuilt and the local project restarted before asking for confirmation.

When `pull` is chosen for the working database, one guided setup run confirms and downloads the remote database only once. If another setup child requests the same pull later in that run, it verifies and reuses the database that was already imported instead of asking again or contacting the remote server again.

## Install the required programs

On macOS:

```bash
brew install --cask docker-desktop
brew install ddev/ddev/ddev
mkcert -install
```

Install current versions of Git, Composer, Node.js, and npm if they are not already available. Open Docker Desktop and wait for it to start.

Check the programs:

```bash
docker info --format 'Docker engine: {{.ServerVersion}}'
ddev version
git --version
composer --version
node --version
npm --version
```

## Let guided setup create the basic DDEV files

Do not run a separate initial `ddev config` command. Guided setup suggests a project name from the WordPress directory name and lets you accept it or type another lowercase name. It then creates `.ddev/config.yaml` and `wp-config-ddev.php` with these settings:

- the chosen local project name;
- WordPress project type;
- the current directory as the document root; and
- the Apache PHP server.

After guided setup, choose different PHP or database versions only when the project requires them:

```bash
ddev config --php-version=8.4 --database=mariadb:11.8
```

Do not start DDEV yet when `wp-config.php` still contains unconditional remote database values. Guided setup adapts a recognized standard file before starting DDEV.

## Install `.anyape-wp-test-tools`

Clone Anyape WP Test Tools before adapting `wp-config.php`:

```bash
git clone https://github.com/froger-me/anyape-wp-test-tools.git .anyape-wp-test-tools
```

This order makes the setup command available for all remaining work.

## Run guided setup

First inspect without changing anything:

```bash
bash .anyape-wp-test-tools/setup-host.sh --check
```

Then run the guided setup:

```bash
bash .anyape-wp-test-tools/setup-host.sh
```

The command asks before editing files, changing DDEV configuration, starting DDEV, or preparing the working database. It runs `composer doctor` and `composer test:harness` at the end. It offers the complete PHP and browser test run separately.

After root Composer commands have been added, later runs can use:

```bash
composer setup
```

For a known existing WordPress database, a deliberate run without questions is:

```bash
composer setup -- --yes --database=keep
```

`--yes` does not decide deployment policy, create optional configuration files, choose a database source, or handle an unfamiliar `wp-config.php`. Supply the database choice explicitly. Add `--run-tests` to run the complete suite after setup:

```bash
composer setup -- --yes --database=keep --run-tests
```

Setup saves detailed command output below `.anyape-wp-test-tools/runtime/logs/` and prints the exact file path. By default, the terminal shows questions, short progress messages, and failures without repeating every plugin notice or database row. Run `composer setup -- -v` to show the full output while also saving it. The complete test and database-copy commands use the same behavior with `composer test -- -v` and `composer db:pull -- -v`.

## Choose the working database

Guided setup asks for one of three choices.

### Keep the existing database

Choose `keep` when database `db` already contains the local WordPress site. The command verifies that WordPress is installed and does not replace the database.

### Make a clean WordPress installation

Choose `clean` to erase local database `db` and create a new WordPress site. The command clearly warns that the existing local site will be erased, requires the exact typed confirmation `erase local db`, and saves every local DDEV database before changing anything. It suggests a site address, site title, administrator login, and administrator email based on the project name in `.ddev/config.yaml`. Press Enter to accept each suggestion or type another value. The administrator password has no default, must be entered twice, and is hidden while it is entered. Setup passes it through a short-lived local file that is readable only by the current user, does not place it in the DDEV command text, and deletes the file after use or failure. If the installation fails after erasing `db`, setup attempts to restore the saved databases automatically. The remote site and the separate `anyape_wp_test_tools` database are not deliberately changed by the clean installation.

### Refresh from a remote WordPress installation

Copy the ignored local example:

```bash
cp .anyape-wp-test-tools/db-refresh-config-example.php .anyape-wp-test-tools/db-refresh-local.php
```

Fill in the SSH alias, absolute remote WordPress path, remote URL, and local URL. Keep connection passwords and keys in the user's SSH configuration.

Choose `pull` during setup, or run the refresh later:

```bash
composer db:pull
```

The refresh confirms its source and destination, downloads and verifies a compressed database export, creates a local DDEV snapshot, imports only into `db`, and replaces the remote URL throughout WordPress data. If import or URL replacement fails, it attempts to restore the snapshot.

## Complete project-specific configuration

Guided setup reports these items instead of guessing them.

### Manual `wp-config.php` arrangement

The command edits only a recognized standard file or completes missing debug settings in an already supported DDEV arrangement. It leaves custom or unclear files untouched.

For a manual setup, define the DDEV check before database settings:

```php
$is_ddev = getenv( 'IS_DDEV_PROJECT' ) === 'true';
```

Keep remote database settings outside DDEV:

```php
if ( ! $is_ddev ) {
	define( 'DB_NAME', 'REMOTE_DATABASE_NAME' );
	define( 'DB_USER', 'REMOTE_DATABASE_USER' );
	define( 'DB_PASSWORD', 'REMOTE_DATABASE_PASSWORD' );
	define( 'DB_HOST', 'localhost' );
}

defined( 'DB_CHARSET' ) || define( 'DB_CHARSET', 'utf8' );
defined( 'DB_COLLATE' ) || define( 'DB_COLLATE', '' );
```

Set local WordPress logging without displaying errors in pages:

```php
defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );
defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', $is_ddev );
defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', false );
```

Preserve a server-specific PHP error-log path outside DDEV:

```php
if ( $is_ddev ) {
	ini_set( 'log_errors', '1' );
	ini_set( 'error_log', __DIR__ . '/wp-content/debug.log' );
} else {
	ini_set( 'error_log', '/remote/path/to/php-error.log' );
}
```

At the bottom, define `ABSPATH`, load DDEV's generated values locally, then load WordPress once:

```php
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( $is_ddev ) {
	require_once __DIR__ . '/wp-config-ddev.php';
}

require_once ABSPATH . 'wp-settings.php';
```

Check the file and rerun setup:

```bash
php -l wp-config.php
composer setup -- --check
```

Guided edits create a backup named like `wp-config.php.before-anyape-wp-test-tools-20260806T120000Z`. An invalid edited file is replaced immediately with its original backup.

### Deployment exclusions

The command can add known paths to an ordinary root `.gitignore`. It does not ignore `.anyape-wp-test-tools` when `.gitmodules` declares it as a Git submodule.

For another Git arrangement, review these entries yourself:

```gitignore
.anyape-wp-test-tools/
.anyape-wp-test-tools.php
wp-config-ddev.php
wp-config.php.before-ddev
wp-config.php.before-anyape-wp-test-tools-*
composer.json.before-anyape-wp-test-tools-*
.gitignore.before-anyape-wp-test-tools-*
```

When `.vscode/sftp.json` is valid JSON with an `ignore` list, interactive setup offers to add:

```text
.vscode
.ddev
.anyape-wp-test-tools
.anyape-wp-test-tools.php
wp-config-ddev.php
wp-config.php.before-ddev
wp-config.php.before-anyape-wp-test-tools-*
composer.json
composer.lock
composer.json.before-anyape-wp-test-tools-*
```

For rsync, a hosting control panel, or another deployment method, add equivalent exclusions manually. Decide separately whether `.ddev/` belongs in the parent Git repository; it must not be deployed to the web server.

Backups of SFTP configuration are stored with owner-only permissions below the ignored `.anyape-wp-test-tools/runtime/setup-backups/` directory so connection details are not copied into a deployable path.

### Optional test selection

Most projects need no `.anyape-wp-test-tools.php`. Active plugins, the active theme, and its parent theme are selected automatically.

Create the example only when selection needs adjustment:

```bash
cp .anyape-wp-test-tools/anyape-wp-test-tools-config-example.php .anyape-wp-test-tools.php
```

The file supports additional or excluded plugins and themes, focused-run dependencies, one site-wide PHP setup file, one browser setup file, and extra paths below `wp-content` that browser tests must restore.

Keep real plugin behavior in plugin code. Do not add Anyape WP Test Tools-specific code merely to make ordinary plugin or theme tests discoverable.

### Optional local services

Redis, compatible object storage, and similar services are not installed by guided setup. Add them only when a plugin needs the real local protocol. Record the choice in the project's own DDEV documentation.

## Review the setup result

Run the read-only environment check:

```bash
composer doctor
```

It confirms the fixed safe database names, DDEV database connection, installed programs and PHP features, WordPress and PHP compatibility, writable generated directories, and installed Anyape WP Test Tools packages.

Run Anyape WP Test Tools' own checks:

```bash
composer test:harness
```

Run all active plugin, theme, and browser tests:

```bash
composer test
```

`composer test` runs PHP tests first and browser tests second. Browser tests save and restore the working database, uploads, must-use plugins, and configured extra paths.

## Add ordinary plugin and theme tests

Anyape WP Test Tools finds normal WordPress test locations without project-specific registration:

```text
wp-content/plugins/<slug>/tests/phpunit/**/*Test.php
wp-content/plugins/<slug>/tests/phpunit/**/test-*.php
wp-content/plugins/<slug>/tests/phpunit/bootstrap.php
wp-content/plugins/<slug>/tests/e2e/**/*.spec.ts
wp-content/plugins/<slug>/tests/e2e/fixtures.php

wp-content/themes/<slug>/tests/phpunit/**/*Test.php
wp-content/themes/<slug>/tests/phpunit/**/test-*.php
wp-content/themes/<slug>/tests/phpunit/bootstrap.php
wp-content/themes/<slug>/tests/e2e/**/*.spec.ts
wp-content/themes/<slug>/tests/e2e/fixtures.php
```

PHP test classes can extend `WP_UnitTestCase` or `AnyapeWPTestTools\IntegrationTestCase`. Browser tests can import directly from `@playwright/test`.

Use Anyape WP Test Tools browser wrapper only when a test needs the extra browser-message and failed-request details:

```ts
import { test, expect, lowerCapabilityStorageState } from '../../../../../.anyape-wp-test-tools/e2e/test';
```

The default browser state is an administrator. The exported lower-capability state is an editor.

## Daily commands

The same commands work from the WordPress root and `.anyape-wp-test-tools`:

```bash
composer setup -- --check
composer doctor
composer test
composer test:php
composer test:e2e
composer test:harness
composer test:plugin -- plugin-slug
composer test:theme -- theme-slug
composer test:multisite
composer test:destructive
composer test:coverage
composer test:junit
composer tail:log
composer clear:log
composer db:pull
composer snapshot -- descriptive-name
composer restore -- descriptive-name
composer reset:tests
```

`restore` and `reset:tests` require typed confirmation. `db:pull` creates a snapshot before replacing `db`. `reset:tests` can recreate only `anyape_wp_test_tools`.

Coverage requires Xdebug or PCOV. With DDEV Xdebug:

```bash
ddev xdebug on
composer test:coverage
ddev xdebug off
```

For DDEV service logs, use:

```bash
ddev logs -f
ddev logs -s db -f
```

## Update WordPress and Anyape WP Test Tools

After a WordPress update:

```bash
composer doctor
composer test
```

The next PHP test run downloads the matching clean WordPress core and official WordPress PHP test library.

Update Anyape WP Test Tools:

```bash
git -C .anyape-wp-test-tools pull --ff-only
composer setup -- --yes --database=keep
composer test
```

The repeated setup command installs missing package updates and keeps existing project choices.

## Completely uninstall Anyape WP Test Tools

Composer reserves the plain word `uninstall` for removing packages. The Anyape WP Test Tools command therefore uses the name `anyape-wp-test-tools:uninstall`.

If the Anyape WP Test Tools source folder is a Git working copy, uninstall stops before changing anything when that folder contains uncommitted changes. Commit or copy those changes first.

See the exact removal scope without changing the project:

```bash
composer anyape-wp-test-tools:uninstall -- --dry-run
```

To remove Anyape WP Test Tools and its complete DDEV project, run:

```bash
composer anyape-wp-test-tools:uninstall
```

Before any deletion, the command reconstructs a normal non-DDEV `wp-config.php` directly from its current recognized structure and checks that the result is valid PHP. It does not depend on an installation backup. If the structure cannot be reversed safely, the command stops without deleting DDEV or project files.

The command explains that all local DDEV databases, snapshots, containers, and configuration will be permanently deleted. It proceeds only after the exact confirmation `uninstall anyape wp test tools`. It removes Anyape WP Test Tools commands from the root Composer file, removes its entries from Git and file-upload exclusions, deletes its generated settings and backups, and deletes `.anyape-wp-test-tools` last. Unrelated project commands and settings are preserved.

Export any local database or file that must survive before confirming the uninstall.

## Common problems

### Guided setup reports an unfamiliar `wp-config.php`

The file was not changed. Use the manual arrangement above, run `php -l wp-config.php`, then run `composer setup -- --check` again.

### DDEV files are missing

Run guided setup again. It creates `.ddev/config.yaml` and `wp-config-ddev.php` itself, or reports the pending change when run with `--check`.

### DDEV is stopped later

Ordinary test and logging commands never start DDEV. Start it explicitly:

```bash
ddev start
```

### `anyape_wp_test_tools` is missing or damaged

Recreate only the test database:

```bash
composer reset:tests
```

### The debug log is unavailable

Check the manual `WP_DEBUG`, `WP_DEBUG_LOG`, and `WP_DEBUG_DISPLAY` settings above. The local `wp-content` directory must be writable.

### A root Composer command conflicts

Guided setup preserves unrelated commands and refuses a name already used for different work. Rename the existing command or decide explicitly which command the project should expose, then rerun setup.

### Browser packages are missing

Rerun setup. It checks Node.js packages and installs Chromium without changing the working database when `--database=keep` is selected.

### A browser test was interrupted

The browser command attempts database and file restoration for success, failure, interruption, and termination signals. If restoration itself fails, the error names the retained DDEV snapshot and local backup directory for manual recovery.
