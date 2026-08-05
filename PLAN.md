# Continuation plan

## Current state

Phase 1, the shared PHPUnit surface, is implemented.

The toolkit now provides:

- read-only `composer doctor`;
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
composer test
composer test:harness
composer test:plugin -- <slug>
composer test:theme -- <slug>
composer test:multisite
composer test:destructive
composer test:coverage
composer test:junit
```

Before beginning another phase, Phase 1 should be exercised in the consuming WordPress installation with its actual active extension set. Any defects found there remain Phase 1 stabilization work.

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
- do not start, restart, or rebuild containers;
- fail clearly when local logging is not configured; and
- document required `wp-config.php` settings.

Acceptance criteria:

- the command immediately follows `wp-content/debug.log`;
- stopping it does not stop DDEV;
- a missing or unwritable log produces an actionable error;
- no remote path or credential is embedded.

### 2. Add only useful companion commands

Evaluate:

```text
composer clear:log
composer logs:web
composer logs:db
```

Add a command only when it materially improves on the direct DDEV command. Do not build a broad wrapper layer around DDEV.

---

## Phase 3 — Add a Playwright E2E surface

### 1. Add repository-managed Node and Playwright dependencies

Keep Node dependencies inside `.test-tools` and expose Composer commands from the WordPress root.

Candidate commands:

```text
composer test:e2e
composer test:e2e -- --grep "settings"
composer test:all
```

Use Chromium first. Other browsers remain opt-in.

### 2. Preserve the working site

Playwright will exercise the real local DDEV URL, but must not leave working state altered.

Implement:

1. verify DDEV is already running;
2. create a temporary DDEV database snapshot;
3. record relevant filesystem state;
4. create dedicated E2E users and fixtures;
5. run Playwright;
6. restore the database in a trap after success, failure, or interruption;
7. remove generated uploads and files not restored by the database snapshot; and
8. verify restoration.

Acceptance criteria:

- dashboard data is identical before and after;
- failed and interrupted runs restore state;
- the command never touches a remote site;
- DDEV lifecycle remains explicit.

### 3. Add authentication and deterministic fixtures

Provide:

- dedicated administrator and lower-capability users;
- reusable authenticated storage state;
- deterministic posts, terms, media, options, and custom-table fixtures;
- plugin-controlled service fakes; and
- cleanup for uploads and generated files.

Do not depend on a developer's personal account or password.

### 4. Define extension E2E discovery

Proposed paths:

```text
wp-content/plugins/<slug>/tests/e2e/**/*.spec.ts
wp-content/themes/<slug>/tests/e2e/**/*.spec.ts
```

Default selection should follow the PHPUnit profile and `.wp-test.php` rules where practical.

Document every consumed path and optional bootstrap/fixture convention before treating it as stable.

### 5. Isolate external services

Provide test-mode boundaries for:

- CAPTCHA;
- payments;
- object storage;
- email;
- webhooks;
- update checks.

CAPTCHA is bypassed through a test verifier, not solved through browser automation. Payment and storage are fake by default. Mail uses Mailpit. Real sandbox integrations require separate explicit commands and ignored credentials.

### 6. Capture useful failure artifacts

On failure retain:

- Playwright trace;
- screenshot;
- browser console output;
- failed network-request summary;
- relevant WordPress debug-log excerpt;
- test title;
- active extension profile.

Keep all artifacts ignored.

### 7. Initial E2E coverage

Harness tests should prove:

- the site opens;
- a dedicated administrator can log in;
- selected plugins are visible;
- a settings form can be saved;
- a mocked service failure produces expected UI;
- state is restored afterward.

Phase 3 is complete when active plugins and themes can add conventional Playwright tests and run them safely through `composer test:e2e`.

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

1. Stabilize Phase 1 against the consuming site's real active extensions.
2. Add `composer tail:log`.
3. Add Playwright with database and filesystem restoration.
4. Add plugin/theme E2E discovery and failure artifacts.
5. Add optional database-refresh and local-service helpers only when justified.
