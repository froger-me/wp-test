<?php

declare(strict_types=1);

final class ExternalHttpIsolationTest extends WP_UnitTestCase
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

	public function test_mocked_http_response_is_allowed(): void
	{
		$url = 'https://example.test/mock';

		$mock_response = [
			'headers'  => [],
			'body'     => '{"success":true}',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];

		$mock_filter = static function (
			$preempt,
			array $parsed_args,
			string $requested_url
		) use ($url, $mock_response) {
			if ($requested_url === $url) {
				return $mock_response;
			}

			return $preempt;
		};

		add_filter(
			'pre_http_request',
			$mock_filter,
			5,
			3
		);

		try {
			$response = wp_remote_get($url);

			$this->assertNotWPError($response);
			$this->assertSame(
				200,
				wp_remote_retrieve_response_code($response)
			);
			$this->assertSame(
				'{"success":true}',
				wp_remote_retrieve_body($response)
			);
		} finally {
			remove_filter(
				'pre_http_request',
				$mock_filter,
				5
			);
		}
	}
}
