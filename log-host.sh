#!/usr/bin/env bash

set -euo pipefail

TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$TOOLKIT_DIR")"
LOG_PATH="/var/www/html/wp-content/debug.log"
ACTION="${1:-}"

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

if ! command -v ddev >/dev/null 2>&1; then
	echo "ERROR: DDEV is not available on the host PATH." >&2
	exit 1
fi

if ! ddev exec --raw true >/dev/null 2>&1; then
	echo "ERROR: The DDEV project is not running. Start it explicitly with 'ddev start'." >&2
	exit 1
fi

ddev wp --skip-plugins --skip-themes eval-file \
	/var/www/html/.test-tools/bin/prepare-debug-log.php

case "$ACTION" in
	tail)
		exec ddev exec --raw tail -F "$LOG_PATH"
		;;
	clear)
		ddev exec --raw truncate --size 0 "$LOG_PATH"
		echo "Cleared wp-content/debug.log."
		;;
esac
