<?php
/**
 * Inspect a WordPress directory before guided local setup.
 *
 * @package AnyapeWPTestTools
 */
declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions -- This standalone host command runs before WordPress is loaded.

/**
 * Inspect setup files without returning their contents.
 *
 * @param string $project_root WordPress root directory.
 * @return array<string, mixed>
 */
function anyape_wp_test_tools_inspect_setup( string $project_root ): array {
	$project_root = rtrim( $project_root, DIRECTORY_SEPARATOR );
	$required     = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-config.php' );
	$missing      = array();
	foreach ( $required as $path ) {
		if ( ! file_exists( $project_root . DIRECTORY_SEPARATOR . $path ) ) {
			$missing[] = $path;
		}
	}

	$wp_config_path   = $project_root . '/wp-config.php';
	$wp_config        = is_file( $wp_config_path ) ? (string) file_get_contents( $wp_config_path ) : '';
	$wp_config_report = anyape_wp_test_tools_inspect_wp_config( $wp_config );
	$ddev_path        = $project_root . '/.ddev/config.yaml';
	$ddev              = is_file( $ddev_path ) ? (string) file_get_contents( $ddev_path ) : '';
	$ddev_project_name = anyape_wp_test_tools_ddev_scalar( $ddev, 'name' );
	$ddev_project_type = anyape_wp_test_tools_ddev_scalar( $ddev, 'type' );
	$ddev_docroot      = anyape_wp_test_tools_ddev_scalar( $ddev, 'docroot' );
	$ddev_webserver    = anyape_wp_test_tools_ddev_scalar( $ddev, 'webserver_type' );
	$ddev_packages     = anyape_wp_test_tools_ddev_packages( $ddev );
	$ddev_ready        =
		is_file( $ddev_path ) &&
		is_file( $project_root . '/wp-config-ddev.php' ) &&
		'wordpress' === $ddev_project_type &&
		'.' === $ddev_docroot &&
		'apache-fpm' === $ddev_webserver &&
		null !== $ddev_project_name;

	$git_mode    = 'none';
	$git_modules = $project_root . '/.gitmodules';
	if ( is_file( $git_modules ) && str_contains( (string) file_get_contents( $git_modules ), '.anyape-wp-test-tools' ) ) {
		$git_mode = 'submodule';
	} elseif ( is_dir( $project_root . '/.git' ) || is_file( $project_root . '/.git' ) ) {
		$git_mode = 'parent';
	}

	$composer_path = $project_root . '/composer.json';

	return array(
		'wordpress_valid'       => array() === $missing,
		'missing_paths'         => $missing,
		'wp_config'             => $wp_config_report,
		'ddev_ready'            => $ddev_ready,
		'ddev_project_name'     => $ddev_project_name,
		'ddev_project_type'     => $ddev_project_type,
		'ddev_docroot'          => $ddev_docroot,
		'ddev_webserver_type'   => $ddev_webserver,
		'ddev_packages'         => $ddev_packages,
		'subversion_configured' => in_array( 'subversion', $ddev_packages, true ),
		'root_composer_exists'  => is_file( $composer_path ),
		'root_gitignore_exists' => is_file( $project_root . '/.gitignore' ),
		'git_mode'              => $git_mode,
		'sftp_config_exists'    => is_file( $project_root . '/.vscode/sftp.json' ),
	);
}

/**
 * Read one top-level scalar from DDEV YAML.
 *
 * @param string $contents DDEV configuration contents.
 * @param string $key      Top-level key.
 * @return string|null
 */
function anyape_wp_test_tools_ddev_scalar( string $contents, string $key ): ?string {
	$pattern = '/^' . preg_quote( $key, '/' ) . ':\s*(.*?)\s*(?:#.*)?$/m';
	if ( 1 !== preg_match( $pattern, $contents, $match ) ) {
		return null;
	}

	$value = trim( $match[1] );
	if ( strlen( $value ) >= 2 ) {
		$first = $value[0];
		$last  = $value[ strlen( $value ) - 1 ];
		if ( ( "'" === $first && "'" === $last ) || ( '"' === $first && '"' === $last ) ) {
			$value = substr( $value, 1, -1 );
		}
	}

	return '' !== $value ? $value : null;
}

