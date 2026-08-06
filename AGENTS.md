# AGENTS.md

## Purpose

This repository is installed as `.test-tools` inside an existing complete WordPress root. Its parent directory is the consuming WordPress installation.

The goal is a lightweight, deterministic local test and development utility surface for plugin and theme combinations, using DDEV for runtime isolation and conventional project commands such as `composer test` and `composer tail:log`.

## Non-negotiable rules

1. PHPUnit uses only database `wp_tests` with prefix `wptests_`. Never run destructive tests against working database `db`.
2. External services are blocked by default. Add explicit mocks or separately named opt-in integration commands; never weaken the default block to make a test pass.
3. Routine commands must not call DDEV start, stop, restart, rebuild, or configuration commands. Environment lifecycle remains explicit. `composer test:e2e` may call DDEV's database snapshot and restore commands; DDEV may recreate service containers internally while restoring a snapshot.
4. Every public Composer command must be available with the same name and behavior from both the consuming WordPress root and the `.test-tools` directory.
5. Developers must not need to enter `ddev sh` for normal work. Host wrappers may use `ddev wp` or `ddev exec`, but not lifecycle or configuration commands.
6. Never add site-specific domains, absolute user paths, SSH aliases, secrets, passwords, API keys, buckets, or service credentials.
7. Never silently alter the consuming site's persistent database, active plugin set, theme, uploads, or source files. A command that intentionally changes a local file, such as `composer clear:log`, must be narrowly scoped and documented.
8. Generated dependencies, downloaded WordPress copies, runtime overlays, reports, caches, and browser artifacts must remain ignored.
9. Agents must never add GitHub CI configuration or workflow files. Do not create or modify `.github/workflows/`, GitHub Actions YAML, or any equivalent GitHub-hosted CI setup.

The GitHub CI prohibition is unconditional. Do not infer permission from a plan, repository convention, or general request to add tests.

## Installed layout

```text
<wordpress-root>/
├── .test-tools/                 # this repository
├── .wp-test.php                 # optional consuming-site configuration
├── wp-admin/
├── wp-content/
├── wp-includes/
└── wp-config.php
```

Expected paths and state:

- toolkit root: `.test-tools`;
- WordPress root: parent of `.test-tools`;
- plugins: `wp-content/plugins`;
- must-use plugins: `wp-content/mu-plugins`;
- themes: `wp-content/themes`;
- local WordPress debug log: `wp-content/debug.log`;
- working database: `db`;
- PHPUnit database: `wp_tests`;
- PHPUnit table prefix: `wptests_`;
- generated runtime overlay: `.test-tools/runtime/wp-content`.

Add one documented configuration entry point when a path needs to become configurable. Do not scatter project-specific checks across scripts.

## Public command contract

Current public commands are exposed from both the consuming WordPress root and `.test-tools`:

```text
composer doctor
composer lint:wpcs
composer format:wpcs
composer tail:log
composer clear:log
composer test
composer test:php
composer test:harness
composer test:plugin -- <slug>
composer test:theme -- <slug>
composer test:multisite
composer test:destructive
composer test:coverage
composer test:junit
composer test:e2e
```

The toolkit's `composer.json` owns the `.test-tools` command mappings. The consuming root `composer.json` mirrors the same command names for convenience. The shell wrappers are the behavioral source of truth.

Rules:

- host wrappers must resolve the WordPress root from their own location and must not depend on the caller's current working directory;
- preserve native PHPUnit argument passthrough from both Composer locations;
- `composer test` runs the default PHP tests followed by browser tests and rejects runner-specific arguments;
- keep command names, profiles, exit codes, and side effects identical in both locations;
- invalid profiles and slugs fail before PHPUnit starts;
- commands never manage DDEV lifecycle;
- `composer doctor` is read-only;
- `composer tail:log` and `composer clear:log` operate only on the local `wp-content/debug.log` after validating local WordPress logging configuration and writability;
- destructive tests remain excluded unless explicitly requested;
- coverage remains opt-in;
- browser tests must reject a WordPress host name that differs from the local DDEV host name;
- browser tests must restore and compare the working database and protected file paths after success, failure, or interruption;
- public command changes require README and SETUP updates in the same commit.

## Browser-test architecture

Playwright runs on the host against the address reported by the already-running DDEV project. Node packages remain in `.test-tools/node_modules`.

Every browser run follows this sequence:

1. confirm DDEV is running and WordPress uses the same local host name;
2. save `wp-content/uploads`, `wp-content/mu-plugins`, and configured extra paths;
3. export and measure working database `db`, then create a temporary DDEV database snapshot;
4. create dedicated users and repeatable content after the snapshot, then sign them in through a random value valid only during this run rather than the site's normal login form;
5. run toolkit and selected extension tests in Chromium;
6. restore the database and saved files from an exit handler without deleting existing top-level protected directories that DDEV may have mounted; and
7. compare the restored database and files with their saved state.

