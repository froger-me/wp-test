# wp-test

This toolkit runs WordPress plugin and theme tests in an existing DDEV project.

Put it in `.test-tools` inside the WordPress directory. It finds tests in the usual plugin and theme folders, so plugins and themes do not need code written specifically for this toolkit.

The main command is:

```bash
composer test
```

It runs all PHP tests first, then all browser tests. Active plugins, the active theme, and the parent theme are included automatically.

For installation, start with [SETUP.md](SETUP.md).

## How to read this guide

The README starts with the common path and gets more detailed as it goes:

1. **Quick setup** gets the toolkit installed.
2. **What happens to the WordPress site?** explains the three places where work happens and what is protected.
3. **Commands you will use** covers normal daily work.
4. **Adding tests** shows the standard plugin and theme folders.
5. **How the PHP and browser tests work** explains each run from start to finish.
6. The remaining sections cover selection rules, optional configuration, test helpers, database tools, logs, saved reports, generated files, updates, and problems.

You do not need to understand the later sections before running `composer test`. They are here so the answer is available when a test needs something unusual.

## Quick setup

Install Docker, DDEV, Git, Composer, Node.js, and npm. Run the initial DDEV configuration in the WordPress directory, then clone the toolkit:

```bash
ddev config \
  --project-name=your-project-name \
  --project-type=wordpress \
  --docroot=. \
  --webserver-type=apache-fpm

git clone https://github.com/froger-me/wp-test.git .test-tools
```

See what the guided setup would change:

```bash
bash .test-tools/setup-host.sh --check
```

When the report looks right, run it:

```bash
bash .test-tools/setup-host.sh
```

It can adapt a standard `wp-config.php`, install the required packages and browser, prepare the test database, add the root Composer commands, and run the first checks. It creates backups before editing and refuses files it cannot understand safely.

Once setup is complete, the same command is available as:

```bash
composer setup
```

The complete guide covers custom `wp-config.php` files, existing and remote databases, deployment exclusions, and troubleshooting: [SETUP.md](SETUP.md).

## What happens to the WordPress site?

There are three separate pieces to keep in mind.

### The working site

This is the WordPress site opened through DDEV. It uses database `db`, the real `wp-content`, and the plugins and themes currently active in that site.

PHP tests read the active plugin and theme list from this site, but they do not run their test changes against its database or uploads.

Browser tests do open this site. Because browser actions can change settings and files, the browser command saves the relevant state first and restores it afterward.

### The PHP test site

PHP tests build a clean WordPress installation using database `wp_tests`. Test table names begin with `wptests_`.

Only the selected plugins and themes are made available to that clean installation. Test uploads and other generated files stay below `.test-tools/runtime/`.

The test site is rebuilt on every run. Options, posts, users, roles, scheduled tasks, and plugin tables created there are disposable.

### The browser-test run

Browser tests use the working site for the duration of one command. The command creates temporary users and repeatable content only after saving the database. It then restores the saved database and protected files, even when a test fails or the command is interrupted.

Other safety rules:

- `composer doctor` only reads and reports.
- Ordinary test and logging commands never start or reconfigure DDEV.
- Unexpected internet requests made through the WordPress HTTP functions are blocked.
- Tests marked as destructive do not run with `composer test`.
- Database replacement and snapshot restoration require confirmation.
- Browser tests refuse to open a WordPress address that does not match the local DDEV address.

## Commands you will use

All commands work from the WordPress root and from `.test-tools`.

