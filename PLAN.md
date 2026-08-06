# Optimization implementation plan

## Goal

Remove confirmed dead code, repeated file-handling code, repeated setup parsing, an unnecessary manifest scan, and unnecessary PHP process starts without changing public commands or their behavior.

The implementation must preserve:

- every Composer command and argument;
- command availability from both the WordPress root and `.anyape-wp-test-tools`;
- all confirmation requirements;
- the working database `db`;
- the PHPUnit database `anyape_wp_test_tools` and prefix `anyape_wptt_`;
- browser-test database and file restoration;
- external-request blocking during ordinary tests;
- setup and uninstall rollback behavior; and
- current plugin, theme, multisite, destructive, coverage, and browser-test selection rules.

## Confirmed audit findings

### 1. Guided setup parses the same state repeatedly

`setup-host.sh` creates a report through `bin/inspect-setup.php`, then starts separate PHP processes to read individual report values. It also reparses `.ddev/config.yaml` in two separate inline PHP blocks to read the DDEV project name and decide whether the DDEV configuration is ready.

`bin/inspect-setup.php` already reads `.ddev/config.yaml`, so setup currently has more than one implementation of the same DDEV checks.

The inspection report also contains confirmed unused fields:

- `project_root`;
- `ddev_config_exists`;
- `ddev_wordpress_exists`;
- `root_composer_valid`;
- `project_test_config`; and
- `db_refresh_config`.

The `$composer_ok` calculation exists only to produce the unused `root_composer_valid` field.

### 2. Safe local file operations are duplicated

The following files separately implement overlapping versions of JSON reading, dated backup names, temporary files, atomic replacement, permission preservation, and PHP syntax validation:

- `bin/update-wp-config.php`;
- `bin/update-root-composer.php`;
- `bin/update-ignore-files.php`; and
- `bin/uninstall-project.php`.

The following files separately implement recursive removal without following symbolic links:

- `bin/prepare-runtime.php`; and
- `bin/e2e-filesystem.php`.

`bin/e2e-filesystem.php` also implements exact copying, directory clearing, and repeatable path digests. These operations are general local file operations and do not depend on browser-test policy.

### 3. Confirmed dead internal code remains

The following internal code has no caller:

- `Manifest::to_array()` in `src/Manifest.php`;
- the unused setup-report fields listed above; and
- `gitignore_present` and `sftp_present` in the result from `anyape_wp_test_tools_uninstall_project_files()`.

These are not documented public extension interfaces.

### 4. Manifest duplicate handling performs an unnecessary scan

`ManifestBuilder::deduplicate_extensions()` stores whether an extension has already been seen, but scans the full result array again when a later duplicate enables tests. The first result position can be stored and updated directly.

### 5. WordPress test synchronization starts PHP four times for four values

`sync-wordpress-tests.sh` starts PHP once to read the installed WordPress version and three more times to read `test_database`, `database_host`, and `table_prefix` from `config.php`.

One PHP invocation can return all four values without changing behavior.

### 6. Several setup tests check source wording instead of behavior

`tests/SetupFilesTest.php` reads shell scripts as text and checks internal variable names, explanatory sentences, and complete command strings. These checks fail after harmless internal changes even when command behavior and safety order remain correct.

The exact typed uninstall confirmation and destructive action order are public safety requirements and must remain covered. Internal variable names and explanatory prose are not public behavior.

## Concrete architecture

### Command ownership

The existing shell files remain the public command implementations. No public command is moved or renamed.

- `setup-host.sh` continues to own setup questions, confirmations, DDEV configuration, package installation, database choice, and the final report.
- `database-host.sh` continues to own snapshots, restores, test-database reset, and remote database copying.
- `run-tests-host.sh` continues to own PHP test profile handling and the container test sequence.
- `run-e2e-host.sh` continues to own browser-test capture, snapshot, execution, restoration, and verification.
- `uninstall-host.sh` continues to own exact confirmation, DDEV deletion, shared-file cleanup, and removal of the toolkit directory.

### Setup state

`bin/inspect-setup.php` becomes the only file that reads and interprets setup state from:

- `wp-config.php`;
- `.ddev/config.yaml`;
- `wp-config-ddev.php`;
- `.gitmodules`;
- `.gitignore`;
- `.vscode/sftp.json`; and
- root `composer.json` existence.

