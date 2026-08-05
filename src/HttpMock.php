<?php

declare(strict_types=1);

namespace WpTest;

use WP_Error;

final class HttpMock
{
	/** @var array<string, list<mixed>> */
	private static array $queues = [];

	/**
	 * @param mixed ...$responses
	 */
	public static function queue(string $url, ...$responses): void
	{
		self::$queues[$url] = array_values($responses);
	}

	public static function reset(): void
	{
		self::$queues = [];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function response(
		string $body = '',
		int $status = 200,
		array $headers = []
	): array {
		return [
			'headers'  => $headers,
			'body'     => $body,
			'response' => [
				'code'    => $status,
				'message' => self::statusMessage($status),
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	public static function error(
		string $code = 'http_request_failed',
		string $message = 'Mocked HTTP request failure.'
	): WP_Error {
		return new WP_Error($code, $message);
	}

	public static function timeout(): WP_Error
	{
		return self::error(
			'http_request_failed',
			'Operation timed out after the configured timeout.'
		);
	}

	/** @return array<string, mixed> */
	public static function malformedJson(int $status = 200): array
	{
		return self::response('{"invalid":', $status);
	}

	/** @return array<string, mixed> */
	public static function rateLimited(int $retryAfter = 60): array
	{
		return self::response(
			'{"error":"rate_limited"}',
			429,
			['retry-after' => (string) $retryAfter]
		);
	}

	/**
	 * @param mixed $response
	 * @return callable
	 */
	public static function delayed($response, int $milliseconds): callable
	{
		return static function () use ($response, $milliseconds) {
			if ($milliseconds > 0) {
				usleep($milliseconds * 1000);
			}

			return $response;
		};
	}

	/**
	 * @param mixed $preempt
	 * @return mixed
	 */
	public static function intercept($preempt, array $parsedArgs, string $url)
	{
		if (! isset(self::$queues[$url]) || self::$queues[$url] === []) {
			return $preempt;
		}

		$response = array_shift(self::$queues[$url]);

		if (is_callable($response)) {
			return $response($parsedArgs, $url);
		}

		return $response;
	}

	/**
	 * @param mixed $preempt
	 * @return mixed
	 */
	public static function blockUnexpected($preempt, array $parsedArgs, string $url)
	{
		if ($preempt !== false) {
			return $preempt;
		}

		return new WP_Error(
			'unexpected_http_request',
			sprintf('External HTTP request blocked during tests: %s', $url)
		);
	}

	private static function statusMessage(int $status): string
	{
		$messages = [
			200 => 'OK',
			201 => 'Created',
			204 => 'No Content',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			409 => 'Conflict',
			422 => 'Unprocessable Entity',
			429 => 'Too Many Requests',
			500 => 'Internal Server Error',
			503 => 'Service Unavailable',
		];

		return $messages[$status] ?? 'Mock Response';
	}
}
