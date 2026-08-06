# wp-test

`wp-test` is a portable PHPUnit integration-test and Playwright browser-test toolkit for an existing, complete WordPress installation.

Install it as `.test-tools` inside a WordPress root served by DDEV. The working site keeps its real plugins, themes, uploads, settings, custom tables, and plugin combinations. Tests boot a matching clean WordPress copy against the separate disposable `wp_tests` database and an isolated runtime `wp-content` overlay.

See [SETUP.md](SETUP.md) for the complete installation guide.

## Safety model

- The interactive DDEV site uses database `db`.
- PHPUnit uses database `wp_tests` with prefix `wptests_`.
- Every test entry point runs the same safety preflight before WordPress test tables are changed.
- `composer doctor` is read-only and exits nonzero when the suite cannot run safely.
- PHPUnit and logging commands never start, stop, restart, rebuild, or reconfigure DDEV.
- Browser tests require DDEV to be running already. They use DDEV's database snapshot and restore commands, but never call a DDEV start, stop, restart, or configuration command.
- Unmocked requests through the WordPress HTTP API are blocked.
- Test uploads and runtime links live under `.test-tools/runtime/`, not the working `wp-content`.
- Destructive tests are excluded from the default run.
- Logging commands operate only on the local `wp-content/debug.log` file after validating the DDEV logging configuration.
- Database replacement and snapshot restoration require typed confirmation, or an explicit `--yes` after the target has been reviewed.

## Public commands

The same Composer scripts are available from either the consuming WordPress root or the `.test-tools` directory itself.

From the WordPress root:

```bash
composer doctor
composer lint:wpcs
composer format:wpcs
composer tail:log
composer clear:log
composer db:pull
composer snapshot
composer restore -- snapshot-name
composer reset:tests
composer test
composer test:php
composer test:harness
composer test:plugin -- plugin-slug
composer test:theme -- theme-slug
composer test:multisite
composer test:destructive
composer test:coverage
composer test:junit
composer test:e2e
```

From inside `.test-tools`, the command names and behavior are identical:

```bash
cd .test-tools
composer doctor
composer lint:wpcs
composer format:wpcs
composer tail:log
composer clear:log
composer db:pull
composer snapshot
composer restore -- snapshot-name
composer reset:tests
composer test
composer test:php
composer test:harness
composer test:plugin -- plugin-slug
composer test:theme -- theme-slug
composer test:multisite
composer test:destructive
composer test:coverage
composer test:junit
composer test:e2e
```

The host wrappers resolve the WordPress root from the `.test-tools` installation path and do not depend on the shell's original working directory. Each PHP test command enters the existing DDEV web container only once. Browser tests use the host browser and DDEV database snapshot commands. Logging commands validate and follow the host-mounted local file directly, so they do not incur container startup latency.

`composer test` runs the default PHP integration tests and then the Playwright browser tests. Runner-specific arguments are intentionally rejected. Run one surface directly when filtering or passing its own options.

Native PHPUnit arguments pass through `composer test:php` and the focused PHP commands from either location:

```bash
composer test:php -- --filter SettingsTest
composer test:php -- --group rest
composer test:plugin -- plugin-slug --filter UpgradeTest
composer test:php -- --order-by=random --random-order-seed=12345
```

`composer lint:wpcs` checks every tracked or untracked, non-ignored PHP file against the WordPress Coding Standards ruleset and the documented CLI/PSR-4 exceptions in `phpcs.xml.dist`. `composer format:wpcs` applies PHPCBF fixes to that same Git-derived file set. Generated, ignored, and vendor files are never passed to either command.

`composer test:coverage` requires Xdebug or PCOV to be loaded explicitly. With DDEV Xdebug, run `ddev xdebug on` first and `ddev xdebug off` afterward. The toolkit forces `XDEBUG_MODE=off` for Doctor, WP-CLI, manifest generation, and other preparation processes, then enables `XDEBUG_MODE=coverage` only for the final PHPUnit process. A coverage-only self-check verifies the requested driver; normal runs exclude that check rather than reporting it as skipped. Coverage is written to `.test-tools/coverage/`.

`composer test:junit` writes `.test-tools/runtime/junit.xml`.

## Database refresh, snapshots, and reset

