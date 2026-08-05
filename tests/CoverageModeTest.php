<?php

declare(strict_types=1);

final class CoverageModeTest extends WP_UnitTestCase
{
	public function test_requested_coverage_driver_is_active(): void
	{
		if (getenv('WP_TEST_COVERAGE') !== '1') {
			$this->markTestSkipped('Coverage was not requested for this run.');
		}

		if (extension_loaded('xdebug')) {
			$this->assertTrue(
				function_exists('xdebug_info'),
				'Xdebug is loaded but xdebug_info() is unavailable.'
			);
			$this->assertContains(
				'coverage',
				xdebug_info('mode'),
				'Xdebug is loaded but coverage mode is not active.'
			);
			return;
		}

		if (extension_loaded('pcov')) {
			$this->assertTrue(
				(bool) ini_get('pcov.enabled'),
				'PCOV is loaded but disabled.'
			);
			return;
		}

		$this->fail('Coverage was requested but neither Xdebug nor PCOV is active.');
	}
}
