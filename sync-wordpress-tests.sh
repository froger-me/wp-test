#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ANYAPE_WP_TEST_TOOLS_DIR="$ROOT_DIR/.anyape-wp-test-tools"
CORE_DIR="$ANYAPE_WP_TEST_TOOLS_DIR/wordpress"
TESTS_DIR="$ANYAPE_WP_TEST_TOOLS_DIR/wordpress-tests-lib"
STATE_FILE="$ANYAPE_WP_TEST_TOOLS_DIR/.wordpress-test-version"
CONFIG_FILE="$ANYAPE_WP_TEST_TOOLS_DIR/config.php"

SETTINGS=()
while IFS= read -r -d '' value; do
	SETTINGS+=("$value")
done < <(
	php -r '
		require $argv[1];
		$config = require $argv[2];
		foreach (
			array(
				$wp_version,
				$config["test_database"],
				$config["database_host"],
				$config["table_prefix"],
			) as $value
		) {
			fwrite(STDOUT, (string) $value . "\0");
		}
	' "$ROOT_DIR/wp-includes/version.php" "$CONFIG_FILE"
)

if ((${#SETTINGS[@]} != 4)); then
	echo "ERROR: Could not read the WordPress test synchronization settings." >&2
	exit 1
fi

WP_VERSION="${SETTINGS[0]}"
TEST_DATABASE="${SETTINGS[1]}"
DATABASE_HOST="${SETTINGS[2]}"
TABLE_PREFIX="${SETTINGS[3]}"

if [[ -z "$WP_VERSION" || -z "$TEST_DATABASE" || -z "$DATABASE_HOST" || -z "$TABLE_PREFIX" ]]; then
	echo "ERROR: WordPress test synchronization settings must not be empty." >&2
	exit 1
fi

if [[ "$WP_VERSION" == *-* ]]; then
	echo "ERROR: Prerelease WordPress versions are not supported automatically: $WP_VERSION" >&2
	exit 1
fi

if (
	[[ -f "$STATE_FILE" ]] &&
	[[ "$(cat "$STATE_FILE")" == "$WP_VERSION" ]] &&
	[[ -f "$CORE_DIR/wp-includes/version.php" ]] &&
	[[ -f "$TESTS_DIR/includes/bootstrap.php" ]] &&
	[[ -f "$TESTS_DIR/wp-tests-config.php" ]]
); then
	echo "WordPress test environment is already synchronized to $WP_VERSION."
	exit 0
fi

echo "Synchronizing WordPress test environment to $WP_VERSION..."

ARCHIVE="$(mktemp)"
trap 'rm -f "$ARCHIVE"' EXIT

curl -fsSL \
	"https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" \
	-o "$ARCHIVE"

rm -rf "$CORE_DIR" "$TESTS_DIR"
mkdir -p "$CORE_DIR" "$TESTS_DIR"

tar \
	--extract \
	--gzip \
	--file="$ARCHIVE" \
	--strip-components=1 \
	--directory="$CORE_DIR"

TESTS_REF="tags/$WP_VERSION"

svn export --quiet \
	"https://develop.svn.wordpress.org/${TESTS_REF}/tests/phpunit/includes/" \
	"$TESTS_DIR/includes"

svn export --quiet \
	"https://develop.svn.wordpress.org/${TESTS_REF}/tests/phpunit/data/" \
	"$TESTS_DIR/data"

svn export --quiet \
	"https://develop.svn.wordpress.org/${TESTS_REF}/wp-tests-config-sample.php" \
	"$TESTS_DIR/wp-tests-config.php"

php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/configure-wordpress-tests.php" \
	"$TESTS_DIR/wp-tests-config.php" \
	"$CORE_DIR" \
	"$TEST_DATABASE" \
	"$DATABASE_HOST" \
	"$TABLE_PREFIX"

printf '%s\n' "$WP_VERSION" > "$STATE_FILE"

echo "WordPress test environment synchronized to $WP_VERSION."
