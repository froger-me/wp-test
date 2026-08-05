#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLKIT_DIR="$ROOT_DIR/.test-tools"
PHPUNIT_ARGS=("$@")

php "$TOOLKIT_DIR/bin/doctor.php" --quiet
"$TOOLKIT_DIR/sync-wordpress-tests.sh"
php "$TOOLKIT_DIR/bin/prepare-runtime.php"
php "$TOOLKIT_DIR/bin/build-phpunit-config.php"

if [[ "${WP_TEST_COVERAGE:-0}" == "1" ]]; then
	if ! php -m | grep -Eiq '^(xdebug|pcov)$'; then
		echo "ERROR: Coverage requires Xdebug or PCOV in the DDEV web container." >&2
		exit 1
	fi

	PHPUNIT_ARGS+=(
		--coverage-html
		"$TOOLKIT_DIR/coverage"
	)
fi

if [[ "${WP_TEST_JUNIT:-0}" == "1" ]]; then
	PHPUNIT_ARGS+=(
		--log-junit
		"$TOOLKIT_DIR/runtime/junit.xml"
	)
fi

exec "$TOOLKIT_DIR/vendor/bin/phpunit" \
	-c "$TOOLKIT_DIR/runtime/phpunit.xml" \
	"${PHPUNIT_ARGS[@]}"
