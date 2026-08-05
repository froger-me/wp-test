#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLKIT_DIR="$ROOT_DIR/.test-tools"
PHPUNIT_ARGS=("$@")
PHPUNIT_ENV=(env XDEBUG_MODE=off)
PHPUNIT_EXECUTABLE=("$TOOLKIT_DIR/vendor/bin/phpunit")

php "$TOOLKIT_DIR/bin/doctor.php" --quiet
"$TOOLKIT_DIR/sync-wordpress-tests.sh"
php "$TOOLKIT_DIR/bin/prepare-runtime.php"
php "$TOOLKIT_DIR/bin/build-phpunit-config.php"

if [[ "${WP_TEST_COVERAGE:-0}" == "1" ]]; then
	if php -m | grep -Eiq '^xdebug$'; then
		if ! env XDEBUG_MODE=coverage php -r '
			exit(
				function_exists("xdebug_info") &&
				in_array("coverage", xdebug_info("mode"), true)
					? 0
					: 1
			);
		'; then
			echo "ERROR: Xdebug is loaded but coverage mode could not be enabled." >&2
			exit 1
		fi

		PHPUNIT_ENV=(env XDEBUG_MODE=coverage)
	elif php -m | grep -Eiq '^pcov$'; then
		PHPUNIT_EXECUTABLE=(
			php
			-d
			pcov.enabled=1
			"$TOOLKIT_DIR/vendor/bin/phpunit"
		)
	else
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

exec "${PHPUNIT_ENV[@]}" \
	"${PHPUNIT_EXECUTABLE[@]}" \
	-c "$TOOLKIT_DIR/runtime/phpunit.xml" \
	"${PHPUNIT_ARGS[@]}"
