#!/usr/bin/env bash

set -euo pipefail

ANYAPE_WP_TEST_TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$ANYAPE_WP_TEST_TOOLS_DIR")"

if [[ "${1:-}" == "--container" ]]; then
	shift
	PHPUNIT_ARGS=("$@")
	PHPUNIT_ENV=(env XDEBUG_MODE=off)
	PHPUNIT_EXECUTABLE=("$ANYAPE_WP_TEST_TOOLS_DIR/vendor/bin/phpunit")
	PROFILE="${ANYAPE_WP_TEST_TOOLS_PROFILE:-default}"
	TARGET="${ANYAPE_WP_TEST_TOOLS_TARGET:-}"
	RUNTIME_DIR="$ANYAPE_WP_TEST_TOOLS_DIR/runtime"

	php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/doctor.php" --quiet

	mkdir -p "$RUNTIME_DIR"

	BUILD_ARGS=(--profile="$PROFILE")

	if [[ -n "$TARGET" ]]; then
		BUILD_ARGS+=(--target="$TARGET")
	fi

	if [[ "$PROFILE" != "harness" ]]; then
		wp --path="$PROJECT_ROOT" --skip-plugins --skip-themes eval-file --use-include \
			"$ANYAPE_WP_TEST_TOOLS_DIR/bin/capture-working-state.php" \
			> "$RUNTIME_DIR/working-site.json"
	fi

	php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/build-manifest.php" "${BUILD_ARGS[@]}"
	"$ANYAPE_WP_TEST_TOOLS_DIR/sync-wordpress-tests.sh"
	php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/prepare-runtime.php"
	php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/build-phpunit-config.php"

	if [[ "${ANYAPE_WP_TEST_TOOLS_COVERAGE:-0}" == "1" ]]; then
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
				"$ANYAPE_WP_TEST_TOOLS_DIR/vendor/bin/phpunit"
			)
		else
			echo "ERROR: Coverage requires Xdebug or PCOV in the DDEV web container." >&2
			exit 1
		fi

		PHPUNIT_ARGS+=(
			--coverage-html
			"$ANYAPE_WP_TEST_TOOLS_DIR/coverage"
		)
	fi

	if [[ "${ANYAPE_WP_TEST_TOOLS_JUNIT:-0}" == "1" ]]; then
		PHPUNIT_ARGS+=(
			--log-junit
			"$ANYAPE_WP_TEST_TOOLS_DIR/runtime/junit.xml"
		)
	fi

	exec "${PHPUNIT_ENV[@]}" \
		"${PHPUNIT_EXECUTABLE[@]}" \
		-c "$ANYAPE_WP_TEST_TOOLS_DIR/runtime/phpunit.xml" \
		"${PHPUNIT_ARGS[@]}"
fi

cd "$PROJECT_ROOT"

PROFILE="default"
TARGET=""
COVERAGE=0
JUNIT=0
INCLUDE_DESTRUCTIVE=0
PHPUNIT_ARGS=()

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
		--coverage)
			COVERAGE=1
			shift
			;;
		--junit)
			JUNIT=1
			shift
			;;
		--include-destructive)
			INCLUDE_DESTRUCTIVE=1
			shift
			;;
		--)
			shift
			PHPUNIT_ARGS+=("$@")
			break
			;;
		*)
			if [[ ("$PROFILE" == "plugin" || "$PROFILE" == "theme") && -z "$TARGET" && "$1" != --* ]]; then
				TARGET="$1"
			else
				PHPUNIT_ARGS+=("$1")
			fi
			shift
			;;
	esac
done

case "$PROFILE" in
	default|harness|plugin|theme|multisite)
		;;
	*)
		echo "ERROR: Unknown test profile '$PROFILE'." >&2
		exit 1
		;;
esac

if [[ ("$PROFILE" == "plugin" || "$PROFILE" == "theme") && -z "$TARGET" ]]; then
	echo "ERROR: The '$PROFILE' profile requires an extension slug." >&2
	exit 1
fi

if ! command -v ddev >/dev/null 2>&1; then
	echo "ERROR: DDEV is not available on the host PATH." >&2
	exit 1
fi

exec ddev exec --raw env \
	XDEBUG_MODE=off \
	ANYAPE_WP_TEST_TOOLS_PROFILE="$PROFILE" \
	ANYAPE_WP_TEST_TOOLS_TARGET="$TARGET" \
	ANYAPE_WP_TEST_TOOLS_COVERAGE="$COVERAGE" \
	ANYAPE_WP_TEST_TOOLS_JUNIT="$JUNIT" \
	ANYAPE_WP_TEST_TOOLS_INCLUDE_DESTRUCTIVE="$INCLUDE_DESTRUCTIVE" \
	/var/www/html/.anyape-wp-test-tools/run-tests-host.sh --container \
	"${PHPUNIT_ARGS[@]}"
