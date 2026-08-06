<?php
/**
 * Guided setup file tests.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise standalone host file writers in a private temporary directory.

require_once dirname( __DIR__ ) . '/bin/inspect-setup.php';
require_once dirname( __DIR__ ) . '/bin/update-wp-config.php';
require_once dirname( __DIR__ ) . '/bin/update-root-composer.php';
require_once dirname( __DIR__ ) . '/bin/update-ignore-files.php';
require_once dirname( __DIR__ ) . '/bin/uninstall-project.php';

/** Verify safe guided-setup file inspection and updates. */
final class SetupFilesTest extends WP_UnitTestCase {

	/**
	 * Private test directory.
	 *
	 * @var string
	 */
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
		$path    = $this->copy_fixture( 'custom-error-log/wp-config.php', 'wp-config.php' );
		$updated = anyape_wp_test_tools_update_wp_config( $path );
		$this->assertTrue( $updated['changed'] );
		$contents = (string) file_get_contents( $path );
		$this->assertStringContainsString( '/srv/private/php-error.log', $contents );
		$this->assertStringContainsString( "__DIR__ . '/wp-content/debug.log'", $contents );
	}

	/** Uninstall reconstructs direct remote settings without reading a backup. */
	public function test_wp_config_uninstall_reverses_guided_setup_without_backup(): void {
		$path = $this->copy_fixture( 'standard/wp-config.php', 'wp-config.php' );
		anyape_wp_test_tools_update_wp_config( $path );
		$restored = anyape_wp_test_tools_uninstall_wp_config_contents( (string) file_get_contents( $path ) );
		$this->assertStringContainsString( "define( 'DB_NAME', 'remote_database' );", $restored );
		$this->assertStringContainsString( "define( 'WP_DEBUG', false );", $restored );
		$this->assertStringNotContainsString( 'IS_DDEV_PROJECT', $restored );
		$this->assertStringNotContainsString( 'wp-config-ddev.php', $restored );
	}

	/** Uninstall keeps an existing remote PHP error-log destination. */
	public function test_wp_config_uninstall_preserves_remote_error_log(): void {
		$path = $this->copy_fixture( 'custom-error-log/wp-config.php', 'wp-config.php' );
		anyape_wp_test_tools_update_wp_config( $path );
		$restored = anyape_wp_test_tools_uninstall_wp_config_contents( (string) file_get_contents( $path ) );
		$this->assertStringContainsString( '/srv/private/php-error.log', $restored );
		$this->assertStringNotContainsString( '/wp-content/debug.log', $restored );
	}

	/** Existing supported configuration is not rewritten. */
	public function test_existing_supported_wp_config_is_unchanged(): void {
		$path     = $this->copy_fixture( 'already-configured/wp-config.php', 'wp-config.php' );
		$original = (string) file_get_contents( $path );
		$result   = anyape_wp_test_tools_update_wp_config( $path );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( $original, file_get_contents( $path ) );
	}

	/** An unclear configuration is refused without a write. */
	public function test_unusual_wp_config_is_refused_without_changes(): void {
		$path     = $this->copy_fixture( 'unusual/wp-config.php', 'wp-config.php' );
		$original = (string) file_get_contents( $path );
		try {
			anyape_wp_test_tools_update_wp_config( $path );
			$this->fail( 'Expected the repeated database definition to be refused.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'Repeated configuration definitions', $error->getMessage() );
		}
		$this->assertSame( $original, file_get_contents( $path ) );
	}

	/** Invalid generated PHP restores the exact original file. */
	public function test_invalid_php_restores_original_wp_config(): void {
		$path     = $this->copy_fixture( 'invalid/wp-config.txt', 'wp-config.php' );
		$original = (string) file_get_contents( $path );
		try {
			anyape_wp_test_tools_update_wp_config( $path );
			$this->fail( 'Expected invalid PHP to fail validation.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'original wp-config.php was restored', $error->getMessage() );
		}
		$this->assertSame( $original, file_get_contents( $path ) );
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

	/** An empty root Composer file receives the complete public command list. */
	public function test_empty_root_composer_receives_commands(): void {
		$root = $this->copy_fixture( 'composer/empty.json', 'composer.json' );
		anyape_wp_test_tools_update_root_composer( $root, dirname( __DIR__ ) . '/composer.json' );
		$data = json_decode( (string) file_get_contents( $root ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 0, $data['config']['process-timeout'] );
		$this->assertArrayHasKey( 'setup', $data['scripts'] );
		$this->assertArrayHasKey( 'anyape-wp-test-tools:uninstall', $data['scripts'] );
		$this->assertArrayHasKey( 'test:e2e', $data['scripts'] );
	}

	/** Uninstall removes owned Composer commands and preserves site commands. */
	public function test_composer_uninstall_preserves_unrelated_project_content(): void {
		$root = $this->copy_fixture( 'composer/existing.json', 'composer.json' );
		$tool = dirname( __DIR__ ) . '/composer.json';
		anyape_wp_test_tools_update_root_composer( $root, $tool );
		$commands = anyape_wp_test_tools_uninstall_composer_commands( $tool );
		$restored = anyape_wp_test_tools_uninstall_root_composer_contents( (string) file_get_contents( $root ), $commands );
		$this->assertNotNull( $restored );
		$data = json_decode( (string) $restored, true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 'php site-command.php', $data['scripts']['site:command'] );
		$this->assertArrayNotHasKey( 'setup', $data['scripts'] );
		$this->assertArrayNotHasKey( 'anyape-wp-test-tools:uninstall', $data['scripts'] );
	}

	/** Complete shared-file cleanup restores configuration and removes only owned entries. */
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
		$this->assertTrue( $result['wp_config_restored'] );
		$this->assertTrue( $result['root_composer_removed'] );
		$this->assertFileDoesNotExist( $root_composer );
		$this->assertStringNotContainsString( 'IS_DDEV_PROJECT', (string) file_get_contents( $wp_config ) );
		$this->assertSame( ".DS_Store\n", file_get_contents( $this->temporary_directory . '/.gitignore' ) );
		$sftp = json_decode( (string) file_get_contents( $this->temporary_directory . '/.vscode/sftp.json' ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertNotContains( '.anyape-wp-test-tools', $sftp['ignore'] );
		$this->assertNotContains( '.ddev', $sftp['ignore'] );
	}

	/** Uninstall checks for uncommitted source changes before deleting DDEV or files. */
	public function test_uninstall_refuses_uncommitted_source_changes_before_deletion(): void {
		$script       = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall-host.sh' );
		$change_check = strpos( $script, 'status --porcelain --untracked-files=all' );
		$ddev_delete  = strpos( $script, 'ddev delete -Oy --skip-hooks' );
		$self_delete  = strpos( $script, 'rm -rf -- "$ANYAPE_WP_TEST_TOOLS_DIR"' );

		$this->assertNotFalse( $change_check );
		$this->assertNotFalse( $ddev_delete );
		$this->assertNotFalse( $self_delete );
		$this->assertLessThan( $ddev_delete, $change_check );
		$this->assertLessThan( $self_delete, $change_check );
	}

	/** Guided setup creates the required DDEV settings before adapting WordPress. */
	public function test_guided_setup_owns_the_initial_ddev_configuration(): void {
		$script          = (string) file_get_contents( dirname( __DIR__ ) . '/setup-host.sh' );
		$ddev_config     = strpos( $script, 'anyape_wp_test_tools_run_logged "Creating the local DDEV settings..." ddev config' );
		$wp_config       = strpos( $script, 'bin/update-wp-config.php' );
		$required_values = array(
			'--project-name="$DDEV_PROJECT_NAME"',
			'--project-type=wordpress',
			'--docroot=.',
			'--webserver-type=apache-fpm',
		);

		$this->assertNotFalse( $ddev_config );
		$this->assertNotFalse( $wp_config );
		$this->assertLessThan( $wp_config, $ddev_config );
		foreach ( $required_values as $required_value ) {
			$this->assertStringContainsString( $required_value, $script );
		}
		$this->assertStringNotContainsString( "Run 'ddev config", $script );
	}

	/** Required Subversion setup distinguishes a first start from a running container rebuild. */
	public function test_subversion_setup_explains_when_a_rebuild_is_needed(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/setup-host.sh' );

		$this->assertStringContainsString( 'Required: the matching WordPress PHP test files', $script );
		$this->assertStringContainsString( 'DDEV_WAS_RUNNING', $script );
		$this->assertStringContainsString( 'SUBVERSION_SETTINGS_ADDED', $script );
		$this->assertStringContainsString( 'add Subversion before building and starting the web container', $script );
		$this->assertStringContainsString( 'current web container was built without Subversion', $script );
		$this->assertStringNotContainsString( 'SUBVERSION_CONFIRMATION', $script );
		$this->assertStringNotContainsString( 'Add required Subversion support before starting DDEV?', $script );
		$this->assertStringNotContainsString( 'Rebuild the DDEV web container with Subversion and restart now?', $script );
	}

	/** A successful setup database pull is reused if another child requests it. */
	public function test_guided_setup_database_pull_is_recorded_and_reused(): void {
		$setup_script    = (string) file_get_contents( dirname( __DIR__ ) . '/setup-host.sh' );
		$database_script = (string) file_get_contents( dirname( __DIR__ ) . '/database-host.sh' );

		$this->assertStringContainsString( 'ANYAPE_WP_TEST_TOOLS_SETUP_RUN_ID', $setup_script );
		$this->assertStringContainsString( 'setup-pull-receipts', $database_script );
		$this->assertStringContainsString( 'Reusing that imported database without contacting the remote site again.', $database_script );
		$this->assertLessThan(
			strpos( $database_script, 'confirm "pull $SSH_ALIAS"' ),
			strpos( $database_script, 'if [[ -f "$SETUP_PULL_RECEIPT" ]]' )
		);
	}

	/** Browser tests copy their temporary login file from DDEV before Playwright starts. */
	public function test_browser_login_file_does_not_depend_on_file_synchronization_timing(): void {
		$script         = (string) file_get_contents( dirname( __DIR__ ) . '/run-e2e-host.sh' );
		$prepare        = strpos( $script, 'bin/prepare-e2e.php' );
		$copy_from_ddev = strpos( $script, 'ddev exec --raw cat "$CONTAINER_USERS_FILE"' );
		$start_browser  = strpos( $script, 'npm --prefix "$ANYAPE_WP_TEST_TOOLS_DIR" run test:e2e' );
		$validate_token = strpos( $script, 'DDEV returned invalid browser-test login details.' );

		$this->assertNotFalse( $prepare );
		$this->assertNotFalse( $copy_from_ddev );
		$this->assertNotFalse( $start_browser );
		$this->assertNotFalse( $validate_token );
		$this->assertLessThan( $copy_from_ddev, $prepare );
		$this->assertLessThan( $start_browser, $copy_from_ddev );
	}

	/** Failed commands report their plain-text log path only once. */
	public function test_saved_logs_are_plain_text_and_reported_once(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/logging-host.sh' );

		$this->assertStringContainsString( 'anyape_wp_test_tools_strip_terminal_decoration', $script );
		$this->assertStringContainsString( "tr '\\r' '\\n'", $script );
		$this->assertStringContainsString( 'tee "$raw_output"', $script );
		$this->assertStringNotContainsString( 'tee -a "$ANYAPE_WP_TEST_TOOLS_LOG_FILE"', $script );
		$this->assertStringContainsString( 'ANYAPE_WP_TEST_TOOLS_LOG_REPORTED=1', $script );
		$this->assertStringContainsString( '"${ANYAPE_WP_TEST_TOOLS_LOG_OWNER:-0}" == "1"', $script );
	}

	/** A command-name conflict is refused without replacing site work. */
	public function test_root_composer_command_conflict_is_refused(): void {
		$root     = $this->copy_fixture( 'composer/conflict.json', 'composer.json' );
		$original = (string) file_get_contents( $root );
		try {
			anyape_wp_test_tools_update_root_composer( $root, dirname( __DIR__ ) . '/composer.json' );
			$this->fail( 'Expected a conflicting root command to be refused.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( "command 'test' conflicts", $error->getMessage() );
		}
		$this->assertSame( $original, file_get_contents( $root ) );
	}

	/** Git and SFTP ignore entries are added once. */
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

	/** A declared Anyape WP Test Tools submodule is not added to the parent Git ignore file. */
	public function test_declared_submodule_is_not_ignored(): void {
		mkdir( $this->temporary_directory . '/.git' );
		copy( dirname( __DIR__ ) . '/fixtures/setup/git-submodule/.gitmodules', $this->temporary_directory . '/.gitmodules' );
		anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'git' );
		$ignore = (string) file_get_contents( $this->temporary_directory . '/.gitignore' );
		$this->assertStringNotContainsString( '.anyape-wp-test-tools/', $ignore );
		$this->assertStringContainsString( 'wp-config-ddev.php', $ignore );
	}

	/**
	 * Copy one fixture into the private test directory.
	 *
	 * @param string $fixture     Relative fixture path.
	 * @param string $destination Destination filename.
	 * @return string
	 */
	private function copy_fixture( string $fixture, string $destination ): string {
		$path = $this->temporary_directory . '/' . $destination;
		copy( dirname( __DIR__ ) . '/fixtures/setup/' . $fixture, $path );
		return $path;
	}

	/**
	 * Recursively remove the known private test directory.
	 *
	 * @param string $directory Private temporary directory.
	 */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $directory );
	}
}