`composer db:pull` replaces only the local working database `db` from a remote WordPress installation. Copy `.test-tools/db-refresh-config-example.php` to `.test-tools/db-refresh.local.php` and provide an SSH alias, the absolute remote WordPress path, the remote URL, and the local URL. The local file is ignored by Git. The SSH alias must obtain its connection details from the user's SSH configuration; do not put passwords or private keys in either file.

The refresh command requires confirmation. It streams a compressed export over SSH with a one-gigabyte packet limit, verifies the compressed archive, and creates a named local DDEV snapshot before importing. WordPress then replaces the configured URL in serialized values as well as plain text. The command refuses to continue unless the configured local URL, the WordPress site URL, and the running DDEV project use the same host name. If importing or URL replacement fails, it attempts to restore the automatic snapshot.

```bash
composer db:pull
# For deliberate non-interactive use after reviewing the configuration:
composer db:pull -- --yes
```

The downloaded archive remains in the ignored `.test-tools/runtime/db-pulls/` directory for inspection. The successful command prints the automatic snapshot name.

Create and restore local DDEV database snapshots explicitly:

```bash
composer snapshot                         # generates a dated name
composer snapshot -- before-plugin-update
composer restore -- before-plugin-update  # requires typed confirmation
composer restore -- before-plugin-update --yes
```

A DDEV snapshot contains every database in this local project. Restoring one can therefore replace both `db` and `wp_tests` and may recreate DDEV's database container.

Reset only the disposable PHPUnit database when its schema or data needs a clean start:

```bash
composer reset:tests          # requires typing: reset wp_tests
composer reset:tests -- --yes
```

This permanently drops and recreates `wp_tests`. It uses fixed database names and does not alter the working WordPress database `db`.

`composer test:e2e` runs Chromium against the local URL reported by the already-running DDEV project. Pass normal Playwright options after `--`:

```bash
composer test:e2e -- --grep "settings"
```

Node packages stay in `.test-tools/node_modules`.

## Browser tests

Before opening a browser, `composer test:e2e` confirms that the WordPress site address has the same host name as the DDEV address. It refuses to continue when the addresses differ, which prevents an accidental request to a remote site.

The command then:

1. records the complete `wp-content/uploads` and `wp-content/mu-plugins` contents, plus any configured extra paths;
2. exports and measures the working database, then creates a temporary DDEV database snapshot;
3. installs a temporary must-use plugin and creates a dedicated administrator, editor, post, term, media record, option, and custom-table row;
4. uses a random value valid only during this run to create reusable signed-in browser state for both users without relying on the site's normal login form;
5. runs the toolkit checks and discovered extension tests in Chromium; and
6. restores the database and files after success, failure, `Ctrl+C`, or another termination signal, then compares them with the saved state. Existing top-level protected directories are kept in place so DDEV remains attached to them.

DDEV removes the temporary database snapshot after a successful restore. If restoration fails, the command keeps the snapshot name and the filesystem backup path in its error output for manual recovery.

Active plugins, the active theme, and its parent theme are selected by default. The same `include_plugins`, `exclude_plugins`, `include_themes`, and `exclude_themes` rules used by PHPUnit also apply. Focused browser runs are available:

```bash
composer test:e2e -- --profile=plugin plugin-slug
composer test:e2e -- --profile=theme theme-slug
```

Extension browser tests and optional repeatable data setup are discovered here:

```text
wp-content/plugins/<slug>/tests/e2e/**/*.spec.ts
wp-content/plugins/<slug>/tests/e2e/fixtures.php
wp-content/themes/<slug>/tests/e2e/**/*.spec.ts
wp-content/themes/<slug>/tests/e2e/fixtures.php
```

The optional `fixtures.php` file runs with WordPress fully loaded after the database snapshot is created. All of its database changes are therefore temporary. A site-wide file may be named with `e2e_bootstrap` in `.wp-test.php`.

Import the toolkit's Playwright wrapper to include browser console messages and failed request details in failure output. From either conventional extension directory:

```ts
import { test, expect, lowerCapabilityStorageState } from '../../../../../.test-tools/e2e/test';
```

The default signed-in state belongs to the dedicated administrator. Use `test.use({ storageState: lowerCapabilityStorageState })` for editor checks. Tests may import directly from `@playwright/test`, but those two extra text attachments will not be recorded.

While browser tests run, the temporary must-use plugin defines `WP_TEST_E2E` and blocks outgoing WordPress HTTP requests except requests to the local site, loopback addresses, and Mailpit. Plugin code should use that constant to replace CAPTCHA verification, payment processing, object storage, webhooks, and update checks with local test behavior. CAPTCHA must use a test verifier; it must not be solved in the browser. Mail is delivered to DDEV's Mailpit. The toolkit does not provide a command for real payment or storage test accounts.