| Command | What it does |
| --- | --- |
| `composer test` | Runs all PHP and browser tests. |
| `composer test:php` | Runs only the PHP tests. |
| `composer test:e2e` | Runs only the browser tests. |
| `composer test:plugin -- plugin-slug` | Runs the PHP tests for one plugin. |
| `composer test:theme -- theme-slug` | Runs the PHP tests for one theme. |
| `composer test:harness` | Tests the toolkit itself. |
| `composer doctor` | Checks DDEV, database separation, packages, PHP, and WordPress compatibility without changing anything. |
| `composer setup -- --check` | Reports unfinished setup work without changing anything. |
| `composer tail:log` | Follows the local WordPress debug log. |
| `composer clear:log` | Empties the local WordPress debug log without deleting it. |
| `composer db:pull` | Replaces the local working database from the configured remote WordPress site after confirmation. |
| `composer snapshot -- name` | Saves all databases in the local DDEV project. |
| `composer restore -- name` | Restores a named DDEV database snapshot after confirmation. |
| `composer reset:tests` | Deletes and recreates only `wp_tests` after confirmation. |

Less common commands:

```bash
composer test:multisite
composer test:destructive
composer test:coverage
composer test:junit
composer lint:wpcs
composer format:wpcs
```

### Setup and checks

`composer setup -- --check` reads the project and reports unfinished setup work. It does not edit files, install packages, start services, or change databases.

`composer setup` is different: its purpose is to finish installation. It explains file changes, creates backups, can configure and start DDEV, installs packages, asks how to handle the working database, creates `wp_tests`, and runs the first checks. It is the only command in this toolkit that starts or configures DDEV.

For a known existing local WordPress database, a run without questions is:

```bash
composer setup -- --yes --database=keep
```

The database choices are:

- `--database=keep` verifies and keeps an installed WordPress site in `db`;
- `--database=clean` installs WordPress only when `db` does not already contain a site; and
- `--database=pull` runs the confirmed remote database refresh using `.test-tools/db-refresh.local.php`.

`--yes` never chooses one of these for you. It also does not decide deployment exclusions or create optional configuration files. Add `--run-tests` when the complete PHP and browser run should happen after setup.

`composer doctor` checks the finished environment without changing it. It verifies:

- DDEV is already running;
- WordPress uses working database `db` on database host `db`;
- PHP tests use `wp_tests` and table names beginning with `wptests_`;
- the working and PHP test databases are different;
- DDEV can connect using the values generated in `wp-config-ddev.php`;
- required host and container programs are available;
- required PHP features are loaded;
- generated directories are writable;
- toolkit packages are installed; and
- the WordPress and DDEV PHP versions are a supported combination.

### PHP test commands

`composer test:php` runs the normal PHP tests for active plugins and themes. It accepts PHPUnit options after `--`.

`composer test:harness` tests the toolkit itself with small included plugins and themes. Use it after installation or a toolkit update.

`composer test:plugin -- plugin-slug` and `composer test:theme -- theme-slug` limit the selected extension tests. Dependencies named in `.wp-test.php` are still loaded so the chosen extension can run normally.

`composer test:multisite` installs the clean PHP test site as WordPress multisite and activates selected plugins across the network.

`composer test:destructive` includes tests marked `destructive`. Keep uninstall tests and tests that deliberately remove large amounts of test data in this group.

`composer test:coverage` creates an HTML coverage report in `.test-tools/coverage/`. It requires Xdebug or PCOV to be enabled explicitly.

`composer test:junit` writes a machine-readable test report to `.test-tools/runtime/junit.xml`.

### Browser test commands

`composer test:e2e` runs the toolkit browser checks plus browser tests belonging to selected plugins and themes. It accepts Playwright options after `--`.

Focused browser commands use the same plugin and theme selection rules as PHP tests:

```bash
composer test:e2e -- --profile=plugin my-plugin
composer test:e2e -- --profile=theme my-theme
```

### Code-style commands

`composer lint:wpcs` checks tracked and new, non-ignored PHP files against the project's WordPress PHP style rules.

`composer format:wpcs` applies the safe automatic fixes offered by those rules. Generated packages, downloaded WordPress files, reports, and ignored files are not included.

### Filtering tests

Pass normal PHPUnit options after `--` when using a PHP-only command:

