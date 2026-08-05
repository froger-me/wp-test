#!/usr/bin/env bash

set -euo pipefail

TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$TOOLKIT_DIR")"
RUNTIME_DIR="$TOOLKIT_DIR/runtime"

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

bash "$TOOLKIT_DIR/doctor-host.sh" --quiet

mkdir -p "$RUNTIME_DIR"

ddev exec --raw env XDEBUG_MODE=off \
	wp --path=/var/www/html option get active_plugins --format=json \
	> "$RUNTIME_DIR/working-active-plugins.json"

ddev exec --raw env XDEBUG_MODE=off \
	wp --path=/var/www/html option get stylesheet \
	> "$RUNTIME_DIR/working-stylesheet.txt"

ddev exec --raw env XDEBUG_MODE=off \
	wp --path=/var/www/html option get template \
	> "$RUNTIME_DIR/working-template.txt"

BUILD_ARGS=(--profile="$PROFILE")

if [[ -n "$TARGET" ]]; then
	BUILD_ARGS+=(--target="$TARGET")
fi

ddev exec --raw env XDEBUG_MODE=off \
	php /var/www/html/.test-tools/bin/build-manifest.php \
	"${BUILD_ARGS[@]}"

exec ddev exec --raw env \
	XDEBUG_MODE=off \
	WP_TEST_COVERAGE="$COVERAGE" \
	WP_TEST_JUNIT="$JUNIT" \
	WP_TEST_INCLUDE_DESTRUCTIVE="$INCLUDE_DESTRUCTIVE" \
	/var/www/html/.test-tools/run-tests.sh \
	"${PHPUNIT_ARGS[@]}"
