#!/usr/bin/env bash

set -euo pipefail

ANYAPE_WP_TEST_TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$ANYAPE_WP_TEST_TOOLS_DIR")"
RUNTIME_ROOT="$ANYAPE_WP_TEST_TOOLS_DIR/runtime/e2e-runs"

if [[ -z "${ANYAPE_WP_TEST_TOOLS_LOG_FILE:-}" ]]; then
	# The path is resolved from this script's directory.
	# shellcheck disable=SC1091
	source "$ANYAPE_WP_TEST_TOOLS_DIR/logging-host.sh"
	anyape_wp_test_tools_run_standalone_test \
		"$ANYAPE_WP_TEST_TOOLS_DIR" \
		"Running the browser tests..." \
		bash "$ANYAPE_WP_TEST_TOOLS_DIR/run-e2e-host.sh" "$@"
	exit $?
fi

PROFILE="default"
TARGET=""
PLAYWRIGHT_ARGS=()

while (($#)); do
	case "$1" in
		--profile=*)
			PROFILE="${1#*=}"
			shift
			;;
		--profile)
			PROFILE="${2:-}"
			shift 2
			;;
		--target=*)
			TARGET="${1#*=}"
			shift
			;;
		--target)
			TARGET="${2:-}"
			shift 2
			;;
		--)
			shift
			PLAYWRIGHT_ARGS+=("$@")
			break
			;;
		*)
			if [[ ("$PROFILE" == "plugin" || "$PROFILE" == "theme") && -z "$TARGET" && "$1" != --* ]]; then
				TARGET="$1"
			else
				PLAYWRIGHT_ARGS+=("$1")
			fi
			shift
			;;
	esac
done

case "$PROFILE" in
	default|plugin|theme)
		;;
	*)
		echo "ERROR: Unknown browser-test profile '$PROFILE'." >&2
		exit 1
		;;
esac
if [[ ("$PROFILE" == "plugin" || "$PROFILE" == "theme") && -z "$TARGET" ]]; then
	echo "ERROR: The '$PROFILE' browser-test profile requires an extension slug." >&2
	exit 1
fi

for command_name in ddev node npm php; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "ERROR: Required host command is missing: $command_name" >&2
		exit 1
	fi
done
if [[ ! -x "$ANYAPE_WP_TEST_TOOLS_DIR/node_modules/.bin/playwright" ]]; then
	echo "ERROR: Playwright dependencies are missing; run 'cd .anyape-wp-test-tools && npm install'." >&2
	exit 1
fi

cd "$PROJECT_ROOT"
mkdir -p "$RUNTIME_ROOT"
RUN_ID="$(date -u +%Y%m%dT%H%M%S)-$$"
RUN_DIR="$RUNTIME_ROOT/$RUN_ID"
SNAPSHOT_NAME="anyape-wp-test-tools-e2e-$RUN_ID"
FILES_CAPTURED=0
SNAPSHOT_CREATED=0
RESTORE_FAILED=0

verify_restored_database() {
	rm -f "$RUN_DIR/database-after.sql"
	if ! ddev export-db --database=db --gzip=false --file="$RUN_DIR/database-after.sql"; then
		echo "ERROR: Could not export the restored working database for verification." >&2
		return 1
	fi

	local after_digest
	if ! after_digest="$(php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/e2e-database-digest.php" "$RUN_DIR/database-after.sql")"; then
		echo "ERROR: Could not calculate the restored working database comparison value." >&2
		return 1
	fi
	if [[ "$after_digest" != "$DATABASE_BEFORE_DIGEST" ]]; then
		echo "ERROR: The restored working database does not match its saved state." >&2
		return 1
	fi
}

restore_database() {
	echo "Restoring the working database from the saved SQL export..."
	if ddev import-db --database=db --file="$RUN_DIR/database-before.sql" && verify_restored_database; then
		return 0
	fi

	echo "The SQL restore failed or did not match; restoring the temporary DDEV snapshot instead..." >&2
	if ! ddev snapshot restore "$SNAPSHOT_NAME"; then
		echo "ERROR: Database restoration failed. Snapshot '$SNAPSHOT_NAME' and filesystem backup '$RUN_DIR' were retained." >&2
		return 1
	fi
	if ! verify_restored_database; then
		echo "ERROR: The snapshot restore could not be verified. Snapshot '$SNAPSHOT_NAME' and filesystem backup '$RUN_DIR' were retained." >&2
		return 1
	fi
}

remove_temporary_snapshot() {
	if ! ddev snapshot --cleanup --name "$SNAPSHOT_NAME" --yes; then
		echo "ERROR: The working database was restored, but temporary snapshot '$SNAPSHOT_NAME' could not be removed." >&2
		echo "Remove it manually with: ddev snapshot --cleanup --name '$SNAPSHOT_NAME' --yes" >&2
		return 1
	fi
	SNAPSHOT_CREATED=0
}

restore_working_site() {
	local original_status="$1"
	trap - EXIT INT TERM HUP
	set +e

	if ((SNAPSHOT_CREATED)); then
		if ! restore_database; then
			RESTORE_FAILED=1
		elif ! remove_temporary_snapshot; then
			RESTORE_FAILED=1
		fi
	fi

	if ((FILES_CAPTURED)); then
		echo "Restoring browser-test filesystem paths..."
		if ! php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/e2e-filesystem.php" restore "$RUN_DIR"; then
			RESTORE_FAILED=1
		fi
	fi

	if ((RESTORE_FAILED)); then
		exit 1
	fi
	php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/e2e-filesystem.php" cleanup "$RUN_DIR"
	if ((original_status == 0)); then
		echo "Browser tests passed; the working database and files were restored and verified, and the temporary snapshot was removed."
	fi
	exit "$original_status"
}