```bash
composer test:php -- --filter SettingsTest
composer test:php -- --group rest
composer test:plugin -- plugin-slug --filter UpgradeTest
```

Filter browser tests in the same way:

```bash
composer test:e2e -- --grep "settings"
```

## Adding tests to a plugin or theme

Use the normal WordPress test folders.

For a plugin:

```text
wp-content/plugins/my-plugin/tests/phpunit/test-my-plugin.php
wp-content/plugins/my-plugin/tests/e2e/settings.spec.ts
```

For a theme:

```text
wp-content/themes/my-theme/tests/phpunit/test-my-theme.php
wp-content/themes/my-theme/tests/e2e/customizer.spec.ts
```

PHP files are found when they are named either `test-*.php` or `*Test.php` anywhere below `tests/phpunit/`.

A small PHP test can use the standard WordPress test class:

```php
<?php

final class Test_My_Plugin extends WP_UnitTestCase {
	public function test_default_value(): void {
		$this->assertSame( 'expected', my_plugin_value() );
	}
}
```

A browser test can use Playwright normally:

```ts
import { test, expect } from '@playwright/test';

test('an administrator can save the setting', async ({ page }) => {
  await page.goto('/wp-admin/options-general.php?page=my-plugin');
  await page.getByLabel('Example setting').fill('Saved value');
  await page.getByRole('button', { name: 'Save Changes' }).click();
  await expect(page.getByText('Settings saved.')).toBeVisible();
});
```

That is enough. The plugin or theme does not need to register itself with `.test-tools`.

### Optional PHP preparation

Add this file only when tests need constants, a plugin-owned Composer loader, or an early WordPress test callback:

```text
tests/phpunit/bootstrap.php
```

It runs after the WordPress test functions are available but before WordPress starts.

### Optional browser-test data

Add this file when a browser test needs repeatable posts, options, users, or other WordPress data:

```text
tests/e2e/fixtures.php
```

It runs after the browser command has saved the database. Its changes are removed when the database is restored.

### Complete test locations

Every matching file below these folders is discovered:

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

A missing test folder or optional preparation file is normal. A PHP syntax error in a preparation file stops the run and names the plugin or theme and file that failed.

## How PHP tests work

When `composer test:php` runs, the toolkit does the following:

1. Checks DDEV, the two database names, installed programs, PHP features, writable paths, and WordPress compatibility.
2. Reads the working site's active plugins, active theme, and parent theme without changing them.
3. Applies any include, exclude, or dependency choices from `.wp-test.php`.
4. Downloads or refreshes a clean copy of the same WordPress version and its official PHP test library.
5. Creates a temporary `wp-content` below `.test-tools/runtime/` containing only the selected plugins and themes.
6. Rebuilds WordPress in database `wp_tests` with table names beginning with `wptests_`.
7. Loads optional plugin and theme `tests/phpunit/bootstrap.php` files.
8. Loads selected plugins in the same order used by the working site.
9. Activates each plugin through WordPress's normal plugin activation function.
10. Runs the toolkit safety tests and every discovered plugin and theme PHP test.

The command enters the DDEV web container once. It does not repeatedly start a new container for each preparation step.

### Plugin activation during tests

Activation is real WordPress activation against the disposable PHP test site. An activation function can create:

- options;
- custom database tables;
- roles and permissions;
- scheduled WordPress tasks;
- rewrite rules; and
- files in the isolated test upload directory.

Activation output and failures name the responsible plugin. A repeated test run starts from a new clean WordPress test installation.

For multisite tests, plugin activation is network-wide.

### What the toolkit's own tests cover

Normal plugin and theme runs keep the toolkit's database and network safety checks. The larger set of included example-plugin checks runs only with:

```bash
composer test:harness
```

Those checks cover plugin activation and removal, custom tables, options, roles, scheduled tasks, WordPress REST requests, uploads, captured email, blocked internet requests, test discovery, and cleanup.

## Choosing what is tested

