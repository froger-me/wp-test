#!/usr/bin/env bash

set -euo pipefail

ANYAPE_WP_TEST_TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$ANYAPE_WP_TEST_TOOLS_DIR")"
ACTION="${1:-}"

if (($# > 0)); then
	shift
fi

if ! command -v ddev >/dev/null 2>&1; then
	echo "ERROR: DDEV is not available on the host PATH." >&2
	exit 1
fi
if ! ddev exec --raw true >/dev/null 2>&1; then
	echo "ERROR: The DDEV project is not running. Start it explicitly with 'ddev start'." >&2
	exit 1
fi

cd "$PROJECT_ROOT"

YES=0
VERBOSE="${ANYAPE_WP_TEST_TOOLS_VERBOSE:-0}"
ARGS=()
for argument in "$@"; do
	case "$argument" in
		--yes) YES=1 ;;
		-v|--verbose) VERBOSE=1 ;;
		*) ARGS+=("$argument") ;;
	esac
done

# The path is resolved from this script's directory.
# shellcheck disable=SC1091
source "$ANYAPE_WP_TEST_TOOLS_DIR/logging-host.sh"
export ANYAPE_WP_TEST_TOOLS_VERBOSE="$VERBOSE"
anyape_wp_test_tools_log_initialize "$ANYAPE_WP_TEST_TOOLS_DIR" "database-$ACTION"
if [[ "$ANYAPE_WP_TEST_TOOLS_LOG_OWNER" == "1" ]]; then
	if ((VERBOSE)); then
		echo "Detailed command output will be shown and saved to: $ANYAPE_WP_TEST_TOOLS_LOG_FILE"
	else
		echo "Detailed command output will be saved to: $ANYAPE_WP_TEST_TOOLS_LOG_FILE"
		echo "Run this command with -v or --verbose to show those details while it runs."
	fi
fi

confirm() {
	local expected="$1"
	local explanation="$2"
	if ((YES)); then
		return
	fi
	if [[ ! -t 0 ]]; then
		echo "ERROR: Confirmation is required. Re-run with --yes only after reviewing the command's target." >&2
		exit 1
	fi
	echo "$explanation"
	printf "Type '%s' to continue: " "$expected"
	local answer
	IFS= read -r answer
	if [[ "$answer" != "$expected" ]]; then
		echo "Cancelled; nothing was changed."
		exit 1
	fi
}

