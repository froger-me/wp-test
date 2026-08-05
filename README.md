# wp-test

`wp-test` is a portable PHPUnit integration-test toolkit for an existing, complete WordPress installation.

Install it as `.test-tools` inside a WordPress root served by DDEV. The working site keeps its real plugins, themes, uploads, settings, custom tables, and plugin combinations. Tests boot a matching clean WordPress copy against the separate disposable `wp_tests` database and an isolated runtime `wp-content` overlay.

See [SETUP.md](SETUP.md) for the complete installation guide.

## Safety model

- The interactive DDEV site uses database `db`.
- PHPUnit uses database `wp_tests` with prefix `wptests_`.
- Every test entry point runs the same safety preflight before WordPress test tables are changed.
- `composer doctor` is read-only and exits nonzero when the suite cannot run safely.
- Test commands never start, stop, restart, rebuild, or reconfigure DDEV.
- Unmocked requests through the WordPress HTTP API are blocked.
- Test uploads and runtime links live under `.test-tools/runtime/`, not the working `wp-content`.
- Destructive tests are excluded from the default run.

## Public commands

The same Composer scripts are available from either:

- the consuming WordPress root; or
- the `.test-tools` directory itself.

From the WordPress root:

```bash
composer doctor
composer test
composer test:harness
composer test:plugin -- plugin-slug
composer test:theme -- theme-slug
composer test:multisite
composer test:destructive
composer test:coverage
composer test:junit
```

From inside `.test-tools`, the command names and behavior are identical:

```bash
cd .test-tools
composer doctor
composer test
composer test:harness
composer test:plugin -- plugin-slug
composer test:theme -- theme-slug
composer test:multisite
composer test:destructive
composer test:coverage
composer test:junit
```

The host wrappers resolve the WordPress root from the `.test-tools` installation path before invoking DDEV. They do not depend on the shell's original working directory and do not require `ddev sh`.

Native PHPUnit arguments pass through from either location:

```bash
composer test -- --filter SettingsTest
composer test -- --group rest
composer test:plugin -- plugin-slug --filter UpgradeTest
composer test -- --order-by=random --random-order-seed=12345
```

`composer test:coverage` requires Xdebug or PCOV to be enabled explicitly. Coverage is written to `.test-tools/coverage/`. `composer test:junit` writes `.test-tools/runtime/junit.xml`.

## Default integration profile

`composer test`:

1. verifies DDEV, database isolation, required tools, PHP extensions, writable generated paths, and the WordPress/PHP/PHPUnit compatibility policy;
2. reads the working site's active ordinary plugins, active theme, and parent theme;
3. applies optional selection rules from `<wordpress-root>/.wp-test.php`;
4. synchronizes a clean WordPress core and WordPress PHPUnit library to the installed WordPress version;
5. creates an isolated runtime `wp-content` containing links to only the selected extensions;
6. boots WordPress using the selected plugin load order and theme;
7. activates each selected plugin through WordPress's normal `activate_plugin()` lifecycle against `wp_tests`;
8. loads conventional extension test bootstraps;
9. discovers conventional plugin and theme PHPUnit tests; and
10. runs the harness and extension suites.

The working `db` database and working upload directory are not used by PHPUnit.

## Extension test conventions

Active plugins are included by default. The active theme and its parent theme are included by default.

Plugin tests:

```text
wp-content/plugins/<slug>/tests/phpunit/**/*Test.php
wp-content/plugins/<slug>/tests/phpunit/bootstrap.php       # optional
```

Theme tests:

```text
wp-content/themes/<slug>/tests/phpunit/**/*Test.php
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

The configuration file must return an array. Paths are relative to the WordPress root unless absolute.

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
	HttpMock::rateLimited(30),
	HttpMock::timeout()
);
```

Available patterns include normal responses, `WP_Error`, malformed JSON, rate limits, delays, and sequential responses. Provider-specific clients and credentials remain in plugin code and local ignored configuration.

### Mail capture

```php
$this->enableMailCapture();

wp_mail('recipient@example.test', 'Subject', 'Body');

$this->assertCount(1, $this->capturedMail());
```

## Compatibility policy

The toolkit currently requires PHP 8.0 or later and PHPUnit 9.6. `composer doctor` compares the installed WordPress branch and DDEV PHP version against the compatibility policy in `.test-tools/config.php`.

Unknown future WordPress branches fail explicitly instead of silently running with an unverified PHPUnit stack. Updating WordPress within a covered branch automatically refreshes the matching clean core and WordPress test library on the next test run.

## Repository layout

```text
.test-tools/
├── autoload.php
├── bin/
├── fixtures/
├── src/
├── tests/
├── bootstrap.php
├── composer.json
├── config.php
├── doctor-host.sh
├── phpunit.xml.dist
├── run-tests-host.sh
├── run-tests.sh
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
.test-tools/.wordpress-test-version
```

## Updating

From the WordPress root:

```bash
git -C .test-tools pull --ff-only
ddev exec --dir=/var/www/html/.test-tools composer install
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

Phase 1, the PHPUnit surface, is implemented. The remaining work is documented in [PLAN.md](PLAN.md), beginning with standard log commands and then Playwright E2E testing.

Repository maintenance rules are in [AGENTS.md](AGENTS.md).