trap 'restore_working_site $?' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

DDEV_DESCRIPTION="$(ddev describe --json-output)"
DDEV_URL="$(printf '%s' "$DDEV_DESCRIPTION" | php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/e2e-ddev-url.php")"
SITE_URL="$(ddev wp --path=/var/www/html option get siteurl --skip-plugins --skip-themes)"
DDEV_HOST="$(php -r 'echo parse_url($argv[1], PHP_URL_HOST);' "$DDEV_URL")"
SITE_HOST="$(php -r 'echo parse_url($argv[1], PHP_URL_HOST);' "$SITE_URL")"
if [[ -z "$DDEV_HOST" || "$DDEV_HOST" != "$SITE_HOST" ]]; then
	echo "ERROR: WordPress site URL '$SITE_URL' does not match this local DDEV project '$DDEV_URL'. Refusing to open a possibly remote site." >&2
	exit 1
fi

php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/e2e-filesystem.php" capture "$RUN_DIR"
FILES_CAPTURED=1
ddev export-db --database=db --gzip=false --file="$RUN_DIR/database-before.sql"
DATABASE_BEFORE_DIGEST="$(php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/e2e-database-digest.php" "$RUN_DIR/database-before.sql")"

ddev wp --path=/var/www/html --skip-plugins --skip-themes eval-file --use-include \
	/var/www/html/.anyape-wp-test-tools/bin/capture-working-state.php > "$ANYAPE_WP_TEST_TOOLS_DIR/runtime/working-site.json"
BUILD_ARGS=(--profile="$PROFILE")
if [[ -n "$TARGET" ]]; then
	BUILD_ARGS+=(--target="$TARGET")
fi
php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/build-manifest.php" "${BUILD_ARGS[@]}"
cp "$ANYAPE_WP_TEST_TOOLS_DIR/runtime/manifest.json" "$RUN_DIR/manifest.json"

echo "Creating temporary DDEV database snapshot '$SNAPSHOT_NAME' as a restoration fallback..."
ddev snapshot --name "$SNAPSHOT_NAME"
SNAPSHOT_CREATED=1

mkdir -p "$PROJECT_ROOT/wp-content/mu-plugins"
cp "$ANYAPE_WP_TEST_TOOLS_DIR/e2e/anyape-wp-test-tools-e2e.php" "$PROJECT_ROOT/wp-content/mu-plugins/anyape-wp-test-tools-e2e.php"

CONTAINER_RUN_DIR="/var/www/html/.anyape-wp-test-tools/runtime/e2e-runs/$RUN_ID"
CONTAINER_USERS_FILE="$CONTAINER_RUN_DIR/users.json"
ddev exec --raw env \
	ANYAPE_WP_TEST_TOOLS_E2E_MANIFEST="$CONTAINER_RUN_DIR/manifest.json" \
	ANYAPE_WP_TEST_TOOLS_E2E_USERS_FILE="$CONTAINER_USERS_FILE" \
	wp --path=/var/www/html eval-file --use-include /var/www/html/.anyape-wp-test-tools/bin/prepare-e2e.php

# DDEV file synchronization may not copy a newly written container file to the
# host before Playwright starts. Copy the short-lived login token directly.
HOST_USERS_FILE="$RUN_DIR/users.json"
HOST_USERS_TEMP="$RUN_DIR/users.json.from-ddev"
if ! ddev exec --raw cat "$CONTAINER_USERS_FILE" > "$HOST_USERS_TEMP"; then
	echo "ERROR: Could not copy the temporary browser-test login details from DDEV." >&2
	exit 1
fi
if ! php -r '$data=json_decode((string) file_get_contents($argv[1]),true); exit(is_array($data) && is_string($data["token"] ?? null) && $data["token"] !== "" ? 0 : 1);' "$HOST_USERS_TEMP"; then
	echo "ERROR: DDEV returned invalid browser-test login details." >&2
	exit 1
fi
chmod 0600 "$HOST_USERS_TEMP"
mv "$HOST_USERS_TEMP" "$HOST_USERS_FILE"

export ANYAPE_WP_TEST_TOOLS_E2E_BASE_URL="$DDEV_URL"
export ANYAPE_WP_TEST_TOOLS_E2E_MANIFEST="$RUN_DIR/manifest.json"
export ANYAPE_WP_TEST_TOOLS_E2E_USERS_FILE="$HOST_USERS_FILE"
export ANYAPE_WP_TEST_TOOLS_E2E_ADMIN_STATE="$RUN_DIR/admin-state.json"
export ANYAPE_WP_TEST_TOOLS_E2E_LOWER_STATE="$RUN_DIR/lower-state.json"
export ANYAPE_WP_TEST_TOOLS_E2E_DEBUG_LOG="$PROJECT_ROOT/wp-content/debug.log"
export ANYAPE_WP_TEST_TOOLS_E2E_PROFILE="$PROFILE${TARGET:+:$TARGET}"
if [[ -f "$ANYAPE_WP_TEST_TOOLS_E2E_DEBUG_LOG" ]]; then
	export ANYAPE_WP_TEST_TOOLS_E2E_DEBUG_LOG_OFFSET="$(wc -c < "$ANYAPE_WP_TEST_TOOLS_E2E_DEBUG_LOG" | tr -d ' ')"
else
	export ANYAPE_WP_TEST_TOOLS_E2E_DEBUG_LOG_OFFSET=0
fi

set +e
npm --prefix "$ANYAPE_WP_TEST_TOOLS_DIR" run test:e2e -- "${PLAYWRIGHT_ARGS[@]}"
TEST_STATUS=$?
set -e
exit "$TEST_STATUS"
