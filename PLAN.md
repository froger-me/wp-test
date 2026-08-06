# Optimization plan

## Goal

Reduce maintenance work, repeated code, unnecessary process starts, and unused internal data without changing any public command, safety rule, database target, confirmation requirement, or test selection rule.

This is a cleanup of a mature project, not a rewrite. The implementation must make the existing behavior easier to maintain while keeping the separate safety checks around the working database, the PHPUnit database, browser-test restoration, remote database copying, setup, and uninstall.

## Limits

- Keep every public Composer command and its current arguments, output meaning, exit codes, and side effects.
- Keep commands available from both the WordPress root and `.anyape-wp-test-tools`.
- Keep `db` as the working database and `anyape_wp_test_tools` with prefix `anyape_wptt_` as the PHPUnit database.
- Keep external requests blocked during ordinary tests.
- Do not add services, packages, frameworks, compatibility layers, or GitHub workflow files.
- Do not combine setup, database copying, browser restoration, and uninstall into one large command. They have different risks and must remain visibly separate.
- Add at most one new internal source file. Rename an existing shared shell file instead of adding another shell helper.
- The total non-test line count across the changed source files should decrease.

## Audit findings

### 1. Guided setup reads the same state in several different ways

Files:

- `setup-host.sh`
- `bin/inspect-setup.php`

`setup-host.sh` creates a JSON inspection report, but then repeatedly starts new PHP processes to read individual values from that report. It also reads `.ddev/config.yaml` again through separate inline PHP snippets to determine the project name and whether the required DDEV settings are present.

This creates two definitions of the same DDEV rules and makes a change to those rules likely to require edits in several places. It also starts many short-lived PHP processes during one setup run.

The inspection report currently returns fields that have no consumer:

- `project_root`;
- `ddev_config_exists`;
- `ddev_wordpress_exists`;
- `root_composer_valid`;
- `project_test_config`; and
- `db_refresh_config`.

The `$composer_ok` variable and the Composer JSON check in `bin/inspect-setup.php` exist only to produce the unused `root_composer_valid` field.

### 2. Host commands repeat the same opening work

Files:

- `logging-host.sh`
- `doctor-host.sh`
- `database-host.sh`
- `run-tests-host.sh`
- `run-e2e-host.sh`
- `run-all-host.sh`
- `setup-host.sh`

These scripts repeatedly resolve the toolkit and WordPress paths, check required host programs, check whether DDEV is running, parse verbose output options, print the detailed-log explanation, and parse focused plugin or theme targets.

`log-host.sh` and `logging-host.sh` are not duplicates: the first implements the WordPress debug-log command, while the second contains shared detailed-output functions. Their similar names make that distinction unnecessarily difficult to see.

### 3. Safe file replacement is implemented several times

Files:

- `bin/update-wp-config.php`
- `bin/update-root-composer.php`
- `bin/update-ignore-files.php`
- `bin/uninstall-project.php`

These files independently implement parts of the same work:

- read and validate JSON;
- choose an unused dated backup name;
- create a temporary file beside the destination;
- preserve file permissions;
- write and rename the temporary file;
- validate generated PHP; and
- remove temporary files after failure.

The rules around those operations are not identical. In particular, `wp-config.php` must be restored after a failed final check, while the SFTP settings backup must remain private inside the toolkit runtime directory. The shared code must cover only the repeated file mechanics; each command must continue to decide its own backup location, validation, restoration, and error message.

### 4. Recursive file handling is repeated

Files:

- `bin/prepare-runtime.php`
- `bin/e2e-filesystem.php`
- `tests/SetupFilesTest.php`

The runtime builder and browser-test restoration code each contain their own directory walking and removal code. Browser tests additionally implement exact copying, top-level directory preservation, symbolic-link handling, and path digests.

The production code should use one implementation for removing a tree without following symbolic links, clearing a directory while keeping the directory itself, exact copying, and repeatable path digests. The browser command must keep its current allowed-directory checks, and the runtime builder must remain restricted to its generated runtime directory.

The test suite may keep an independent small cleanup function for its private temporary directory. Tests should not rely on the same removal function they are meant to verify.

### 5. Proven dead internal code remains

Files:

- `src/Manifest.php`
- `bin/inspect-setup.php`
- `bin/uninstall-project.php`

`Manifest::to_array()` has no caller. The unused setup-report fields listed above have no caller. `gitignore_present` and `sftp_present` are returned by `anyape_wp_test_tools_uninstall_project_files()` but are not read by the command or tests.

These are internal values rather than documented extension interfaces and should be removed.

Parameters required by WordPress hooks, such as the existing mail result or parsed HTTP arguments, are not dead merely because the current function body does not read them. Keep required callback signatures intact.

### 6. Manifest duplicate handling scans work that was already done

File:

- `src/ManifestBuilder.php`

`deduplicate_extensions()` records that an extension has already been seen, but then scans the complete result list again to find that extension when test discovery must be enabled. The result position can be recorded when the extension is first added and updated directly later.

This is a small runtime cost with normal plugin counts, but the current implementation is longer and easier to break than a direct lookup.

### 7. Some tests depend on internal variable names and exact script wording

File:

- `tests/SetupFilesTest.php`

Several tests read shell scripts as text and assert internal variable names, exact explanatory sentences, or exact command strings. Examples include the setup DDEV configuration test, the Subversion setup test, the database-pull receipt test, and parts of the uninstall-order test.

These tests create avoidable maintenance work: harmless renaming or clearer wording can fail the suite even when behavior is unchanged. Safety ordering still needs proof, but most checks should run code against temporary project files or fake host commands and assert results, exit status, and recorded command order.

### 8. WordPress test synchronization starts PHP repeatedly for fixed values

File:

- `sync-wordpress-tests.sh`

The script starts PHP once for the WordPress version and three more times for values from `config.php`. One PHP invocation can return all four values in a safe machine-readable form. This is not a major runtime cost compared with downloading WordPress, but it is unnecessary and repeats parsing code.

### 9. Large command files should be shortened without being split into many new commands

Files:

- `setup-host.sh`
- `database-host.sh`
- `run-e2e-host.sh`
- `bin/doctor.php`

These files are long because they describe real sequences with different failure recovery. Splitting each step into another file would increase the number of places that must be followed during a failure.

Reduce them by removing repeated path checks, report parsing, logging setup, and file operations. Keep the main sequence in each existing command so the order of destructive actions and restoration remains readable in one place.

## Implementation sequence

### Phase 1 — Record current behavior before cleanup

1. Record the current public Composer command list from `composer.json` and confirm the root-command generator still produces the same names.
2. Add or tighten tests for current argument handling before moving shared shell code:
   - valid and invalid test profiles;
   - missing plugin and theme targets;
   - `composer test` accepting only `-v` and `--verbose`;
   - DDEV missing and DDEV stopped errors; and
   - exit status from PHP and browser test runners.
3. Keep exact confirmation text tests only where the exact typed phrase is part of the public safety contract.
4. Do not change implementation behavior in this phase.

Files:

- `tests/ManifestTest.php`
- `tests/SetupFilesTest.php`
- existing shell-command tests, where present

### Phase 2 — Make the setup inspection report the only DDEV settings reader

1. Extend `bin/inspect-setup.php` to report only the setup values that are used, including:
   - whether `.ddev/config.yaml` and `wp-config-ddev.php` together form a ready DDEV setup;
   - the configured DDEV project name;
   - project type;
   - document root;
   - web server type; and
   - configured extra web-image packages.
2. Keep the report free of database credentials, passwords, remote configuration values, and complete file contents.
3. Remove the unused report fields and the dead Composer validation branch.
4. Add a machine-readable output mode that Bash can read without executing the output. Use pairs of names and values separated by zero bytes so spaces, punctuation, and line breaks cannot become shell commands.
5. Change `setup-host.sh` to load all report values into a Bash associative array once after each inspection refresh.
6. Remove `report_value()`, the repeated report-reading `php -r` calls, `configured_ddev_project_name()`, and the separate DDEV configuration parser.
7. Keep the existing report refresh after setup creates or changes DDEV settings.
8. Add setup fixtures for missing, complete, and partially configured DDEV files.

Files:

- `bin/inspect-setup.php`
- `setup-host.sh`
- `fixtures/setup/`
- `tests/SetupFilesTest.php`

