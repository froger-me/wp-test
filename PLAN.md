# Continuation plan

## Current state

The repository currently provides a working shared PHPUnit harness that:

- is installed as `.test-tools` inside a complete WordPress file tree;
- runs through `composer test` from the WordPress root;
- uses DDEV without starting or rebuilding it from the test command;
- keeps the working `db` database separate from the disposable `wp_tests` database;
- detects the installed WordPress version and synchronizes a matching clean core and WordPress PHPUnit library;
- reads the active plugin combination from the working local site;
- loads ordinary plugins through WordPress's normal bootstrap;
- makes must-use plugins available;
- runs registered plugin activation hooks against `wp_tests`;
- blocks unmocked WordPress HTTP API requests; and
- contains harness-level tests for the environment, active plugins, and HTTP isolation.

The next work should finish the PHPUnit surface before adding Playwright.

---

## Phase 1 — Finish the PHPUnit surface

### 1. Harden environment and database safety

Implement a single preflight used by every PHPUnit entry point.

It must verify:

- DDEV is already running;
- execution is inside the expected DDEV project;
- the WordPress root contains `wp-admin`, `wp-content`, and `wp-includes`;
- the working database is `db`;
- the PHPUnit database is exactly `wp_tests`;
- the database host is DDEV's internal `db` service;
- the test table prefix is `wptests_`;
- the test database is not the working database;
- required tools and extensions are available; and
- generated directories are writable.

Add `composer doctor` for a read-only diagnostic report. It must not start or reconfigure DDEV.

Acceptance criteria:

- a wrong database name or host fails before WordPress tables are changed;
- the error identifies the exact failed check;
- `composer doctor` exits nonzero when the suite cannot run safely.

### 2. Replace ad hoc activation with a complete lifecycle bootstrap

Review the current direct `activate_{$plugin}` action calls and align behavior with WordPress activation semantics without touching the working site.

Cover:

- ordinary activation;
- activation errors;
- network activation when multisite support is added;
- plugins that create options, custom tables, roles, cron events, rewrite rules, or files;
- repeated suite runs and activation idempotency;
- plugin load order; and
- activation callbacks that expect admin bootstrap files or current-user capabilities.

Add harness tests using small fixture plugins rather than relying only on whatever happens to be active on one developer's site.

Acceptance criteria:

- a fixture plugin can create an option and custom table during activation;
- those objects exist in `wp_tests` only;
- repeated runs do not fail or leak duplicate state;
- activation failures name the responsible plugin.

### 3. Define plugin and theme test-discovery conventions

Add a stable, documented convention so the toolkit can run tests supplied by active plugins and themes without editing `.test-tools/phpunit.xml.dist`.

Proposed default conventions:

```text
wp-content/plugins/<slug>/tests/phpunit/**/*Test.php
wp-content/themes/<slug>/tests/phpunit/**/*Test.php
```

Optional per-extension bootstrap files:

```text
wp-content/plugins/<slug>/tests/phpunit/bootstrap.php
wp-content/themes/<slug>/tests/phpunit/bootstrap.php
```

Before implementation, confirm whether one site-level configuration file is needed for inclusion/exclusion and profiles. Prefer one explicit file at the WordPress root over several environment variables.

Discovery rules must be deterministic:

- active plugins are included by default;
- the active theme and parent theme are included by default when they provide tests;
- inactive extensions are excluded unless explicitly selected;
- missing test directories are normal;
- a malformed bootstrap fails with the extension slug and path; and
- toolkit harness tests remain a separate PHPUnit testsuite.

Acceptance criteria:

- adding a conventional test file to an active fixture plugin makes it run automatically;
- deactivating that plugin removes its tests from the default run;
- theme tests follow the same documented rule;
- the README and setup guide list every consumed extension path.

### 4. Add test profiles and focused commands

Keep `composer test` as the default integration run, then add focused entry points without creating unnecessary wrappers.

Candidate commands:

```text
composer test
composer test:plugin -- <slug>
composer test:theme -- <slug>
composer test:harness
composer test:multisite
```

Where practical, continue supporting native PHPUnit arguments:

```bash
composer test -- --filter SomeTest
composer test -- --group rest
```

Profiles must select extension code and extension tests together. Do not load every installed plugin merely because it exists on disk.

Acceptance criteria:

- focused runs are faster and clearly report the selected profile;
- invalid slugs fail before PHPUnit starts;
- all public commands are documented.

### 5. Provide reusable WordPress integration-test helpers

Add a small toolkit namespace for common setup and assertions without becoming a framework.

Priorities:

- option and settings cleanup;
- custom-table existence and schema assertions;
- cron-event assertions;
- REST request helpers and authentication setup;
- admin-user and capability fixtures;
- uploads and temporary-file cleanup;
- mail assertions using WordPress hooks or Mailpit where appropriate;
- plugin activation/deactivation helpers; and
- safe uninstall testing in isolated state.

