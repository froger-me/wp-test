# Setup

This guide configures an existing complete WordPress file tree with DDEV and installs `froger-me/wp-test` as `.test-tools`.

The intended layout is:

```text
wordpress-root/
├── .ddev/
├── .test-tools/
├── .wp-test.php                 # optional
├── composer.json
├── wp-admin/
├── wp-content/
├── wp-includes/
├── wp-config.php
└── wp-config-ddev.php
```

The existing WordPress files remain the files you edit and deploy. DDEV supplies the local web server, PHP runtime, database, WP-CLI, and containerized test execution.

All toolkit Composer commands work from either the WordPress root or the `.test-tools` directory. The root `composer.json` mirrors the command names for convenience; `.test-tools/composer.json` contains the toolkit's own command mappings.

## 1. Install host prerequisites

On macOS:

```bash
brew install --cask docker-desktop
brew install ddev/ddev/ddev
mkcert -install
```

Open Docker Desktop and wait for its engine to run.

Host Composer provides the routine command surface from both supported directories:

```bash
docker info --format 'Docker engine: {{.ServerVersion}}'
ddev version
composer --version
```

## 2. Configure the existing WordPress tree

Run from the directory containing `wp-admin`, `wp-content`, `wp-includes`, and `wp-config.php`:

```bash
ddev config \
  --project-name=your-project-name \
  --project-type=wordpress \
  --docroot=. \
  --webserver-type=apache-fpm
```

DDEV creates `.ddev/config.yaml` and normally creates `wp-config-ddev.php` for a user-managed WordPress configuration.

Pin project compatibility versions only when needed:

```bash
ddev config --php-version=8.4 --database=mariadb:11.8
```

## 3. Adapt `wp-config.php`

Before the database constants:

```php
$is_ddev = getenv('IS_DDEV_PROJECT') === 'true';
```

Use remote credentials only outside DDEV:

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

Define debug behavior without redefining DDEV-managed constants:

```php
defined('WP_DEBUG') || define('WP_DEBUG', true);
defined('WP_DEBUG_LOG') || define('WP_DEBUG_LOG', $is_ddev);
defined('WP_DEBUG_DISPLAY') || define('WP_DEBUG_DISPLAY', false);
```

Make any server-specific PHP error-log path conditional:

```php
ini_set('log_errors', '1');

if ($is_ddev) {
	ini_set('error_log', __DIR__ . '/wp-content/debug.log');
} else {
	ini_set('error_log', '/remote/path/to/error_log');
}
```

At the bottom, define `ABSPATH` before including DDEV's generated settings:

```php
if (! defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

if ($is_ddev) {
	require_once __DIR__ . '/wp-config-ddev.php';
}

require_once ABSPATH . 'wp-settings.php';
```

Validate:

```bash
php -l wp-config.php
```

## 4. Exclude local-only files from deployment

For a VS Code SFTP `ignore` array, include:

```json
".ddev",
".test-tools",
".wp-test.php",
"wp-config-ddev.php",
"wp-config.php.before-ddev"
```

Validate the JSON:

```bash
python3 -m json.tool .vscode/sftp.json >/dev/null
```

For a parent WordPress Git repository using an ordinary nested clone:

```gitignore
.test-tools/
.wp-test.php
wp-config-ddev.php
wp-config.php.before-ddev
```

Do not ignore `.test-tools` when intentionally using it as a Git submodule. `.ddev/` may be committed to share the local environment, but should remain excluded from SFTP deployment.

## 5. Start DDEV

```bash
ddev start
```

Routine test commands require DDEV to be running and never start it implicitly.

## 6. Populate the working database

Choose one option.

### Option A: clean installation

```bash
ddev wp core install \
  --url="https://your-project-name.ddev.site" \
  --title="Local WordPress Development" \
  --admin_user="admin" \
  --admin_email="admin@example.test" \
  --prompt=admin_password
```

### Option B: import an existing database

Export through an existing SSH alias:

```bash
ssh your-ssh-alias '
  set -o pipefail
  cd /remote/path/to/wordpress
  wp db export - --add-drop-table --max_allowed_packet=1G | gzip -c
' > /tmp/wordpress-remote.sql.gz
```

Verify and import:

```bash
gzip -t /tmp/wordpress-remote.sql.gz
ddev import-db --file=/tmp/wordpress-remote.sql.gz
```

Replace URLs with serialized-data support:

```bash
ddev wp search-replace \
  'https://remote.example.test' \
  'https://your-project-name.ddev.site' \
  --all-tables \
  --skip-columns=guid \
  --precise
```

Create a working restore point:

```bash
ddev snapshot --name=initial-working-local
```

## 7. Create the disposable test database

```bash
ddev mysql -uroot -proot -e "
CREATE DATABASE IF NOT EXISTS wp_tests
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON wp_tests.* TO 'db'@'%';
FLUSH PRIVILEGES;
"
```

Verify:

```bash
ddev mysql -N -e "
SELECT SCHEMA_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'wp_tests';
"
```

The required names are fixed:

- working database: `db`
- PHPUnit database: `wp_tests`
- database host: `db`
- PHPUnit prefix: `wptests_`

The safety preflight refuses alternatives.

## 8. Add Subversion to DDEV

The matching WordPress PHPUnit library is retrieved from the official WordPress Subversion repository:

```bash
ddev config --webimage-extra-packages=subversion
ddev restart
ddev exec svn --version --quiet
```

This is a one-time DDEV image configuration. Routine tests do not rebuild the image.

## 9. Install `.test-tools`

```bash
git clone https://github.com/froger-me/wp-test.git .test-tools
ddev exec --dir=/var/www/html/.test-tools composer install
```

The cloned `.test-tools/composer.json` already exposes every toolkit command. No root Composer configuration is required when commands will only be run from inside `.test-tools`.

Ensure the existing shell entry points remain executable:

```bash
chmod +x \
  .test-tools/run-tests-host.sh \
  .test-tools/run-tests.sh \
  .test-tools/sync-wordpress-tests.sh
```

`doctor-host.sh` is invoked through `bash` and does not require an executable bit.

## 10. Mirror the commands at the WordPress root

To use the same commands without changing into `.test-tools`, create or merge these entries into the WordPress root `composer.json`:

```json
{
    "name": "local/wordpress-development-site",
    "private": true,
    "config": {
        "process-timeout": 0
    },
    "scripts": {
        "doctor": "bash .test-tools/doctor-host.sh",
        "test": "bash .test-tools/run-tests-host.sh",
        "test:harness": "bash .test-tools/run-tests-host.sh --profile=harness",
        "test:plugin": "bash .test-tools/run-tests-host.sh --profile=plugin",
        "test:theme": "bash .test-tools/run-tests-host.sh --profile=theme",
        "test:multisite": "bash .test-tools/run-tests-host.sh --profile=multisite",
        "test:destructive": "bash .test-tools/run-tests-host.sh --include-destructive --group destructive",
        "test:coverage": "bash .test-tools/run-tests-host.sh --coverage",
        "test:junit": "bash .test-tools/run-tests-host.sh --junit"
    }
}
```

Do not replace unrelated existing Composer configuration. Keep the root command names and arguments aligned with `.test-tools/composer.json`.

Both Composer files invoke the same host wrappers. The wrappers resolve the WordPress root from their own installation path before calling DDEV, so behavior does not depend on whether Composer was started in the WordPress root or `.test-tools`.

## 11. Run diagnostics and harness tests

From the WordPress root:

```bash
composer doctor
composer test:harness
```

Or from inside the toolkit:

```bash
cd .test-tools
composer doctor
composer test:harness
```

The results and side effects are identical. Neither form requires `ddev sh`, and neither form starts or rebuilds DDEV.

`composer doctor` is read-only. It verifies:

- DDEV is already running;
- the expected WordPress root exists;
- DDEV exposes working database `db` on host `db`;
- `wp_tests` exists;
- the test database and prefix are fixed and distinct from the working database;
- required commands and PHP extensions are available;
- generated directories are writable;
- Composer dependencies are installed;
- the installed WordPress and DDEV PHP versions are covered by the compatibility policy; and
- an existing generated `wp-tests-config.php` still targets the safe database.

`composer test:harness` additionally proves lifecycle activation, custom tables, options, roles, cron, REST authorization, uploads, mail capture, HTTP isolation, extension discovery, and helper cleanup using toolkit fixture extensions.

## 12. Run the working-site integration profile

From either supported directory:

```bash
composer test
```

The command reads the working site's active ordinary plugins, active theme, and parent theme. It does not modify the working site's active-plugin option.

Conventional extension paths are discovered automatically:

```text
wp-content/plugins/<slug>/tests/phpunit/**/*Test.php
wp-content/plugins/<slug>/tests/phpunit/bootstrap.php

wp-content/themes/<slug>/tests/phpunit/**/*Test.php
wp-content/themes/<slug>/tests/phpunit/bootstrap.php
```

Extension bootstraps run before WordPress boots. Use them for constants, local Composer autoloaders, and `tests_add_filter()` registration.

## 13. Add optional project configuration