Completion checks:

- `setup-host.sh` no longer uses inline PHP to read the inspection report or parse `.ddev/config.yaml`.
- A setup inspection contains no unused top-level fields.
- `composer setup -- --check` remains read-only.
- Running setup twice still produces no second file change.

### Phase 3 — Reuse common host-command work

1. Rename `logging-host.sh` to `host-common.sh` so its purpose is clear without adding another shell file.
2. Keep the existing detailed-output functions and add small functions for:
   - resolving the toolkit and WordPress root from the calling script;
   - checking required host commands;
   - checking that DDEV is installed and running;
   - printing the detailed-log explanation; and
   - parsing the shared profile and target forms used by PHP and browser tests.
3. Let each caller provide its allowed profile names and continue to own its runner-specific arguments.
4. Keep destructive confirmations, database names, snapshot names, restoration functions, and DDEV lifecycle decisions in their current command files.
5. Use the common functions from the host commands that currently repeat them.
6. Read the WordPress version and the three test-database settings in `sync-wordpress-tests.sh` through one PHP call.

Files:

- `logging-host.sh` renamed to `host-common.sh`
- `doctor-host.sh`
- `database-host.sh`
- `run-tests-host.sh`
- `run-e2e-host.sh`
- `run-all-host.sh`
- `setup-host.sh`
- `sync-wordpress-tests.sh`

Completion checks:

- Public shell files remain the command targets in `composer.json`.
- DDEV is never started by an ordinary test, log, doctor, snapshot, restore, or reset command.
- PHP and browser test arguments still reach their respective runners unchanged.
- A failure in a child command still becomes the public command's exit status.

### Phase 4 — Use one implementation for local file mechanics

1. Add one dependency-free internal PHP file for local file operations. It must work before Composer packages are installed.
2. Move only repeated mechanics into it:
   - read a JSON object with a useful path-specific error;
   - create an unused dated backup path;
   - write through a temporary file in the destination directory and rename only after writing succeeds;
   - preserve permissions when requested;
   - validate PHP syntax; and
   - remove, clear, copy, and digest directory trees without following symbolic links.
3. Keep all allowed-root checks in the calling commands.
4. Keep `wp-config.php` post-write inspection and restoration in `bin/update-wp-config.php`.
5. Keep the private SFTP backup path and mode in `bin/update-ignore-files.php`.
6. Keep uninstall's complete preflight before DDEV or project files are deleted.
7. Replace the duplicate local functions in the four file writers, the runtime builder, and the browser filesystem command.
8. Do not route the explicit confirmed removals in `uninstall-host.sh` through a general recursive deletion function.

Files:

- one new internal PHP file under `bin/`
- `bin/update-wp-config.php`
- `bin/update-root-composer.php`
- `bin/update-ignore-files.php`
- `bin/uninstall-project.php`
- `bin/prepare-runtime.php`
- `bin/e2e-filesystem.php`
- `tests/SetupFilesTest.php`

Completion checks:

- There is one production implementation of dated backup naming.
- There is one production implementation of temporary-file replacement.
- There is one production implementation of recursive removal, exact copying, and path digest creation.
- A failed generated `wp-config.php` restores the exact original.
- Symbolic links are copied or removed as links and are never followed into another directory.
- Browser restoration still preserves an existing top-level protected directory.

### Phase 5 — Remove dead code and shorten manifest handling

1. Remove `Manifest::to_array()`.
2. Remove the unused setup-report fields and their supporting calculations.
3. Remove `gitignore_present` and `sftp_present` from the uninstall result.
4. Change `ManifestBuilder::deduplicate_extensions()` to record each result position and update it directly when a duplicate enables tests.
5. Review private variables and functions in the touched files and remove only those with no internal caller and no required callback signature.
6. Do not remove public integration-test helpers merely because this repository's own fixtures do not call every helper; plugin and theme tests are expected to use them.

Files:

- `src/Manifest.php`
- `src/ManifestBuilder.php`
- `bin/inspect-setup.php`
- `bin/uninstall-project.php`
- `tests/ManifestTest.php`
- `tests/SetupFilesTest.php`