By default, tests include:

- every active ordinary plugin;
- the active theme; and
- the active theme's parent, when it has one.

Run one plugin or theme when working on a smaller change:

```bash
composer test:plugin -- my-plugin
composer test:theme -- my-theme
```

Focused browser runs are also available:

```bash
composer test:e2e -- --profile=plugin my-plugin
composer test:e2e -- --profile=theme my-theme
```

Most sites need no extra configuration. When the defaults are not enough, copy the example:

```bash
cp .test-tools/wp-test.config.example.php .wp-test.php
```

That file can include or exclude installed plugins and themes, name dependencies for focused runs, add one site-wide preparation file, and list extra paths below `wp-content` that browser tests must restore.

The supported settings are:

| Setting | Meaning |
| --- | --- |
| `include_plugins` | Adds installed plugins even when they are inactive on the working site. |
| `exclude_plugins` | Removes plugins from the normal selection. |
| `include_themes` | Adds installed themes that are not the active theme or its parent. |
| `exclude_themes` | Removes themes from the normal selection. |
| `plugin_dependencies` | Names plugins that must be loaded before one focused plugin. Dependency tests are not selected automatically. |
| `theme_dependencies` | Names plugins that must be loaded for one focused theme. Dependency tests are not selected automatically. |
| `bootstrap` | Names one site-wide PHP preparation file for PHP tests. |
| `e2e_bootstrap` | Names one site-wide PHP data-preparation file for browser tests. |
| `e2e_filesystem_paths` | Lists extra narrow paths below `wp-content` that browser tests save, restore, and compare. |

Paths are relative to the WordPress root unless they begin with `/`.

Focused runs load the dependencies needed by the selected extension, but they add only the selected extension's own test files. This keeps a focused run focused while still giving the plugin or theme the environment it expects.

## Browser tests

`composer test:e2e` performs these steps in order:

1. Reads the running DDEV address and the WordPress site address.
2. Stops if their host names differ, so it cannot accidentally open a remote site.
3. Saves `wp-content/uploads`, `wp-content/mu-plugins`, and extra paths listed in `.wp-test.php`.
4. Exports the working database, records a comparison value, and creates a temporary DDEV database snapshot.
5. Adds a temporary must-use plugin and creates an administrator, editor, post, category, media record, option, and small custom table entry.
6. Loads plugin, theme, and site-wide `fixtures.php` preparation files.
7. Creates signed-in browser state for the temporary users through a random value valid only for this run.
8. Runs the toolkit checks and selected plugin and theme browser tests in Chromium.
9. Restores the DDEV database snapshot and saved files after success, failure, interruption, or another termination signal.
10. Exports the restored database and compares it with the saved state. It also compares every protected file path.

The restore operation keeps existing top-level directories in place while replacing their contents. This matters for directories DDEV has attached to the container, such as uploads.

After a successful restore, DDEV removes the temporary snapshot. If restoration fails, the error prints the retained snapshot name and backup directory so they can be recovered manually.

Browser tests use temporary administrator and editor accounts. They do not use a developer's account or the site's normal login form.

The default browser state is the temporary administrator. Import the toolkit wrapper when a test needs the temporary editor or extra browser error details:

```ts
import {
  test,
  expect,
  lowerCapabilityStorageState,
} from '../../../../../.test-tools/e2e/test';

test.use({ storageState: lowerCapabilityStorageState });
```

During the run, the temporary must-use plugin defines `WP_TEST_E2E`. Plugins should use that value to replace CAPTCHA checks, payments, file storage, webhooks, and update checks with local test behavior. Browser tests must not solve real CAPTCHAs or use real payment and storage accounts.

WordPress email goes to DDEV's Mailpit. WordPress HTTP requests may reach only the local site, loopback addresses, and Mailpit unless a test provides a local replacement.

The temporary must-use plugin and all temporary database records are removed by restoration. Existing must-use plugins are put back exactly as they were.