It exposes the same inspection through two output forms:

1. default JSON for direct use and tests;
2. `--shell` output containing alternating zero-byte-terminated names and values for safe Bash loading.

`setup-host.sh` loads the complete inspection once after each refresh and assigns each known value through a fixed `case` statement. It must not use associative arrays because the command must remain compatible with the Bash version supplied by macOS. It must not use `eval` or execute generated shell code.

### Shared local file operations

Create `bin/file-tools.php` as a dependency-free file loaded with `require_once`. It contains only local file operations. It must not contain setup policy, uninstall policy, allowed-root decisions, database decisions, or command output.

Callers keep all policy:

- `bin/update-wp-config.php` keeps backup restoration and final structure checks;
- `bin/update-ignore-files.php` keeps the private SFTP backup location and permissions;
- `bin/uninstall-project.php` keeps complete preflight before any write;
- `bin/prepare-runtime.php` keeps the fixed runtime target;
- `bin/e2e-filesystem.php` keeps the allowed browser-run directory and protected-path checks.

### Manifest handling

`Manifest` remains the read-only runtime manifest object. Only the unused `to_array()` method is removed.

`ManifestBuilder` keeps the current manifest structure and selection order. Duplicate entries are updated through a stored result index instead of a second scan.

### Tests

Tests continue to use private temporary directories. They may use fake host commands placed first in `PATH`, but they must not call real DDEV lifecycle commands, real SSH, real databases, or external services.

One narrow source-order test remains for uninstall because the final deletion of the toolkit cannot be executed inside the running test suite. All other source-text checks covered by this plan are replaced with output, file-state, exit-status, or recorded-command assertions.

## Files to create

### `bin/file-tools.php`

Create this single dependency-free internal file with these exact functions:

```php
/** @return array<string, mixed> */
function anyape_wp_test_tools_read_json_object( string $path ): array;

function anyape_wp_test_tools_unused_backup_path( string $path ): string;

function anyape_wp_test_tools_atomic_write(
    string $path,
    string $contents,
    ?int $permissions = null
): void;

function anyape_wp_test_tools_assert_php_syntax( string $path ): void;

function anyape_wp_test_tools_remove_path( string $path ): void;

function anyape_wp_test_tools_clear_directory( string $path ): void;

function anyape_wp_test_tools_copy_path(
    string $source,
    string $destination
): void;

function anyape_wp_test_tools_path_digest( string $path ): string;
```

The function names are fixed by this plan. Do not add a class, namespace, package, framework, or Composer dependency for these operations.

## Files to update

- `bin/inspect-setup.php`
- `setup-host.sh`
- `bin/update-wp-config.php`
- `bin/update-root-composer.php`
- `bin/update-ignore-files.php`
- `bin/uninstall-project.php`
- `bin/prepare-runtime.php`
- `bin/e2e-filesystem.php`
- `src/Manifest.php`
- `src/ManifestBuilder.php`
- `sync-wordpress-tests.sh`
- `tests/SetupFilesTest.php`
- `tests/ManifestTest.php`

`PLAN.md` and `AGENTS.md` were updated by the planning task. Do not modify either file while implementing this plan.

`README.md` and `SETUP.md` are not implementation files for this cleanup because no documented public behavior changes. During final review, verify that neither document names a removed internal method or report field. Do not edit them unless that verification finds an actual inaccurate statement; if it does, stop and report the plan inconsistency before changing documentation.

## Files to delete

None.

No existing command file or test fixture directory is deleted or renamed.

## Implementation sequence

### Phase 1 — Lock current manifest behavior

Update `tests/ManifestTest.php` before changing manifest production code.

Add coverage that proves the current behavior:

- duplicate plugin entries retain the position of the first entry;
- duplicate theme entries retain the position of the first entry;
- a later duplicate with `tests_enabled=true` enables tests on the retained entry;
- a later duplicate transfers its `tests_path` and `bootstrap` values when tests become enabled;
- focused plugin and theme selection remain unchanged;
- harness values remain unchanged; and
- multisite values remain unchanged.

Acceptance criteria for Phase 1:

- all new tests pass against the current `ManifestBuilder` implementation;
- the tests fail if duplicate extension order changes;
- the tests fail if a later test-enabled duplicate does not update the retained entry; and
- no production file changes in this phase.

