<?php
/**
 * Create repeatable users and content for browser tests.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

$anyape_wp_test_tools_root = dirname( __DIR__ );
$project_root              = dirname( $anyape_wp_test_tools_root );
$manifest                  = getenv( 'ANYAPE_WP_TEST_TOOLS_E2E_MANIFEST' );
$users_file                = getenv( 'ANYAPE_WP_TEST_TOOLS_E2E_USERS_FILE' );

if ( false === $manifest || ! is_file( $manifest ) || false === $users_file || '' === $users_file ) {
	WP_CLI::error( 'Browser-test manifest or users-file environment value is missing.' );
}

$data = json_decode( (string) file_get_contents( $manifest ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a local generated manifest.
if ( ! is_array( $data ) ) {
	WP_CLI::error( 'The browser-test manifest is invalid.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';


foreach ( $data['plugins'] ?? array() as $extension_plugin ) {
	$file = (string) ( $extension_plugin['file'] ?? '' );
	if ( '' !== $file && ! is_plugin_active( $file ) ) {
		$result = activate_plugin( $file );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( sprintf( 'Could not activate browser-test plugin %s: %s', $file, $result->get_error_message() ) );
		}
	}
}

if ( 'theme' === ( $data['profile'] ?? '' ) ) {
	$stylesheet = (string) ( $data['stylesheet'] ?? '' );
	if ( '' !== $stylesheet ) {
		switch_theme( $stylesheet );
	}
}

$accounts = array(
	'admin' => array(
		'user_login' => 'anyape_wp_test_tools_e2e_admin',
		'user_pass'  => wp_generate_password( 32, true, true ),
		'user_email' => 'anyape-wp-test-tools-e2e-admin@example.invalid',
		'role'       => 'administrator',
	),
	'lower' => array(
		'user_login' => 'anyape_wp_test_tools_e2e_editor',
		'user_pass'  => wp_generate_password( 32, true, true ),
		'user_email' => 'anyape-wp-test-tools-e2e-editor@example.invalid',
		'role'       => 'editor',
	),
);

foreach ( $accounts as &$account ) {
	$existing = get_user_by( 'login', $account['user_login'] );
	$user_id  = $existing instanceof WP_User ? $existing->ID : wp_insert_user( $account );
	if ( is_wp_error( $user_id ) ) {
		WP_CLI::error( 'Could not create browser-test user: ' . $user_id->get_error_message() );
	}
	wp_set_password( $account['user_pass'], (int) $user_id );
	$user = new WP_User( (int) $user_id );
	$user->set_role( $account['role'] );
	$account['id'] = (int) $user_id;
}
unset( $account );

$fixture_post_id = wp_insert_post(
	array(
		'post_title'   => 'Anyape WP Test Tools Browser Fixture',
		'post_content' => 'Repeatable content created for the local browser test run.',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	),
	true
);
if ( is_wp_error( $fixture_post_id ) ) {
	WP_CLI::error( 'Could not create browser-test post: ' . $fixture_post_id->get_error_message() );
}

$fixture_term = wp_insert_term( 'Anyape WP Test Tools Browser Term', 'category', array( 'slug' => 'anyape-wp-test-tools-e2e-term' ) );
if ( is_wp_error( $fixture_term ) && 'term_exists' !== $fixture_term->get_error_code() ) {
	WP_CLI::error( 'Could not create browser-test term: ' . $fixture_term->get_error_message() );
}

$attachment_id = wp_insert_attachment(
	array(
		'post_title'     => 'Anyape WP Test Tools Browser Media',
		'post_status'    => 'inherit',
		'post_mime_type' => 'text/plain',
		'guid'           => home_url( '/anyape-wp-test-tools-e2e-media.txt' ),
	),
	'',
	(int) $fixture_post_id,
	true
);
if ( is_wp_error( $attachment_id ) ) {
	WP_CLI::error( 'Could not create browser-test attachment: ' . $attachment_id->get_error_message() );
}

update_option( 'anyape_wp_test_tools_e2e_fixture', 'ready', false );

global $wpdb;
$table = $wpdb->prefix . 'anyape_wp_test_tools_e2e_fixture';
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This creates and fills a disposable table that the database snapshot removes.
$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, fixture_key VARCHAR(191) NOT NULL, fixture_value LONGTEXT NOT NULL, PRIMARY KEY (id), UNIQUE KEY fixture_key (fixture_key)) {$wpdb->get_charset_collate()}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Disposable table restored from the database snapshot.
$wpdb->replace(
	$table,
	array(
		'fixture_key'   => 'ready',
		'fixture_value' => '1',
	),
	array( '%s', '%s' )
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable deterministic fixture.
// phpcs:enable WordPress.DB.DirectDatabaseQuery

$fixture_files = array();
foreach ( array_merge( $data['plugins'] ?? array(), $data['themes'] ?? array() ) as $extension ) {
	if ( empty( $extension['tests_enabled'] ) || ! is_string( $extension['source_path'] ?? null ) ) {
		continue;
	}
	$fixture = rtrim( $extension['source_path'], '/' ) . '/tests/e2e/fixtures.php';
	if ( is_file( $fixture ) ) {
		$fixture_files[] = $fixture;
	}
}

$configuration_file = $project_root . '/.anyape-wp-test-tools.php';
$configuration      = is_file( $configuration_file ) ? require $configuration_file : array();
if ( is_array( $configuration ) && is_string( $configuration['e2e_bootstrap'] ?? null ) && '' !== trim( $configuration['e2e_bootstrap'] ) ) {
	$bootstrap       = trim( $configuration['e2e_bootstrap'] );
	$fixture_files[] = str_starts_with( $bootstrap, '/' ) ? $bootstrap : $project_root . '/' . ltrim( $bootstrap, '/' );
}

foreach ( $fixture_files as $fixture_file ) {
	if ( ! is_file( $fixture_file ) ) {
		WP_CLI::error( 'Browser-test fixture file does not exist: ' . $fixture_file );
	}
	require $fixture_file;
}

$payload = array(
	'token' => wp_generate_password( 48, false, false ),
);
update_option( 'anyape_wp_test_tools_e2e_auth_token_hash', hash( 'sha256', $payload['token'] ), false );
if ( false === file_put_contents( $users_file, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ) . PHP_EOL ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runtime credentials are written outside the document root.
	WP_CLI::error( 'Could not write browser-test user details.' );
}
chmod( $users_file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restrict the local runtime credentials file.
WP_CLI::success( sprintf( 'Created browser-test users, content, media, options, table data, and %d extension fixture file(s).', count( $fixture_files ) ) );