Helpers must use WordPress APIs where possible and keep plugin-specific business logic in plugin tests.

Acceptance criteria:

- fixture tests demonstrate options, a custom table, REST authorization, cron registration, and cleanup;
- helper state is removed between tests;
- helper APIs are documented before being treated as stable entry points.

### 6. Finish external-service isolation and mocks

The current WordPress HTTP API block is the baseline. Expand this into clear testing surfaces for:

- successful and failed HTTP responses;
- timeouts and connection errors;
- malformed JSON;
- rate limits;
- webhook payloads and signatures;
- CAPTCHA verification;
- payment gateways;
- object storage; and
- update servers.

Do not build provider-specific clients into the toolkit. Supply generic response fixtures and helper patterns. Plugins should keep provider-specific adapters behind their own boundaries.

Add a separately named opt-in integration mode only when a real sandbox test is required. It must never run as part of `composer test`.

Acceptance criteria:

- unmocked network access still fails;
- mock helpers can return arrays, `WP_Error`, delayed responses, and sequential responses;
- real-service tests require an explicit command and local credentials outside the repository.

### 7. Add lifecycle and data-safety suites

Create standard groups or base cases for:

- activation on a clean database;
- activation over existing options/data;
- schema upgrades from fixtures;
- deactivation without data loss;
- uninstall cleanup;
- preservation of records owned by other plugins;
- multisite site/network behavior; and
- repeated install/upgrade runs.

Uninstall tests must use a deliberately disposable database state and must not be mixed into ordinary tests without clear isolation.

Acceptance criteria:

- a plugin can test upgrade fixtures without changing the working database;
- uninstall tests prove both intended deletion and intended preservation;
- destructive groups are clearly named and documented.

### 8. Support WordPress, PHP, and PHPUnit compatibility cleanly

The current Composer dependencies pin PHPUnit 9.6. Finish a compatibility policy that accounts for:

- the installed WordPress version;
- the selected DDEV PHP version;
- WordPress PHPUnit compatibility;
- PHPUnit Polyfills; and
- future PHPUnit major versions.

Prefer one maintained dependency strategy over dynamically rewriting Composer requirements on every run.

Add an optional compatibility workflow only after the normal current-version run is stable. Candidate combinations:

- minimum supported WordPress and PHP;
- latest WordPress with minimum PHP; and
- latest WordPress with latest supported PHP.

This must remain lightweight and explicit; it must not create several always-running environments.

Acceptance criteria:

- unsupported combinations fail with a useful compatibility message;
- a WordPress update does not silently leave an incompatible PHPUnit stack;
- compatibility commands are opt-in and documented.

### 9. Add PHPUnit reporting and developer ergonomics

Add only useful outputs:

- optional coverage when Xdebug or PCOV is enabled;
- deprecation and warning visibility;
- concise failure context;
- deterministic random order when requested;
- JUnit output for future CI; and
- a command to rerun the last failed subset if it can be implemented without brittle state.

Do not enable expensive coverage in the default `composer test` path.

### 10. Finish PHPUnit documentation and self-tests

For every command, configuration file, plugin/theme path, helper, fixture convention, and generated file added above:

- update `README.md` and `SETUP.md`;
- update `AGENTS.md` when the maintenance contract changes;
- add harness-level tests; and
- add ignore entries for generated output.

Phase 1 is complete when a plugin with settings, custom tables, core-table data, cron, REST routes, uploads, and mocked external calls can add conventional tests and run them through `composer test` without editing the toolkit.

---

## Phase 2 — Add standard logging commands

### 1. Add `composer tail:log`

Create a host wrapper that follows the local WordPress debug log inside DDEV:

```text
composer tail:log
```

Requirements:

- DDEV must already be running;
- ensure `wp-content/debug.log` exists without changing remote configuration;
- use `tail -F` so log rotation or recreation is handled;
- propagate Ctrl+C cleanly;
- do not start, restart, or rebuild containers; and
- fail clearly when local logging is not configured.

### 2. Add narrowly useful companion commands

Consider:

```text
composer clear:log
composer logs:web
composer logs:db
```

Only add commands that are materially easier than the underlying DDEV command and do not create a broad command-wrapper layer.

Acceptance criteria:

- `composer tail:log` immediately follows `wp-content/debug.log`;
- stopping it does not stop DDEV;
- the command and required `wp-config.php` settings are documented.

---

## Phase 3 — Add a Playwright E2E surface

### 1. Add the Node.js and Playwright package surface

Add repository-managed Node dependencies and scripts inside `.test-tools`, while continuing to expose Composer commands from the WordPress root.

