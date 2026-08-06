#!/usr/bin/env bash

set -euo pipefail

ANYAPE_WP_TEST_TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VERBOSE="${ANYAPE_WP_TEST_TOOLS_VERBOSE:-0}"

for argument in "$@"; do
	case "$argument" in
		-v|--verbose) VERBOSE=1 ;;
		*)
			echo "ERROR: 'composer test' accepts only -v or --verbose." >&2
			echo "Use 'composer test:php -- <PHPUnit arguments>' or 'composer test:e2e -- <Playwright arguments>' for test-runner options." >&2
			exit 2
			;;
	esac
done

# The path is resolved from this script's directory.
# shellcheck disable=SC1091
source "$ANYAPE_WP_TEST_TOOLS_DIR/logging-host.sh"
export ANYAPE_WP_TEST_TOOLS_VERBOSE="$VERBOSE"
anyape_wp_test_tools_log_initialize "$ANYAPE_WP_TEST_TOOLS_DIR" test 1
export ANYAPE_WP_TEST_TOOLS_LOG_FILE
if [[ "$ANYAPE_WP_TEST_TOOLS_LOG_OWNER" == "1" ]]; then
	if ((VERBOSE)); then
		echo "Detailed test output will be shown and saved to: $ANYAPE_WP_TEST_TOOLS_LOG_FILE"
	else
		echo "Detailed test output will be saved to: $ANYAPE_WP_TEST_TOOLS_LOG_FILE"
		echo "Run 'composer test -- -v' to show those details while the tests run."
	fi
fi

anyape_wp_test_tools_run_logged "Running the PHP tests..." "$ANYAPE_WP_TEST_TOOLS_DIR/run-tests-host.sh"
anyape_wp_test_tools_run_logged "Running the browser tests..." "$ANYAPE_WP_TEST_TOOLS_DIR/run-e2e-host.sh"
anyape_wp_test_tools_report_log
