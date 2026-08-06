<?php
/**
 * Deterministic WordPress HTTP API responses for integration tests.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

namespace AnyapeWPTestTools;

use WP_Error;

/** Provides queued and blocking responses for the WordPress HTTP API. */
final class HttpMock {

	/**
	 * Queued responses keyed by URL.
	 *
	 * @var array<string, list<mixed>>
	 */
	private static array $queues = array();

	/**
	 * Queue responses for a URL.
	 *
	 * @param string $url          Request URL.
	 * @param mixed  ...$responses Responses returned in order.
	 */
	public static function queue( string $url, ...$responses ): void {
		self::$queues[ $url ] = array_values( $responses );
	}

	/** Remove all queued responses. */
	public static function reset(): void {
		self::$queues = array();
	}

	/**
	 * Build a successful HTTP response array.
	 *
	 * @param string               $body    Response body.
	 * @param int                  $status  HTTP status code.
	 * @param array<string, mixed> $headers Response headers.
	 * @return array<string, mixed>
	 */
	public static function response(
		string $body = '',
		int $status = 200,
		array $headers = array()
	): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $status,
				'message' => self::status_message( $status ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Build a WordPress error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return WP_Error
	 */
	public static function error(
		string $code = 'http_request_failed',
		string $message = 'Mocked HTTP request failure.'
	): WP_Error {
		return new WP_Error( $code, $message );
	}

	/** Return a timeout error. */
	public static function timeout(): WP_Error {
		return self::error(
			'http_request_failed',
			'Operation timed out after the configured timeout.'
		);
	}

	/**
	 * Build a malformed JSON response.
	 *
	 * @param int $status HTTP status code.
	 * @return array<string, mixed>
	 */
	public static function malformed_json( int $status = 200 ): array {
		return self::response( '{"invalid":', $status );
	}

	/**
	 * Build a rate-limit response.
	 *
	 * @param int $retry_after Retry delay in seconds.
	 * @return array<string, mixed>
	 */
	public static function rate_limited( int $retry_after = 60 ): array {
		return self::response(
			'{"error":"rate_limited"}',
			429,
			array( 'retry-after' => (string) $retry_after )
		);
	}

	/**
	 * Delay a mocked response.
	 *
	 * @param mixed $response     Response value.
	 * @param int   $milliseconds Delay in milliseconds.
	 * @return callable
	 */
	public static function delayed( $response, int $milliseconds ): callable {
		return static function () use ( $response, $milliseconds ) {
			if ( $milliseconds > 0 ) {
				usleep( $milliseconds * 1000 );
			}

			return $response;
		};
	}

	/**
	 * Return the next queued response for a URL.
	 *
	 * @param mixed                $preempt     Existing preempted response.
	 * @param array<string, mixed> $parsed_args Parsed request arguments.
	 * @param string               $url         Request URL.
	 * @return mixed
	 */
	public static function intercept( $preempt, array $parsed_args, string $url ) {
		if ( ! isset( self::$queues[ $url ] ) || array() === self::$queues[ $url ] ) {
			return $preempt;
		}

		$response = array_shift( self::$queues[ $url ] );

		if ( is_callable( $response ) ) {
			return $response( $parsed_args, $url );
		}

		return $response;
	}

	/**
	 * Block any HTTP request not already preempted.
	 *
	 * @param mixed                $preempt     Existing preempted response.
	 * @param array<string, mixed> $parsed_args Parsed request arguments.
	 * @param string               $url         Request URL.
	 * @return mixed
	 */
	public static function block_unexpected( $preempt, array $parsed_args, string $url ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		return new WP_Error(
			'unexpected_http_request',
			sprintf( 'External HTTP request blocked during tests: %s', $url )
		);
	}

	/**
	 * Return a status message for an HTTP code.
	 *
	 * @param int $status HTTP status code.
	 * @return string
	 */
	private static function status_message( int $status ): string {
		$messages = array(
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
		);

		return $messages[ $status ] ?? 'Mock Response';
	}
}
