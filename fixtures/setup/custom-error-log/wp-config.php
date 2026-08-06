<?php
define( 'DB_NAME', 'remote_database' );
define( 'DB_USER', 'remote_user' );
define( 'DB_PASSWORD', 'remote_password_fixture' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', false );
ini_set( 'error_log', '/srv/private/php-error.log' );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
