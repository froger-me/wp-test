#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS_DIR="$ROOT_DIR/.test-tools"
CORE_DIR="$TOOLS_DIR/wordpress"
TESTS_DIR="$TOOLS_DIR/wordpress-tests-lib"
STATE_FILE="$TOOLS_DIR/.wordpress-test-version"

WP_VERSION="$(
	php -r '
		require $argv[1];
		echo $wp_version;
	' "$ROOT_DIR/wp-includes/version.php"
)"

if [[ -z "$WP_VERSION" ]]; then
	echo "Could not determine the installed WordPress version." >&2
	exit 1
fi

if [[ "$WP_VERSION" == *-* ]]; then
	echo "Prerelease WordPress versions are not currently supported by this script: $WP_VERSION" >&2
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

php -r '
	$file     = $argv[1];
	$core_dir = rtrim($argv[2], "/") . "/";

	$config = file_get_contents($file);

	if ($config === false) {
		fwrite(STDERR, "Could not read wp-tests-config.php.\n");
		exit(1);
	}

	$config = str_replace(
		[
			"dirname( __FILE__ ) . \"/src/\"",
			"dirname( __FILE__ ) . '\''/src/'\''",
			"youremptytestdbnamehere",
			"yourusernamehere",
			"yourpasswordhere",
			"localhost",
		],
		[
			var_export($core_dir, true),
			var_export($core_dir, true),
			"wp_tests",
			"db",
			"db",
			"db",
		],
		$config
	);

	if (
		str_contains($config, "youremptytestdbnamehere") ||
		str_contains($config, "yourusernamehere") ||
		str_contains($config, "yourpasswordhere")
	) {
		fwrite(STDERR, "Could not configure the WordPress test database settings.\n");
		exit(1);
	}

	file_put_contents($file, $config);
' "$TESTS_DIR/wp-tests-config.php" "$CORE_DIR"

printf '%s\n' "$WP_VERSION" > "$STATE_FILE"

echo "WordPress test environment synchronized to $WP_VERSION."
