<?php
/**
 * Guided setup file tests.
 *
 * @package WpTest
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- These tests exercise standalone host file writers in a private temporary directory.

require_once dirname( __DIR__ ) . '/bin/inspect-setup.php';
require_once dirname( __DIR__ ) . '/bin/update-wp-config.php';
require_once dirname( __DIR__ ) . '/bin/update-root-composer.php';
require_once dirname( __DIR__ ) . '/bin/update-ignore-files.php';

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
		$this->temporary_directory = sys_get_temp_dir() . '/wp-test-setup-' . wp_generate_uuid4();
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
		$this->assertSame( 'update', wp_test_inspect_wp_config( (string) file_get_contents( $path ) )['status'] );

		$result = wp_test_update_wp_config( $path );
		$this->assertTrue( $result['changed'] );
		$this->assertFileExists( $result['backup'] );
		$updated = (string) file_get_contents( $path );
		$this->assertStringContainsString( 'IS_DDEV_PROJECT', $updated );
		$this->assertStringContainsString( 'WP_DEBUG_DISPLAY', $updated );
		$this->assertStringContainsString( 'wp-config-ddev.php', $updated );
		$this->assertSame( 'ready', wp_test_inspect_wp_config( $updated )['status'] );

		$second = wp_test_update_wp_config( $path );
		$this->assertFalse( $second['changed'] );
		$this->assertNull( $second['backup'] );
	}

	/** A custom remote PHP error-log path remains in the remote branch. */
	public function test_custom_remote_error_log_is_preserved(): void {
		$path    = $this->copy_fixture( 'custom-error-log/wp-config.php', 'wp-config.php' );
		$updated = wp_test_update_wp_config( $path );
		$this->assertTrue( $updated['changed'] );
		$contents = (string) file_get_contents( $path );
		$this->assertStringContainsString( '/srv/private/php-error.log', $contents );
		$this->assertStringContainsString( "__DIR__ . '/wp-content/debug.log'", $contents );
	}

	/** Existing supported configuration is not rewritten. */
	public function test_existing_supported_wp_config_is_unchanged(): void {
		$path     = $this->copy_fixture( 'already-configured/wp-config.php', 'wp-config.php' );
		$original = (string) file_get_contents( $path );
		$result   = wp_test_update_wp_config( $path );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( $original, file_get_contents( $path ) );
	}

	/** An unclear configuration is refused without a write. */
	public function test_unusual_wp_config_is_refused_without_changes(): void {
		$path     = $this->copy_fixture( 'unusual/wp-config.php', 'wp-config.php' );
		$original = (string) file_get_contents( $path );
		try {
			wp_test_update_wp_config( $path );
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
			wp_test_update_wp_config( $path );
			$this->fail( 'Expected invalid PHP to fail validation.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( 'original wp-config.php was restored', $error->getMessage() );
		}
		$this->assertSame( $original, file_get_contents( $path ) );
	}

	/** Inspection reports structure without returning stored remote values. */
	public function test_inspection_does_not_return_remote_values(): void {
		$contents = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/setup/standard/wp-config.php' );
		$report   = wp_json_encode( wp_test_inspect_wp_config( $contents ) );
		$this->assertStringNotContainsString( 'remote_password_fixture', $report );
		$this->assertStringNotContainsString( 'remote_database', $report );
	}

	/** Root Composer commands are merged without removing site content. */
	public function test_root_composer_is_merged_and_repeatable(): void {
		$root    = $this->copy_fixture( 'composer/existing.json', 'composer.json' );
		$toolkit = dirname( __DIR__ ) . '/composer.json';
		$result  = wp_test_update_root_composer( $root, $toolkit );
		$this->assertTrue( $result['changed'] );
		$data = json_decode( (string) file_get_contents( $root ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( '^1.0', $data['require']['example/package'] );
		$this->assertSame( 'php site-command.php', $data['scripts']['site:command'] );
		$this->assertSame( 'bash .test-tools/setup-host.sh', $data['scripts']['setup'] );
		$this->assertFalse( wp_test_update_root_composer( $root, $toolkit )['changed'] );
	}

	/** An empty root Composer file receives the complete public command list. */
	public function test_empty_root_composer_receives_commands(): void {
		$root = $this->copy_fixture( 'composer/empty.json', 'composer.json' );
		wp_test_update_root_composer( $root, dirname( __DIR__ ) . '/composer.json' );
		$data = json_decode( (string) file_get_contents( $root ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 0, $data['config']['process-timeout'] );
		$this->assertArrayHasKey( 'setup', $data['scripts'] );
		$this->assertArrayHasKey( 'test:e2e', $data['scripts'] );
	}

	/** A command-name conflict is refused without replacing site work. */
	public function test_root_composer_command_conflict_is_refused(): void {
		$root     = $this->copy_fixture( 'composer/conflict.json', 'composer.json' );
		$original = (string) file_get_contents( $root );
		try {
			wp_test_update_root_composer( $root, dirname( __DIR__ ) . '/composer.json' );
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

		$this->assertTrue( wp_test_update_ignore_file( $this->temporary_directory, 'git' )['changed'] );
		$sftp_result = wp_test_update_ignore_file( $this->temporary_directory, 'sftp' );
		$this->assertTrue( $sftp_result['changed'] );
		$this->assertStringStartsWith( $this->temporary_directory . '/.test-tools/runtime/setup-backups/', $sftp_result['backup'] );
		$this->assertSame( 0600, fileperms( $sftp_result['backup'] ) & 0777 );
		$this->assertFalse( wp_test_update_ignore_file( $this->temporary_directory, 'git' )['changed'] );
		$this->assertFalse( wp_test_update_ignore_file( $this->temporary_directory, 'sftp' )['changed'] );
		$this->assertSame( 1, substr_count( (string) file_get_contents( $this->temporary_directory . '/.gitignore' ), '.test-tools/' ) );
		$sftp = json_decode( (string) file_get_contents( $this->temporary_directory . '/.vscode/sftp.json' ), true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 1, count( array_keys( $sftp['ignore'], '.test-tools', true ) ) );
	}

	/** A declared toolkit submodule is not added to the parent Git ignore file. */
	public function test_declared_submodule_is_not_ignored(): void {
		mkdir( $this->temporary_directory . '/.git' );
		copy( dirname( __DIR__ ) . '/fixtures/setup/git-submodule/.gitmodules', $this->temporary_directory . '/.gitmodules' );
		wp_test_update_ignore_file( $this->temporary_directory, 'git' );
		$ignore = (string) file_get_contents( $this->temporary_directory . '/.gitignore' );
		$this->assertStringNotContainsString( '.test-tools/', $ignore );
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
