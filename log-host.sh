#!/usr/bin/env bash

set -euo pipefail

TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$TOOLKIT_DIR")"
ACTION="${1:-}"
LOG_PATH="$PROJECT_ROOT/wp-content/debug.log"

if (($# > 0)); then
	shift
fi

if (($# > 0)); then
	echo "ERROR: Unexpected logging command arguments: $*" >&2
	exit 1
fi

case "$ACTION" in
	tail|clear)
		;;
	*)
		echo "ERROR: Expected logging action 'tail' or 'clear'." >&2
		exit 1
		;;
esac

cd "$PROJECT_ROOT"

php "$TOOLKIT_DIR/bin/validate-debug-log.php" \
	"$PROJECT_ROOT/wp-config.php" \
	"$LOG_PATH"

case "$ACTION" in
	tail)
		interrupted=0
		trap 'interrupted=1' INT

		if tail -F "$LOG_PATH"; then
			status=0
		else
			status=$?
		fi

		trap - INT

		if ((interrupted)) || ((status == 130)); then
			exit 0
		fi

		exit "$status"
		;;
	clear)
		truncate -s 0 "$LOG_PATH"
		echo "Cleared wp-content/debug.log."
		;;
esac
