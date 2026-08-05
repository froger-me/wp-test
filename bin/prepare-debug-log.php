<?php

declare(strict_types=1);

$fail = static function (string $message): void {
	WP_CLI::error($message);
};

if (! defined('WP_DEBUG') || WP_DEBUG !== true) {
	$fail(
		"Local WordPress logging is not configured: define('WP_DEBUG', true) in wp-config.php for DDEV."
	);
}

if (! defined('WP_DEBUG_LOG')) {
	$fail(
		"Local WordPress logging is not configured: define('WP_DEBUG_LOG', true) in wp-config.php for DDEV."
	);
}

if (! defined('WP_CONTENT_DIR')) {
	$fail('WordPress did not define WP_CONTENT_DIR.');
}

$logFile    = WP_CONTENT_DIR . '/debug.log';
$configured = WP_DEBUG_LOG;

if ($configured !== true) {
	$configuredPath = is_string($configured) ? wp_normalize_path($configured) : '';
	$expectedPath   = wp_normalize_path($logFile);

	if ($configuredPath !== $expectedPath) {
		$fail(
			"composer tail:log and composer clear:log require WP_DEBUG_LOG to be true or the exact local path wp-content/debug.log."
		);
	}
}

$directory = dirname($logFile);

if (! is_dir($directory)) {
	$fail(sprintf('WordPress content directory does not exist: %s', $directory));
}

if (! is_writable($directory)) {
	$fail(sprintf('WordPress content directory is not writable: %s', $directory));
}

if (file_exists($logFile) && ! is_file($logFile)) {
	$fail(sprintf('WordPress debug log path is not a regular file: %s', $logFile));
}

if (! file_exists($logFile) && ! @touch($logFile)) {
	$fail(sprintf('Could not create the local WordPress debug log: %s', $logFile));
}

if (! is_writable($logFile)) {
	$fail(sprintf('WordPress debug log is not writable: %s', $logFile));
}
