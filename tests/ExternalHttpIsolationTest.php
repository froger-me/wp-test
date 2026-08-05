<?php

declare(strict_types=1);

use WpTest\HttpMock;
use WpTest\IntegrationTestCase;

final class ExternalHttpIsolationTest extends IntegrationTestCase
{
	public function test_unmocked_external_http_request_is_blocked(): void
	{
		$response = wp_remote_get(
			'https://example.com/should-not-be-contacted'
		);

		$this->assertWPError($response);
		$this->assertSame(
			'unexpected_http_request',
			$response->get_error_code()
		);
	}

	public function test_success_and_failure_responses_can_be_queued(): void
	{
		$url = 'https://service.example.test/sequence';

		HttpMock::queue(
			$url,
			HttpMock::response('{"ok":true}', 200),
			HttpMock::error('service_down', 'Service unavailable.')
		);

		$success = wp_remote_get($url);
		$failure = wp_remote_get($url);

		$this->assertNotWPError($success);
		$this->assertSame(200, wp_remote_retrieve_response_code($success));
		$this->assertSame('{"ok":true}', wp_remote_retrieve_body($success));
		$this->assertWPError($failure);
		$this->assertSame('service_down', $failure->get_error_code());
	}

	public function test_timeout_malformed_json_rate_limit_and_delay_are_available(): void
	{
		$timeoutUrl = 'https://service.example.test/timeout';
		$jsonUrl    = 'https://service.example.test/json';
		$rateUrl    = 'https://service.example.test/rate';
		$delayUrl   = 'https://service.example.test/delay';

		HttpMock::queue($timeoutUrl, HttpMock::timeout());
		HttpMock::queue($jsonUrl, HttpMock::malformedJson());
		HttpMock::queue($rateUrl, HttpMock::rateLimited(30));
		HttpMock::queue(
			$delayUrl,
			HttpMock::delayed(HttpMock::response('delayed'), 1)
		);

		$this->assertWPError(wp_remote_get($timeoutUrl));
		$this->assertSame(
			'{"invalid":',
			wp_remote_retrieve_body(wp_remote_get($jsonUrl))
		);

		$rate = wp_remote_get($rateUrl);
		$this->assertSame(429, wp_remote_retrieve_response_code($rate));
		$this->assertSame(
			'30',
			wp_remote_retrieve_header($rate, 'retry-after')
		);
		$this->assertSame(
			'delayed',
			wp_remote_retrieve_body(wp_remote_get($delayUrl))
		);
	}
}