Extension tests use `wp-content/plugins/<slug>/tests/e2e/**/*.spec.ts` and `wp-content/themes/<slug>/tests/e2e/**/*.spec.ts`. An extension may add `tests/e2e/fixtures.php`; it runs with WordPress fully loaded after the snapshot. The root `.wp-test.php` file may name `e2e_bootstrap` and extra `e2e_filesystem_paths` below `wp-content`.

The temporary must-use plugin defines `WP_TEST_E2E` and blocks outgoing WordPress HTTP calls except local, loopback, and Mailpit requests. Extensions must replace CAPTCHA, payments, storage, webhooks, and update checks with local behavior when this constant is true. Browser automation must never solve a real CAPTCHA or use real payment or storage credentials.

Failed browser tests retain traces, screenshots, browser messages, failed requests, the test title, selected profile, and a WordPress debug-log excerpt in ignored paths.

## PHPUnit architecture

The installed WordPress version is the source of truth for the synchronized clean core and WordPress PHPUnit library.

Every run follows this sequence inside one DDEV container invocation:

1. container doctor confirms database, environment, compatibility, tools, extensions, and writable paths;
2. the working site's active plugin and theme state is read without mutation in one WordPress bootstrap; the harness profile skips this step;
3. `ManifestBuilder` selects the profile and writes `runtime/manifest.json`;
4. `prepare-runtime.php` creates an isolated `runtime/wp-content`;
5. `sync-wordpress-tests.sh` synchronizes matching WordPress test assets;
6. `bootstrap.php` loads selected code and runs plugin activation against `wp_tests`;
7. generated `runtime/phpunit.xml` discovers toolkit and extension tests;
8. PHPUnit runs.

Do not bypass the preflight or lifecycle manager from a public entry point.

## Extension discovery contract

Default plugin test paths:

```text
wp-content/plugins/<slug>/tests/phpunit/**/*Test.php
wp-content/plugins/<slug>/tests/phpunit/**/test-*.php
wp-content/plugins/<slug>/tests/phpunit/bootstrap.php
```

Default theme test paths:

```text
wp-content/themes/<slug>/tests/phpunit/**/*Test.php
wp-content/themes/<slug>/tests/phpunit/**/test-*.php
wp-content/themes/<slug>/tests/phpunit/bootstrap.php
```

Extension bootstrap files run after WordPress test functions are available but before full WordPress bootstrap. They may define constants, load local autoloaders, and register `tests_add_filter()` callbacks. They must not assume full WordPress is already loaded.

Selection rules:

- active ordinary plugins are selected by default;
- active theme and parent theme are selected by default;
- inactive extensions require explicit configuration or a focused profile;
- focused profiles load configured dependencies but do not automatically select dependency tests;
- missing test paths are normal;
- malformed bootstraps fail with extension slug and path;
- toolkit harness tests remain a separate testsuite.

## Consuming-site configuration

Optional root file:

```text
<wordpress-root>/.wp-test.php
```

It may define:

- `include_plugins`;
- `exclude_plugins`;
- `include_themes`;
- `exclude_themes`;
- `plugin_dependencies`;
- `theme_dependencies`;
- `bootstrap`.

Keep this surface small. New keys require validation, documentation, examples, and harness tests.

## Lifecycle and data safety

- Plugin activation uses WordPress's activation API, not direct ad hoc hook calls.
- Activation, deactivation, and uninstall helpers must assert `DB_NAME === 'wp_tests'` and prefix `wptests_`.
- Activation failures must identify the plugin and include captured output when available.
- Activation must support options, custom tables, roles, cron, rewrite rules, and isolated uploads.
- Multisite profile activation is network-wide.
- Uninstall and deliberately destructive tests use PHPUnit group `destructive`.
- Default runs exclude `destructive`.
- Test uploads and plugin-generated content belong under the runtime overlay.
- Never write fixtures into the consuming site's real plugin, theme, or upload directories.
- `composer clear:log` may truncate only the validated local `wp-content/debug.log`; it must not delete it or target configurable remote paths.

## External services

- The priority-10 HTTP blocker must remain installed.
- `WpTest\HttpMock` may preempt it at priority 5.
- Generic mocks may cover arrays, `WP_Error`, malformed JSON, rate limits, delays, and sequences.
- Do not add provider-specific SDKs, clients, or credentials to the toolkit.
- Real sandbox calls require a distinct future opt-in command and ignored local credentials.
- Ordinary `composer test` must remain offline-safe.

