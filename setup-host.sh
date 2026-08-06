#!/usr/bin/env bash

set -euo pipefail

TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$TOOLKIT_DIR")"
CHECK_ONLY=0
YES=0
RUN_TESTS=0
DATABASE_CHOICE=""

for argument in "$@"; do
	case "$argument" in
		--check) CHECK_ONLY=1 ;;
		--yes) YES=1 ;;
		--run-tests) RUN_TESTS=1 ;;
		--database=keep|--database=clean|--database=pull) DATABASE_CHOICE="${argument#*=}" ;;
		*)
			echo "ERROR: Unknown setup option '$argument'." >&2
			echo "Usage: bash .test-tools/setup-host.sh [--check] [--yes] [--database=keep|clean|pull] [--run-tests]" >&2
			exit 1
			;;
	esac
done

for command_name in php ddev composer node npm git; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "ERROR: Required host command is missing: $command_name" >&2
		exit 1
	fi
done

REPORT_FILE="$(mktemp "${TMPDIR:-/tmp}/wp-test-setup.XXXXXX")"
cleanup_setup_report() {
	rm -f "$REPORT_FILE"
}
trap cleanup_setup_report EXIT INT TERM HUP

php "$TOOLKIT_DIR/bin/inspect-setup.php" "$PROJECT_ROOT" > "$REPORT_FILE"

report_value() {
	php -r '$data=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); $value=$data[$argv[2]] ?? null; if (is_bool($value)) { echo $value ? "1" : "0"; } elseif (is_array($value)) { echo implode(",", $value); } elseif ($value !== null) { echo $value; }' "$REPORT_FILE" "$1"
}

confirm_change() {
	local prompt="$1"
	if ((YES)); then
		return 0
	fi
	printf '%s [y/N] ' "$prompt"
	local answer
	IFS= read -r answer
	[[ "$answer" == "y" || "$answer" == "Y" || "$answer" == "yes" || "$answer" == "YES" ]]
}

copy_optional_config() {
	local source="$1"
	local destination="$2"
	local label="$3"
	if [[ -f "$destination" ]]; then
		echo "Complete: $label already exists."
		return
	fi
	if ((YES)); then
		echo "Manual: $label was not created because it needs project-specific choices."
		return
	fi
	if confirm_change "Create the $label example now?"; then
		cp "$source" "$destination"
		echo "Created $destination; edit its placeholder values before use."
	else
		echo "Skipped: $label."
	fi
}

check_file_update() {
	local label="$1"
	shift
	local result
	if result="$("$@" 2>&1)"; then
		local changed
		changed="$(php -r '$data=json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR); echo !empty($data["changed"]) ? "1" : "0";' "$result")"
		if [[ "$changed" == "1" ]]; then
			echo "Pending: $label"
		else
			echo "Complete: $label"
		fi
	else
		echo "Manual: $label"
		echo "$result" >&2
	fi
}

echo "WordPress guided setup"
echo "Project: $PROJECT_ROOT"
echo

if [[ "$(report_value wordpress_valid)" != "1" ]]; then
	echo "ERROR: This is not a complete WordPress directory. Missing: $(report_value missing_paths)" >&2
	exit 1
fi
if [[ "$(report_value ddev_config_exists)" != "1" || "$(report_value ddev_wordpress_exists)" != "1" ]]; then
	echo "ERROR: Run 'ddev config --project-type=wordpress --docroot=.' before guided setup. The .ddev/config.yaml and wp-config-ddev.php files are required." >&2
	exit 1
fi

WP_CONFIG_STATUS="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["wp_config"]["status"];' "$REPORT_FILE")"
echo "wp-config.php: $WP_CONFIG_STATUS"
echo "DDEV Subversion package: $([[ "$(report_value subversion_configured)" == "1" ]] && echo configured || echo missing)"
echo "Root composer.json: $([[ "$(report_value root_composer_exists)" == "1" ]] && echo present || echo missing)"
echo "Project .gitignore: $([[ "$(report_value root_gitignore_exists)" == "1" ]] && echo present || echo missing)"
echo "SFTP configuration: $([[ "$(report_value sftp_config_exists)" == "1" ]] && echo present || echo absent)"

