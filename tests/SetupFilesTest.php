<?php
/**
 * Guided setup file and command tests.
 *
 * @package AnyapeWPTestTools
 */
declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise standalone host files in private temporary directories.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- Command behavior is tested through private fake host commands.

require_once dirname( __DIR__ ) . '/bin/file-tools.php';
require_once dirname( __DIR__ ) . '/bin/inspect-setup.php';
require_once dirname( __DIR__ ) . '/bin/update-wp-config.php';
require_once dirname( __DIR__ ) . '/bin/update-root-composer.php';
require_once dirname( __DIR__ ) . '/bin/update-ignore-files.php';
require_once dirname( __DIR__ ) . '/bin/uninstall-project.php';

/** Verify safe guided-setup inspection, file updates, and command behavior. */
final class SetupFilesTest extends WP_UnitTestCase {

	/** Private test directory. */
	private string $temporary_directory;

	/** Create a private directory for each test. */
	public function setUp(): void {
		parent::setUp();
		$this->temporary_directory = sys_get_temp_dir() . '/anyape-wp-test-tools-setup-' . wp_generate_uuid4();
		mkdir( $this->temporary_directory, 0700, true );
	}

	/** Remove only the private test directory. */
	public function tearDown(): void {
		$this->remove_directory( $this->temporary_directory );
		parent::tearDown();
	}

	/** A standard configuration is adapted, backed up, and unchanged on a second run. */
	public function test_standard_wp_config_is_updated_once(): void {
		$path = $this->copy_fixture( 'standard/wp-config.php', 'wp-config.php' );
		$this->assertSame( 'update', anyape_wp_test_tools_inspect_wp_config( (string) file_get_contents( $path ) )['status'] );

		$result = anyape_wp_test_tools_update_wp_config( $path );
		$this->assertTrue( $result['changed'] );
		$this->assertFileExists( $result['backup'] );
		$updated = (string) file_get_contents( $path );
		$this->assertStringContainsString( 'IS_DDEV_PROJECT', $updated );
		$this->assertStringContainsString( 'WP_DEBUG_DISPLAY', $updated );
		$this->assertStringContainsString( 'wp-config-ddev.php', $updated );
		$this->assertSame( 'ready', anyape_wp_test_tools_inspect_wp_config( $updated )['status'] );

		$second = anyape_wp_test_tools_update_wp_config( $path );
		$this->assertFalse( $second['changed'] );
		$this->assertNull( $second['backup'] );
	}

	/** A custom remote PHP error-log path remains in the remote branch. */
	public function test_custom_remote_error_log_is_preserved(): void {
		$path = $this->copy_fixture( 'custom-error-log/wp-config.php', 'wp-config.php' );
		anyape_wp_test_tools_update_wp_config( $path );
		$contents = (string) file_get_contents( $path );
		$this->assertStringContainsString( '/srv/private/php-error.log', $contents );
		$this->assertStringContainsString( "__DIR__ . '/wp-content/debug.log'", $contents );
	}

