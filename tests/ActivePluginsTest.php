<?php
/**
 * Active extension tests.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

use AnyapeWPTestTools\IntegrationTestCase;
use AnyapeWPTestTools\Manifest;

/** Tests selected plugin and theme loading. */
final class ActivePluginsTest extends IntegrationTestCase {

	/** Verifies that selected plugins are loaded and activated. */
	public function test_selected_plugins_are_active_loaded_and_activated(): void {
		$manifest = Manifest::from_file(
			dirname( __DIR__ ) . '/runtime/manifest.json'
		);

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$included_files = array_map(
			static fn ( string $file ): string =>
				(string) realpath( $file ),
			get_included_files()
		);
		$plugins        = $manifest->plugins();
		if ( array() === $plugins ) {
			$this->assertSame(
				array(),
				get_option( 'active_plugins', array() ),
				'No plugins should be active when the test profile selects none.'
			);
			return;
		}

		foreach ( $plugins as $plugin ) {
			$plugin_file  = (string) $plugin['file'];
			$runtime_file = WP_PLUGIN_DIR . '/' . $plugin_file;
			$real_file    = realpath( $runtime_file );

			$this->assertNotFalse(
				$real_file,
				sprintf( 'Selected plugin file does not exist: %s', $plugin_file )
			);
			$this->assertTrue(
				is_plugin_active( $plugin_file ),
				sprintf( 'Selected plugin is not active: %s', $plugin_file )
			);
			$this->assertContains(
				$real_file,
				$included_files,
				sprintf( 'Selected plugin was not loaded: %s', $plugin_file )
			);
			$this->assertGreaterThan(
				0,
				did_action( 'activate_' . $plugin_file ),
				sprintf( 'Activation hook was not run: %s', $plugin_file )
			);
		}
	}

	/** Verifies that selected themes exist in the runtime overlay. */
	public function test_selected_themes_are_available_in_runtime_overlay(): void {
		$manifest = Manifest::from_file(
			dirname( __DIR__ ) . '/runtime/manifest.json'
		);

		foreach ( $manifest->themes() as $theme ) {
			$this->assertDirectoryExists(
				WP_CONTENT_DIR . '/themes/' . (string) $theme['slug']
			);
		}
	}
}