validate_snapshot_name() {
	local name="$1"
	if [[ ! "$name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]]; then
		echo "ERROR: Snapshot names may contain only letters, numbers, periods, underscores, and hyphens, and must start with a letter or number." >&2
		exit 1
	fi
}

case "$ACTION" in
	snapshot)
		if ((${#ARGS[@]} > 1)); then
			echo "ERROR: composer snapshot accepts at most one snapshot name." >&2
			exit 1
		fi
		SNAPSHOT_NAME="${ARGS[0]:-local-$(date -u +%Y%m%dT%H%M%SZ)}"
		validate_snapshot_name "$SNAPSHOT_NAME"
		anyape_wp_test_tools_run_logged "Creating local database snapshot '$SNAPSHOT_NAME'..." ddev snapshot --name "$SNAPSHOT_NAME"
		echo "Created snapshot '$SNAPSHOT_NAME'. Restore it with: composer restore -- $SNAPSHOT_NAME"
		;;
	restore)
		if ((${#ARGS[@]} != 1)); then
			echo "ERROR: composer restore requires exactly one snapshot name." >&2
			exit 1
		fi
		SNAPSHOT_NAME="${ARGS[0]}"
		validate_snapshot_name "$SNAPSHOT_NAME"
		confirm "restore $SNAPSHOT_NAME" "This replaces every database in the local DDEV project with snapshot '$SNAPSHOT_NAME'."
		anyape_wp_test_tools_run_logged "Restoring local database snapshot '$SNAPSHOT_NAME'..." ddev snapshot restore "$SNAPSHOT_NAME"
		;;
	reset-tests)
		if ((${#ARGS[@]} != 0)); then
			echo "ERROR: composer reset:tests accepts only the optional --yes flag." >&2
			exit 1
		fi
		confirm "reset anyape_wp_test_tools" "This permanently deletes and recreates only the local PHPUnit database 'anyape_wp_test_tools'. The working WordPress database 'db' is not changed."
		anyape_wp_test_tools_run_logged "Recreating the separate PHP test database..." ddev mysql -uroot -proot -e "DROP DATABASE IF EXISTS anyape_wp_test_tools; CREATE DATABASE anyape_wp_test_tools CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON anyape_wp_test_tools.* TO 'db'@'%'; FLUSH PRIVILEGES;"
		echo "Recreated the local PHPUnit database 'anyape_wp_test_tools'."
		;;
	pull)
		if ((${#ARGS[@]} != 0)); then
			echo "ERROR: composer db:pull accepts only the optional --yes flag." >&2
			exit 1
		fi
		for command_name in ssh php gzip; do
			if ! command -v "$command_name" >/dev/null 2>&1; then
				echo "ERROR: Required host command is missing: $command_name" >&2
				exit 1
			fi
		done

		CONFIG_OUTPUT="$(mktemp "${TMPDIR:-/tmp}/anyape-wp-test-tools-db-refresh.XXXXXX")"
		if ! php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/database-refresh-config.php" "$ANYAPE_WP_TEST_TOOLS_DIR/db-refresh-local.php" > "$CONFIG_OUTPUT"; then
			rm -f "$CONFIG_OUTPUT"
			exit 1
		fi
		CONFIG_VALUES=()
		while IFS= read -r -d '' value; do
			CONFIG_VALUES+=("$value")
		done < "$CONFIG_OUTPUT"
		rm -f "$CONFIG_OUTPUT"
		if ((${#CONFIG_VALUES[@]} != 5)); then
			echo "ERROR: Could not read the database-refresh configuration." >&2
			exit 1
		fi
		SSH_ALIAS="${CONFIG_VALUES[0]}"
		REMOTE_PATH="${CONFIG_VALUES[1]}"
		REMOTE_URL="${CONFIG_VALUES[2]%/}"
		LOCAL_URL="${CONFIG_VALUES[3]%/}"
		REMOTE_PATH_SHELL="${CONFIG_VALUES[4]}"

		DDEV_URL="$(ddev describe --json-output | php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/e2e-ddev-url.php")"
		URL_CHECK="$(php -r '
			$ddev = parse_url($argv[1], PHP_URL_HOST);
			$local = parse_url($argv[2], PHP_URL_HOST);
			exit(is_string($ddev) && $ddev !== "" && $ddev === $local ? 0 : 1);
		' "$DDEV_URL" "$LOCAL_URL" && printf valid || printf invalid)"
		if [[ "$URL_CHECK" != "valid" ]]; then
			echo "ERROR: Configured local_url '$LOCAL_URL' and DDEV URL '$DDEV_URL' must use the same host name." >&2
			exit 1
		fi

		confirm "pull $SSH_ALIAS" "This reads the WordPress database at '$SSH_ALIAS:$REMOTE_PATH' without changing the remote site. It saves a copy of every local DDEV database, replaces only the local working database named 'db', and changes '$REMOTE_URL' to '$LOCAL_URL'."

		RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
		RUN_DIR="$ANYAPE_WP_TEST_TOOLS_DIR/runtime/db-pulls/$RUN_ID"
		ARCHIVE="$RUN_DIR/remote.sql.gz"
		SNAPSHOT_NAME="before-db-pull-$RUN_ID"
		umask 077
		mkdir -p "$RUN_DIR"

		REMOTE_COMMAND="set -euo pipefail; cd $REMOTE_PATH_SHELL; wp db export - --add-drop-table --single-transaction --max_allowed_packet=1G | gzip -c"
		printf -v REMOTE_COMMAND_SHELL '%q' "$REMOTE_COMMAND"
		echo "Streaming a compressed database export from '$SSH_ALIAS'..."
		ssh "$SSH_ALIAS" "bash -lc $REMOTE_COMMAND_SHELL" > "$ARCHIVE"
		if [[ ! -s "$ARCHIVE" ]]; then
			echo "ERROR: Remote database export produced an empty archive: $ARCHIVE" >&2
			exit 1
		fi
		gzip -t "$ARCHIVE"
		echo "Verified compressed database archive '$ARCHIVE'."

		anyape_wp_test_tools_run_logged "Saving the current local databases as '$SNAPSHOT_NAME'..." ddev snapshot --name "$SNAPSHOT_NAME"
		PULL_CHANGED=0
		restore_failed_pull() {
			local status=$?
			trap - EXIT
			if ((status != 0 && PULL_CHANGED)); then
				echo "Database refresh failed; restoring automatic snapshot '$SNAPSHOT_NAME'..." >&2
				if ! ddev snapshot restore "$SNAPSHOT_NAME"; then
					echo "ERROR: Automatic restoration also failed. Restore manually with: composer restore -- $SNAPSHOT_NAME" >&2
				fi
			fi
			exit "$status"
		}
		trap restore_failed_pull EXIT
		PULL_CHANGED=1
		anyape_wp_test_tools_run_logged "Importing the remote copy into local database 'db'..." ddev import-db --database=db --file="$ARCHIVE"
		anyape_wp_test_tools_run_logged "Replacing the remote site address with the local address..." ddev wp --path=/var/www/html search-replace "$REMOTE_URL" "$LOCAL_URL" --all-tables --skip-columns=guid --precise
		UPDATED_SITE_URL="$(ddev wp --path=/var/www/html option get siteurl --skip-plugins --skip-themes)"
		if [[ "${UPDATED_SITE_URL%/}" != "$LOCAL_URL" ]]; then
			echo "ERROR: Imported WordPress site URL '$UPDATED_SITE_URL' does not equal configured local_url '$LOCAL_URL'." >&2
			exit 1
		fi
		PULL_CHANGED=0
		trap - EXIT
		echo "Refreshed local database 'db'. Automatic snapshot: '$SNAPSHOT_NAME'."
		;;
	*)
		echo "ERROR: Expected database action 'pull', 'snapshot', 'restore', or 'reset-tests'." >&2
		exit 1
		;;
esac

anyape_wp_test_tools_report_log