When a browser test fails, useful files are kept here:

```text
.test-tools/test-results/
.test-tools/playwright-report/
```

They include screenshots, a recorded browser trace, browser messages, failed requests, the selected plugin or theme, the test title, and the last 200 lines written to the WordPress debug log during the run.

## Database commands

### Refresh the local site from a remote database

Copy and edit the ignored local example:

```bash
cp .test-tools/db-refresh-config-example.php .test-tools/db-refresh.local.php
composer db:pull
```

The file contains an SSH alias, remote WordPress path, remote URL, and local URL. Authentication stays in the user's SSH configuration.

Before replacing `db`, the command downloads and verifies a compressed export and creates a local snapshot. It then replaces the remote URL safely throughout WordPress data. If import fails, it attempts to restore the snapshot.

More precisely, the command:

1. validates the ignored local configuration;
2. checks that the configured local URL, WordPress address, and DDEV address use the same host name;
3. shows the remote SSH alias and path, local database, old URL, and new URL;
4. requires confirmation;
5. streams a compressed export over SSH with a one-gigabyte database packet limit;
6. checks that the archive is not empty and that its compression is valid;
7. creates a named DDEV snapshot;
8. imports into database `db` only;
9. replaces the URL in plain text and in WordPress values that store string lengths; and
10. confirms that the final WordPress site address equals the configured local URL.

The downloaded archive stays below `.test-tools/runtime/db-pulls/`, which is ignored by Git. The successful command prints the automatic snapshot name.

### Save and restore local databases

```bash
composer snapshot -- before-plugin-update
composer restore -- before-plugin-update
```

A DDEV snapshot contains every database in the project, including `db` and `wp_tests`.

Running `composer snapshot` without a name creates a dated name. Snapshot names accept letters, numbers, periods, underscores, and hyphens.

Restoration can recreate DDEV's database container. It requires typing the displayed confirmation unless `--yes` is deliberately passed after `--`.

### Start the PHP test database again

```bash
composer reset:tests
```

This command can delete and recreate only `wp_tests`. It never targets `db`.

It also restores the test database's permission for DDEV's normal database user. The command requires typing `reset wp_tests` unless `--yes` is deliberately supplied.

## WordPress logs

Guided setup configures the normal local log at `wp-content/debug.log` when it can safely edit `wp-config.php`.

The expected local WordPress settings are:

```php
defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );
defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', $is_ddev );
defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', false );
```

Follow or clear that file:

```bash
composer tail:log
composer clear:log
```

The commands read `wp-config.php` on the host without starting WordPress. They require `WP_DEBUG` to be enabled and `WP_DEBUG_LOG` to be either `true` or the exact local `wp-content/debug.log` path.

`tail:log` creates the file when it is missing and `wp-content` is writable, then follows it even when the file is rotated or recreated. Pressing `Ctrl+C` stops only the log follower and returns successfully.

`clear:log` checks the same path and permissions before emptying the file. It does not delete the file.

A disabled log, another log destination, or an unwritable file produces an error instead of editing `wp-config.php` or guessing a location.

For the DDEV web and database service logs, use DDEV directly:

```bash
ddev logs -f
ddev logs -s db -f
```

## Test helpers

Plugin and theme tests can extend `WpTest\IntegrationTestCase` when they need helpers for:

- options that are removed after each test;
- database table and column checks;
- scheduled WordPress tasks;
- administrator creation;
- WordPress REST requests;
- temporary uploads;
- captured email; and
- plugin activation, deactivation, or uninstall.

Use the plain WordPress `WP_UnitTestCase` when none of these helpers are needed.

### Options, tables, scheduled tasks, REST, and uploads

Tracked options are removed during test cleanup:

```php
$this->set_tracked_option( 'my_plugin_setting', 'value' );
```

Database helpers can check that a table or column created by a plugin exists. Scheduled-task helpers can check that a named WordPress task is registered. REST helpers create requests through WordPress without making an internet connection.

