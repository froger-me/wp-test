<?php
/**
 * External HTTP isolation tests.
 *
 * @package WpTest
 */

declare(strict_types=1);

use WpTest\HttpMock;
use WpTest\IntegrationTestCase;

/** Tests blocked and mocked WordPress HTTP requests. */
final class ExternalHttpIsolationTest extends IntegrationTestCase {

	/** Verifies that unmocked external requests are blocked. */
	public function test_unmocked_external_http_request_is_blocked(): void {
		$response = wp_remote_get(
			'https://example.com/should-not-be-contacted'
		);

		$this->assertWPError( $response );
		$this->assertSame(
			'unexpected_http_request',
			$response->get_error_code()
		);
	}

	/** Verifies queued success and failure responses. */
	public function test_success_and_failure_responses_can_be_queued(): void {
		$url = 'https://service.example.test/sequence';

		HttpMock::queue(
			$url,
			HttpMock::response( '{"ok":true}', 200 ),
			HttpMock::error( 'service_down', 'Service unavailable.' )
		);

		$success = wp_remote_get( $url );
		$failure = wp_remote_get( $url );

		$this->assertNotWPError( $success );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $success ) );
		$this->assertSame( '{"ok":true}', wp_remote_retrieve_body( $success ) );
		$this->assertWPError( $failure );
		$this->assertSame( 'service_down', $failure->get_error_code() );
	}

	/** Verifies the standard HTTP mock response variants. */
	public function test_timeout_malformed_json_rate_limit_and_delay_are_available(): void {
		$timeout_url = 'https://service.example.test/timeout';
		$json_url    = 'https://service.example.test/json';
		$rate_url    = 'https://service.example.test/rate';
		$delay_url   = 'https://service.example.test/delay';

		HttpMock::queue( $timeout_url, HttpMock::timeout() );
		HttpMock::queue( $json_url, HttpMock::malformed_json() );
		HttpMock::queue( $rate_url, HttpMock::rate_limited( 30 ) );
		HttpMock::queue(
			$delay_url,
			HttpMock::delayed( HttpMock::response( 'delayed' ), 1 )
		);

		$this->assertWPError( wp_remote_get( $timeout_url ) );
		$this->assertSame(
			'{"invalid":',
			wp_remote_retrieve_body( wp_remote_get( $json_url ) )
		);

		$rate = wp_remote_get( $rate_url );
		$this->assertSame( 429, wp_remote_retrieve_response_code( $rate ) );
		$this->assertSame(
			'30',
			wp_remote_retrieve_header( $rate, 'retry-after' )
		);
		$this->assertSame(
			'delayed',
			wp_remote_retrieve_body( wp_remote_get( $delay_url ) )
		);
	}
}