Completion checks:

- Repository search finds no reference to a removed method or report field.
- Duplicate plugin and theme entries retain the same order and correctly enable tests when any matching entry requests them.
- Focused plugin, focused theme, parent theme, dependency, exclusion, harness, and multisite manifest tests still pass.

### Phase 6 — Replace fragile source-text tests

1. Replace assertions about internal variable names and explanatory wording with tests of returned data, exit status, written files, and recorded command order.
2. Run shell commands against private temporary project copies with fake `ddev`, `npm`, `node`, `ssh`, or other host commands where needed. Each fake command should record its arguments and make no real system or database change.
3. Test setup inspection and `--check` behavior through fixtures rather than by searching `setup-host.sh` for variable names.
4. Test uninstall preflight and action order in a disposable copied project. The fake DDEV command must prove that shared-file validation occurs before deletion and that the toolkit directory is removed last.
5. If a destructive order cannot be exercised safely, retain one narrow source-order assertion for the two command calls involved. Do not assert unrelated prose or internal variable names.
6. Keep tests independent from the production recursive cleanup function when deleting their own temporary directories.

Files:

- `tests/SetupFilesTest.php`
- existing command tests
- `fixtures/setup/`

Completion checks:

- No test fails solely because an internal shell variable was renamed.
- Exact user-facing wording is asserted only for typed confirmations or messages that documentation tells users to rely on.
- Safety order remains covered before the old source-text assertions are removed.

### Phase 7 — Final review and documentation check

1. Compare every public command from the WordPress root and `.anyape-wp-test-tools`.
2. Confirm that the cleanup did not introduce another configuration file, another public command, or another package installation step.
3. Update `README.md` or `SETUP.md` only if a documented behavior or internal filename mentioned by those documents changed. Do not add documentation for implementation details that users do not need.
4. Replace this plan with a short completion record or remove completed sections after implementation, so `PLAN.md` does not again become a history of finished phases.

## Required validation

Run at least:

```bash
(
  cd .anyape-wp-test-tools
  git ls-files --cached --others --exclude-standard -z -- '*.php' |
    while IFS= read -r -d '' file; do
      [[ ! -f "$file" ]] || php -l "$file" || exit
    done
)
(
  cd .anyape-wp-test-tools
  git ls-files --cached --others --exclude-standard -z -- '*.sh' |
    while IFS= read -r -d '' file; do
      [[ ! -f "$file" ]] || bash -n "$file" || exit
    done
)
python3 -m json.tool .anyape-wp-test-tools/composer.json >/dev/null
composer lint:wpcs
composer doctor
composer test:harness
composer test:php
(cd .anyape-wp-test-tools && composer doctor)
(cd .anyape-wp-test-tools && composer test:harness)
composer test
(cd .anyape-wp-test-tools && composer test)
```

Also run focused checks for every changed command:

```bash
composer setup -- --check
composer test:plugin -- <fixture-plugin-slug>
composer test:theme -- <fixture-theme-slug>
composer test:multisite
composer test:destructive
composer test:junit
composer anyape-wp-test-tools:uninstall -- --dry-run
```

Manually confirm both logging locations still work:

```bash
composer clear:log
(cd .anyape-wp-test-tools && composer clear:log)
```

Start `composer tail:log` from both supported directories, append one line to `wp-content/debug.log`, confirm that it appears immediately, and stop with `Ctrl+C` without stopping DDEV.

## Final acceptance criteria

- The public command list is unchanged.
- Safety checks remain independently present at destructive database and file boundaries.
- Setup uses one definition of DDEV readiness and does not repeatedly start PHP to read individual report values.
- Repeated host-command checks come from one existing shared shell file with a clearer name.
- Repeated local file operations come from one new dependency-free PHP file.
- Proven dead methods, report fields, result fields, and supporting variables are removed.
- Manifest duplicate handling no longer rescans the completed result list.
- Tests verify behavior and safety order instead of internal variable names wherever practical.
- The source file count increases by no more than one, and changed production files contain fewer total lines than before.
- No public behavior, database name, table prefix, external-request rule, DDEV lifecycle rule, or restoration guarantee changes.
- No GitHub workflow file is added or modified.
