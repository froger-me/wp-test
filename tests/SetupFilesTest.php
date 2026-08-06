<?php
/**
 * Guided setup file and command tests.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise standalone host files in private temporary directories.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- One read-only command test uses private fake host commands.

require_once dirname( __DIR__ ) . '/bin/file-tools.php';
require_once dirname( __DIR__ ) . '/bin/inspect-setup.php';
require_once dirname( __DIR__ ) . '/bin/update-wp-config.php';
require_once dirname( __DIR__ ) . '/bin/update-root-composer.php';
require_once dirname( __DIR__ ) . '/bin/update-ignore-files.php';
require_once dirname( __DIR__ ) . '/bin/uninstall-project.php';

/** Verify guided setup inspection, file updates, and safety order. */
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

	/** WordPress configuration is updated once and restored after invalid generation. */
	public function test_wp_config_update_is_repeatable_and_restores_failure(): void {
		$path   = $this->copy_fixture( 'standard/wp-config.php', 'wp-config.php' );
		$result = anyape_wp_test_tools_update_wp_config( $path );
		$this->assertTrue( $result['changed'] );
		$this->assertFileExists( $result['backup'] );
		$this->assertSame( 'ready', anyape_wp_test_tools_inspect_wp_config( (string) file_get_contents( $path ) )['status'] );
		$this->assertFalse( anyape_wp_test_tools_update_wp_config( $path )['changed'] );

		$invalid  = $this->copy_fixture( 'invalid/wp-config.txt', 'invalid.php' );
		$original = (string) file_get_contents( $invalid );
		try {
			anyape_wp_test_tools_update_wp_config( $invalid );
			$this->fail( 'Expected invalid generated PHP to fail.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $original, file_get_contents( $invalid ) );
		}
	}

	/** A custom remote error-log path and stored remote values remain private. */
	public function test_wp_config_preserves_remote_settings_without_reporting_values(): void {
		$path = $this->copy_fixture( 'custom-error-log/wp-config.php', 'wp-config.php' );
		anyape_wp_test_tools_update_wp_config( $path );
		$contents = (string) file_get_contents( $path );
		$this->assertStringContainsString( '/srv/private/php-error.log', $contents );
		$this->assertStringContainsString( "__DIR__ . '/wp-content/debug.log'", $contents );

		$report = wp_json_encode( anyape_wp_test_tools_inspect_wp_config( $contents ) );
		$this->assertStringNotContainsString( 'remote_password_fixture', $report );
		$this->assertStringNotContainsString( 'remote_database', $report );
	}

	/** Composer and ignore updates preserve unrelated project content. */
	public function test_shared_project_files_are_updated_without_duplicates(): void {
		$composer = $this->copy_fixture( 'composer/existing.json', 'composer.json' );
		$result   = anyape_wp_test_tools_update_root_composer( $composer, dirname( __DIR__ ) . '/composer.json' );
		$this->assertTrue( $result['changed'] );
		$data = json_decode( (string) file_get_contents( $composer ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( '^1.0', $data['require']['example/package'] );
		$this->assertSame( 'php site-command.php', $data['scripts']['site:command'] );
		$this->assertFalse( anyape_wp_test_tools_update_root_composer( $composer, dirname( __DIR__ ) . '/composer.json' )['changed'] );

		mkdir( $this->temporary_directory . '/.git' );
		file_put_contents( $this->temporary_directory . '/.gitignore', ".DS_Store\n" );
		mkdir( $this->temporary_directory . '/.vscode' );
		copy( dirname( __DIR__ ) . '/fixtures/setup/sftp/sftp.json', $this->temporary_directory . '/.vscode/sftp.json' );
		$this->assertTrue( anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'git' )['changed'] );
		$sftp = anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'sftp' );
		$this->assertSame( 0600, fileperms( $sftp['backup'] ) & 0777 );
		$this->assertFalse( anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'git' )['changed'] );
		$this->assertFalse( anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'sftp' )['changed'] );
	}

	/** A conflicting root command is refused without replacing the file. */
	public function test_root_composer_conflict_is_refused(): void {
		$path     = $this->copy_fixture( 'composer/conflict.json', 'composer.json' );
		$original = (string) file_get_contents( $path );
		try {
			anyape_wp_test_tools_update_root_composer( $path, dirname( __DIR__ ) . '/composer.json' );
			$this->fail( 'Expected a command conflict.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( $original, file_get_contents( $path ) );
		}
	}

	/** Complete uninstall restores configuration and removes only owned entries. */
	public function test_project_file_uninstall_is_complete_without_backup(): void {
		$wp_config = $this->copy_fixture( 'standard/wp-config.php', 'wp-config.php' );
		anyape_wp_test_tools_update_wp_config( $wp_config );
		$composer = $this->copy_fixture( 'composer/empty.json', 'composer.json' );
		anyape_wp_test_tools_update_root_composer( $composer, dirname( __DIR__ ) . '/composer.json' );
		file_put_contents( $this->temporary_directory . '/.gitignore', ".DS_Store\n" );
		mkdir( $this->temporary_directory . '/.vscode' );
		copy( dirname( __DIR__ ) . '/fixtures/setup/sftp/sftp.json', $this->temporary_directory . '/.vscode/sftp.json' );
		anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'git' );
		anyape_wp_test_tools_update_ignore_file( $this->temporary_directory, 'sftp' );

		$this->assertSame(
			array( 'wp_config_restored' => true, 'root_composer_removed' => true ),
			anyape_wp_test_tools_uninstall_project_files( $this->temporary_directory, dirname( __DIR__ ) . '/composer.json', false )
		);
		$this->assertFileDoesNotExist( $composer );
		$this->assertStringNotContainsString( 'IS_DDEV_PROJECT', (string) file_get_contents( $wp_config ) );
		$this->assertSame( ".DS_Store\n", file_get_contents( $this->temporary_directory . '/.gitignore' ) );
	}

	/** Setup inspection reports configured DDEV packages. */
	public function test_setup_inspection_reports_ddev_packages(): void {
		$root = $this->create_wordpress_project( 'with-subversion' );
		$this->write_ddev_config( $root, array( 'git', 'subversion' ) );
		$report = anyape_wp_test_tools_inspect_setup( $root );
		$this->assertSame( array( 'git', 'subversion' ), $report['ddev_packages'] );
		$this->assertTrue( $report['subversion_configured'] );

		$root = $this->create_wordpress_project( 'without-subversion' );
		$this->write_ddev_config( $root, array( 'git' ) );
		$this->assertFalse( anyape_wp_test_tools_inspect_setup( $root )['subversion_configured'] );
	}

	/** Shared file operations preserve permissions and do not follow links. */
	public function test_shared_file_operations_are_safe(): void {
		$json = $this->temporary_directory . '/settings.json';
		file_put_contents( $json, "{\"enabled\":true}\n" );
		$this->assertSame( array( 'enabled' => true ), anyape_wp_test_tools_read_json_object( $json ) );

		$source  = $this->temporary_directory . '/source';
		$copy    = $this->temporary_directory . '/copy';
		$outside = $this->temporary_directory . '/outside';
		mkdir( $source . '/nested', 0750, true );
		mkdir( $outside );
		file_put_contents( $source . '/nested/file.txt', 'contents' );
		chmod( $source . '/nested/file.txt', 0640 );
		file_put_contents( $outside . '/kept.txt', 'outside' );
		symlink( '../outside', $source . '/outside-link' );

		anyape_wp_test_tools_copy_path( $source, $copy );
		$this->assertTrue( is_link( $copy . '/outside-link' ) );
		$this->assertSame( 0640, fileperms( $copy . '/nested/file.txt' ) & 0777 );
		$this->assertSame( anyape_wp_test_tools_path_digest( $source ), anyape_wp_test_tools_path_digest( $copy ) );
		$inode = fileinode( $copy );
		anyape_wp_test_tools_clear_directory( $copy );
		$this->assertSame( $inode, fileinode( $copy ) );
		anyape_wp_test_tools_remove_path( $source );
		$this->assertFileExists( $outside . '/kept.txt' );
	}

	/** Setup check executes without changing files or invoking host commands. */
	public function test_setup_check_is_read_only(): void {
		$root = $this->create_wordpress_project( 'setup-check' );
		$tool = $root . '/.anyape-wp-test-tools';
		$this->copy_tool_files(
			$tool,
			array(
				'setup-host.sh', 'logging-host.sh', 'composer.json', 'bin/file-tools.php',
				'bin/inspect-setup.php', 'bin/update-wp-config.php',
				'bin/update-root-composer.php', 'bin/update-ignore-files.php',
			)
		);
		$this->write_ddev_config( $root, array( 'subversion' ) );
		file_put_contents( $root . '/wp-config-ddev.php', "<?php\n" );
		mkdir( $root . '/.git' );

		$fakes = $this->temporary_directory . '/fakes';
		mkdir( $fakes );
		foreach ( array( 'ddev', 'composer', 'node', 'npm', 'git' ) as $command ) {
			$path = $fakes . '/' . $command;
			file_put_contents( $path, "#!/usr/bin/env bash\nexit 99\n" );
			chmod( $path, 0755 );
		}

		$before = $this->directory_snapshot( $root );
		$result = $this->run_command(
			array( 'bash', $tool . '/setup-host.sh', '--check' ),
			$root,
			array( 'PATH' => $fakes . PATH_SEPARATOR . getenv( 'PATH' ) )
		);
		$this->assertSame( 0, $result['status'], $result['stderr'] . $result['stdout'] );
		$this->assertSame( $before, $this->directory_snapshot( $root ) );
	}

	/** Uninstall validates before DDEV deletion and removes the toolkit last. */
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

	/** Create a complete private WordPress project. */
	private function create_wordpress_project( string $name ): string {
		$root = $this->temporary_directory . '/' . $name;
		mkdir( $root . '/wp-admin', 0700, true );
		mkdir( $root . '/wp-content', 0700, true );
		mkdir( $root . '/wp-includes', 0700, true );
		copy( dirname( __DIR__ ) . '/fixtures/setup/standard/wp-config.php', $root . '/wp-config.php' );
		return $root;
	}

	/** Write a private DDEV configuration. */
	private function write_ddev_config( string $root, array $packages ): void {
		mkdir( $root . '/.ddev', 0700, true );
		file_put_contents(
			$root . '/.ddev/config.yaml',
			'name: ' . basename( $root ) . "\n"
			. "type: wordpress\n"
			. "docroot: .\n"
			. "webserver_type: apache-fpm\n"
			. 'webimage_extra_packages: [' . implode( ', ', $packages ) . "]\n"
		);
	}

	/** Copy selected repository files into a private toolkit directory. */
	private function copy_tool_files( string $tool, array $paths ): void {
		foreach ( $paths as $relative ) {
			$destination = $tool . '/' . $relative;
			if ( ! is_dir( dirname( $destination ) ) ) {
				mkdir( dirname( $destination ), 0700, true );
			}
			copy( dirname( __DIR__ ) . '/' . $relative, $destination );
		}
	}

	/** Run one private command. */
	private function run_command( array $command, string $working_directory, array $environment ): array {
		$process = proc_open(
			$command,
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			$working_directory,
			array_replace( getenv(), $environment )
		);
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( 'status' => proc_close( $process ), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr );
	}

	/** Return a repeatable directory snapshot independent of production helpers. */
	private function directory_snapshot( string $root ): array {
		$entries  = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			$relative  = substr( $item->getPathname(), strlen( $root ) + 1 );
			$entries[] = ( $item->isDir() ? 'd ' : 'f ' ) . $relative . ( $item->isFile() ? ' ' . hash_file( 'sha256', $item->getPathname() ) : '' );
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

	/** Recursively remove the private test directory without following links. */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}
}
