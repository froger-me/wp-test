<?php
/**
 * Theme fixture functions.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'title-tag' );
	}
);
