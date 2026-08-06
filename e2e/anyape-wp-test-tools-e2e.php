<?php
/**
 * Plugin loaded only while the local browser tests are running.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

if ( ! defined( 'ANYAPE_WP_TEST_TOOLS_E2E' ) ) {
	define( 'ANYAPE_WP_TEST_TOOLS_E2E', true );
}

add_action(
	'login_init',
	static function (): void {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A random one-run token is verified below.
		if ( 'anyape_wp_test_tools_e2e_login' !== $action ) {
			return;
		}

		$account = isset( $_GET['account'] ) ? sanitize_key( wp_unslash( $_GET['account'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A random one-run token is verified below.
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is the nonce-equivalent random token.
		$stored  = (string) get_option( 'anyape_wp_test_tools_e2e_auth_token_hash', '' );
		$users   = array(
			'admin' => 'anyape_wp_test_tools_e2e_admin',
			'lower' => 'anyape_wp_test_tools_e2e_editor',
		);

		if ( '' === $stored || '' === $token || ! hash_equals( $stored, hash( 'sha256', $token ) ) || ! isset( $users[ $account ] ) ) {
			wp_die( esc_html__( 'Invalid local browser-test login.', 'anyape-wp-test-tools' ), '', array( 'response' => 403 ) );
		}

		$user = get_user_by( 'login', $users[ $account ] );
		if ( ! $user instanceof WP_User ) {
			wp_die( esc_html__( 'Local browser-test user does not exist.', 'anyape-wp-test-tools' ), '', array( 'response' => 500 ) );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		wp_safe_redirect( admin_url() );
		exit;
	},
	-1000
);

add_filter(
	'pre_http_request',
	static function ( $preempt, array $arguments, string $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		$host       = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$local_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$allowed    = array( $local_host, 'localhost', '127.0.0.1', '::1', 'mailpit' );
		$allowed    = (array) apply_filters( 'anyape_wp_test_tools_e2e_allowed_http_hosts', $allowed, $url, $arguments );
		if ( in_array( $host, $allowed, true ) ) {
			return false;
		}
		return new WP_Error( 'anyape_wp_test_tools_e2e_external_http_blocked', 'External HTTP is blocked during local browser tests: ' . $host );
	},
	PHP_INT_MAX,
	3
);

add_action(
	'admin_menu',
	static function (): void {
		add_management_page( 'Anyape WP Test Tools', 'Anyape WP Test Tools', 'manage_options', 'anyape-wp-test-tools-e2e', 'anyape_wp_test_tools_e2e_render_page' );
	}
);

/** Render Anyape WP Test Tools settings and fake-service page. */
function anyape_wp_test_tools_e2e_render_page(): void {
	if ( isset( $_POST['anyape_wp_test_tools_e2e_value'] ) ) {
		check_admin_referer( 'anyape_wp_test_tools_e2e_save' );
		update_option( 'anyape_wp_test_tools_e2e_saved_value', sanitize_text_field( wp_unslash( $_POST['anyape_wp_test_tools_e2e_value'] ) ) );
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}
	$value = (string) get_option( 'anyape_wp_test_tools_e2e_saved_value', '' );
	?>
	<div class="wrap">
		<h1>Anyape WP Test Tools</h1>
		<form method="post">
			<?php wp_nonce_field( 'anyape_wp_test_tools_e2e_save' ); ?>
			<label for="anyape-wp-test-tools-e2e-value">Fixture setting</label>
			<input id="anyape-wp-test-tools-e2e-value" name="anyape_wp_test_tools_e2e_value" value="<?php echo esc_attr( $value ); ?>">
			<?php submit_button( 'Save fixture setting' ); ?>
		</form>
		<?php if ( isset( $_GET['service-failure'] ) ) : ?>
			<div class="notice notice-error"><p>Fake service request failed as expected.</p></div>
		<?php endif; ?>
	</div>
	<?php
}
