<?php
/**
 * Register the test toolkit class autoloader.
 *
 * @package WpTest
 */

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'WpTest\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $path ) ) {
			require $path;
		}
	}
);