if ((CHECK_ONLY)); then
	echo
	check_file_update "wp-config.php needs an approved update." php "$TOOLKIT_DIR/bin/update-wp-config.php" --check "$PROJECT_ROOT/wp-config.php"
	check_file_update "root composer.json needs the current public command list." php "$TOOLKIT_DIR/bin/update-root-composer.php" --check "$PROJECT_ROOT/composer.json" "$TOOLKIT_DIR/composer.json"
	if [[ "$(report_value git_mode)" == "parent" ]]; then
		check_file_update "project .gitignore needs local setup paths." php "$TOOLKIT_DIR/bin/update-ignore-files.php" --check "$PROJECT_ROOT" git
	fi
	if [[ "$(report_value sftp_config_exists)" == "1" ]]; then
		check_file_update ".vscode/sftp.json needs local deployment exclusions." php "$TOOLKIT_DIR/bin/update-ignore-files.php" --check "$PROJECT_ROOT" sftp
	fi
	echo "Check complete; no files, packages, services, or databases were changed."
	exit 0
fi

if [[ "$WP_CONFIG_STATUS" == "manual" ]]; then
	php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach ($d["wp_config"]["reasons"] as $reason) { fwrite(STDERR, "ERROR: {$reason}\n"); }' "$REPORT_FILE"
	echo "ERROR: wp-config.php was not changed. Follow the manual wp-config.php section in SETUP.md, then run setup again." >&2
	exit 1
fi

if [[ "$(report_value git_mode)" == "parent" ]]; then
	if confirm_change "Add toolkit-local paths and backup names to the project .gitignore?"; then
		php "$TOOLKIT_DIR/bin/update-ignore-files.php" "$PROJECT_ROOT" git
	else
		echo "Skipped: project .gitignore."
	fi
else
	echo "Manual: no parent Git repository was found, so no project .gitignore was changed."
fi

if [[ "$(report_value sftp_config_exists)" == "1" ]]; then
	if ((YES)); then
		echo "Manual: .vscode/sftp.json was not changed because deployment policy needs an explicit answer."
	elif confirm_change "Add local toolkit paths and backup names to .vscode/sftp.json?"; then
		php "$TOOLKIT_DIR/bin/update-ignore-files.php" "$PROJECT_ROOT" sftp
	else
		echo "Skipped: .vscode/sftp.json."
	fi
fi

if [[ "$WP_CONFIG_STATUS" == "update" ]]; then
	echo "Proposed wp-config.php changes: keep existing remote values, complete the DDEV-only database and debug arrangement, add the local debug-log path, and load wp-config-ddev.php before WordPress starts."
	if ! confirm_change "Back up and adapt wp-config.php for DDEV?"; then
		echo "ERROR: wp-config.php setup was declined; setup cannot safely continue." >&2
		exit 1
	fi
	php "$TOOLKIT_DIR/bin/update-wp-config.php" "$PROJECT_ROOT/wp-config.php"
else
	echo "Complete: wp-config.php already has the supported DDEV arrangement."
fi

echo "Proposed root composer.json changes: preserve existing packages and unrelated commands, set an unlimited command timeout, and add the public .test-tools commands."
if confirm_change "Merge all toolkit commands into the root composer.json?"; then
	php "$TOOLKIT_DIR/bin/update-root-composer.php" "$PROJECT_ROOT/composer.json" "$TOOLKIT_DIR/composer.json"
else
	echo "ERROR: Root Composer command setup was declined; setup cannot continue." >&2
	exit 1
fi

copy_optional_config "$TOOLKIT_DIR/wp-test.config.example.php" "$PROJECT_ROOT/.wp-test.php" ".wp-test.php project test configuration"
copy_optional_config "$TOOLKIT_DIR/db-refresh-config-example.php" "$TOOLKIT_DIR/db-refresh.local.php" "remote database refresh configuration"

DDEV_RESTART_NEEDED=0
if [[ "$(report_value subversion_configured)" != "1" ]]; then
	if confirm_change "Add Subversion to the DDEV web image? This rebuilds the image on the next start."; then
		EXISTING_PACKAGES="$(report_value ddev_packages)"
		PACKAGES="${EXISTING_PACKAGES:+$EXISTING_PACKAGES,}subversion"
		ddev config --webimage-extra-packages="$PACKAGES"
		DDEV_RESTART_NEEDED=1
	else
		echo "ERROR: Subversion is required to download the matching WordPress PHP test library." >&2
		exit 1
	fi
else
	echo "Complete: Subversion is already configured in DDEV."
fi

