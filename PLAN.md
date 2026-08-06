# Continuation plan

## Current state

Phases 1 through 3 are implemented.

The toolkit now provides:

- read-only `composer doctor`;
- standard local logging commands (`composer tail:log` and `composer clear:log`);
- a shared preflight for every PHPUnit entry point;
- fixed database safety requirements (`db`, `wp_tests`, `db`, `wptests_`);
- WordPress/PHP/PHPUnit compatibility checks;
- active plugin, active theme, and parent-theme selection;
- optional root `.wp-test.php` configuration;
- focused plugin and theme profiles;
- a multisite profile;
- an isolated runtime `wp-content`;
- normal WordPress plugin activation semantics;
- fixture-backed lifecycle and discovery self-tests;
- plugin and theme PHPUnit discovery;
- reusable integration helpers;
- deterministic external HTTP mocks and a default network block;
- opt-in destructive tests;
- opt-in coverage and JUnit output; and
- synchronized documentation and generated-file ignores.

Public commands:

```text
composer doctor
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

Before beginning another phase, the implemented surfaces should be exercised in the consuming WordPress installation with its actual active extension set. Any defects found there remain stabilization work for the relevant phase.

---

## Phase 2 — Standard logging commands (complete)

Implemented:

- `composer tail:log` validates the local DDEV WordPress logging configuration, creates `wp-content/debug.log` when possible, and follows it with `tail -F`;
- `composer clear:log` performs the same checks before truncating the local file without deleting it;
- both commands work from the WordPress root and `.test-tools`;
- both commands require DDEV to already be running and never manage its lifecycle;
- disabled logging, custom log destinations, and unwritable paths fail with actionable messages; and
- required `wp-config.php` settings and troubleshooting are documented.

`composer logs:web` and `composer logs:db` were not added. The direct DDEV commands already provide the intended behavior without a toolkit-specific safety or convenience improvement:

```text
ddev logs -f
ddev logs -s db -f
```

---

## Phase 3 — Playwright browser tests (complete)

Implemented:

- repository-managed Node, TypeScript, Playwright, and Chromium setup;
- an aggregate `composer test` command, plus direct `composer test:php` and `composer test:e2e` commands, at both supported command locations;
- refusal to open a WordPress host name that differs from the running DDEV project's local host name;
- temporary DDEV database snapshots plus saved upload, must-use plugin, and configured file paths;
- database and file restoration with comparison after success, failure, and interruption;
- dedicated administrator and editor accounts with reusable signed-in browser state;
- repeatable post, term, media, option, and custom-table records;
- active, included, excluded, and focused plugin and theme browser-test selection;
- conventional extension test and PHP setup-file discovery below `tests/e2e`;
- a site-wide browser setup file and extra protected paths through `.wp-test.php`;
- a temporary `WP_TEST_E2E` must-use plugin, local WordPress HTTP boundary, and documented plugin-controlled service replacement rules;
- traces, screenshots, browser messages, failed requests, test titles, selected profiles, and WordPress debug-log excerpts for failures; and
- five Chromium checks covering the local site, dedicated login, selected plugins, settings persistence, and a fake service failure.

Phase 3 is complete. Browser tests run against the local working site and restore its database and protected files before returning.

---

## Phase 4 — Remaining developer utilities

### 1. Remote database refresh helper

Consider `composer db:pull` only after logging and E2E are stable.

Requirements:

- ignored local configuration for SSH alias, remote path, remote URL, and local URL;
- compressed streaming export;
- large packet limit;
- archive verification;
- automatic local snapshot before replacement;
- serialized URL replacement;
- explicit confirmation;
- no credentials in the repository.

### 2. Snapshot and reset helpers

Evaluate:

```text
composer snapshot
composer restore -- <name>
composer reset:tests
```

Do not hide destructive behavior behind generic commands.

### 3. Optional local services

Add Redis, MinIO/S3-compatible storage, or other services only as opt-in DDEV add-ons for plugins that need protocol-level tests. Do not make them baseline dependencies.

### 4. Mail ergonomics

Document Mailpit and consider a concise command that reports or opens its URL. Keep ordinary test assertions independent of the graphical inbox.

### 5. Local-only automation policy

Do not add GitHub CI or GitHub Actions files. Agents are explicitly prohibited from creating them by `AGENTS.md`.

Any future non-GitHub automation requires a separate explicit user request and must reuse the same public local commands rather than create a second test architecture.

### 6. Final production-like smoke testing

Keep a remote development/staging installation for checks containers cannot reproduce reliably:

- exact hosting restrictions;
- packaging and deployment artifacts;
- filesystem ownership;
- disabled PHP functions;
- host cron behavior;
- outbound firewall rules;
- real vendor sandbox connectivity.

This remains a final smoke-test layer, not the primary workflow.

---

## Recommended next order

1. Stabilize Phases 1 through 3 against the consuming site's real active extensions and local logging configuration.
2. Add optional database-refresh and local-service helpers only when justified.
