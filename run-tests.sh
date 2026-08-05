#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

"$ROOT_DIR/.test-tools/sync-wordpress-tests.sh"

exec "$ROOT_DIR/.test-tools/vendor/bin/phpunit" \
	-c "$ROOT_DIR/.test-tools/phpunit.xml.dist" \
	"$@"