### Phase 2 — Make setup inspection the only setup-state reader

Update `bin/inspect-setup.php` to return this exact top-level structure:

```php
return array(
    'wordpress_valid'       => array() === $missing,
    'missing_paths'         => $missing,
    'wp_config'             => $wp_config_report,
    'ddev_ready'            => $ddev_ready,
    'ddev_project_name'     => $ddev_project_name,
    'ddev_project_type'     => $ddev_project_type,
    'ddev_docroot'          => $ddev_docroot,
    'ddev_webserver_type'   => $ddev_webserver_type,
    'ddev_packages'         => $ddev_packages,
    'subversion_configured' => in_array( 'subversion', $ddev_packages, true ),
    'root_composer_exists'  => is_file( $composer_path ),
    'root_gitignore_exists' => is_file( $project_root . '/.gitignore' ),
    'git_mode'              => $git_mode,
    'sftp_config_exists'    => is_file( $project_root . '/.vscode/sftp.json' ),
);
```

Determine `ddev_ready` only when all of these are true:

```php
$ddev_ready =
    is_file( $ddev_path ) &&
    is_file( $project_root . '/wp-config-ddev.php' ) &&
    'wordpress' === $ddev_project_type &&
    '.' === $ddev_docroot &&
    'apache-fpm' === $ddev_webserver_type &&
    null !== $ddev_project_name;
```

Add `--shell` output. It must flatten the values needed by Bash and write alternating zero-byte-terminated names and values:

```php
foreach ( $shell_values as $name => $value ) {
    fwrite( STDOUT, $name . "\0" . $value . "\0" );
}
```

Arrays used by Bash must be converted before output:

- `missing_paths` becomes a comma-separated display value;
- `ddev_packages` becomes a comma-separated value accepted by the existing DDEV command;
- `wp_config.reasons` becomes newline-separated text for error output;
- booleans become `1` or `0`.

Update `setup-host.sh` to declare plain variables and load them through a fixed name list:

```bash
SETUP_WORDPRESS_VALID=""
SETUP_MISSING_PATHS=""
SETUP_WP_CONFIG_STATUS=""
SETUP_WP_CONFIG_REASONS=""
SETUP_DDEV_READY=""
SETUP_DDEV_PROJECT_NAME=""
SETUP_DDEV_PROJECT_TYPE=""
SETUP_DDEV_DOCROOT=""
SETUP_DDEV_WEBSERVER_TYPE=""
SETUP_DDEV_PACKAGES=""
SETUP_SUBVERSION_CONFIGURED=""
SETUP_ROOT_COMPOSER_EXISTS=""
SETUP_ROOT_GITIGNORE_EXISTS=""
SETUP_GIT_MODE=""
SETUP_SFTP_CONFIG_EXISTS=""

load_setup_state() {
    local name
    local value

    while IFS= read -r -d '' name && IFS= read -r -d '' value; do
        case "$name" in
            wordpress_valid) SETUP_WORDPRESS_VALID="$value" ;;
            missing_paths) SETUP_MISSING_PATHS="$value" ;;
            wp_config_status) SETUP_WP_CONFIG_STATUS="$value" ;;
            wp_config_reasons) SETUP_WP_CONFIG_REASONS="$value" ;;
            ddev_ready) SETUP_DDEV_READY="$value" ;;
            ddev_project_name) SETUP_DDEV_PROJECT_NAME="$value" ;;
            ddev_project_type) SETUP_DDEV_PROJECT_TYPE="$value" ;;
            ddev_docroot) SETUP_DDEV_DOCROOT="$value" ;;
            ddev_webserver_type) SETUP_DDEV_WEBSERVER_TYPE="$value" ;;
            ddev_packages) SETUP_DDEV_PACKAGES="$value" ;;
            subversion_configured) SETUP_SUBVERSION_CONFIGURED="$value" ;;
            root_composer_exists) SETUP_ROOT_COMPOSER_EXISTS="$value" ;;
            root_gitignore_exists) SETUP_ROOT_GITIGNORE_EXISTS="$value" ;;
            git_mode) SETUP_GIT_MODE="$value" ;;
            sftp_config_exists) SETUP_SFTP_CONFIG_EXISTS="$value" ;;
            *)
                echo "ERROR: Unknown setup inspection field '$name'." >&2
                return 1
                ;;
        esac
    done < <(
        php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/inspect-setup.php" \
            --shell \
            "$PROJECT_ROOT"
    )
}
```

