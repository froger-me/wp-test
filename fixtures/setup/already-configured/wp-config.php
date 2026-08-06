<?php
$is_ddev = getenv( 'IS_DDEV_PROJECT' ) === 'true';
if ( ! $is_ddev ) {
	define( 'DB_NAME', 'remote_database' );
	define( 'DB_USER', 'remote_user' );
	define( 'DB_PASSWORD', 'remote_password_fixture' );
	define( 'DB_HOST', 'localhost' );
}
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', true );
defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', $is_ddev );
defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', false );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( $is_ddev ) {
	require_once __DIR__ . '/wp-config-ddev.php';
}
require_once ABSPATH . 'wp-settings.php';
