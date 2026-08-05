# Setup

This guide configures an existing, complete WordPress file tree for local development with DDEV and installs this repository as `.test-tools`.

The intended result is:

```text
wordpress-root/
├── .ddev/
├── .test-tools/
├── composer.json
├── wp-admin/
├── wp-content/
├── wp-includes/
├── wp-config.php
└── wp-config-ddev.php
```

The existing WordPress files remain the files you edit and deploy. DDEV supplies the local web server, PHP runtime, database, WP-CLI, and containerized Composer. The working site uses DDEV's normal `db` database; PHPUnit uses a separate `wp_tests` database.

## 1. Install the host prerequisites

On macOS, install Docker Desktop with its graphical interface, then install DDEV and the local certificate helper:

```bash
brew install --cask docker-desktop
brew install ddev/ddev/ddev
mkcert -install
```

Open Docker Desktop and wait until its engine is running.

Verify the tools:

```bash
docker info --format 'Docker engine: {{.ServerVersion}}'
ddev version
composer --version
```

Composer must be available on the host because the routine project command is `composer test`. The actual PHPUnit process still runs inside DDEV.

## 2. Configure the existing WordPress tree as a DDEV project

Run this from the directory containing `wp-admin`, `wp-content`, `wp-includes`, and `wp-config.php`:

```bash
ddev config \
  --project-name=your-project-name \
  --project-type=wordpress \
  --docroot=. \
  --webserver-type=apache-fpm
```

DDEV creates `.ddev/config.yaml` and, when it detects a user-managed `wp-config.php`, creates `wp-config-ddev.php`.

You may pin PHP or database versions when the project requires it:

```bash
ddev config --php-version=8.3 --database=mariadb:10.11
```

The remote server does not need to be inspected to create the local environment. Pin versions only when the project itself has a compatibility requirement you want to reproduce.

## 3. Make `wp-config.php` work locally and remotely

The remote database constants must not be defined inside DDEV, because `wp-config-ddev.php` supplies the local values. The DDEV include must occur after `ABSPATH` is defined because the generated file uses it.

Near the beginning of `wp-config.php`, before the database constants, add:

```php
$is_ddev = getenv('IS_DDEV_PROJECT') === 'true';
```

Wrap the remote database credentials so they are used only outside DDEV:

```php
if (! $is_ddev) {
	define('DB_NAME', 'REMOTE_DATABASE_NAME');
	define('DB_USER', 'REMOTE_DATABASE_USER');
	define('DB_PASSWORD', 'REMOTE_DATABASE_PASSWORD');
	define('DB_HOST', 'localhost');
}

defined('DB_CHARSET') || define('DB_CHARSET', 'utf8');
defined('DB_COLLATE') || define('DB_COLLATE', '');
```

Keep the existing table prefix, authentication keys, salts, and other project constants.

Define debug settings without redefining constants that may already exist:

```php
defined('WP_DEBUG') || define('WP_DEBUG', true);
defined('WP_DEBUG_LOG') || define('WP_DEBUG_LOG', $is_ddev);
defined('WP_DEBUG_DISPLAY') || define('WP_DEBUG_DISPLAY', false);
```

For a project that redirects PHP errors to a server-specific path, make the path conditional:

```php
ini_set('log_errors', '1');

if ($is_ddev) {
	ini_set('error_log', __DIR__ . '/wp-content/debug.log');
} else {
	ini_set('error_log', '/remote/path/to/error_log');
}
```

At the bottom of `wp-config.php`, replace the normal `ABSPATH` and `wp-settings.php` block with:

```php
/** Absolute path to the WordPress directory. */
if (! defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

/** Load DDEV's local database and URL settings only inside DDEV. */
if ($is_ddev) {
	require_once __DIR__ . '/wp-config-ddev.php';
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
```

Validate the result:

```bash
php -l wp-config.php
```

Do not commit or upload `wp-config-ddev.php`. It is generated for the local DDEV project.

## 4. Exclude local-only files from SFTP

For the VS Code SFTP extension, add at least these entries to the existing `ignore` array:

```json
".ddev",
".test-tools",
"wp-config-ddev.php",
"wp-config.php.before-ddev"
```