After loading, verify that every expected field was received. An absent field must fail setup before any change.

Replace every current `report_value` access with the corresponding `SETUP_*` variable.

Remove from `setup-host.sh`:

- `REPORT_FILE`;
- `cleanup_setup_report()` report-file removal;
- `refresh_setup_report()`;
- `report_value()`;
- `configured_ddev_project_name()`;
- `ddev_configuration_ready()`; and
- every inline `php -r` command that reads the setup report or `.ddev/config.yaml`.

Keep the existing call sequence by invoking `load_setup_state`:

- once before the first WordPress validity check;
- again immediately after `ddev config`; and
- again after any setup action whose result is read later from inspection state.

Update `tests/SetupFilesTest.php` in this phase. Build DDEV configurations directly inside each private temporary test directory; do not add permanent fixture files. Cover:

- missing `.ddev/config.yaml`;
- missing `wp-config-ddev.php`;
- complete supported settings;
- wrong project type;
- wrong document root;
- wrong web server type;
- package list without `subversion`; and
- package list with `subversion`.

Assert that the JSON report does not contain the six removed fields.

Critical safety rule:

`composer setup -- --check` must continue to avoid DDEV lifecycle commands, package installation, file writes, and database changes.

Acceptance criteria for Phase 2:

- `setup-host.sh` contains no parser for `.ddev/config.yaml`;
- `setup-host.sh` contains no temporary JSON report file;
- `setup-host.sh` contains no inline PHP used to read inspection values;
- the JSON report contains only the documented top-level fields above;
- `--shell` output is loaded without `eval`, `source`, or Bash associative arrays;
- values containing spaces cannot become shell commands;
- missing shell fields fail before any setup change;
- `composer setup -- --check` is read-only; and
- repeated setup still reports completed work without rewriting files.

### Phase 3 — Centralize local file operations

Create `bin/file-tools.php` and update the six callers listed below to load it directly:

```php
require_once __DIR__ . '/file-tools.php';
```

#### JSON reading

Move the complete JSON-object validation from `bin/update-root-composer.php` into `anyape_wp_test_tools_read_json_object()`.

The function must:

- fail when the path does not exist;
- use `JSON_THROW_ON_ERROR`;
- require the decoded value to be an array; and
- include the exact path in the exception message.

Use it from:

- `bin/update-root-composer.php`;
- `bin/update-ignore-files.php` for SFTP JSON; and
- `bin/uninstall-project.php` for Composer and SFTP JSON.

#### Backup names

Move the duplicate dated-name loops into `anyape_wp_test_tools_unused_backup_path()`.

Use the format already used by the project:

```php
$base = $path . '.before-anyape-wp-test-tools-' . gmdate( 'Ymd\THis\Z' );
```

Append `-1`, `-2`, and later integers until the path is unused.

Use it from:

- `bin/update-wp-config.php`;
- `bin/update-root-composer.php`; and
- `bin/update-ignore-files.php`.

The SFTP writer still passes its private runtime backup path as the input path and still applies mode `0600` after writing the backup.

#### Atomic replacement

Implement `anyape_wp_test_tools_atomic_write()` with a temporary file in the destination directory:

```php
function anyape_wp_test_tools_atomic_write(
    string $path,
    string $contents,
    ?int $permissions = null
): void {
    $temp = tempnam( dirname( $path ), '.anyape-wp-test-tools-' );

    try {
        if ( false === $temp || false === file_put_contents( $temp, $contents ) ) {
            throw new RuntimeException( 'Could not write temporary file for: ' . $path );
        }

        if ( null !== $permissions && ! chmod( $temp, $permissions ) ) {
            throw new RuntimeException( 'Could not preserve permissions for: ' . $path );
        }

        if ( ! rename( $temp, $path ) ) {
            throw new RuntimeException( 'Could not replace file safely: ' . $path );
        }

        $temp = null;
    } finally {
        if ( is_string( $temp ) && file_exists( $temp ) ) {
            unlink( $temp );
        }
    }
}
```