The administrator helper creates a test administrator and selects it as the current user. Upload helpers write only below the isolated PHP test upload directory.

### Plugin lifecycle helpers

Activation, deactivation, and uninstall helpers use WordPress's normal functions. Before changing anything, they check that the current database is exactly `wp_tests` and the current table prefix is exactly `wptests_`.

Uninstall is destructive even in a disposable test site, so uninstall tests belong in the `destructive` group:

```php
/**
 * @group destructive
 */
public function test_uninstall_removes_plugin_data(): void {
	// Test uninstall behavior here.
}
```

### Expected HTTP replies

Unexpected WordPress HTTP requests fail. Provide known replies with `WpTest\HttpMock`:

```php
use WpTest\HttpMock;

HttpMock::queue(
	'https://service.example.test/api',
	HttpMock::response( '{"ok":true}', 200 ),
	HttpMock::timeout()
);
```

One address can return several replies in order. Available replies include:

- a normal response with a chosen status, body, and headers;
- a WordPress error;
- malformed JSON;
- a rate-limit response with a retry delay;
- a timeout; and
- a delayed response.

This keeps ordinary tests repeatable and prevents them from calling real payment, storage, webhook, or other service accounts.

### Captured email

PHP tests can capture WordPress email without sending it:

```php
$this->enable_mail_capture();

wp_mail( 'recipient@example.test', 'Subject', 'Body' );

$this->assertCount( 1, $this->captured_mail() );
```

Browser tests use DDEV Mailpit instead because they run through the working site.

## Reports and generated files

The toolkit keeps downloaded packages, temporary WordPress copies, test state, and reports inside `.test-tools`.

Important output paths:

| Path | Contents |
| --- | --- |
| `.test-tools/runtime/` | Generated test selection, PHP test configuration, temporary uploads, database refresh downloads, and private setup backups. |
| `.test-tools/test-results/` | Browser screenshots, traces, messages, failed requests, and debug-log excerpts. |
| `.test-tools/playwright-report/` | Readable browser-test report. |
| `.test-tools/coverage/` | HTML PHP coverage report. |
| `.test-tools/runtime/junit.xml` | Machine-readable PHP test results. |
| `.test-tools/wordpress/` | Clean WordPress files matching the installed site version. |
| `.test-tools/wordpress-tests-lib/` | Official WordPress PHP test library matching that version. |
| `.test-tools/vendor/` | Toolkit PHP packages. |
| `.test-tools/node_modules/` | Toolkit Node.js and browser-test packages. |

These paths are ignored by the toolkit repository. Successful browser tests remove their private working backup after database and file comparison. Failed restoration keeps its backup and prints the path.

The working site's `wp-content/debug.log` is not inside `.test-tools`; it belongs to the WordPress installation.

## Safety rules in detail

These rules are checked in code rather than being documentation promises alone:

- PHP plugin lifecycle helpers refuse any database other than `wp_tests` or any table prefix other than `wptests_`.
- The environment check refuses equal working and PHP test database names.
- Browser tests compare the local DDEV and WordPress host names before opening Chromium.
- Browser tests create a database snapshot before temporary users, posts, options, tables, or media are added.
- Browser tests compare the restored database and protected files with their saved state.
- The WordPress HTTP blocker runs during PHP tests unless a test has registered an expected reply first.
- Destructive tests remain excluded unless the destructive command is used.
- Remote database import, snapshot restoration, and test-database reset display their targets and require confirmation.
- Setup writes through temporary files, checks PHP or JSON, creates dated backups, and restores `wp-config.php` after a failed check.
- Setup reports structure without returning database values or other contents from `wp-config.php`.
- SFTP configuration backups are stored with owner-only permissions below the ignored `.test-tools/runtime/setup-backups/` directory.

