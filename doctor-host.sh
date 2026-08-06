#!/usr/bin/env bash

set -euo pipefail

ANYAPE_WP_TEST_TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$ANYAPE_WP_TEST_TOOLS_DIR")"

cd "$PROJECT_ROOT"

if ! command -v ddev >/dev/null 2>&1; then
	echo "ERROR: DDEV is not available on the host PATH." >&2
	exit 1
fi

if ! ddev exec --raw true >/dev/null 2>&1; then
	echo "ERROR: The DDEV project is not running. Start it explicitly with 'ddev start'." >&2
	exit 1
fi

exec ddev exec --raw env \
	XDEBUG_MODE=off \
	php /var/www/html/.anyape-wp-test-tools/bin/doctor.php \
	"$@"
