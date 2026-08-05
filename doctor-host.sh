#!/usr/bin/env bash

set -euo pipefail

TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$TOOLKIT_DIR")"

cd "$PROJECT_ROOT"

if ! command -v ddev >/dev/null 2>&1; then
	echo "ERROR: DDEV is not available on the host PATH." >&2
	exit 1
fi

if ! ddev exec --raw true >/dev/null 2>&1; then
	echo "ERROR: The DDEV project is not running. Start it explicitly with 'ddev start'." >&2
	exit 1
fi

DDEV_DATABASE_CONFIG="$TOOLKIT_DIR/bin/read-ddev-database-config.php"

if [[ ! -f "$DDEV_DATABASE_CONFIG" ]]; then
	echo "ERROR: Missing DDEV database configuration reader: $DDEV_DATABASE_CONFIG" >&2
	exit 1
fi

database_values="$(
	ddev exec --raw php \
		/var/www/html/.test-tools/bin/read-ddev-database-config.php
)"

database_name="$(printf '%s\n' "$database_values" | sed -n '1p')"
database_host="$(printf '%s\n' "$database_values" | sed -n '2p')"
database_user="$(printf '%s\n' "$database_values" | sed -n '3p')"
database_password="$(printf '%s\n' "$database_values" | sed -n '4p')"

if [[ -z "$database_name" || -z "$database_host" || -z "$database_user" ]]; then
	echo "ERROR: Could not read complete database settings from wp-config-ddev.php." >&2
	exit 1
fi

exec ddev exec --raw env \
	DB_NAME="$database_name" \
	DB_HOST="$database_host" \
	DB_USER="$database_user" \
	DB_PASSWORD="$database_password" \
	php /var/www/html/.test-tools/bin/doctor.php \
	"$@"
