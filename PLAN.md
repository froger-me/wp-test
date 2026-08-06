# Continuation plan

## Current state

Phases 1 through 3 are complete. The remote database refresh, local snapshot, restore, and test-database reset commands from Phase 4 are also complete.

Anyape WP Test Tools currently provides:

- read-only `composer doctor` checks;
- local WordPress log viewing and clearing;
- separate PHP and browser tests, with `composer test` running both;
- active plugin and theme test discovery;
- focused plugin, theme, multisite, destructive, coverage, and report commands;
- a separate `anyape_wp_test_tools` database and isolated test files;
- automatic restoration of the working database and protected files after browser tests;
- confirmed remote database refresh with an automatic local snapshot; and
- explicit local snapshot, restore, and test-database reset commands.

Current public commands:

```text
composer doctor
composer lint:wpcs
composer format:wpcs
composer tail:log
composer clear:log
composer db:pull
composer snapshot
composer restore -- <name>
composer reset:tests
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

## Completed phases

### Phase 1 — WordPress PHP tests

Anyape WP Test Tools checks the local environment, uses only `anyape_wp_test_tools`, loads the working site's active plugins and themes, finds ordinary plugin and theme test files, and provides focused and specialist test commands.

### Phase 2 — WordPress logging commands

`composer tail:log` and `composer clear:log` validate and use the local `wp-content/debug.log` file. They do not edit `wp-config.php` or manage DDEV.

### Phase 3 — Browser tests

The browser tests use Chromium against the local DDEV site. Every run saves the working database and protected file paths, creates temporary users and data, runs Anyape WP Test Tools and extension tests, then restores and compares the original data and files.

### Phase 4 — Database utilities completed so far

`composer db:pull` uses an ignored local file for the SSH alias, remote path, remote URL, and local URL. It confirms the source and destination, downloads and verifies a compressed export, creates a local snapshot, imports only into `db`, replaces the old URL safely throughout WordPress data, and attempts restoration if importing fails.

`composer snapshot` creates a named or dated DDEV database snapshot. `composer restore` requires an exact name and confirmation. `composer reset:tests` requires confirmation and can recreate only `anyape_wp_test_tools`.

## Phase 5 — Guided project setup (complete)

Implemented and verified against the consuming WordPress project.

### Goal

The long manual sequence from the former Step 3 onward is replaced by one guided setup command. A new installation has only these opening steps:

1. install Docker, DDEV, Composer, Node.js, and Git;
2. run `ddev config` in the existing WordPress directory; and
3. clone Anyape WP Test Tools as `.anyape-wp-test-tools`.

The next command is:

```text
bash .anyape-wp-test-tools/setup-host.sh
```

The direct shell command works before Anyape WP Test Tools packages or a root `composer.json` exist. Setup adds `composer setup` to both Composer files so later runs work from either the WordPress root or `.anyape-wp-test-tools`.

The command reduces repetition without pretending every WordPress installation is arranged the same way. It explains proposed changes, asks before important changes, and gives clear manual instructions when it cannot make a safe decision.

### Work performed by the setup command

The command:

1. confirm that it is running from a complete WordPress directory containing `wp-admin`, `wp-content`, `wp-includes`, and `wp-config.php`;
2. check the required host programs and the existing `.ddev/config.yaml` and `wp-config-ddev.php` files;
3. inspect `wp-config.php`, prepare the local DDEV changes, show them, and ask before writing;
4. save the original file, check PHP syntax after editing, and immediately restore the original when the edited file is invalid;
5. add local-only paths to a suitable root `.gitignore` while preserving all existing entries;
6. point out deployment files such as `.vscode/sftp.json`, but change them only when their structure is understood and the user approves the exact edit;
7. add Subversion to the DDEV web image when it is missing, explain that this rebuilds the image, and ask before doing it;
8. start DDEV as an explicit setup action, never as a hidden action inside ordinary test commands;
9. ask how to prepare the working database: keep the existing database, make a clean WordPress installation, or use `composer db:pull`;
10. create or repair only the fixed `anyape_wp_test_tools` database and its permission for the DDEV database user;
11. install Anyape WP Test Tools' PHP packages inside DDEV and its Node.js packages and Chromium browser on the host;
12. merge Anyape WP Test Tools commands into the root `composer.json` without replacing unrelated packages, settings, or commands;
13. offer to create `.anyape-wp-test-tools.php` and `db-refresh-local.php` from their examples, without inventing project-specific values;
14. run `composer doctor` and `composer test:harness`; and
15. print a short report of completed work, skipped work, and anything the user still needs to edit.

Offer `composer test` at the end rather than forcing it. That command includes all active plugins, themes, and browser tests and can take much longer.

### Safe handling of `wp-config.php`

This file differs most between projects, so it needs strict rules.

The setup command recognizes common WordPress configurations and prepares only the changes needed for local DDEV use:

- determine whether WordPress is running inside DDEV;
- use existing remote database settings only outside DDEV;
- avoid redefining values supplied by `wp-config-ddev.php`;
- enable the standard local WordPress debug log without displaying errors in browser pages;
- use the local debug-log path only inside DDEV while preserving the remote server's PHP error-log path;
- define `ABSPATH` before loading the generated DDEV settings; and
- load `wp-settings.php` exactly once.

Do not use blind text replacement. Refuse to edit when database definitions, `ABSPATH`, the DDEV include, or the `wp-settings.php` include appear more than once or are arranged in a way the command does not understand. In that case, print the exact block the user needs, identify the uncertain lines, and leave the file untouched.

Before an approved edit, save a dated backup that deployment excludes. Show a readable comparison. After editing, check PHP syntax and confirm that the local branch loads `wp-config-ddev.php`. Restore the backup automatically if either check fails.

Never ask for, move, print, or save remote database passwords. Existing remote values remain wherever the project already keeps them.

### Choices that remain manual

The rewritten guide must retain instructions for anything the command cannot safely know:

- the remote database name, user, password, host, and where production receives those values;
- the remote server's PHP error-log path;
- whether `.ddev/` is committed and whether `.anyape-wp-test-tools` is a normal nested clone, a Git submodule, or excluded from the parent repository;
- the deployment exclusion format used by SFTP, rsync, a hosting control panel, or another deployment method;
- the project name, desired PHP and database versions, local site title, administrator details, and working database source;
- the SSH alias, remote WordPress path, remote URL, and local URL used by `composer db:pull`;
- plugin- or theme-specific include, exclude, dependency, setup-file, and protected-file choices in `.anyape-wp-test-tools.php`;
- optional local services such as Redis or compatible object storage; and
- checks that only the real hosting environment can prove.

The command detects these situations and points to the relevant guide section. It does not guess values or silently choose a policy.

### Running setup again

The command must be safe to run more than once.

- A second run with no project changes reports that each item is complete and does not rewrite files or rebuild DDEV.
- Matching Composer commands and ignore entries are not duplicated.
- Existing Anyape WP Test Tools packages are checked before installation runs again.
- An existing `anyape_wp_test_tools` database remains unless it is missing or the user explicitly chooses to recreate it.
- Backups are retained and named clearly.
- `--check` inspects and reports without changing anything.
- `--yes` accepts only changes whose targets and values are already clear. It does not answer questions about the database source, remote values, deployment policy, or an unfamiliar `wp-config.php`.

### Implementation files and responsibilities

The implementation uses these files:

```text
.anyape-wp-test-tools/setup-host.sh
.anyape-wp-test-tools/bin/inspect-setup.php
.anyape-wp-test-tools/bin/update-wp-config.php
.anyape-wp-test-tools/bin/update-root-composer.php
.anyape-wp-test-tools/bin/update-ignore-files.php
.anyape-wp-test-tools/fixtures/setup/
.anyape-wp-test-tools/tests/SetupFilesTest.php
```

- `setup-host.sh` owns the questions, confirmations, host-program checks, DDEV commands, package installation, database choices, and final report. It does not contain file-editing rules.
- `inspect-setup.php` reads the WordPress directory, `wp-config.php`, DDEV files, root Composer file, ignore files, and optional local configuration. It returns one machine-readable report and never writes.
- `update-wp-config.php` accepts the inspection report, creates the dated backup, writes through a temporary file, checks PHP syntax, replaces the original only after validation, and restores the backup after a failed post-write check.
- `update-root-composer.php` merges the complete public command list and required Composer settings while preserving unrelated content and refusing conflicting command names.
- `update-ignore-files.php` adds only approved local and backup paths, avoids duplicates, and refuses deployment files whose structure it does not recognize.
- `fixtures/setup/` contains the example WordPress configurations, Composer files, Git arrangements, and deployment files used by the automated checks.
- `tests/SetupFilesTest.php` exercises inspection and every file update against those examples without starting DDEV.

Inspection and file validation run without DDEV. Every file writer receives explicit source and destination paths, creates a backup, and either finishes successfully or leaves the original file unchanged.

### Automated checks

The included example WordPress directories cover:

- a normal single-file `wp-config.php`;
- a configuration that already supports DDEV;
- remote database values inside an existing condition;
- a custom remote PHP error-log path;
- duplicate or unusual includes that must be refused;
- an empty root `composer.json`;
- an existing root Composer project with unrelated packages and commands;
- a normal nested Anyape WP Test Tools clone and a declared Git submodule; and
- repeated setup runs that produce no second change.

Automated checks prove:

- no remote value appears in generated reports;
- invalid PHP causes immediate restoration of the original file;
- unfamiliar `wp-config.php` layouts are reported and not changed;
- root Composer settings are merged rather than replaced;
- deployment and Git exclusions are not duplicated;
- only `anyape_wp_test_tools` can be created or repaired by the test-database step;
- no working database is imported, replaced, or reset without a clear choice and confirmation; and
- the file writers pass against private temporary example directories, while the consuming DDEV project passes `composer doctor` and the complete PHP and browser test command.

### Complete rewrite of `SETUP.md`

The old 18-step guide was replaced instead of receiving another layer of instructions. The new guide follows the order a person actually uses:

1. explain what the setup command will and will not do;
2. install the host programs;
3. run the initial `ddev config` command;
4. install `.anyape-wp-test-tools`;
5. run `bash .anyape-wp-test-tools/setup-host.sh`;
6. choose how to prepare the working database;
7. complete project-specific configuration deliberately left for the user;
8. review the setup report and run all tests;
9. add ordinary plugin and theme tests;
10. use daily commands for tests, logs, database refresh, snapshots, and reset;
11. update WordPress and Anyape WP Test Tools; and
12. solve common setup problems.

Keep manual `wp-config.php`, deployment exclusion, database creation, package installation, and root Composer examples in clearly labelled fallback sections. They remain necessary for unusual projects and for understanding what the command changed, but they do not interrupt the normal setup path.

The rewrite removes the old numbering and repeated command lists. Each command has one main explanation, with short sentences and placeholder values.

### Completion checks

The completed phase satisfies these checks:

- a standard existing WordPress directory can go from initial `ddev config` and Anyape WP Test Tools clone to passing `composer doctor` through the guided command;
- the user sees and approves every important lasting change;
- a custom or unclear `wp-config.php` is preserved and receives useful manual instructions;
- running setup twice produces no unwanted changes;
- no secret or remote server value is added to tracked files, reports, or command output;
- the root and `.anyape-wp-test-tools` Composer commands remain aligned;
- the rewritten `SETUP.md` matches the command's real behavior; and
- the existing PHP and browser tests still pass with `composer test`.

## Later work

Add Redis, compatible object storage, or other local services only when a plugin needs them. Keep them optional. Improve Mailpit convenience only if it adds something beyond the existing DDEV commands. Do not add GitHub automation. Keep final checks against a remote development or staging installation for hosting restrictions that containers cannot reproduce.

## Recommended next order

1. Exercise guided setup on the next new WordPress project and record any unfamiliar `wp-config.php` arrangement it correctly refuses.
2. Add support for another configuration arrangement only with a matching example and refusal test.
3. Add optional local services only when a real plugin requires them.