Ordinary commands assume DDEV is already running. They do not call `ddev start`, `ddev stop`, `ddev restart`, or `ddev config`. Guided setup is the one explicit exception because preparing DDEV is its stated job.

## Toolkit files

The main files are arranged as follows:

```text
.test-tools/
├── bin/                         # small PHP commands for checking and preparing runs
├── e2e/                         # browser setup, browser helpers, and toolkit browser checks
├── fixtures/                    # included example plugins, themes, and setup files
├── src/                         # reusable PHP test helpers and plugin lifecycle code
├── tests/                       # toolkit PHP checks
├── composer.json               # PHP packages and public commands
├── package.json                # browser-test packages
├── config.php                  # fixed database values and compatibility rules
├── setup-host.sh               # guided installation
├── doctor-host.sh              # read-only environment check
├── run-tests-host.sh           # PHP test command
├── run-e2e-host.sh             # browser test command and restoration
├── run-all-host.sh             # PHP tests followed by browser tests
├── database-host.sh            # refresh, snapshot, restore, and reset commands
├── log-host.sh                 # WordPress debug-log commands
└── wp-test.config.example.php  # optional project selection example
```

The root `composer.json` mirrors the public command names. Both locations call the same files inside `.test-tools`, so command behavior does not depend on the current directory.

## Requirements and updates

The toolkit requires PHP 8.0 or later and currently uses PHPUnit 9.6. `composer doctor` checks the installed WordPress version against the DDEV PHP version and refuses combinations the toolkit does not cover.

Update the toolkit from the WordPress root:

```bash
git -C .test-tools pull --ff-only
composer setup -- --yes --database=keep
composer test
```

After a WordPress update, run:

```bash
composer doctor
composer test
```

The next PHP test run downloads the matching clean WordPress files and official WordPress PHP test library.

WordPress versions within a known supported branch automatically use the matching official test files. A future WordPress branch that has not been reviewed fails clearly instead of guessing that the current PHPUnit version is compatible.

## Common problems

### DDEV is not running

Normal commands stop and ask you to start it explicitly:

```bash
ddev start
```

### Guided setup refuses `wp-config.php`

The file was not changed. The setup command refuses repeated database definitions, repeated WordPress startup includes, partial arrangements it does not recognize, and other uncertain layouts.

Use the manual `wp-config.php` section in [SETUP.md](SETUP.md), run `php -l wp-config.php`, then check again:

```bash
composer setup -- --check
```

### A root Composer command has the same name

Setup keeps unrelated root packages, settings, and commands. It refuses only when an existing command uses one of the toolkit's public names for different work. Rename the existing command or decide explicitly which behavior the project needs.

### `wp_tests` is missing or damaged

Run:

```bash
composer reset:tests
```

This does not change `db`.

### PHP coverage is unavailable

Enable Xdebug explicitly:

```bash
ddev xdebug on
composer test:coverage
ddev xdebug off
```

PCOV is also supported when it is installed and enabled in the DDEV web container.

### A plugin preparation or activation step fails

The error identifies the plugin or theme, its path, and the preparation or activation error. Remember that `tests/phpunit/bootstrap.php` runs before WordPress has fully started.

### The debug log is missing

Check the logging settings above and confirm that `wp-content` is writable. The log commands do not redirect themselves to another path.

### Browser restoration fails

Do not begin another browser run. Use the snapshot name and backup directory printed by the failed command. The database snapshot can be restored with `composer restore -- snapshot-name`; protected files remain in the named backup directory for manual recovery.

### DDEV file synchronization reports conflicts

Use DDEV's diagnosis first:

```bash
ddev utility mutagen-diagnose
```

Reset synchronization only when those results call for it. See [SETUP.md](SETUP.md) for the full troubleshooting steps.

For full installation details and problems, see [SETUP.md](SETUP.md). Repository maintenance rules are in [AGENTS.md](AGENTS.md). Remaining ideas are recorded in [PLAN.md](PLAN.md).