Failed tests keep traces, screenshots, browser messages, failed request details, the test title, selected extension profile, and the last 200 WordPress debug-log lines under `.test-tools/test-results/`. The HTML report is written to `.test-tools/playwright-report/`. These paths are ignored by Git.

## Local WordPress logging

The logging commands require local DDEV WordPress logging to resolve to `wp-content/debug.log`:

```php
defined('WP_DEBUG') || define('WP_DEBUG', true);
defined('WP_DEBUG_LOG') || define('WP_DEBUG_LOG', $is_ddev);
defined('WP_DEBUG_DISPLAY') || define('WP_DEBUG_DISPLAY', false);
```

Follow the log:

```bash
composer tail:log
```

The command reads `WP_DEBUG` and `WP_DEBUG_LOG` directly from `wp-config.php` without booting WordPress, creates `wp-content/debug.log` when its directory is writable, and then runs the host's `tail -F` against the mounted file. `Ctrl+C` exits successfully and stops only the log follower.

Clear the log without deleting it:

```bash
composer clear:log
```

A disabled debug configuration, a custom `WP_DEBUG_LOG` destination, or an unwritable file fails with an actionable error. The commands never edit `wp-config.php` and never contain a remote path or credential.

DDEV already provides direct commands for container service logs, so the toolkit does not wrap them:

```bash
ddev logs -f
ddev logs -s db -f
```

## Default integration profile

`composer test:php`:

1. enters the existing DDEV web container once and verifies database isolation, required tools, PHP extensions, writable generated paths, and the WordPress/PHP/PHPUnit compatibility policy;
2. reads the working site's active ordinary plugins, active theme, and parent theme in one WordPress bootstrap;
3. applies optional selection rules from `<wordpress-root>/.wp-test.php`;
4. synchronizes a clean WordPress core and WordPress PHPUnit library to the installed WordPress version;
5. creates an isolated runtime `wp-content` containing links to only the selected extensions;
6. boots WordPress using the selected plugin load order and theme;
7. activates each selected plugin through WordPress's normal `activate_plugin()` lifecycle against `wp_tests`;
8. loads conventional extension test bootstraps;
9. discovers conventional plugin and theme PHPUnit tests; and
10. runs the harness and extension suites.

The working `db` database and working upload directory are not used by PHPUnit. The harness profile does not inspect or boot the working site because its fixture selection is self-contained.

The toolkit's non-fixture safety checks remain part of normal profiles. Fixture lifecycle, REST, upload, mail, and helper self-tests use PHPUnit group `harness-fixture` and run only through `composer test:harness`; default, focused, multisite, coverage, JUnit, and destructive profiles exclude that group so toolkit fixtures cannot affect the selected real plugin/theme combination.

## Extension test conventions

Active plugins are included by default. The active theme and its parent theme are included by default.

Plugin tests:

```text
wp-content/plugins/<slug>/tests/phpunit/**/*Test.php
wp-content/plugins/<slug>/tests/phpunit/**/test-*.php
wp-content/plugins/<slug>/tests/phpunit/bootstrap.php       # optional
```

Theme tests:

```text
wp-content/themes/<slug>/tests/phpunit/**/*Test.php
wp-content/themes/<slug>/tests/phpunit/**/test-*.php
wp-content/themes/<slug>/tests/phpunit/bootstrap.php        # optional
```

An extension bootstrap is loaded after the WordPress test functions are available but before WordPress boots. It may define constants, load an extension-local Composer autoloader, or register `tests_add_filter()` callbacks. It should not assume that the complete WordPress runtime has loaded yet.

Missing test directories and bootstraps are normal. A malformed bootstrap fails with the extension type, slug, and path.

## Focused profiles

```bash
composer test:plugin -- plugin-slug
composer test:theme -- theme-slug
```

A focused plugin run loads the selected plugin, configured plugin dependencies, and the working theme for runtime compatibility. Only the selected plugin's conventional tests are added.

A focused theme run loads the selected theme and parent theme plus any configured plugin dependencies. Only the selected theme's conventional tests are added.

Invalid or excluded slugs fail before PHPUnit starts.

## Optional site configuration

Copy `.test-tools/wp-test.config.example.php` to `<wordpress-root>/.wp-test.php` only when the active-site defaults are insufficient.