/**
 * Read configured extra DDEV web-image packages.
 *
 * @param string $contents DDEV configuration contents.
 * @return list<string>
 */
function anyape_wp_test_tools_ddev_packages( string $contents ): array {
	$packages = array();
	if ( preg_match( '/^webimage_extra_packages:\s*\[([^\]]*)\]/m', $contents, $match ) ) {
		foreach ( explode( ',', $match[1] ) as $package ) {
			$package = trim( $package, " \t\n\r\0\x0B'\"" );
			if ( '' !== $package ) {
				$packages[] = $package;
			}
		}
	} elseif ( preg_match( '/^webimage_extra_packages:\s*\R((?:[ \t]+-[^\r\n]*\R?)*)/m', $contents, $match ) ) {
		if ( preg_match_all( '/^[ \t]+-[ \t]*[\'\"]?([^\'\"\r\n]+)[\'\"]?[ \t]*$/m', $match[1], $package_matches ) ) {
			foreach ( $package_matches[1] as $package ) {
				$packages[] = trim( $package );
			}
		}
	}
	return $packages;
}

/**
 * Inspect only structural markers in wp-config.php.
 *
 * @param string $contents wp-config.php contents.
 * @return array<string, mixed>
 */
function anyape_wp_test_tools_inspect_wp_config( string $contents ): array {
	$counts     = array(
		'db_name'      => preg_match_all( "/define\s*\(\s*['\"]DB_NAME['\"]/", $contents ),
		'db_user'      => preg_match_all( "/define\s*\(\s*['\"]DB_USER['\"]/", $contents ),
		'db_password'  => preg_match_all( "/define\s*\(\s*['\"]DB_PASSWORD['\"]/", $contents ),
		'db_host'      => preg_match_all( "/define\s*\(\s*['\"]DB_HOST['\"]/", $contents ),
		'wp_settings'  => preg_match_all( '/wp-settings\.php/', $contents ),
		'abspath'      => preg_match_all( "/define\s*\(\s*['\"]ABSPATH['\"]/", $contents ),
		'ddev_include' => preg_match_all( '/wp-config-ddev\.php/', $contents ),
	);
	$duplicates = array();
	foreach ( $counts as $name => $count ) {
		if ( $count > 1 && ! in_array( $name, array( 'abspath', 'ddev_include' ), true ) ) {
			$duplicates[] = $name;
		}
	}

	$has_ddev_flag      = str_contains( $contents, 'IS_DDEV_PROJECT' );
	$has_ddev_include   = $counts['ddev_include'] >= 1;
	$has_remote_guard   = 1 === preg_match( '/if\s*\(\s*!\s*\$is_ddev\s*\)/', $contents );
	$has_debug          = str_contains( $contents, 'WP_DEBUG' ) && str_contains( $contents, 'WP_DEBUG_LOG' ) && str_contains( $contents, 'WP_DEBUG_DISPLAY' );
	$configured_base    = $has_ddev_flag && $has_ddev_include && $has_remote_guard && 1 === $counts['wp_settings'];
	$configured         = $configured_base && $has_debug;
	$no_ddev_markers    = ! $has_ddev_flag && ! $has_ddev_include && ! $has_remote_guard;
	$standard_structure = $no_ddev_markers && array() === $duplicates && 1 === $counts['db_name'] && 1 === $counts['db_user'] && 1 === $counts['db_password'] && 1 === $counts['db_host'] && 1 === $counts['wp_settings'] && $counts['abspath'] >= 1;

	if ( $configured ) {
		$status  = 'ready';
		$reasons = array();
	} elseif ( $configured_base && array() === $duplicates ) {
		$status  = 'update';
		$reasons = array( 'The existing DDEV configuration is supported and its missing debug settings can be completed automatically.' );
	} elseif ( $standard_structure ) {
		$status  = 'update';
		$reasons = array( 'A standard WordPress configuration can be adapted automatically.' );
	} else {
		$status  = 'manual';
		$reasons = array();
		if ( array() !== $duplicates ) {
			$reasons[] = 'Repeated configuration definitions require manual review: ' . implode( ', ', $duplicates ) . '.';
		}
		if ( ! $no_ddev_markers && ! $configured ) {
			$reasons[] = 'The existing DDEV changes are incomplete or use an unsupported arrangement.';
		}
		if ( 1 !== $counts['wp_settings'] ) {
			$reasons[] = 'wp-settings.php must be loaded exactly once.';
		}
		if ( array() === $reasons ) {
			$reasons[] = 'The WordPress configuration arrangement is not recognized.';
		}
	}

	return array(
		'status'             => $status,
		'reasons'            => $reasons,
		'has_ddev_flag'      => $has_ddev_flag,
		'has_ddev_include'   => $has_ddev_include,
		'has_remote_guard'   => $has_remote_guard,
		'has_standard_debug' => $has_debug,
	);
}

