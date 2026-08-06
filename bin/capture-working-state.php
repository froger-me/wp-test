<?php
/**
 * Capture the active extensions from the working WordPress site.
 *
 * @package WpTest
 */

declare(strict_types=1);

$active_plugins = get_option( 'active_plugins', array() );

if ( ! is_array( $active_plugins ) ) {
	$active_plugins = array();
}

WP_CLI::line(
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() cannot throw on encoding failure.
	json_encode(
		array(
			'active_plugins' => array_values( $active_plugins ),
			'stylesheet'     => (string) get_option( 'stylesheet', '' ),
			'template'       => (string) get_option( 'template', '' ),
		),
		JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	)
);