Supported keys:

- `include_plugins`
- `exclude_plugins`
- `include_themes`
- `exclude_themes`
- `plugin_dependencies`
- `theme_dependencies`
- `bootstrap`
- `e2e_bootstrap`
- `e2e_filesystem_paths`

The configuration file must return an array. Paths are relative to the WordPress root unless absolute. Each `e2e_filesystem_paths` entry must be a narrow path below `wp-content`; the browser command saves, restores, and compares each listed path.

## Lifecycle behavior

Selected plugins are loaded in the same order as the working site's `active_plugins` option. After the clean WordPress test site boots, the toolkit:

- creates and selects a test administrator;
- clears the generated test site's active-plugin option;
- activates each selected plugin with WordPress's normal activation API;
- reports activation output or errors with the responsible plugin path; and
- supports network-wide activation in `composer test:multisite`.

Activation callbacks can create options, custom tables, roles, cron events, rewrite rules, and upload files inside isolated test state. Repeated suite runs recreate the WordPress test installation.

Destructive plugin uninstall tests must use PHPUnit group `destructive`. They run only through:

```bash
composer test:destructive
```

## Reusable helpers

Extension tests may extend:

```php
use WpTest\IntegrationTestCase;
```

The base class provides helpers for:

- tracked option cleanup;
- custom-table and column assertions;
- cron assertions;
- administrator creation;
- REST requests;
- isolated upload files;
- captured mail;
- activation, deactivation, and uninstall operations.

Lifecycle helpers refuse to run unless `DB_NAME` is `wp_tests` and the active table prefix is `wptests_`.

### HTTP mocks

Unmocked WordPress HTTP requests return `WP_Error` code `unexpected_http_request`.

Queue deterministic responses with `WpTest\HttpMock`:

```php
use WpTest\HttpMock;

HttpMock::queue(
	'https://service.example.test/api',
	HttpMock::response('{"ok":true}', 200),
	HttpMock::rate_limited(30),
	HttpMock::timeout()
);
```

Available patterns include normal responses, `WP_Error`, malformed JSON, rate limits, delays, and sequential responses. Provider-specific clients and credentials remain in plugin code and local ignored configuration.

### Mail capture

```php
$this->enable_mail_capture();

wp_mail('recipient@example.test', 'Subject', 'Body');

$this->assertCount(1, $this->captured_mail());
```

## Compatibility policy

The toolkit currently requires PHP 8.0 or later and PHPUnit 9.6. `composer doctor` compares the installed WordPress branch and DDEV PHP version against the compatibility policy in `.test-tools/config.php`.

Unknown future WordPress branches fail explicitly instead of silently running with an unverified PHPUnit stack. Updating WordPress within a covered branch automatically refreshes the matching clean core and WordPress test library on the next test run.

## Repository layout

```text
.test-tools/
├── autoload.php
├── bin/
│   ├── capture-working-state.php
│   └── validate-debug-log.php
├── fixtures/
├── e2e/
├── src/
├── tests/
├── bootstrap.php
├── composer.json
├── package.json
├── playwright.config.ts
├── config.php
├── doctor-host.sh
├── log-host.sh
├── phpunit.xml.dist
├── run-tests-host.sh
├── run-e2e-host.sh
├── sync-wordpress-tests.sh
└── wp-test.config.example.php
```

Generated content is ignored:

```text
.test-tools/vendor/
.test-tools/wordpress/
.test-tools/wordpress-tests-lib/
.test-tools/runtime/
.test-tools/coverage/
.test-tools/node_modules/
.test-tools/playwright-report/
.test-tools/test-results/
.test-tools/.wordpress-test-version
```

## Updating

From the WordPress root:

```bash
git -C .test-tools pull --ff-only
ddev exec --dir=/var/www/html/.test-tools composer install
cd .test-tools && npm install && npx playwright install chromium && cd ..
composer doctor
composer test:harness
composer test
```

After updating, the same checks may instead be run from the toolkit directory:

```bash
cd .test-tools
composer doctor
composer test:harness
composer test
```

When public commands, setup steps, consumed plugin/theme paths, configuration files, helpers, or generated paths change, the documentation must change in the same commit.

## Next phases

Phases 1 through 3 are implemented. Remaining local developer utilities are documented in [PLAN.md](PLAN.md).

Repository maintenance rules are in [AGENTS.md](AGENTS.md).