When the active-site defaults need adjustment, run this from the WordPress root:

```bash
cp .test-tools/wp-test.config.example.php .wp-test.php
```

Example:

```php
<?php

declare(strict_types=1);

return [
	'include_plugins' => [
		'plugin-needed-only-for-tests',
	],
	'exclude_plugins' => [
		'plugin-that-cannot-run-in-tests',
	],
	'include_themes' => [],
	'exclude_themes' => [],
	'plugin_dependencies' => [
		'my-plugin' => ['shared-library-plugin'],
	],
	'theme_dependencies' => [
		'my-theme' => ['theme-support-plugin'],
	],
	'bootstrap' => 'tests/phpunit/site-bootstrap.php',
];
```

The optional site bootstrap is loaded at the same pre-WordPress stage as extension bootstraps.

## 14. Focused and specialist runs

These commands work from the WordPress root or `.test-tools`:

```bash
composer test:plugin -- my-plugin
composer test:theme -- my-theme
composer test:multisite
composer test:destructive
```

Pass PHPUnit options after the slug where applicable:

```bash
composer test:plugin -- my-plugin --filter UpgradeTest
composer test -- --group rest
```

Destructive tests are excluded from all normal runs. Tag uninstall or deliberately destructive tests:

```php
/**
 * @group destructive
 */
public function test_uninstall_cleanup(): void
{
	// ...
}
```

## 15. Use the helper surface

Plugin and theme tests may extend:

```php
use WpTest\IntegrationTestCase;

final class SettingsTest extends IntegrationTestCase
{
	public function test_settings_and_cron(): void
	{
		$this->setTrackedOption('my_plugin_setting', 'value');
		$this->assertCronEventScheduled('my_plugin_cron');
	}
}
```

HTTP mocks:

```php
use WpTest\HttpMock;

HttpMock::queue(
	'https://service.example.test/api',
	HttpMock::response('{"ok":true}'),
	HttpMock::timeout()
);
```

Unmocked requests fail with `unexpected_http_request`.

## 16. Reporting

JUnit:

```bash
composer test:junit
```

Output:

```text
.test-tools/runtime/junit.xml
```

Coverage:

```bash
ddev xdebug on
composer test:coverage
ddev xdebug off
```

Output:

```text
.test-tools/coverage/
```

Coverage is never enabled in the default path.

## 17. Generated paths

The toolkit ignores:

```text
.test-tools/.wordpress-test-version
.test-tools/active-plugins.json
.test-tools/coverage/
.test-tools/runtime/
.test-tools/vendor/
.test-tools/wordpress/
.test-tools/wordpress-tests-lib/
```

`runtime/` contains the generated manifest, PHPUnit configuration, working-site selection snapshots, isolated upload directory, and linked extension overlay. It is rebuilt for each run.

## 18. WordPress and toolkit updates

After a WordPress update, run from either supported directory:

```bash
composer doctor
composer test
```

The next test run synchronizes the clean core and WordPress test library to the detected WordPress version. An unknown WordPress branch fails with an explicit compatibility message.

Update the toolkit from the WordPress root:

```bash
git -C .test-tools pull --ff-only
ddev exec --dir=/var/www/html/.test-tools composer install
composer doctor
composer test:harness
composer test
```

Or run the post-update checks inside `.test-tools`:

```bash
cd .test-tools
composer doctor
composer test:harness
composer test
```

## Troubleshooting

### DDEV is stopped

`composer doctor` and all test commands fail from both supported directories. Start DDEV explicitly from the WordPress root:

```bash
ddev start
```

### Command exists in only one directory

Update `.test-tools`, then compare the root `scripts` entries from Step 10 with `.test-tools/composer.json`. Public command names must remain mirrored.

### `wp_tests` is missing

Repeat Step 7. Tests never create or substitute a database automatically.

### WordPress/PHP compatibility failure

Select a DDEV PHP version supported by the installed WordPress branch, then explicitly restart DDEV:

```bash
ddev config --php-version=8.4
ddev restart
composer doctor
```

### Extension bootstrap failure

The error identifies the extension slug and bootstrap path. Remember that the file runs before full WordPress bootstrap.

### Activation failure

The error identifies the plugin path and includes captured activation output where WordPress provides it.

### Coverage unavailable

Enable Xdebug or install/enable PCOV explicitly. The test command does not change DDEV configuration.

### Mutagen conflicts

```bash
ddev utility mutagen-diagnose
```

Remove `.DS_Store` conflicts and reset Mutagen only when diagnostics call for it:

```bash
find . -name '.DS_Store' -type f -delete
ddev mutagen reset
ddev restart
```
