#!/usr/bin/env bash

set -euo pipefail

ANYAPE_WP_TEST_TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
PROJECT_ROOT="$(dirname "$ANYAPE_WP_TEST_TOOLS_DIR")"
DRY_RUN=0

if [[ "${1:-}" == "--dry-run" && $# -eq 1 ]]; then
	DRY_RUN=1
elif (($# != 0)); then
	echo "Usage: composer anyape-wp-test-tools:uninstall [-- --dry-run]" >&2
	exit 2
fi

fail() {
	echo "ERROR: $1" >&2
	exit 1
}

[[ "$(basename "$ANYAPE_WP_TEST_TOOLS_DIR")" == ".anyape-wp-test-tools" ]] || fail "The command is not running from the expected .anyape-wp-test-tools directory."
[[ -d "$PROJECT_ROOT/wp-admin" && -d "$PROJECT_ROOT/wp-content" && -d "$PROJECT_ROOT/wp-includes" ]] || fail "The parent directory is not a complete WordPress project."
[[ -f "$ANYAPE_WP_TEST_TOOLS_DIR/composer.json" ]] || fail "Anyape WP Test Tools composer.json is missing."
[[ "$(php -r '$data=json_decode(file_get_contents($argv[1]),true); echo $data["name"] ?? "";' "$ANYAPE_WP_TEST_TOOLS_DIR/composer.json")" == "anyape/anyape-wp-test-tools" ]] || fail "The package marker does not identify Anyape WP Test Tools."
if git -C "$ANYAPE_WP_TEST_TOOLS_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	UNCOMMITTED_CHANGES="$(git -C "$ANYAPE_WP_TEST_TOOLS_DIR" status --porcelain --untracked-files=all)"
	if [[ -n "$UNCOMMITTED_CHANGES" ]]; then
		fail "Anyape WP Test Tools contains uncommitted changes. Nothing was changed. Commit or copy those changes before uninstalling."
	fi
fi
[[ -f "$PROJECT_ROOT/.ddev/config.yaml" ]] || fail "The associated .ddev/config.yaml file is missing."
command -v ddev >/dev/null 2>&1 || fail "DDEV is not installed."

php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/uninstall-project.php" --check "$PROJECT_ROOT" "$ANYAPE_WP_TEST_TOOLS_DIR/composer.json" >/dev/null

echo "Anyape WP Test Tools uninstall"
echo "Project: $PROJECT_ROOT"
echo
echo "This permanently removes:"
echo "  - the associated DDEV project, containers, databases, snapshots, and registration;"
echo "  - the complete $ANYAPE_WP_TEST_TOOLS_DIR directory;"
echo "  - .ddev, wp-config-ddev.php, and .anyape-wp-test-tools.php;"
echo "  - generated Anyape WP Test Tools files and dated setup backups; and"
echo "  - Anyape WP Test Tools commands and exclusions from shared project files."
echo
echo "wp-config.php will be changed back to direct remote database settings and validated before any deletion starts."
echo "The working DDEV database will not be saved. This cannot be undone by this command."

if ((DRY_RUN)); then
	echo
	echo "Dry run complete. Nothing was changed."
	exit 0
fi

echo
if [[ ! -r /dev/tty ]]; then
	fail "Exact confirmation requires an interactive terminal. Nothing was changed."
fi
if ! read -r -p "Type 'uninstall anyape wp test tools' to continue: " confirmation </dev/tty; then
	echo "Uninstall cancelled. Nothing was changed."
	exit 1
fi
if [[ "$confirmation" != "uninstall anyape wp test tools" ]]; then
	echo "Uninstall cancelled. Nothing was changed."
	exit 1
fi

echo
echo "Deleting the associated DDEV project and all of its local data..."
(
	cd "$PROJECT_ROOT"
	ddev delete -Oy --skip-hooks
)

echo "Restoring wp-config.php and cleaning shared project files..."
php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/uninstall-project.php" "$PROJECT_ROOT" "$ANYAPE_WP_TEST_TOOLS_DIR/composer.json" >/dev/null

echo "Removing generated configuration, DDEV files, and setup backups..."
rm -rf -- "$PROJECT_ROOT/.ddev"
rm -f -- \
	"$PROJECT_ROOT/.anyape-wp-test-tools.php" \
	"$PROJECT_ROOT/wp-config-ddev.php" \
	"$PROJECT_ROOT/wp-content/mu-plugins/anyape-wp-test-tools-e2e.php"

shopt -s nullglob
setup_backups=(
	"$PROJECT_ROOT"/wp-config.php.before-anyape-wp-test-tools-*
	"$PROJECT_ROOT"/composer.json.before-anyape-wp-test-tools-*
	"$PROJECT_ROOT"/.gitignore.before-anyape-wp-test-tools-*
)
if ((${#setup_backups[@]})); then
	rm -f -- "${setup_backups[@]}"
fi

echo "Removing Anyape WP Test Tools itself..."
cd "$PROJECT_ROOT"
rm -rf -- "$ANYAPE_WP_TEST_TOOLS_DIR"

echo
echo "Anyape WP Test Tools and the associated DDEV project were completely removed."
