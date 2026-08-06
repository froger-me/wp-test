#!/usr/bin/env bash

# Shared detailed-output logging for host commands.

anyape_wp_test_tools_strip_terminal_decoration() {
	LC_ALL=C sed -E $'s/\033\\[[0-?]*[ -\\/]*[@-~]//g; s/\033\\][^\a]*(\a|\033\\\\)//g' | tr '\r' '\n'
}

anyape_wp_test_tools_log_initialize() {
	local toolkit_dir="$1"
	local command_name="$2"
	ANYAPE_WP_TEST_TOOLS_LOG_OWNER=0
	if [[ -z "${ANYAPE_WP_TEST_TOOLS_LOG_FILE:-}" ]]; then
		local log_directory="$toolkit_dir/runtime/logs"
		local run_id
		run_id="$(date -u +%Y%m%dT%H%M%SZ)-$$"
		umask 077
		mkdir -p "$log_directory"
		ANYAPE_WP_TEST_TOOLS_LOG_FILE="$log_directory/$command_name-$run_id.log"
		: > "$ANYAPE_WP_TEST_TOOLS_LOG_FILE"
		ANYAPE_WP_TEST_TOOLS_LOG_OWNER=1
	fi
	ANYAPE_WP_TEST_TOOLS_VERBOSE="${ANYAPE_WP_TEST_TOOLS_VERBOSE:-0}"
	ANYAPE_WP_TEST_TOOLS_LOG_REPORTED="${ANYAPE_WP_TEST_TOOLS_LOG_REPORTED:-0}"
}

anyape_wp_test_tools_run_logged() {
	local description="$1"
	shift
	local status=0
	local raw_output
	raw_output="$(mktemp "${TMPDIR:-/tmp}/anyape-wp-test-tools-output.XXXXXX")"

	printf '\n[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$description" >> "$ANYAPE_WP_TEST_TOOLS_LOG_FILE"
	echo "$description"
	if ((ANYAPE_WP_TEST_TOOLS_VERBOSE)); then
		if "$@" 2>&1 | tee "$raw_output"; then
			status=0
		else
			status="${PIPESTATUS[0]}"
		fi
	else
		if "$@" > "$raw_output" 2>&1; then
			status=0
		else
			status=$?
		fi
	fi
	anyape_wp_test_tools_strip_terminal_decoration < "$raw_output" >> "$ANYAPE_WP_TEST_TOOLS_LOG_FILE"
	rm -f "$raw_output"

	if ((status != 0)); then
		echo "ERROR: $description failed." >&2
		if [[ "${ANYAPE_WP_TEST_TOOLS_LOG_OWNER:-0}" == "1" ]]; then
			echo "Detailed log: $ANYAPE_WP_TEST_TOOLS_LOG_FILE" >&2
			ANYAPE_WP_TEST_TOOLS_LOG_REPORTED=1
		fi
		return "$status"
	fi
	echo "Complete: $description"
}

anyape_wp_test_tools_report_log() {
	if [[ "${ANYAPE_WP_TEST_TOOLS_LOG_OWNER:-0}" == "1" && "${ANYAPE_WP_TEST_TOOLS_LOG_REPORTED:-0}" != "1" && -n "${ANYAPE_WP_TEST_TOOLS_LOG_FILE:-}" && -s "$ANYAPE_WP_TEST_TOOLS_LOG_FILE" ]]; then
		echo "Detailed log: $ANYAPE_WP_TEST_TOOLS_LOG_FILE"
		ANYAPE_WP_TEST_TOOLS_LOG_REPORTED=1
	fi
}