Callers must pass existing permissions masked with `0777` when permissions must be preserved.

Use it from:

- `bin/update-wp-config.php`;
- `bin/update-root-composer.php`;
- `bin/update-ignore-files.php`; and
- `bin/uninstall-project.php`.

`bin/update-wp-config.php` must keep this caller-owned sequence:

1. create backup;
2. write proposed contents to a temporary validation file;
3. validate proposed PHP syntax;
4. atomically replace the destination;
5. validate the replaced file;
6. inspect the replaced structure;
7. restore the backup on any failure after the backup is created.

#### PHP syntax validation

Move the repeated `PHP_BINARY -l` command into `anyape_wp_test_tools_assert_php_syntax()` and use it from:

- `bin/update-wp-config.php`; and
- `bin/uninstall-project.php`.

The exception must include the syntax-check output.

#### Directory operations

Move the production implementations into `bin/file-tools.php`.

`anyape_wp_test_tools_remove_path()` must check symbolic links before directories:

```php
if ( is_link( $path ) || is_file( $path ) ) {
    if ( ! unlink( $path ) ) {
        throw new RuntimeException( 'Could not remove file: ' . $path );
    }
    return;
}
```

It must recurse only through real directories and must never follow a symbolic link.

`anyape_wp_test_tools_clear_directory()` must remove children while preserving the supplied directory.

`anyape_wp_test_tools_copy_path()` must:

- recreate symbolic links as symbolic links;
- copy files without changing their contents;
- preserve file and directory permission bits;
- create parent directories as needed; and
- recurse only through real directories.

`anyape_wp_test_tools_path_digest()` must keep the existing browser-test digest rules for missing paths, directories, files, symbolic links, relative paths, and SHA-256 file hashes.

Use these functions from:

- `bin/prepare-runtime.php`; and
- `bin/e2e-filesystem.php`.

Keep these checks in their existing callers:

- `bin/prepare-runtime.php` may remove only `runtime/wp-content`;
- `bin/e2e-filesystem.php` may operate only inside `runtime/e2e-runs` and only on configured paths below `wp-content`;
- browser restore must preserve an existing top-level directory instead of deleting and recreating it; and
- `uninstall-host.sh` continues to use its explicit confirmed removal commands.

Update `tests/SetupFilesTest.php` with direct tests of the shared functions using only its private temporary directory. Keep the test class's independent cleanup method so test cleanup does not use the code being tested.

Acceptance criteria for Phase 3:

- repository search finds one production implementation of dated backup naming;
- repository search finds one production implementation of atomic replacement;
- repository search finds one production implementation of PHP syntax validation;
- repository search finds one production implementation of recursive removal, exact copying, directory clearing, and path digest creation;
- invalid generated `wp-config.php` restores the exact original contents;
- SFTP backups remain under `runtime/setup-backups` with mode `0600`;
- symbolic links are copied or removed as links and never followed; and
- browser restoration still preserves top-level protected directories.

### Phase 4 — Remove dead internal code and direct-update manifest duplicates

Update `src/Manifest.php` and remove this complete method:

```php
public function to_array(): array {
    return $this->data;
}
```

Update `bin/inspect-setup.php` by removing the six unused fields and every calculation used only by those fields.

Update `bin/uninstall-project.php` so `anyape_wp_test_tools_uninstall_project_files()` returns only:

```php
return array(
    'wp_config_restored'    => true,
    'root_composer_removed' => $remove_root_composer,
);
```

Update `ManifestBuilder::deduplicate_extensions()` to store result positions:

```php
private function deduplicate_extensions( array $extensions ): array {
    $result    = array();
    $positions = array();

    foreach ( $extensions as $extension ) {
        $key = (string) $extension['type'] . ':' . (string) $extension['slug'];

        if ( isset( $positions[ $key ] ) ) {
            $index = $positions[ $key ];

            if ( ! empty( $extension['tests_enabled'] ) ) {
                $result[ $index ]['tests_enabled'] = true;
                $result[ $index ]['tests_path']    = $extension['tests_path'];
                $result[ $index ]['bootstrap']     = $extension['bootstrap'];
            }

            continue;
        }

        $positions[ $key ] = count( $result );
        $result[]          = $extension;
    }

    return $result;
}
```

