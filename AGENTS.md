# AGENTS.md

## Purpose

This repository is installed as `.test-tools` inside an existing complete WordPress root. Its parent directory is the consuming WordPress installation.

The goal is a lightweight, deterministic local test surface for plugin and theme combinations, using DDEV for runtime isolation and conventional project commands such as `composer test`.

## Non-negotiable rules

1. PHPUnit uses only database `wp_tests` with prefix `wptests_`. Never run destructive tests against working database `db`.
2. External services are blocked by default. Add explicit mocks or separately named opt-in integration commands; never weaken the default block to make a test pass.
3. Routine commands must not start, stop, restart, rebuild, or reconfigure DDEV. Environment lifecycle remains explicit.
4. Public developer entry points belong in the consuming WordPress root's `composer.json`.
5. Developers must not need to enter `ddev sh` for normal work. Host wrappers may use `ddev wp` or `ddev exec`, but not lifecycle or configuration commands.
6. Never add site-specific domains, absolute user paths, SSH aliases, secrets, passwords, API keys, buckets, or service credentials.
7. Never silently alter the consuming site's persistent database, active plugin set, theme, uploads, or source files.
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
- working database: `db`;
- PHPUnit database: `wp_tests`;
- PHPUnit table prefix: `wptests_`;
- generated runtime overlay: `.test-tools/runtime/wp-content`.

Add one documented configuration entry point when a path needs to become configurable. Do not scatter project-specific checks across scripts.

## Public command contract

Current public commands are exposed from the consuming WordPress root:

```text
composer doctor
composer test
composer test:harness
composer test:plugin -- <slug>
composer test:theme -- <slug>
composer test:multisite
composer test:destructive
composer test:coverage
composer test:junit
```

Rules:

- preserve native PHPUnit argument passthrough;
- invalid profiles and slugs fail before PHPUnit starts;
- commands never manage DDEV lifecycle;
- `composer doctor` is read-only;
- destructive tests remain excluded unless explicitly requested;
- coverage remains opt-in;
- public command changes require README and SETUP updates in the same commit.

## PHPUnit architecture

The installed WordPress version is the source of truth for the synchronized clean core and WordPress PHPUnit library.

Every run follows this sequence:

1. host preflight confirms DDEV is already running;
2. container doctor confirms database, environment, compatibility, tools, extensions, and writable paths;
3. the working site's active plugin and theme state is read without mutation;
4. `ManifestBuilder` selects the profile and writes `runtime/manifest.json`;
5. `prepare-runtime.php` creates an isolated `runtime/wp-content`;
6. `sync-wordpress-tests.sh` synchronizes matching WordPress test assets;
7. `bootstrap.php` loads selected code and runs plugin activation against `wp_tests`;
8. generated `runtime/phpunit.xml` discovers toolkit and extension tests;
9. PHPUnit runs.

Do not bypass the preflight or lifecycle manager from a public entry point.

## Extension discovery contract

Default plugin test paths:

```text
wp-content/plugins/<slug>/tests/phpunit/**/*Test.php
wp-content/plugins/<slug>/tests/phpunit/bootstrap.php
```

Default theme test paths:

```text
wp-content/themes/<slug>/tests/phpunit/**/*Test.php
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
- Exceptions must identify the failed command, extension, path, database, or configuration key.
- Do not accidentally raise the documented PHP requirement.
- Avoid frameworks and unnecessary abstraction layers.
- Prefer a small number of cohesive files.
- Centralize path resolution, environment checks, version detection, and selection logic.

## Generated files

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

- public command;
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
- `SETUP.md`: complete installation, database, ignore, update, and troubleshooting steps;
- `PLAN.md`: unfinished work, sequence, and acceptance criteria;
- `AGENTS.md`: repository goals and maintenance rules.

Do not present planned commands as current features.

## Validation

For PHP or shell changes, perform at least:

```bash
find .test-tools -name '*.php' -type f -print0 | xargs -0 -n1 php -l
find .test-tools -name '*.sh' -type f -print0 | xargs -0 -n1 bash -n
python3 -m json.tool .test-tools/composer.json >/dev/null
composer doctor
composer test:harness
composer test
```

Run specialist commands when changed:

```bash
composer test:plugin -- <fixture-or-real-slug>
composer test:theme -- <fixture-or-real-slug>
composer test:multisite
composer test:destructive
composer test:junit
```

Coverage validation requires Xdebug or PCOV and remains optional.

Validate documentation against actual filenames, command behavior, consumed paths, ignore rules, and limitations.

Do not add CI, remote-database automation, optional services, or Playwright before the local surface they depend on is stable. The prohibition on GitHub CI files remains in force even after the local surface is stable.
