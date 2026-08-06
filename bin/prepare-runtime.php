<?php
/**
 * Prepare the isolated WordPress content runtime.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

use AnyapeWPTestTools\Manifest;

$anyape_wp_test_tools_root = dirname( __DIR__ );
$project_root              = dirname( $anyape_wp_test_tools_root );
$runtime_root              = $anyape_wp_test_tools_root . '/runtime';
$content_root              = $runtime_root . '/wp-content';

require $anyape_wp_test_tools_root . '/vendor/autoload.php';
require $anyape_wp_test_tools_root . '/autoload.php';

$manifest = Manifest::from_file( $runtime_root . '/manifest.json' );

$remove_tree = static function ( string $tree_path ) use ( &$remove_tree ): void {
	if ( is_link( $tree_path ) || is_file( $tree_path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WordPress is not loaded; this removes only the isolated runtime tree.
		unlink( $tree_path );
		return;
	}

	if ( ! is_dir( $tree_path ) ) {
		return;
	}

	$entries = scandir( $tree_path );
	foreach ( false !== $entries ? $entries : array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$remove_tree( $tree_path . '/' . $entry );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WordPress is not loaded; this removes only the isolated runtime tree.
	rmdir( $tree_path );
};

$remove_tree( $content_root );

foreach ( array( 'plugins', 'mu-plugins', 'themes', 'uploads' ) as $directory ) {
	$runtime_directory = $content_root . '/' . $directory;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- WordPress is not loaded by this runtime builder.
	if ( ! mkdir( $runtime_directory, 0777, true ) && ! is_dir( $runtime_directory ) ) {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the exact local runtime path.
		throw new RuntimeException(
			sprintf( 'Could not create runtime directory: %s', $runtime_directory )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}

$relative_path = static function ( string $from_directory, string $to_path ): string {
	$from_parts = explode( '/', trim( $from_directory, '/' ) );
	$to_parts   = explode( '/', trim( $to_path, '/' ) );

	while (
		array() !== $from_parts &&
		array() !== $to_parts &&
		$to_parts[0] === $from_parts[0]
	) {
		array_shift( $from_parts );
		array_shift( $to_parts );
	}

	return implode(
		'/',
		array_merge(
			array_fill( 0, count( $from_parts ), '..' ),
			$to_parts
		)
	);
};

$create_link = static function (
	string $source,
	string $target
) use ( $relative_path ): void {
	if ( ! file_exists( $source ) && ! is_link( $source ) ) {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the exact local source path.
		throw new RuntimeException(
			sprintf( 'Runtime source does not exist: %s', $source )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	if ( file_exists( $target ) || is_link( $target ) ) {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the exact local target path.
		throw new RuntimeException(
			sprintf( 'Runtime target already exists: %s', $target )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	$relative_source = $relative_path( dirname( $target ), $source );

	if ( ! symlink( $relative_source, $target ) ) {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve exact local source and target paths.
		throw new RuntimeException(
			sprintf( 'Could not create runtime link %s -> %s', $target, $source )
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
};

foreach ( $manifest->plugins() as $plugin_entry ) {
	$source = (string) $plugin_entry['source_path'];
	$target = 'file' === (string) $plugin_entry['link_type']
		? $content_root . '/plugins/' . basename( $source )
		: $content_root . '/plugins/' . (string) $plugin_entry['slug'];

	$create_link( $source, $target );
}

foreach ( $manifest->themes() as $theme ) {
	$create_link(
		(string) $theme['source_path'],
		$content_root . '/themes/' . (string) $theme['slug']
	);
}

$real_mu_plugins = $project_root . '/wp-content/mu-plugins';

if ( is_dir( $real_mu_plugins ) ) {
	$mu_plugin_entries = scandir( $real_mu_plugins );
	foreach ( false !== $mu_plugin_entries ? $mu_plugin_entries : array() as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$create_link(
			$real_mu_plugins . '/' . $entry,
			$content_root . '/mu-plugins/' . $entry
		);
	}
}

$real_languages = $project_root . '/wp-content/languages';

if ( is_dir( $real_languages ) ) {
	$create_link( $real_languages, $content_root . '/languages' );
}