	/** Invalid generated PHP restores the exact original file. */
	public function test_invalid_php_restores_original_wp_config(): void {
		$path     = $this->copy_fixture( 'invalid/wp-config.txt', 'wp-config.php' );
		$original = (string) file_get_contents( $path );
		try {
			anyape_wp_test_tools_update_wp_config( $path );
			$this->fail( 'Expected invalid generated PHP to fail.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $original, file_get_contents( $path ) );
		}
	}

	/** Inspection reports structure without returning stored remote values. */
	public function test_inspection_does_not_return_remote_values(): void {
		$contents = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/setup/standard/wp-config.php' );
		$report   = wp_json_encode( anyape_wp_test_tools_inspect_wp_config( $contents ) );
		$this->assertStringNotContainsString( 'remote_password_fixture', $report );
		$this->assertStringNotContainsString( 'remote_database', $report );
	}

	/** Root Composer commands are merged without removing site content. */
	public function test_root_composer_is_merged_and_repeatable(): void {
		$root                 = $this->copy_fixture( 'composer/existing.json', 'composer.json' );
		$anyape_wp_test_tools = dirname( __DIR__ ) . '/composer.json';
		$result               = anyape_wp_test_tools_update_root_composer( $root, $anyape_wp_test_tools );
		$this->assertTrue( $result['changed'] );
		$data = json_decode( (string) file_get_contents( $root ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( '^1.0', $data['require']['example/package'] );
		$this->assertSame( 'php site-command.php', $data['scripts']['site:command'] );
		$this->assertSame( 'bash .anyape-wp-test-tools/setup-host.sh', $data['scripts']['setup'] );
		$this->assertFalse( anyape_wp_test_tools_update_root_composer( $root, $anyape_wp_test_tools )['changed'] );
	}

	/** A command-name conflict is refused without replacing site work. */
	public function test_root_composer_command_conflict_is_refused(): void {
		$root     = $this->copy_fixture( 'composer/conflict.json', 'composer.json' );
		$original = (string) file_get_contents( $root );
		try {
			anyape_wp_test_tools_update_root_composer( $root, dirname( __DIR__ ) . '/composer.json' );
			$this->fail( 'Expected a conflicting root command to be refused.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $original, file_get_contents( $root ) );
		}
	}

	/** Git and SFTP ignore entries are added once and the SFTP backup remains private. */
	public function test_ignore_files_are_updated_without_duplicates(): void {
		mkdir( $this->temporary_directory . '/.git' );
		file_put_contents( $this->temporary_directory . '/.gitignore', ".DS_Store\n" );
		mkdir( $this->temporary_directory . '/.vscode' );
		copy( dirname( __DIR__ ) . '/fixtures/setup/sftp/sftp.json', $this->temporary_directory . '/.vscode/sftp.json' );

		$this->assertTrue( anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'git' )['changed'] );
		$sftp_result = anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'sftp' );
		$this->assertTrue( $sftp_result['changed'] );
		$this->assertStringStartsWith( $this->temporary_directory . '/.anyape-wp-test-tools/runtime/setup-backups/', $sftp_result['backup'] );
		$this->assertSame( 0600, fileperms( $sftp_result['backup'] ) & 0777 );
		$this->assertFalse( anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'git' )['changed'] );
		$this->assertFalse( anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'sftp' )['changed'] );
		$this->assertSame( 1, substr_count( (string) file_get_contents( $this->temporary_directory . '/.gitignore' ), '.anyape-wp-test-tools/' ) );
		$sftp = json_decode( (string) file_get_contents( $this->temporary_directory . '/.vscode/sftp.json' ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 1, count( array_keys( $sftp['ignore'], '.anyape-wp-test-tools', true ) ) );
	}

	/** Complete shared-file cleanup restores configuration and returns only live result fields. */
	public function test_project_file_uninstall_is_complete_without_backup(): void {
		$wp_config = $this->copy_fixture( 'standard/wp-config.php', 'wp-config.php' );
		anyape_wp_test_tools_update_wp_config( $wp_config );
		$root_composer = $this->copy_fixture( 'composer/empty.json', 'composer.json' );
		anyape_wp_test_tools_update_root_composer( $root_composer, dirname( __DIR__ ) . '/composer.json' );
		file_put_contents( $this->temporary_directory . '/.gitignore', ".DS_Store\n" );
		mkdir( $this->temporary_directory . '/.vscode' );
		copy( dirname( __DIR__ ) . '/fixtures/setup/sftp/sftp.json', $this->temporary_directory . '/.vscode/sftp.json' );
		anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'git' );
		anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'sftp' );