if ! ddev exec --raw true >/dev/null 2>&1; then
	if confirm_change "Start the DDEV project now?"; then
		ddev start
	else
		echo "ERROR: DDEV must be running to finish setup." >&2
		exit 1
	fi
elif ((DDEV_RESTART_NEEDED)); then
	echo "Restarting DDEV so the confirmed Subversion package is available..."
	ddev restart
elif ! ddev exec --raw command -v svn >/dev/null 2>&1; then
	if confirm_change "Subversion is configured but missing from the running container. Restart DDEV now?"; then
		ddev restart
	else
		echo "ERROR: Restart DDEV before finishing setup so Subversion is available." >&2
		exit 1
	fi
fi

if [[ ! -f "$TOOLKIT_DIR/vendor/autoload.php" ]]; then
	echo "Installing toolkit PHP packages inside DDEV..."
else
	echo "Checking toolkit PHP packages against composer.lock..."
fi
ddev exec --dir=/var/www/html/.test-tools composer install
if [[ ! -x "$TOOLKIT_DIR/node_modules/.bin/playwright" ]]; then
	echo "Installing toolkit Node.js packages on the host..."
else
	echo "Checking toolkit Node.js packages against package-lock.json..."
fi
npm --prefix "$TOOLKIT_DIR" install
echo "Checking the host Chromium installation used by browser tests..."
(
	cd "$TOOLKIT_DIR"
	npx playwright install chromium
)

if [[ -z "$DATABASE_CHOICE" ]]; then
	if ((YES)); then
		echo "ERROR: --yes requires an explicit --database=keep, --database=clean, or --database=pull choice." >&2
		exit 1
	fi
	printf 'Working database choice [keep/clean/pull]: '
	IFS= read -r DATABASE_CHOICE
fi

case "$DATABASE_CHOICE" in
	keep)
		if ! ddev wp --path=/var/www/html core is-installed >/dev/null 2>&1; then
			echo "ERROR: The working database does not contain an installed WordPress site. Choose clean or pull." >&2
			exit 1
		fi
		echo "Complete: keeping the existing working database 'db'."
		;;
	clean)
		if ddev wp --path=/var/www/html core is-installed >/dev/null 2>&1; then
			echo "ERROR: Clean installation is only allowed when WordPress is not installed. Use the confirmed database tools rather than hiding a working-database deletion inside setup." >&2
			exit 1
		fi
		if ((YES)); then
			echo "ERROR: Clean installation needs interactive site and administrator values; it cannot be selected with --yes." >&2
			exit 1
		fi
		printf 'Local site URL: '; IFS= read -r SITE_URL
		printf 'Site title: '; IFS= read -r SITE_TITLE
		printf 'Administrator login: '; IFS= read -r ADMIN_USER
		printf 'Administrator email: '; IFS= read -r ADMIN_EMAIL
		ddev wp --path=/var/www/html core install --url="$SITE_URL" --title="$SITE_TITLE" --admin_user="$ADMIN_USER" --admin_email="$ADMIN_EMAIL" --prompt=admin_password
		;;
	pull)
		if [[ ! -f "$TOOLKIT_DIR/db-refresh.local.php" ]]; then
			echo "ERROR: Edit .test-tools/db-refresh.local.php before choosing the remote database refresh." >&2
			exit 1
		fi
		if ((YES)); then
			bash "$TOOLKIT_DIR/database-host.sh" pull --yes
		else
			bash "$TOOLKIT_DIR/database-host.sh" pull
		fi
		;;
	*)
		echo "ERROR: Database choice must be keep, clean, or pull." >&2
		exit 1
		;;
esac

ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS wp_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON wp_tests.* TO 'db'@'%'; FLUSH PRIVILEGES;"
echo "Complete: test database 'wp_tests' exists and is available to the DDEV database user."

bash "$TOOLKIT_DIR/doctor-host.sh"
bash "$TOOLKIT_DIR/run-tests-host.sh" --profile=harness

if ((RUN_TESTS)); then
	bash "$TOOLKIT_DIR/run-all-host.sh"
elif ((!YES)) && confirm_change "Run all PHP and browser tests now?"; then
	bash "$TOOLKIT_DIR/run-all-host.sh"
else
	echo "Skipped: full test run. Run 'composer test' when ready."
fi

echo
echo "Guided setup complete."
echo "Review any lines marked Manual or Skipped, then use 'composer test' for the complete test run."
