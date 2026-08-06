<?php
/**
 * Coverage driver tests.
 *
 * @package AnyapeWPTestTools
 */

declare(strict_types=1);

/** Tests coverage-driver activation. */
final class CoverageModeTest extends WP_UnitTestCase {

	/**
	 * Verifies that the requested coverage driver is active.
	 *
	 * @group coverage
	 */
	public function test_requested_coverage_driver_is_active(): void {
		if ( '1' !== getenv( 'ANYAPE_WP_TEST_TOOLS_COVERAGE' ) ) {
			$this->fail( 'The coverage self-check ran without coverage being requested.' );
		}

		if ( extension_loaded( 'xdebug' ) ) {
			$this->assertTrue(
				function_exists( 'xdebug_info' ),
				'Xdebug is loaded but xdebug_info() is unavailable.'
			);
			$this->assertContains(
				'coverage',
				call_user_func( 'xdebug_info', 'mode' ),
				'Xdebug is loaded but coverage mode is not active.'
			);
			return;
		}

		if ( extension_loaded( 'pcov' ) ) {
			$this->assertTrue(
				(bool) ini_get( 'pcov.enabled' ),
				'PCOV is loaded but disabled.'
			);
			return;
		}

		$this->fail( 'Coverage was requested but neither Xdebug nor PCOV is active.' );
	}
}