		$result = anyape_wp_test_tools_uninstall_project_files( $this->temporary_directory, dirname( __DIR__ ) . '/composer.json', false );
		$this->assertSame(
			array(
				'wp_config_restored'    => true,
				'root_composer_removed' => true,
			),
			$result
		);
		$this->assertFileDoesNotExist( $root_composer );
		$this->assertStringNotContainsString( 'IS_DDEV_PROJECT', (string) file_get_contents( $wp_config ) );
		$this->assertSame( ".DS_Store\n", file_get_contents( $this->temporary_directory . '/.gitignore' ) );
		$sftp = json_decode( (string) file_get_contents( $this->temporary_directory . '/.vscode/sftp.json' ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertNotContains( '.anyape-wp-test-tools', $sftp['ignore'] );
		$this->assertNotContains( '.ddev', $sftp['ignore'] );
	}

	/** Setup inspection is the only definition of supported DDEV readiness. */
	public function test_setup_inspection_reports_supported_and_partial_ddev_states(): void {
		$missing_ddev = $this->create_wordpress_project( 'missing-ddev' );
		$this->assertFalse( anyape_wp_test_tools_inspect_setup( $missing_ddev )['ddev_ready'] );

		$missing_include = $this->create_wordpress_project( 'missing-include' );
		$this->write_ddev_config( $missing_include );
		$this->assertFalse( anyape_wp_test_tools_inspect_setup( $missing_include )['ddev_ready'] );

		$supported = $this->create_wordpress_project( 'supported' );
		$this->write_ddev_config( $supported, array(), array( 'git', 'subversion', 'imagemagick' ) );
		file_put_contents( $supported . '/wp-config-ddev.php', "<?php\n" );
		$report = anyape_wp_test_tools_inspect_setup( $supported );
		$this->assertTrue( $report['ddev_ready'] );
		$this->assertSame( 'supported', $report['ddev_project_name'] );
		$this->assertSame( 'wordpress', $report['ddev_project_type'] );
		$this->assertSame( '.', $report['ddev_docroot'] );
		$this->assertSame( 'apache-fpm', $report['ddev_webserver_type'] );
		$this->assertSame( array( 'git', 'subversion', 'imagemagick' ), $report['ddev_packages'] );
		$this->assertTrue( $report['subversion_configured'] );

		foreach (
			array(
				'wrong-type'      => array( 'type' => 'php' ),
				'wrong-docroot'   => array( 'docroot' => 'public' ),
				'wrong-webserver' => array( 'webserver_type' => 'nginx-fpm' ),
			) as $name => $overrides
		) {
			$root = $this->create_wordpress_project( $name );
			$this->write_ddev_config( $root, $overrides );
			file_put_contents( $root . '/wp-config-ddev.php', "<?php\n" );
			$this->assertFalse( anyape_wp_test_tools_inspect_setup( $root )['ddev_ready'], $name );
		}

		$without_subversion = $this->create_wordpress_project( 'without-subversion' );
		$this->write_ddev_config( $without_subversion, array(), array( 'git', 'imagemagick' ) );
		file_put_contents( $without_subversion . '/wp-config-ddev.php', "<?php\n" );
		$this->assertFalse( anyape_wp_test_tools_inspect_setup( $without_subversion )['subversion_configured'] );

		foreach ( array( 'project_root', 'ddev_config_exists', 'ddev_wordpress_exists', 'root_composer_valid', 'project_test_config', 'db_refresh_config' ) as $removed_field ) {
			$this->assertArrayNotHasKey( $removed_field, $report );
		}
	}

	/** Shell inspection output preserves values without producing executable shell text. */
	public function test_setup_shell_report_contains_fixed_null_delimited_fields(): void {
		$root = $this->create_wordpress_project( 'shell-report' );
		$this->write_ddev_config( $root, array( 'name' => 'project with spaces' ), array( 'git', 'subversion' ) );
		file_put_contents( $root . '/wp-config-ddev.php', "<?php\n" );
		$report = anyape_wp_test_tools_inspect_setup( $root );

		ob_start();
		anyape_wp_test_tools_write_setup_shell_report( $report );
		$output = (string) ob_get_clean();
		$parts  = explode( "\0", $output );
		array_pop( $parts );
		$this->assertSame( 0, count( $parts ) % 2 );
		$values = array();
		for ( $index = 0; $index < count( $parts ); $index += 2 ) {
			$values[ $parts[ $index ] ] = $parts[ $index + 1 ];
		}
		$this->assertSame( 'project with spaces', $values['ddev_project_name'] );
		$this->assertSame( 'git,subversion', $values['ddev_packages'] );
		$this->assertSame( '1', $values['subversion_configured'] );
		$this->assertCount( 15, $values );
	}

	/** Shared file operations preserve contents, permissions, symbolic links, and outside paths. */
	public function test_shared_file_operations_are_safe_and_repeatable(): void {
		$json = $this->temporary_directory . '/settings.json';
		file_put_contents( $json, "{\"enabled\":true}\n" );
		$this->assertSame( array( 'enabled' => true ), anyape_wp_test_tools_read_json_object( $json ) );

		$invalid = $this->temporary_directory . '/invalid.json';
		file_put_contents( $invalid, '{' );
		try {
			anyape_wp_test_tools_read_json_object( $invalid );
			$this->fail( 'Expected invalid JSON to fail.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( $invalid, $error->getMessage() );
		}

		$atomic = $this->temporary_directory . '/atomic.txt';
		file_put_contents( $atomic, 'before' );
		chmod( $atomic, 0640 );
		anyape_wp_test_tools_atomic_write( $atomic, 'after', 0640 );
		$this->assertSame( 'after', file_get_contents( $atomic ) );
		$this->assertSame( 0640, fileperms( $atomic ) & 0777 );

		$backup = anyape_wp_test_tools_unused_backup_path( $atomic );
		file_put_contents( $backup, 'backup' );
		$this->assertNotSame( $backup, anyape_wp_test_tools_unused_backup_path( $atomic ) );

		$outside = $this->temporary_directory . '/outside';
		$source  = $this->temporary_directory . '/source';
		$copy    = $this->temporary_directory . '/copy';
		mkdir( $outside );
		mkdir( $source . '/nested', 0750, true );
		file_put_contents( $outside . '/kept.txt', 'outside' );
		file_put_contents( $source . '/nested/file.txt', 'contents' );
		chmod( $source . '/nested/file.txt', 0640 );
		symlink( '../outside', $source . '/outside-link' );

		anyape_wp_test_tools_copy_path( $source, $copy );
		$this->assertTrue( is_link( $copy . '/outside-link' ) );
		$this->assertSame( '../outside', readlink( $copy . '/outside-link' ) );
		$this->assertSame( 0640, fileperms( $copy . '/nested/file.txt' ) & 0777 );
		$this->assertSame( anyape_wp_test_tools_path_digest( $source ), anyape_wp_test_tools_path_digest( $copy ) );

		$inode = fileinode( $copy );
		anyape_wp_test_tools_clear_directory( $copy );
		$this->assertDirectoryExists( $copy );
		$this->assertSame( $inode, fileinode( $copy ) );
		$this->assertSame( array( '.', '..' ), scandir( $copy ) );

		anyape_wp_test_tools_remove_path( $source );
		$this->assertFileExists( $outside . '/kept.txt' );
		$this->assertDirectoryDoesNotExist( $source );
	}

	/** Setup check runs through the real script without changing the private project. */
	public function test_setup_check_is_read_only(): void {
		$root = $this->create_wordpress_project( 'setup-check' );
		$tool = $root . '/.anyape-wp-test-tools';
		$this->copy_tool_files(
			$tool,
			array(
				'setup-host.sh',
				'logging-host.sh',
				'composer.json',
				'bin/file-tools.php',
				'bin/inspect-setup.php',
				'bin/update-wp-config.php',
				'bin/update-root-composer.php',
				'bin/update-ignore-files.php',
			)
		);
		$this->write_ddev_config( $root, array( 'name' => 'setup-check' ), array( 'subversion' ) );
		file_put_contents( $root . '/wp-config-ddev.php', "<?php\n" );

		$fake_directory = $this->temporary_directory . '/setup-check-fakes';
		$command_log    = $this->temporary_directory . '/setup-check-commands.log';
		mkdir( $fake_directory );
		foreach ( array( 'ddev', 'composer', 'node', 'npm', 'git' ) as $command ) {
			$this->create_fake_command(
				$fake_directory,
				$command,
				'printf "%s %s\\n" "$(basename "$0")" "$*" >> "$FAKE_COMMAND_LOG"' . "\nexit 0"
			);
		}

		$before = $this->directory_snapshot( $root );
		$result = $this->run_command(
			array( 'bash', $tool . '/setup-host.sh', '--check' ),
			$root,
			array(
				'PATH'             => $fake_directory . PATH_SEPARATOR . (string) getenv( 'PATH' ),
				'FAKE_COMMAND_LOG' => $command_log,
			)
		);
		$after = $this->directory_snapshot( $root );

		$this->assertSame( 0, $result['status'], $result['stderr'] . $result['stdout'] );
		$this->assertSame( $before, $after );
		$this->assertSame( '', is_file( $command_log ) ? (string) file_get_contents( $command_log ) : '' );
	}

	/** An exact setup database-pull receipt avoids every remote connection. */
	public function test_database_pull_reuses_an_exact_setup_receipt(): void {
		$result = $this->run_database_pull_case( 'exact', array(), false );
		$this->assertSame( 0, $result['status'], $result['stderr'] . $result['stdout'] );
		$this->assertStringNotContainsString( "\nssh ", "\n" . $result['commands'] );
	}

	/**
	 * A changed receipt input performs a fresh remote export.
	 *
	 * @dataProvider database_pull_mismatch_provider
	 */
	public function test_database_pull_does_not_reuse_a_changed_setup_receipt( string $name, array $changes, bool $first_site_url_mismatch ): void {
		$result = $this->run_database_pull_case( $name, $changes, $first_site_url_mismatch );
		$this->assertSame( 0, $result['status'], $result['stderr'] . $result['stdout'] );
		$this->assertStringContainsString( "\nssh ", "\n" . $result['commands'] );
	}

	/** Database receipt mismatch cases. */
	public function database_pull_mismatch_provider(): array {
		return array(
			'ssh alias'        => array( 'ssh-alias', array( 'ssh_alias' => 'changed-remote' ), false ),
			'remote path'      => array( 'remote-path', array( 'remote_path' => '/srv/other-site' ), false ),
			'remote URL'       => array( 'remote-url', array( 'remote_url' => 'https://other-remote.example' ), false ),
			'local URL'        => array( 'local-url', array( 'local_url' => 'https://local.example/other' ), false ),
			'current site URL' => array( 'current-site-url', array(), true ),
		);
	}

	/** Uninstall validates shared files before DDEV deletion and removes the toolkit last. */
	public function test_uninstall_action_order_remains_protected(): void {
		$script         = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall-host.sh' );
		$preflight      = strpos( $script, 'bin/uninstall-project.php" --check' );
		$ddev_delete    = strpos( $script, 'ddev delete -Oy --skip-hooks' );
		$shared_cleanup = strpos( $script, 'bin/uninstall-project.php" "$PROJECT_ROOT"' );
		$self_delete    = strpos( $script, 'rm -rf -- "$ANYAPE_WP_TEST_TOOLS_DIR"' );

		$this->assertNotFalse( $preflight );
		$this->assertNotFalse( $ddev_delete );
		$this->assertNotFalse( $shared_cleanup );
		$this->assertNotFalse( $self_delete );
		$this->assertLessThan( $ddev_delete, $preflight );
		$this->assertLessThan( $self_delete, $shared_cleanup );
	}

	/** Run one complete database-pull receipt case with private fake host commands. */
	private function run_database_pull_case( string $name, array $changes, bool $first_site_url_mismatch ): array {
		$root = $this->temporary_directory . '/database-' . $name;
		$tool = $root . '/.anyape-wp-test-tools';
		mkdir( $root, 0700, true );
		$this->copy_tool_files(
			$tool,
			array(
				'database-host.sh',
				'logging-host.sh',
				'bin/database-refresh-config.php',
				'bin/e2e-ddev-url.php',
			)
		);

		$base = array(
			'ssh_alias'   => 'remote-site',
			'remote_path' => '/srv/site',
			'remote_url'  => 'https://remote.example',
			'local_url'   => 'https://local.example',
		);
		$config = array_replace( $base, $changes );
		file_put_contents(
			$tool . '/db-refresh-local.php',
			"<?php\nreturn " . var_export( $config, true ) . ";\n"
		);

		$run_id      = '20260806T120000Z-123';
		$receipt_dir = $tool . '/runtime/setup-pull-receipts';
		mkdir( $receipt_dir, 0700, true );
		file_put_contents(
			$receipt_dir . '/' . $run_id,
			implode( "\n", array_values( $base ) ) . "\n"
		);

		$fake_directory = $this->temporary_directory . '/database-fakes-' . $name;
		$command_log    = $this->temporary_directory . '/database-commands-' . $name . '.log';
		$count_file     = $this->temporary_directory . '/database-site-url-count-' . $name;
		mkdir( $fake_directory );

		$this->create_fake_command(
			$fake_directory,
			'ddev',
			<<<'BASH'
printf 'ddev %s\n' "$*" >> "$FAKE_COMMAND_LOG"
if [[ "${1:-}" == "describe" ]]; then
	printf '{"raw":{"status":"running","approot":"%s","primary_url":"%s"}}\n' "$FAKE_PROJECT_ROOT" "$FAKE_LOCAL_URL"
	exit 0
fi
if [[ "${1:-}" == "wp" && "$*" == *"option get siteurl"* ]]; then
	count=0
	if [[ -f "$FAKE_SITE_URL_COUNT_FILE" ]]; then
		count="$(cat "$FAKE_SITE_URL_COUNT_FILE")"
	fi
	count=$((count + 1))
	printf '%s' "$count" > "$FAKE_SITE_URL_COUNT_FILE"
	if [[ "${FAKE_FIRST_SITE_URL_MISMATCH:-0}" == "1" && "$count" == "1" ]]; then
		printf '%s\n' 'https://different-local.example'
	else
		printf '%s\n' "$FAKE_LOCAL_URL"
	fi
	exit 0
fi
exit 0
BASH
		);
		$this->create_fake_command(
			$fake_directory,
			'php',
			<<<'BASH'
printf 'php %s\n' "$*" >> "$FAKE_COMMAND_LOG"
if [[ "${1:-}" == "-r" && "${2:-}" == *'parse_url($argv[1]'* ]]; then
	exit 0
fi
exec "$REAL_PHP" "$@"
BASH
		);
		$this->create_fake_command(
			$fake_directory,
			'gzip',
			<<<'BASH'
printf 'gzip %s\n' "$*" >> "$FAKE_COMMAND_LOG"
exec "$REAL_GZIP" "$@"
BASH
		);
		$this->create_fake_command(
			$fake_directory,
			'ssh',
			<<<'BASH'
printf 'ssh %s\n' "$*" >> "$FAKE_COMMAND_LOG"
printf 'SELECT 1;\n' | "$REAL_GZIP" -c
BASH
		);

		$result = $this->run_command(
			array( 'bash', $tool . '/database-host.sh', 'pull', '--yes' ),
			$root,
			array(
				'PATH'                              => $fake_directory . PATH_SEPARATOR . (string) getenv( 'PATH' ),
				'FAKE_COMMAND_LOG'                  => $command_log,
				'FAKE_PROJECT_ROOT'                 => $root,
				'FAKE_LOCAL_URL'                    => $config['local_url'],
				'FAKE_SITE_URL_COUNT_FILE'          => $count_file,
				'FAKE_FIRST_SITE_URL_MISMATCH'      => $first_site_url_mismatch ? '1' : '0',
				'REAL_PHP'                          => PHP_BINARY,
				'REAL_GZIP'                         => $this->required_command_path( 'gzip' ),
				'ANYAPE_WP_TEST_TOOLS_SETUP_RUN_ID' => $run_id,
			)
		);
		$result['commands'] = is_file( $command_log ) ? (string) file_get_contents( $command_log ) : '';
		return $result;
	}

	/** Create a complete private WordPress project. */
	private function create_wordpress_project( string $name ): string {
		$root = $this->temporary_directory . '/' . $name;
		mkdir( $root . '/wp-admin', 0700, true );
		mkdir( $root . '/wp-content', 0700, true );
		mkdir( $root . '/wp-includes', 0700, true );
		copy( dirname( __DIR__ ) . '/fixtures/setup/standard/wp-config.php', $root . '/wp-config.php' );
		return $root;
	}

	/** Write a private DDEV configuration with explicit supported defaults. */
	private function write_ddev_config( string $root, array $overrides = array(), array $packages = array() ): void {
		$values = array_replace(
			array(
				'name'           => basename( $root ),
				'type'           => 'wordpress',
				'docroot'        => '.',
				'webserver_type' => 'apache-fpm',
			),
			$overrides
		);
		mkdir( $root . '/.ddev', 0700, true );
		$contents = '';
		foreach ( $values as $key => $value ) {
			$contents .= $key . ': ' . $value . "\n";
		}
		$contents .= 'webimage_extra_packages: [' . implode( ', ', $packages ) . "]\n";
		file_put_contents( $root . '/.ddev/config.yaml', $contents );
	}

	/** Copy selected repository files into a private toolkit directory. */
	private function copy_tool_files( string $tool, array $paths ): void {
		foreach ( $paths as $relative ) {
			$source      = dirname( __DIR__ ) . '/' . $relative;
			$destination = $tool . '/' . $relative;
			$directory   = dirname( $destination );
			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0700, true );
			}
			copy( $source, $destination );
			chmod( $destination, fileperms( $source ) & 0777 );
		}
	}