Keep any existing exclusions such as `.git`, `.vscode`, `.DS_Store`, and `node_modules`.

Validate the JSON after editing:

```bash
python3 -m json.tool .vscode/sftp.json >/dev/null && echo "sftp.json is valid"
```

If the WordPress tree is itself a Git repository and `.test-tools` is installed as an ordinary nested clone, add this to the parent repository's `.gitignore`:

```gitignore
.test-tools/
wp-config-ddev.php
wp-config.php.before-ddev
```

Do not ignore `.test-tools` when intentionally installing it as a Git submodule.

`.ddev/` may be committed to the WordPress project's repository when the DDEV configuration is intended to be shared, but it should still be excluded from SFTP deployment.

## 5. Start DDEV

```bash
ddev start
```

DDEV prints the project URL, normally similar to:

```text
https://your-project-name.ddev.site
```

If port 80 is occupied, DDEV may use an alternate host port internally. The `.ddev.site` HTTPS URL remains the normal browser entry point.

## 6. Populate the local working database

Choose one option.

### Option A: create a new WordPress installation

Use this when the local site should begin empty:

```bash
ddev wp core install \
  --url="https://your-project-name.ddev.site" \
  --title="Local WordPress Development" \
  --admin_user="admin" \
  --admin_email="admin@example.test" \
  --prompt=admin_password
```

### Option B: import an existing database

Use this when the local site should reproduce an existing development or staging installation.

Export through an existing SSH alias and compress the stream locally:

```bash
ssh your-ssh-alias '
  set -o pipefail
  cd /remote/path/to/wordpress
  wp db export - --add-drop-table --max_allowed_packet=1G | gzip -c
' > /tmp/wordpress-remote.sql.gz
```

The larger packet limit avoids failures on tables containing large rows, such as security-plugin configuration tables.

Verify the dump before importing it:

```bash
gzip -t /tmp/wordpress-remote.sql.gz
ls -lh /tmp/wordpress-remote.sql.gz
```

Import it into DDEV's working `db` database:

```bash
ddev import-db --file=/tmp/wordpress-remote.sql.gz
```

Replace the remote URL with the DDEV URL using WP-CLI so serialized data is handled correctly:

```bash
ddev wp search-replace \
  'https://remote.example.test' \
  'https://your-project-name.ddev.site' \
  --all-tables \
  --skip-columns=guid \
  --precise
```

Because `wp-config-ddev.php` defines `WP_HOME` and `WP_SITEURL`, `ddev wp option get home` may display the local URL even before the database values have been replaced. Inspect the raw values directly when needed:

```bash
ddev mysql -N -e "
SELECT option_name, option_value
FROM wp_options
WHERE option_name IN ('home', 'siteurl')
ORDER BY option_name;
"
```

After confirming the imported site works, create a restore point:

```bash
ddev snapshot --name=initial-working-local
ddev snapshot --list
```

Restore it later with:

```bash
ddev snapshot restore initial-working-local
```

## 7. Create the dedicated PHPUnit database

The working site uses `db`. Tests must use a separate database:

```bash
ddev mysql -uroot -proot -e "
CREATE DATABASE IF NOT EXISTS wp_tests
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON wp_tests.* TO 'db'@'%';
FLUSH PRIVILEGES;
"
```

Verify it:

```bash
ddev mysql -N -e "
SELECT SCHEMA_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'wp_tests';
"
```

The expected result is `wp_tests`.

## 8. Add Subversion to the DDEV web image

The WordPress PHPUnit library is retrieved from the official WordPress Subversion repository. Install Subversion in the project image once:

```bash
ddev config --webimage-extra-packages=subversion
ddev restart
```

Verify it:

```bash
ddev exec svn --version --quiet
```

This configuration is stored in `.ddev/config.yaml`. Routine test runs do not rebuild the image.

If DDEV reports Mutagen conflicts after large plugin updates, inspect them with:

```bash
ddev utility mutagen-diagnose
```

Typical `.DS_Store` conflicts can be cleared with:

```bash
find . -name '.DS_Store' -type f -delete
ddev mutagen reset
ddev restart
```

Large project-specific `node_modules` directories may be added to DDEV's `upload_dirs` configuration so Mutagen does not synchronize them.