Candidate public commands:

```text
composer test:e2e
composer test:e2e -- --grep "settings"
composer test:all
```

Use Chromium as the initial default. Additional browsers remain opt-in.

### 2. Preserve the working site during E2E tests

E2E tests will exercise the real local DDEV URL, but they must not leave the developer's working database altered.

Implement one reliable isolation model:

- create a temporary DDEV database snapshot at suite start;
- create dedicated test users and fixtures;
- run Playwright;
- restore the snapshot in a trap even after failures; and
- remove temporary files not covered by the database restore.

Evaluate filesystem effects separately because database snapshots do not restore uploads or plugin-created files.

Acceptance criteria:

- the local dashboard state is identical before and after the suite;
- failed or interrupted suites still restore state;
- the command never touches a remote site.

### 3. Add authentication and fixtures

Provide:

- a dedicated administrator created through WP-CLI;
- reusable authenticated browser storage state;
- helpers for users with lower capabilities;
- deterministic posts, terms, media, and plugin settings; and
- cleanup for uploaded files and generated content.

Do not depend on a developer's personal admin account or password.

### 4. Define plugin and theme E2E discovery

Proposed conventions:

```text
wp-content/plugins/<slug>/tests/e2e/**/*.spec.ts
wp-content/themes/<slug>/tests/e2e/**/*.spec.ts
```

Add optional extension-local fixture/bootstrap entry points only when required. Document every path consumed by the runner.

Default discovery should follow the same active plugin/theme selection policy as PHPUnit.

### 5. Add external-service E2E isolation

Provide local test-mode boundaries for CAPTCHA, payments, object storage, email, webhooks, and update checks.

- CAPTCHA challenges are bypassed through a test verifier, not browser automation of the challenge.
- Payment and storage operations use fakes by default.
- Mail uses Mailpit.
- Real sandbox integrations use separate explicit commands and local credentials.

### 6. Add failure artifacts

On failure, retain:

- Playwright trace;
- screenshot;
- browser console output;
- failed network-request summary;
- relevant WordPress debug-log excerpt; and
- the test title and active extension profile.

Keep these outputs ignored by Git.

### 7. Add initial E2E coverage

Start with harness tests that prove:

- the site opens;
- a dedicated admin can log in;
- active plugins are visible;
- a settings form can be saved;
- a failed mocked service call produces the expected UI; and
- state is restored afterward.

Phase 3 is complete when plugins and themes can add conventional Playwright tests and run them safely through `composer test:e2e`.

---

## Phase 4 — Remaining developer utilities

### 1. Remote database refresh helper

Consider an optional `composer db:pull` command after the local test surfaces are stable.

It must use a local, ignored configuration file for SSH alias, remote path, remote URL, and local URL; stream a compressed dump; use a large packet limit; verify the archive; snapshot the current local database; import; perform serialized URL replacement; and never contain credentials in the repository.

The command must require explicit confirmation because it replaces the working local database.

### 2. Snapshot and reset helpers

Consider explicit commands for:

```text
composer snapshot
composer restore -- <name>
composer reset:tests
```

Do not hide destructive behavior behind a generic `test` command.

### 3. Optional local services

Add Redis, MinIO/S3-compatible storage, or other service containers only as opt-in DDEV add-ons when a plugin needs protocol-level integration tests. Do not make them baseline dependencies.

### 4. Mail ergonomics

Document Mailpit and consider a concise command that opens or reports its URL. Keep ordinary unit/integration mail assertions independent of the graphical inbox where possible.

### 5. CI after local stability

Only after PHPUnit and Playwright commands are stable, add a minimal CI workflow that invokes the same public commands or their container-safe equivalents. CI must not become a separate test architecture.

### 6. Final production-like smoke testing

Keep a remote development/staging installation for the narrow checks local containers cannot reproduce reliably:

- exact hosting restrictions;
- packaging and deployment artifacts;
- filesystem ownership;
- disabled PHP functions;
- host cron behavior;
- outbound firewall rules; and
- real vendor sandbox connectivity.

This remains a final smoke-test layer, not the primary development workflow.

---

## Recommended implementation order

1. Add safety preflight and `composer doctor`.
2. Harden activation/lifecycle behavior with fixture plugins.
3. Add plugin/theme PHPUnit discovery.
4. Add focused PHPUnit profiles and commands.
5. Add reusable integration helpers and lifecycle suites.
6. Complete compatibility handling and PHPUnit reporting.
7. Add `composer tail:log`.
8. Add Playwright with database/filesystem restoration.
9. Add plugin/theme E2E discovery and failure artifacts.
10. Add optional database-refresh and service helpers only where they remain lightweight.
11. Add CI last, reusing the established command surface.