	/** Create one private executable fake host command. */
	private function create_fake_command( string $directory, string $name, string $body ): void {
		$path = $directory . '/' . $name;
		file_put_contents( $path, "#!/usr/bin/env bash\nset -euo pipefail\n" . $body . "\n" );
		chmod( $path, 0755 );
	}

	/** Run a private command and capture its result. */
	private function run_command( array $command, string $working_directory, array $environment ): array {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$current_environment = getenv();
		$process             = proc_open(
			$command,
			$descriptors,
			$pipes,
			$working_directory,
			array_replace( is_array( $current_environment ) ? $current_environment : array(), $environment )
		);
		if ( ! is_resource( $process ) ) {
			$this->fail( 'Could not start private command.' );
		}
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		return array(
			'status' => $status,
			'stdout' => (string) $stdout,
			'stderr' => (string) $stderr,
		);
	}

	/** Resolve one required real host command. */
	private function required_command_path( string $command ): string {
		$output = array();
		$status = 0;
		exec( 'command -v ' . escapeshellarg( $command ), $output, $status );
		$this->assertSame( 0, $status, 'Required test command is unavailable: ' . $command );
		$this->assertNotEmpty( $output );
		return (string) $output[0];
	}

	/** Return a repeatable snapshot without using the production file helper. */
	private function directory_snapshot( string $root ): array {
		$entries  = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			$relative = substr( $item->getPathname(), strlen( $root ) + 1 );
			if ( $item->isLink() ) {
				$entries[] = 'l ' . $relative . ' ' . readlink( $item->getPathname() );
			} elseif ( $item->isDir() ) {
				$entries[] = 'd ' . $relative;
			} else {
				$entries[] = 'f ' . $relative . ' ' . hash_file( 'sha256', $item->getPathname() );
			}
		}
		sort( $entries, SORT_STRING );
		return $entries;
	}

	/** Copy one fixture into the private test directory. */
	private function copy_fixture( string $fixture, string $destination ): string {
		$path = $this->temporary_directory . '/' . $destination;
		copy( dirname( __DIR__ ) . '/fixtures/setup/' . $fixture, $path );
		return $path;
	}

	/** Recursively remove the known private test directory without following links. */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $directory );
	}
}