## Helper surface

`WpTest\IntegrationTestCase` is intentionally small. Keep plugin-specific business logic in plugin tests.

Supported helper categories:

- tracked options;
- table and column assertions;
- cron assertions;
- REST requests;
- administrator fixtures;
- runtime uploads;
- captured mail;
- activation, deactivation, and uninstall.

Document helper changes before treating them as stable public APIs.

## Compatibility policy

`config.php` is the central safety and compatibility configuration.

- Do not dynamically rewrite Composer requirements during a test run.
- Keep PHPUnit 9.6 and PHPUnit Polyfills aligned with documented supported WordPress/PHP combinations.
- Unknown future WordPress branches must fail explicitly until the policy is reviewed.
- Update compatibility rules from authoritative WordPress documentation.
- Changes to minimum PHP, supported WordPress branches, or required extensions require README and SETUP updates.

## Implementation style

- Shell scripts use `#!/usr/bin/env bash` and `set -euo pipefail`.
- Quote paths and variables.
- Use arrays for argument forwarding.
- Use traps for temporary files.
- Use `exec` for final long-running processes.
- Prefer a small PHP helper over nested shell quoting.
- PHP files use `declare(strict_types=1);`.
- Exceptions and command errors must identify the failed command, extension, path, database, or configuration key.
- Do not accidentally raise the documented PHP requirement.
- Avoid frameworks and unnecessary abstraction layers.
- Prefer a small number of cohesive files.
- Centralize path resolution, environment checks, version detection, selection logic, and logging-target validation.

## Generated files

Routine validation and bulk maintenance must operate only on tracked or untracked, non-ignored files reported by Git. Do not traverse or modify ignored generated trees.

Keep at least these ignored:

```text
.wordpress-test-version
active-plugins.json
coverage/
runtime/
vendor/
wordpress/
wordpress-tests-lib/
.phpunit.result.cache
node_modules/
playwright-report/
test-results/
```

Update `.gitignore` in the same change whenever a tool adds generated downloads, caches, reports, snapshots, traces, symlinks, or temporary configuration.

## Documentation maintenance

Documentation is part of implementation. Update `README.md` and/or `SETUP.md` in the same commit whenever any of these changes:

- public command or invocation location;
- setup or upgrade step;
- required DDEV package or service;
- database, host, prefix, environment variable, or configuration file;
- file or directory read from the consuming WordPress root;
- plugin/theme test, bootstrap, fixture, or manifest convention;
- generated or ignored path;
- default extension-selection rule;
- lifecycle behavior;
- external-service mock mechanism;
- compatibility rule;
- behavior that modifies or restores local state;
- helper API.

Document structure:

- `README.md`: purpose, capabilities, daily use, behavior, limitations, and links;
- `SETUP.md`: complete installation, database, logging, ignore, update, and troubleshooting steps;
- `PLAN.md`: unfinished work, sequence, and acceptance criteria;
- `AGENTS.md`: repository goals and maintenance rules.

Do not present planned commands as current features.

## Validation

For PHP or shell changes, perform at least:

```bash
(
  cd .test-tools
  git ls-files --cached --others --exclude-standard -z -- '*.php' |
    while IFS= read -r -d '' file; do
      [[ ! -f "$file" ]] || php -l "$file" || exit
    done
)
(
  cd .test-tools
  git ls-files --cached --others --exclude-standard -z -- '*.sh' |
    while IFS= read -r -d '' file; do
      [[ ! -f "$file" ]] || bash -n "$file" || exit
    done
)
python3 -m json.tool .test-tools/composer.json >/dev/null
composer lint:wpcs
composer doctor
composer test:harness
composer test:php
(cd .test-tools && composer doctor)
(cd .test-tools && composer test:harness)
composer test
(cd .test-tools && composer test)
```

Run specialist commands when changed:

```bash
composer test:plugin -- <fixture-or-real-slug>
composer test:theme -- <fixture-or-real-slug>
composer test:multisite
composer test:destructive
composer test:junit
```

For logging changes, additionally verify:

```bash
composer clear:log
(cd .test-tools && composer clear:log)
```

Start `composer tail:log` from both supported directories, append a line to `wp-content/debug.log`, confirm it appears immediately, and stop the command with `Ctrl+C` without stopping DDEV.

Coverage validation requires Xdebug or PCOV and remains optional.

Validate documentation against actual filenames, command behavior, consumed paths, ignore rules, and limitations.

Do not add CI, remote-database automation, optional services, or Playwright before the local surface they depend on is stable. The prohibition on GitHub CI files remains in force even after the local surface is stable.