Do not change extension keys, output order, manifest fields, or selection rules.

Acceptance criteria for Phase 4:

- repository search finds no call to `Manifest::to_array()`;
- the six removed report fields do not appear in production code or tests;
- `gitignore_present` and `sftp_present` do not appear in the uninstall result or tests;
- duplicate extension order remains unchanged; and
- all manifest profile tests pass.

### Phase 5 — Read synchronization settings through one PHP process

Update `sync-wordpress-tests.sh` so one PHP process returns the installed WordPress version and the three configuration values as zero-byte-terminated values:

```bash
SETTINGS=()
while IFS= read -r -d '' value; do
    SETTINGS+=("$value")
done < <(
    php -r '
        require $argv[1];
        $config = require $argv[2];
        foreach (
            array(
                $wp_version,
                $config["test_database"],
                $config["database_host"],
                $config["table_prefix"],
            ) as $value
        ) {
            fwrite(STDOUT, (string) $value . "\0");
        }
    ' "$ROOT_DIR/wp-includes/version.php" "$CONFIG_FILE"
)

if ((${#SETTINGS[@]} != 4)); then
    echo "ERROR: Could not read the WordPress test synchronization settings." >&2
    exit 1
fi

WP_VERSION="${SETTINGS[0]}"
TEST_DATABASE="${SETTINGS[1]}"
DATABASE_HOST="${SETTINGS[2]}"
TABLE_PREFIX="${SETTINGS[3]}"
```

Validate that none of the four values is empty before any download or removal.

Keep all current prerelease checks, synchronization checks, download addresses, extraction steps, Subversion exports, generated configuration, and state-file behavior.

Acceptance criteria for Phase 5:

- the initial settings read uses one PHP process;
- the four values are validated before any download or removal;
- a missing or malformed value fails before `rm -rf "$CORE_DIR" "$TESTS_DIR"`; and
- a synchronized matching version still exits without downloading files.

### Phase 6 — Replace fragile source-text tests

Update `tests/SetupFilesTest.php`.

Remove assertions that depend on:

- internal shell variable names;
- exact explanatory sentences;
- the complete text of non-public command descriptions; and
- implementation-specific formatting of a command line.

Replace them with these exact tests:

1. **Setup check is read-only**
   - create a private temporary WordPress project with a copied `.anyape-wp-test-tools` command surface required by setup check;
   - use the real PHP executable;
   - place fake `ddev`, `composer`, `node`, `npm`, and `git` commands first in `PATH`;
   - make every fake command append its arguments to a private log and otherwise return the minimum successful output required by the check;
   - run `setup-host.sh --check`;
   - assert exit status `0`;
   - assert that the log contains no DDEV `start`, `restart`, `config`, snapshot, restore, database, package-installation, or file-writing action.

2. **Setup inspection controls DDEV readiness**
   - call `anyape_wp_test_tools_inspect_setup()` against the temporary DDEV configurations created in Phase 2 tests;
   - assert `ddev_ready`, project name, project type, document root, web server type, package list, and Subversion state.

3. **Database-pull receipt matching remains exact**
   - create a temporary project and toolkit copy containing `database-host.sh` and its required PHP helpers;
   - create a valid `db-refresh-local.php` and matching setup receipt;
   - put fake `ddev`, `ssh`, `php`, and `gzip` commands first in `PATH`, except that the fake PHP command must delegate normal script execution to the real PHP binary and intercept only the inline URL-host check;
   - run `database-host.sh pull --yes` with `ANYAPE_WP_TEST_TOOLS_SETUP_RUN_ID` set;
   - assert that an exact receipt exits successfully without invoking fake SSH;
   - run separate cases changing the SSH alias, remote path, remote URL, local URL, and current site URL;
   - assert that each changed value reaches the confirmation-complete remote-export path and invokes fake SSH.

4. **Uninstall order remains protected**
   - retain one narrow source-order assertion proving that `bin/uninstall-project.php --check` occurs before `ddev delete`;
   - retain one narrow source-order assertion proving that shared-file cleanup occurs before `rm -rf -- "$ANYAPE_WP_TEST_TOOLS_DIR"`;
   - remove every unrelated prose and variable-name assertion from those tests.

