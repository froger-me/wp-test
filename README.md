# wp-test

`wp-test` is a portable local testing toolkit for an existing, complete WordPress installation.

It is designed to be cloned as `.test-tools` inside a WordPress file tree that is served by DDEV. The working site keeps all of its plugins, themes, uploads, settings, custom tables, and plugin combinations, while PHPUnit runs against a separate disposable `wp_tests` database.

## Current capabilities

- Runs with the standard project command `composer test` from the WordPress root.
- Uses DDEV for PHP, WordPress, MariaDB/MySQL, WP-CLI, Composer, and test execution.
- Detects the installed WordPress version and downloads the matching clean WordPress core and PHPUnit test library.
- Refreshes the active-plugin list from the working local site before each test run.
- Loads the same active plugins through WordPress's normal bootstrap.
- Runs registered plugin activation hooks against the test database so plugin options and custom tables can be installed.
- Loads must-use plugins when `wp-content/mu-plugins` exists.
- Blocks unmocked external HTTP requests during PHPUnit execution.
- Leaves the working DDEV database untouched.
- Does not start, restart, or rebuild DDEV when tests run.

## Requirements

- An existing WordPress file tree.
- Docker Desktop or another DDEV-supported container runtime.
- DDEV.
- Composer available on the host so `composer test` can be used directly.
- PHP 8.0 or later in the DDEV web container for the current toolkit code.

## Getting started

The complete guide covers:

1. wrapping an existing WordPress file tree in DDEV;
2. either creating a clean local database or importing an existing database;
3. installing this repository as `.test-tools`;
4. configuring `composer test`;
5. excluding local tooling from SFTP and source-control uploads; and
6. running and updating the suite.

See [SETUP.md](SETUP.md).

## Daily use

Start the development environment explicitly:

```bash
ddev start
```

Run the PHPUnit surface from the WordPress root:

```bash
composer test
```

Pass PHPUnit arguments after `--`:

```bash
composer test -- --filter ActivePluginsTest
composer test -- --testsuite "Shared WordPress integration tests"
```

Stop the environment explicitly when finished:

```bash
ddev stop
```

`composer test` intentionally fails when DDEV is not running. Test commands do not manage the environment lifecycle.

## What happens during `composer test`

1. The host runner reads the working site's `active_plugins` option through WP-CLI.
2. The container runner checks the installed WordPress version.
3. If WordPress was updated, the matching clean core and WordPress PHPUnit library are downloaded automatically.
4. PHPUnit installs a clean test site in the `wp_tests` database using the `wptests_` table prefix.
5. The locally active plugins are loaded and their activation hooks are run against `wp_tests`.
6. Unmocked WordPress HTTP API requests are rejected.
7. The test suite runs without modifying the working site's `db` database.

## Repository layout

This repository's root becomes `.test-tools` in the consuming WordPress installation.

```text
.test-tools/
├── bootstrap.php
├── composer.json
├── composer.lock
├── phpunit.xml.dist
├── run-tests-host.sh
├── run-tests.sh
├── sync-wordpress-tests.sh
└── tests/
```

Generated dependencies, downloaded WordPress files, the generated active-plugin list, and test artifacts are excluded by this repository's `.gitignore`.

## Updating the toolkit

From the consuming WordPress root:

```bash
git -C .test-tools pull --ff-only
ddev exec --dir=/var/www/html/.test-tools composer install
composer test
```

When the installed WordPress core is updated, no toolkit configuration change is required. The next `composer test` synchronizes the clean test core and WordPress test library to the detected version.

## Safety model

- `db` is the persistent interactive development database.
- `wp_tests` is the disposable PHPUnit database.
- The suite must never run destructive test operations against `db`.
- External HTTP calls are denied unless a test supplies a mock response.
- Remote service credentials should not be stored in this repository.
- DDEV startup, shutdown, image rebuilds, and database imports remain explicit developer actions.

## Current limitations

The current suite validates the shared harness and active-plugin bootstrap. Plugin- and theme-local test discovery, lifecycle-specific test helpers, multisite execution, Playwright E2E testing, and standard log commands are planned but not yet implemented.

See [PLAN.md](PLAN.md) for the detailed continuation plan.

## Contributing and agent guidance

Repository modification rules and maintenance expectations are documented in [AGENTS.md](AGENTS.md).
