#!/usr/bin/env bash

set -euo pipefail

TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$TOOLKIT_DIR")"

if [[ "${1:-}" == "--container" ]]; then
	shift
	PHPUNIT_ARGS=("$@")
	PHPUNIT_ENV=(env XDEBUG_MODE=off)
	PHPUNIT_EXECUTABLE=("$TOOLKIT_DIR/vendor/bin/phpunit")
	PROFILE="${WP_TEST_PROFILE:-default}"
	TARGET="${WP_TEST_TARGET:-}"
	RUNTIME_DIR="$TOOLKIT_DIR/runtime"

	php "$TOOLKIT_DIR/bin/doctor.php" --quiet

	mkdir -p "$RUNTIME_DIR"

	BUILD_ARGS=(--profile="$PROFILE")

	if [[ -n "$TARGET" ]]; then
		BUILD_ARGS+=(--target="$TARGET")
	fi

	if [[ "$PROFILE" != "harness" ]]; then
		wp --path="$PROJECT_ROOT" --skip-plugins --skip-themes eval-file --use-include \
			"$TOOLKIT_DIR/bin/capture-working-state.php" \
			> "$RUNTIME_DIR/working-site.json"
	fi

	php "$TOOLKIT_DIR/bin/build-manifest.php" "${BUILD_ARGS[@]}"
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
	WP_TEST_PROFILE="$PROFILE" \
	WP_TEST_TARGET="$TARGET" \
	WP_TEST_COVERAGE="$COVERAGE" \
	WP_TEST_JUNIT="$JUNIT" \
	WP_TEST_INCLUDE_DESTRUCTIVE="$INCLUDE_DESTRUCTIVE" \
	/var/www/html/.test-tools/run-tests-host.sh --container \
	"${PHPUNIT_ARGS[@]}"
