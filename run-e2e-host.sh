#!/usr/bin/env bash

set -euo pipefail

TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$TOOLKIT_DIR")"
RUNTIME_ROOT="$TOOLKIT_DIR/runtime/e2e-runs"
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
if [[ ! -x "$TOOLKIT_DIR/node_modules/.bin/playwright" ]]; then
	echo "ERROR: Playwright dependencies are missing; run 'cd .test-tools && npm install'." >&2
	exit 1
fi

cd "$PROJECT_ROOT"
mkdir -p "$RUNTIME_ROOT"
RUN_ID="$(date -u +%Y%m%dT%H%M%S)-$$"
RUN_DIR="$RUNTIME_ROOT/$RUN_ID"
SNAPSHOT_NAME="wp-test-e2e-$RUN_ID"
FILES_CAPTURED=0
SNAPSHOT_CREATED=0
RESTORE_FAILED=0

restore_working_site() {
	local original_status="$1"
	trap - EXIT INT TERM HUP
	set +e

	if ((SNAPSHOT_CREATED)); then
		echo "Restoring the working database from the temporary snapshot..."
		if ! ddev snapshot restore "$SNAPSHOT_NAME"; then
			echo "ERROR: Database restoration failed. Snapshot '$SNAPSHOT_NAME' and filesystem backup '$RUN_DIR' were retained." >&2
			RESTORE_FAILED=1
		else
			ddev export-db --database=db --gzip=false --file="$RUN_DIR/database-after.sql"
			local after_digest
			after_digest="$(php "$TOOLKIT_DIR/bin/e2e-database-digest.php" "$RUN_DIR/database-after.sql")"
			if [[ "$after_digest" != "$DATABASE_BEFORE_DIGEST" ]]; then
				echo "ERROR: The restored database does not match its saved state. Snapshot '$SNAPSHOT_NAME' was retained." >&2
				RESTORE_FAILED=1
			fi
		fi
	fi

	if ((FILES_CAPTURED)); then
		echo "Restoring browser-test filesystem paths..."
		if ! php "$TOOLKIT_DIR/bin/e2e-filesystem.php" restore "$RUN_DIR"; then
			RESTORE_FAILED=1
		fi
	fi

	if ((RESTORE_FAILED)); then
		exit 1
	fi
	php "$TOOLKIT_DIR/bin/e2e-filesystem.php" cleanup "$RUN_DIR"
	if ((original_status == 0)); then
		echo "Browser tests passed; the working database and files were restored and verified."
	fi
	exit "$original_status"
}

trap 'restore_working_site $?' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

DDEV_DESCRIPTION="$(ddev describe --json-output)"
DDEV_URL="$(printf '%s' "$DDEV_DESCRIPTION" | php "$TOOLKIT_DIR/bin/e2e-ddev-url.php")"
SITE_URL="$(ddev wp --path=/var/www/html option get siteurl --skip-plugins --skip-themes)"
DDEV_HOST="$(php -r 'echo parse_url($argv[1], PHP_URL_HOST);' "$DDEV_URL")"
SITE_HOST="$(php -r 'echo parse_url($argv[1], PHP_URL_HOST);' "$SITE_URL")"
if [[ -z "$DDEV_HOST" || "$DDEV_HOST" != "$SITE_HOST" ]]; then
	echo "ERROR: WordPress site URL '$SITE_URL' does not match this local DDEV project '$DDEV_URL'. Refusing to open a possibly remote site." >&2
	exit 1
fi

php "$TOOLKIT_DIR/bin/e2e-filesystem.php" capture "$RUN_DIR"
FILES_CAPTURED=1
ddev export-db --database=db --gzip=false --file="$RUN_DIR/database-before.sql"
DATABASE_BEFORE_DIGEST="$(php "$TOOLKIT_DIR/bin/e2e-database-digest.php" "$RUN_DIR/database-before.sql")"

ddev wp --path=/var/www/html --skip-plugins --skip-themes eval-file --use-include \
	/var/www/html/.test-tools/bin/capture-working-state.php > "$TOOLKIT_DIR/runtime/working-site.json"
BUILD_ARGS=(--profile="$PROFILE")
if [[ -n "$TARGET" ]]; then
	BUILD_ARGS+=(--target="$TARGET")
fi
php "$TOOLKIT_DIR/bin/build-manifest.php" "${BUILD_ARGS[@]}"
cp "$TOOLKIT_DIR/runtime/manifest.json" "$RUN_DIR/manifest.json"

echo "Creating temporary DDEV database snapshot '$SNAPSHOT_NAME'..."
ddev snapshot --name "$SNAPSHOT_NAME"
SNAPSHOT_CREATED=1

mkdir -p "$PROJECT_ROOT/wp-content/mu-plugins"
cp "$TOOLKIT_DIR/e2e/wp-test-e2e.php" "$PROJECT_ROOT/wp-content/mu-plugins/wp-test-e2e.php"

CONTAINER_RUN_DIR="/var/www/html/.test-tools/runtime/e2e-runs/$RUN_ID"
ddev exec --raw env \
	WP_TEST_E2E_MANIFEST="$CONTAINER_RUN_DIR/manifest.json" \
	WP_TEST_E2E_USERS_FILE="$CONTAINER_RUN_DIR/users.json" \
	wp --path=/var/www/html eval-file --use-include /var/www/html/.test-tools/bin/prepare-e2e.php

export WP_TEST_E2E_BASE_URL="$DDEV_URL"
export WP_TEST_E2E_MANIFEST="$RUN_DIR/manifest.json"
export WP_TEST_E2E_USERS_FILE="$RUN_DIR/users.json"
export WP_TEST_E2E_ADMIN_STATE="$RUN_DIR/admin-state.json"
export WP_TEST_E2E_LOWER_STATE="$RUN_DIR/lower-state.json"
export WP_TEST_E2E_DEBUG_LOG="$PROJECT_ROOT/wp-content/debug.log"
export WP_TEST_E2E_PROFILE="$PROFILE${TARGET:+:$TARGET}"
if [[ -f "$WP_TEST_E2E_DEBUG_LOG" ]]; then
	export WP_TEST_E2E_DEBUG_LOG_OFFSET="$(wc -c < "$WP_TEST_E2E_DEBUG_LOG" | tr -d ' ')"
else
	export WP_TEST_E2E_DEBUG_LOG_OFFSET=0
fi

set +e
npm --prefix "$TOOLKIT_DIR" run test:e2e -- "${PLAYWRIGHT_ARGS[@]}"
TEST_STATUS=$?
set -e
exit "$TEST_STATUS"