Do not create a permanent fake-command fixture directory. Create fake commands inside each test's private temporary directory and remove them in the existing independent test cleanup.

Acceptance criteria for Phase 6:

- renaming an internal shell variable does not fail a test;
- changing non-contract explanatory prose does not fail a test;
- `setup --check` read-only behavior is tested through command execution;
- database-pull receipt matching is tested through command behavior;
- uninstall preflight and final deletion order remain covered; and
- test cleanup does not use the production recursive removal function.

### Phase 7 — Cleanup review and validation

Review every changed production file and remove:

- old local helper functions replaced by `bin/file-tools.php`;
- variables used only by removed report fields or removed return values;
- obsolete comments describing removed implementations; and
- unused `require_once` statements.

Do not leave compatibility wrappers for removed private functions.

Verify public command names from `composer.json` before and after implementation. They must be identical.

Verify root command generation by running the existing root Composer merge tests. The generated root commands must remain identical.

Verify documentation. Because no public behavior changes, `README.md` and `SETUP.md` must remain unchanged. If implementation reveals that either document must change, stop and report the plan inconsistency instead of editing it.

## Required validation

Run PHP syntax checks on every tracked or untracked, non-ignored PHP file:

```bash
(
  cd .anyape-wp-test-tools
  git ls-files --cached --others --exclude-standard -z -- '*.php' |
    while IFS= read -r -d '' file; do
      [[ ! -f "$file" ]] || php -l "$file" || exit
    done
)
```

Run shell syntax checks on every tracked or untracked, non-ignored shell file:

```bash
(
  cd .anyape-wp-test-tools
  git ls-files --cached --others --exclude-standard -z -- '*.sh' |
    while IFS= read -r -d '' file; do
      [[ ! -f "$file" ]] || bash -n "$file" || exit
    done
)
```

Run:

```bash
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

Run the specialist commands that do not require an unknown project-specific extension slug:

```bash
composer test:multisite
composer test:destructive
composer test:junit
```

Run setup checks from both supported locations:

```bash
composer setup -- --check
(cd .anyape-wp-test-tools && composer setup -- --check)
```

Run the backup-independent shared-file uninstall preflight directly, without deleting DDEV or the toolkit:

```bash
php .anyape-wp-test-tools/bin/uninstall-project.php \
  --check \
  "$PWD" \
  "$PWD/.anyape-wp-test-tools/composer.json"
```

## Acceptance criteria

The implementation is accepted only when all of these are true:

- implementation changes are limited to the files listed under **Files to create** and **Files to update**;
- `PLAN.md` and `AGENTS.md` remain unchanged during implementation;
- no existing file is deleted;
- all required validation commands pass;
- every public Composer command name and mapping is unchanged;
- setup state is parsed only by `bin/inspect-setup.php`;
- setup shell values are loaded without `eval`, `source`, or Bash associative arrays;
- the six confirmed unused setup fields are removed;
- `Manifest::to_array()` is removed;
- unused uninstall result fields are removed;
- manifest duplicate handling uses stored positions and keeps current output order;
- one PHP process reads all initial WordPress synchronization values;
- local file operations have one production implementation in `bin/file-tools.php`;
- setup, database, browser-test, and uninstall policy remains in the existing command files;
- setup check remains read-only;
- browser restoration still restores and verifies the working database and protected files after success, failure, and interruption;
- invalid `wp-config.php` generation restores the exact original file;
- SFTP backups remain private;
- no symbolic link is followed during removal, copying, or digest creation;
- no source-text test depends on non-contract prose or internal variable names; and
- `README.md` and `SETUP.md` remain unchanged and accurate.

## Exit conditions

Implementation is complete only after:

1. every phase is implemented in order;
2. every phase's acceptance criteria pass before the next phase begins;
3. all required validation commands pass;
4. repository search confirms removed private functions and fields have no remaining references;
5. `git diff --check` reports no whitespace errors;
6. implementation `git status --short` contains only the files listed in this plan, excluding the already completed `PLAN.md` and `AGENTS.md` planning changes; and
7. the final diff contains no temporary files, generated reports, compatibility wrappers, commented-out code, or unfinished notes.

Stop implementation and report the inconsistency before making further changes if any required step conflicts with an existing safety rule, public command contract, or another requirement in `AGENTS.md`.