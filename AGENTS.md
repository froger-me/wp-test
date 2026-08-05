# AGENTS.md

## Purpose

This repository is installed as `.test-tools` inside an existing complete WordPress root. Its parent directory is therefore the consuming WordPress installation.

The goal is a lightweight, deterministic local test surface for plugin and theme combinations, using DDEV for runtime isolation and conventional project commands such as `composer test`.

## Core rules

1. PHPUnit must use the disposable `wp_tests` database. Never run destructive tests against the working `db` database.
2. External services are blocked by default. Add explicit mocks or a separately named opt-in integration mode; never weaken the default block merely to make a test pass.
3. Routine commands must not start, stop, restart, rebuild, or reconfigure DDEV. Environment lifecycle remains explicit.
4. Public developer entry points belong in the consuming WordPress root's `composer.json`. Prefer commands such as `composer test`, `composer test:e2e`, `composer test:all`, `composer tail:log`, and `composer doctor`.
5. Developers must not need to enter `ddev sh` for normal work. Host wrappers may call `ddev wp` or `ddev exec`, but not lifecycle or configuration commands.
6. Do not add site-specific domains, paths, SSH aliases, secrets, or service credentials. Examples use placeholders.
7. Generated dependencies, downloaded WordPress copies, test libraries, active-plugin snapshots, reports, traces, caches, and browser artifacts must remain ignored.
8. Do not silently alter the consuming site's active plugin set or persistent database.

## Installed layout

```text
<wordpress-root>/
├── .test-tools/                 # this repository
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
- PHPUnit table prefix: `wptests_`.

Add one documented configuration entry point when a path needs to become configurable. Do not scatter project-specific path checks across scripts.

## PHPUnit guidance

- The installed WordPress version is the source of truth for the synchronized clean core and WordPress PHPUnit library.
- The working site's active ordinary plugins are the default integration combination.
- Must-use plugins load through normal WordPress behavior.
- Plugin activation work targets `wp_tests` only.
- Options, custom tables, core-table data, cron, REST routes, files, and uninstall behavior should be testable without coupling the toolkit to a specific plugin.
- Unmocked WordPress HTTP API calls remain denied.
- Bootstrap failures must identify the responsible plugin, theme, file, database, or configuration entry.
- Normal PHPUnit filters and groups should pass through `composer test -- ...`.

## E2E guidance

The planned Playwright surface must use the existing DDEV site without starting a hidden stack, preserve the developer's working state, create dedicated test users and fixtures, block external services by default, capture useful failure artifacts, discover plugin/theme tests through a documented convention, and expose a standard Composer command.

## Implementation style

- Shell scripts use `#!/usr/bin/env bash` and `set -euo pipefail` unless documented otherwise.
- Quote paths and variables; use traps for temporary files; propagate subprocess exit codes.
- Use `exec` for the final long-running process.
- Prefer a small helper file over brittle nested shell quoting.
- PHP files use `declare(strict_types=1);` and actionable exceptions.
- Do not raise the documented PHP requirement accidentally.
- Avoid frameworks or extra abstraction layers for simple bootstrap and test-helper needs.
- Prefer a small number of cohesive files over churn and wrappers.
- Centralize path resolution, environment checks, and WordPress version detection.

## Generated files

At minimum, keep these ignored:

```text
.wordpress-test-version
active-plugins.json
vendor/
wordpress/
wordpress-tests-lib/
.phpunit.result.cache
coverage/
node_modules/
playwright-report/
test-results/
```

Update `.gitignore` in the same change whenever a tool adds generated downloads, caches, reports, snapshots, traces, or temporary configuration.

## Documentation maintenance

Documentation is part of the implementation. Update `README.md` and/or `SETUP.md` in the same change whenever any of these changes:

- a public command;
- a setup or upgrade step;
- a required DDEV package or service;
- a database, table prefix, environment variable, or configuration file;
- a file or directory read from the consuming WordPress root;
- a file, manifest, bootstrap, fixture, or test convention read from a plugin or theme;
- a generated or ignored path;
- a default plugin/theme selection rule;
- an external-service mock or integration mechanism;
- a compatibility rule;
- behavior that modifies or restores local state.

Document structure:

- `README.md`: purpose, capabilities, daily use, behavior, limitations, and links;
- `SETUP.md`: complete installation, database, ignore, update, and troubleshooting steps;
- `PLAN.md`: unfinished work, sequence, and acceptance criteria;
- `AGENTS.md`: repository goals and maintenance rules.

Do not present planned commands as current features.

## Validation

For current PHP or shell changes, run the relevant checks from an installed consuming project:

```bash
composer test
php -l .test-tools/bootstrap.php
```

Validate JSON files and preserve executable bits on shell scripts. Documentation changes must be checked against actual file names, commands, layout, ignore rules, and current limitations.

Do not introduce CI, remote-database automation, optional services, or compatibility matrices before the local surface they depend on is stable unless explicitly requested.