## 9. Install this repository as `.test-tools`

From the WordPress root:

```bash
git clone https://github.com/froger-me/wp-test.git .test-tools
```

Install the toolkit's PHP dependencies inside DDEV:

```bash
ddev exec --dir=/var/www/html/.test-tools composer install
```

The repository already contains the bootstrap, test runners, PHPUnit configuration, synchronization script, and harness tests. Do not recreate those files manually.

The generated paths below remain local and are ignored by the toolkit repository:

```text
.test-tools/vendor/
.test-tools/wordpress/
.test-tools/wordpress-tests-lib/
.test-tools/active-plugins.json
.test-tools/.wordpress-test-version
```

## 10. Expose the standard root command

The consuming WordPress root needs a Composer script that calls the toolkit's host runner.

When no root `composer.json` exists, create:

```json
{
    "name": "local/wordpress-development-site",
    "private": true,
    "scripts": {
        "test": ".test-tools/run-tests-host.sh"
    }
}
```

When a root `composer.json` already exists, add only this script entry without replacing existing configuration:

```json
"test": ".test-tools/run-tests-host.sh"
```

Ensure the scripts are executable if file permissions were not preserved:

```bash
chmod +x \
  .test-tools/run-tests-host.sh \
  .test-tools/run-tests.sh \
  .test-tools/sync-wordpress-tests.sh
```

## 11. Run the suite

DDEV must already be running:

```bash
ddev start
```

Then use the standard project command:

```bash
composer test
```

The command does not call `ddev start`, restart containers, rebuild images, or manage the environment lifecycle.

Pass PHPUnit arguments after `--`:

```bash
composer test -- --filter WordPressEnvironmentTest
composer test -- --filter ActivePluginsTest
composer test -- --stop-on-failure
```

## 12. Understand the current behavior

On each run:

- `.test-tools/run-tests-host.sh` reads `active_plugins` from the working DDEV site and writes `.test-tools/active-plugins.json`.
- `.test-tools/run-tests.sh` invokes the version synchronizer, then PHPUnit.
- `.test-tools/sync-wordpress-tests.sh` reads `wp-includes/version.php` from the working file tree.
- A matching clean WordPress core is downloaded into `.test-tools/wordpress`.
- The matching WordPress PHPUnit library is exported into `.test-tools/wordpress-tests-lib`.
- PHPUnit installs a test site in `wp_tests` with the `wptests_` prefix.
- Active ordinary plugins are loaded through WordPress's normal bootstrap.
- Must-use plugins are available from the real `wp-content/mu-plugins` directory.
- Registered activation hooks are run against the test database.
- Unmocked requests made through the WordPress HTTP API return an `unexpected_http_request` error.
- The working `db` database remains untouched.

A test can mock an HTTP request by returning a response through `pre_http_request` at a priority lower than `10`:

```php
$mock = static function ($preempt, array $args, string $url) {
	if ($url !== 'https://service.example.test/verify') {
		return $preempt;
	}

	return [
		'headers'  => [],
		'body'     => '{"success":true}',
		'response' => [
			'code'    => 200,
			'message' => 'OK',
		],
		'cookies'  => [],
		'filename' => null,
	];
};

add_filter('pre_http_request', $mock, 5, 3);
```

Remove test-specific filters during teardown or in a `finally` block.

## 13. Update WordPress or the toolkit

After updating WordPress core, run the normal command:

```bash
composer test
```

The synchronizer detects the new installed version and refreshes the clean core and WordPress PHPUnit library automatically.

To update this toolkit:

```bash
git -C .test-tools pull --ff-only
ddev exec --dir=/var/www/html/.test-tools composer install
composer test
```

## 14. Current limitations

The repository is still being completed. At present:

- PHPUnit runs the toolkit's shared harness tests.
- Plugin- and theme-local test discovery has not been added yet.
- Plugin lifecycle helpers beyond bootstrap activation have not been finalized.
- Multisite execution is not exposed through a standard command.
- PHPUnit is currently pinned to the 9.6 line.
- Playwright E2E tests are not installed yet.
- A standard `composer tail:log` command is not implemented yet.

The intended work is detailed in [PLAN.md](PLAN.md).