/**
 * Write the setup report as shell-safe null-delimited name and value pairs.
 *
 * @param array<string, mixed> $report Setup inspection report.
 */
function anyape_wp_test_tools_write_setup_shell_report( array $report ): void {
	$wp_config    = is_array( $report['wp_config'] ?? null ) ? $report['wp_config'] : array();
	$shell_values = array(
		'wordpress_valid'       => ! empty( $report['wordpress_valid'] ) ? '1' : '0',
		'missing_paths'         => implode( ',', (array) ( $report['missing_paths'] ?? array() ) ),
		'wp_config_status'      => (string) ( $wp_config['status'] ?? '' ),
		'wp_config_reasons'     => implode( "\n", (array) ( $wp_config['reasons'] ?? array() ) ),
		'ddev_ready'            => ! empty( $report['ddev_ready'] ) ? '1' : '0',
		'ddev_project_name'     => (string) ( $report['ddev_project_name'] ?? '' ),
		'ddev_project_type'     => (string) ( $report['ddev_project_type'] ?? '' ),
		'ddev_docroot'          => (string) ( $report['ddev_docroot'] ?? '' ),
		'ddev_webserver_type'   => (string) ( $report['ddev_webserver_type'] ?? '' ),
		'ddev_packages'         => implode( ',', (array) ( $report['ddev_packages'] ?? array() ) ),
		'subversion_configured' => ! empty( $report['subversion_configured'] ) ? '1' : '0',
		'root_composer_exists'  => ! empty( $report['root_composer_exists'] ) ? '1' : '0',
		'root_gitignore_exists' => ! empty( $report['root_gitignore_exists'] ) ? '1' : '0',
		'git_mode'              => (string) ( $report['git_mode'] ?? '' ),
		'sftp_config_exists'    => ! empty( $report['sftp_config_exists'] ) ? '1' : '0',
	);

	foreach ( $shell_values as $name => $value ) {
		fwrite( STDOUT, $name . "\0" . $value . "\0" );
	}
}

if ( realpath( (string) ( $argv[0] ?? '' ) ) === __FILE__ ) {
	$arguments = array_slice( $argv, 1 );
	$shell     = in_array( '--shell', $arguments, true );
	$arguments = array_values( array_diff( $arguments, array( '--shell' ) ) );
	if ( 1 !== count( $arguments ) ) {
		fwrite( STDERR, "ERROR: Usage: php inspect-setup.php [--shell] <wordpress-root>\n" );
		exit( 1 );
	}
	try {
		$report = anyape_wp_test_tools_inspect_setup( $arguments[0] );
		if ( $shell ) {
			anyape_wp_test_tools_write_setup_shell_report( $report );
		} else {
			echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), PHP_EOL;
		}
	} catch ( Throwable $error ) {
		fwrite( STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL );
		exit( 1 );
	}
}
