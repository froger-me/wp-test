<?php
/**
 * Capture the active extensions from the working WordPress site.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

$active_plugins = get_option( 'active_plugins', array() );

if ( ! is_array( $active_plugins ) ) {
	$active_plugins = array();
}

$available_active_plugins = array();
foreach ( $active_plugins as $plugin_file ) {
	if ( ! is_string( $plugin_file ) ) {
		continue;
	}
	if ( ! is_file( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
		WP_CLI::warning( sprintf( 'Skipping plugin recorded as active because its file is missing: %s', $plugin_file ) );
		continue;
	}
	$available_active_plugins[] = $plugin_file;
}

WP_CLI::line(
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() cannot throw on encoding failure.
	json_encode(
		array(
			'active_plugins' => $available_active_plugins,
			'stylesheet'     => (string) get_option( 'stylesheet', '' ),
			'template'       => (string) get_option( 'template', '' ),
		),
		JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	)
);
