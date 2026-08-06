#!/usr/bin/env bash

set -euo pipefail

ANYAPE_WP_TEST_TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$ANYAPE_WP_TEST_TOOLS_DIR")"
CHECK_ONLY=0
YES=0
RUN_TESTS=0
DATABASE_CHOICE=""
VERBOSE=0

for argument in "$@"; do
	case "$argument" in
		--check) CHECK_ONLY=1 ;;
		--yes) YES=1 ;;
		--run-tests) RUN_TESTS=1 ;;
		-v|--verbose) VERBOSE=1 ;;
		--database=keep|--database=clean|--database=pull) DATABASE_CHOICE="${argument#*=}" ;;
		*)
			echo "ERROR: Unknown setup option '$argument'." >&2
			echo "Usage: bash .anyape-wp-test-tools/setup-host.sh [-v|--verbose] [--check] [--yes] [--database=keep|clean|pull] [--run-tests]" >&2
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

REPORT_FILE="$(mktemp "${TMPDIR:-/tmp}/anyape-wp-test-tools-setup.XXXXXX")"
cleanup_setup_report() {
	local status=$?
	rm -f "$REPORT_FILE"
	anyape_wp_test_tools_report_log
	return "$status"
}
trap cleanup_setup_report EXIT INT TERM HUP

# The path is resolved from this script's directory.
# shellcheck disable=SC1091
source "$ANYAPE_WP_TEST_TOOLS_DIR/logging-host.sh"

refresh_setup_report() {
	php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/inspect-setup.php" "$PROJECT_ROOT" > "$REPORT_FILE"
}

suggest_ddev_project_name() {
	local suggested_name
	suggested_name="$(basename "$PROJECT_ROOT" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//')"
	printf '%s' "${suggested_name:-wordpress-project}"
}

configured_ddev_project_name() {
	php -r '
		$configuration = (string) file_get_contents($argv[1]);
		if (preg_match("/^name:\\s*[\047\"]?([A-Za-z0-9][A-Za-z0-9_-]*)[\047\"]?\\s*(?:#.*)?$/m", $configuration, $match) !== 1) {
			exit(1);
		}
		echo $match[1];
	' "$PROJECT_ROOT/.ddev/config.yaml"
}

ddev_configuration_ready() {
	[[ -f "$PROJECT_ROOT/.ddev/config.yaml" && -f "$PROJECT_ROOT/wp-config-ddev.php" ]] || return 1
	php -r '
		$configuration = (string) file_get_contents($argv[1]);
		$required = array(
			"/^type:\\s*[\047\"]?wordpress[\047\"]?\\s*(?:#.*)?$/m",
			"/^docroot:\\s*[\047\"]?\\.[\047\"]?\\s*(?:#.*)?$/m",
			"/^webserver_type:\\s*[\047\"]?apache-fpm[\047\"]?\\s*(?:#.*)?$/m",
		);
		foreach ($required as $pattern) {
			if (preg_match($pattern, $configuration) !== 1) {
				exit(1);
			}
		}
	' "$PROJECT_ROOT/.ddev/config.yaml"
}

refresh_setup_report

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

print_step() {
	echo
	echo "=== $1 ==="
	echo
}

copy_optional_config() {
	local source="$1"
	local destination="$2"
	local label="$3"
	local explanation="$4"
	if [[ -f "$destination" ]]; then
		echo "Complete: $label already exists."
		return
	fi
	if ((YES)); then
		echo "Manual: $label was not created because it needs project-specific choices."
		return
	fi
	echo "$explanation"
	echo "The new file will contain examples only. You can edit or remove it later."
	echo
	if confirm_change "Create the $label example now?"; then
		cp "$source" "$destination"
		echo "Created $destination; edit its placeholder values before use."
	else
		echo "Skipped: $label."
	fi
}

check_file_update() {
	local pending_label="$1"
	local complete_label="$2"
	shift 2
	local result
	if result="$("$@" 2>&1)"; then
		local changed
		changed="$(php -r '$data=json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR); echo !empty($data["changed"]) ? "1" : "0";' "$result")"
		if [[ "$changed" == "1" ]]; then
			echo "Pending: $pending_label"
		else
			echo "Complete: $complete_label"
		fi
	else
		echo "Manual: could not check whether $pending_label"
		echo "$result" >&2
	fi
}

file_update_needed() {
	local result
	result="$("$@")"
	php -r '$data=json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR); echo !empty($data["changed"]) ? "1" : "0";' "$result"
}

ddev_project_running() {
	local result
	if ! result="$(ddev describe -j 2>/dev/null)"; then
		return 1
	fi
	php -r '$data=json_decode($argv[1], true); $services=$data["raw"]["services"] ?? array(); exit((($services["web"]["status"] ?? "") === "running" && ($services["db"]["status"] ?? "") === "running") ? 0 : 1);' "$result"
}

echo "WordPress guided setup"
echo "Project: $PROJECT_ROOT"

if [[ "$(report_value wordpress_valid)" != "1" ]]; then
	echo "ERROR: This is not a complete WordPress directory. Missing: $(report_value missing_paths)" >&2
	exit 1
fi

if ((!CHECK_ONLY)); then
	export ANYAPE_WP_TEST_TOOLS_VERBOSE="$VERBOSE"
	ANYAPE_WP_TEST_TOOLS_SETUP_RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
	export ANYAPE_WP_TEST_TOOLS_SETUP_RUN_ID
	anyape_wp_test_tools_log_initialize "$ANYAPE_WP_TEST_TOOLS_DIR" setup
	if ((VERBOSE)); then
		echo "Detailed command output will be shown and saved to: $ANYAPE_WP_TEST_TOOLS_LOG_FILE"
	else
		echo "Detailed command output will be saved to: $ANYAPE_WP_TEST_TOOLS_LOG_FILE"
		echo "Run setup with -v or --verbose to show those details while it runs."
	fi
fi

if ! ddev_configuration_ready; then
	if ((CHECK_ONLY)); then
		DDEV_CONFIGURATION_DESCRIPTION="needs a guided change"
	else
		print_step "Create the local DDEV settings"
		if [[ -f "$PROJECT_ROOT/.ddev/config.yaml" ]]; then
			if ! DDEV_PROJECT_NAME="$(configured_ddev_project_name)"; then
				echo "ERROR: Could not read the existing DDEV project name." >&2
				exit 1
			fi
			echo "The existing DDEV settings do not yet use the required WordPress, current-directory document root, and Apache PHP server values."
			echo "The project name '$DDEV_PROJECT_NAME' and unrelated DDEV settings will be kept."
		else
			DDEV_PROJECT_NAME="$(suggest_ddev_project_name)"
			echo "DDEV needs a local project name. The suggested name comes from the WordPress directory name."
			echo "Press Enter to accept it, or type a different lowercase name using letters, numbers, and hyphens."
			echo
			if ((!YES)); then
				printf 'Local DDEV project name [%s]: ' "$DDEV_PROJECT_NAME"
				IFS= read -r requested_ddev_project_name
				DDEV_PROJECT_NAME="${requested_ddev_project_name:-$DDEV_PROJECT_NAME}"
			fi
		fi
		if [[ ! "$DDEV_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
			echo "ERROR: The DDEV project name must start with a lowercase letter or number and contain only lowercase letters, numbers, and hyphens." >&2
			exit 1
		fi
		echo
		echo "Setup will configure this WordPress directory as the DDEV document root and use the Apache PHP server."
		if ! confirm_change "Create or update the local DDEV settings now?"; then
			echo "ERROR: DDEV settings are required to continue setup." >&2
			exit 1
		fi
		(
			cd "$PROJECT_ROOT"
			anyape_wp_test_tools_run_logged "Creating the local DDEV settings..." ddev config \
				--project-name="$DDEV_PROJECT_NAME" \
				--project-type=wordpress \
				--docroot=. \
				--webserver-type=apache-fpm
		)
		refresh_setup_report
		if ! ddev_configuration_ready; then
			echo "ERROR: DDEV did not create the required .ddev/config.yaml and wp-config-ddev.php settings." >&2
			exit 1
		fi
		DDEV_CONFIGURATION_DESCRIPTION="ready"
	fi
else
	DDEV_CONFIGURATION_DESCRIPTION="ready"
fi

WP_CONFIG_STATUS="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["wp_config"]["status"];' "$REPORT_FILE")"
case "$WP_CONFIG_STATUS" in
	ready) WP_CONFIG_DESCRIPTION="ready for local DDEV use" ;;
	update) WP_CONFIG_DESCRIPTION="needs a guided change" ;;
	manual) WP_CONFIG_DESCRIPTION="needs a manual review" ;;
	*) WP_CONFIG_DESCRIPTION="unknown state" ;;
esac
print_step "Current project state"
echo "DDEV settings: $DDEV_CONFIGURATION_DESCRIPTION"
echo "WordPress settings (wp-config.php): $WP_CONFIG_DESCRIPTION"
echo "Subversion program inside DDEV: $([[ "$(report_value subversion_configured)" == "1" ]] && echo configured || echo missing)"
echo "Project composer.json: $([[ "$(report_value root_composer_exists)" == "1" ]] && echo present || echo missing)"
echo "Project .gitignore: $([[ "$(report_value root_gitignore_exists)" == "1" ]] && echo present || echo missing)"
echo "File-upload settings (.vscode/sftp.json): $([[ "$(report_value sftp_config_exists)" == "1" ]] && echo present || echo absent)"

if ((CHECK_ONLY)); then
	print_step "Read-only check"
	if [[ "$DDEV_CONFIGURATION_DESCRIPTION" == "ready" ]]; then
		echo "Complete: DDEV uses the WordPress, current-directory document root, and Apache PHP server settings."
	else
		echo "Pending: guided setup will create or update DDEV with a suggested project name, WordPress type, current-directory document root, and Apache PHP server."
	fi
	check_file_update "WordPress settings need a guided change." "WordPress settings are ready for local DDEV use." php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-wp-config.php" --check "$PROJECT_ROOT/wp-config.php"
	check_file_update "project composer.json needs the test commands." "project composer.json has the current test commands." php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-root-composer.php" --check "$PROJECT_ROOT/composer.json" "$ANYAPE_WP_TEST_TOOLS_DIR/composer.json"
	if [[ "$(report_value git_mode)" == "parent" ]]; then
		check_file_update "project .gitignore needs the local-only file names." "project .gitignore excludes the local-only file names." php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-ignore-files.php" --check "$PROJECT_ROOT" git
	fi
	if [[ "$(report_value sftp_config_exists)" == "1" ]]; then
		check_file_update ".vscode/sftp.json needs the local-only upload exclusions." ".vscode/sftp.json excludes the local-only files from uploads." php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-ignore-files.php" --check "$PROJECT_ROOT" sftp
	fi
	echo "Check complete; no files, packages, services, or databases were changed."
	exit 0
fi

if [[ "$WP_CONFIG_STATUS" == "manual" ]]; then
	php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach ($d["wp_config"]["reasons"] as $reason) { fwrite(STDERR, "ERROR: {$reason}\n"); }' "$REPORT_FILE"
	echo "ERROR: wp-config.php was not changed. Follow the manual wp-config.php section in SETUP.md, then run setup again." >&2
	exit 1
fi

print_step "Keep local-only files out of Git"

if [[ "$(report_value git_mode)" == "parent" ]]; then
	GIT_IGNORE_UPDATE_NEEDED="$(file_update_needed php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-ignore-files.php" --check "$PROJECT_ROOT" git)"
	if [[ "$GIT_IGNORE_UPDATE_NEEDED" != "1" ]]; then
		echo "Complete: project .gitignore already has the local setup paths."
	else
		echo "The project .gitignore does not yet exclude local test settings, generated files, or setup backups."
		echo "Adding the entries prevents those files from being committed to the parent Git repository. Existing ignore rules will be kept."
		echo
		if confirm_change "Add these local-only names to the project .gitignore?"; then
			php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-ignore-files.php" "$PROJECT_ROOT" git
		else
			echo "Skipped: project .gitignore."
		fi
	fi
else
	echo "Manual: this project is not inside a parent Git repository, so there is no project .gitignore to update."
fi

if [[ "$(report_value sftp_config_exists)" == "1" ]]; then
	print_step "Keep local-only files out of remote uploads"
	SFTP_UPDATE_NEEDED="$(file_update_needed php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-ignore-files.php" --check "$PROJECT_ROOT" sftp)"
	if [[ "$SFTP_UPDATE_NEEDED" != "1" ]]; then
		echo "Complete: .vscode/sftp.json already has the local deployment exclusions."
	elif ((YES)); then
		echo "Manual: .vscode/sftp.json was not changed because only you can decide what may be uploaded to the remote server."
	else
		echo "The file-upload settings do not yet exclude Anyape WP Test Tools, local settings, generated files, and setup backups."
		echo "Adding the exclusions prevents those local-only files from being uploaded. Existing upload settings will be kept."
		echo
		if confirm_change "Prevent these local-only files from being uploaded?"; then
			php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-ignore-files.php" "$PROJECT_ROOT" sftp
		else
			echo "Skipped: .vscode/sftp.json."
		fi
	fi
fi

print_step "Prepare WordPress for the local DDEV site"

if [[ "$WP_CONFIG_STATUS" == "update" ]]; then
	echo "WordPress must use DDEV's local database settings when it runs locally, while keeping the existing remote-server settings for the remote site."
	echo "The change also sends local PHP errors to wp-content/debug.log and loads wp-config-ddev.php before WordPress starts."
	echo "A dated copy of the current wp-config.php will be created before it is changed."
	echo
	if ! confirm_change "Back up wp-config.php and prepare it for the local DDEV site?"; then
		echo "ERROR: wp-config.php setup was declined; setup cannot safely continue." >&2
		exit 1
	fi
	php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-wp-config.php" "$PROJECT_ROOT/wp-config.php"
else
	echo "Complete: wp-config.php already has the supported DDEV arrangement."
fi

print_step "Add the testing commands to the project"

if [[ "$(file_update_needed php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-root-composer.php" --check "$PROJECT_ROOT/composer.json" "$ANYAPE_WP_TEST_TOOLS_DIR/composer.json")" != "1" ]]; then
	echo "Complete: project composer.json already has the current test commands."
else
	echo "The project composer.json needs commands such as 'composer test', 'composer doctor', and 'composer db:pull'."
	echo "Existing packages and unrelated commands will be kept. A dated copy will be created if the file already exists."
	echo
	if confirm_change "Add the Anyape WP Test Tools commands to the project composer.json?"; then
		php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/update-root-composer.php" "$PROJECT_ROOT/composer.json" "$ANYAPE_WP_TEST_TOOLS_DIR/composer.json"
	else
		echo "ERROR: Adding the project test commands was declined, so setup cannot continue." >&2
		exit 1
	fi
fi

print_step "Create optional project settings"

copy_optional_config "$ANYAPE_WP_TEST_TOOLS_DIR/anyape-wp-test-tools-config-example.php" "$PROJECT_ROOT/.anyape-wp-test-tools.php" ".anyape-wp-test-tools.php plugin and theme selection file" ".anyape-wp-test-tools.php lets you include or exclude particular plugins and themes from tests and name extra local files that browser tests must restore. Most projects can keep the empty example values."
echo
copy_optional_config "$ANYAPE_WP_TEST_TOOLS_DIR/db-refresh-config-example.php" "$ANYAPE_WP_TEST_TOOLS_DIR/db-refresh-local.php" "remote database copy settings file" "db-refresh-local.php tells the database-copy command which remote WordPress site to copy and which local DDEV address to use. It does not contain database passwords."

print_step "Install required Subversion support in DDEV"

DDEV_RESTART_NEEDED=0
DDEV_WAS_RUNNING=0
SUBVERSION_SETTINGS_ADDED=0
if ddev_project_running; then
	DDEV_WAS_RUNNING=1
fi
if [[ "$(report_value subversion_configured)" != "1" ]]; then
	echo "Required: the matching WordPress PHP test files are downloaded from WordPress's development repository with the Subversion program."
	echo "Subversion is not included in this project's DDEV web container yet. Setup cannot prepare the PHP tests without it."
	if ((DDEV_WAS_RUNNING)); then
		echo "Because DDEV is already running, its current web container was built without Subversion. Setup will add the program, rebuild that container, and restart the local project automatically."
	else
		echo "DDEV is not running. Setup will add Subversion before building and starting the web container, so no separate restart is needed."
	fi
	echo "This changes only the local DDEV environment. It does not change the remote site."
	echo
	EXISTING_PACKAGES="$(report_value ddev_packages)"
	PACKAGES="${EXISTING_PACKAGES:+$EXISTING_PACKAGES,}subversion"
	anyape_wp_test_tools_run_logged "Adding required Subversion support to the DDEV settings..." ddev config --webimage-extra-packages="$PACKAGES"
	DDEV_RESTART_NEEDED="$DDEV_WAS_RUNNING"
	SUBVERSION_SETTINGS_ADDED=1
else
	echo "Complete: required Subversion support is already included in the DDEV settings."
fi

if ((DDEV_RESTART_NEEDED)); then
	anyape_wp_test_tools_run_logged "Rebuilding the DDEV web container with Subversion and restarting the project..." ddev restart
elif ! ddev_project_running; then
	if ((SUBVERSION_SETTINGS_ADDED)); then
		echo "The local DDEV project is stopped. Starting it now builds the web container with every configured program, including Subversion."
		DDEV_START_DESCRIPTION="Building and starting the local DDEV project..."
	else
		echo "The local DDEV project is stopped, and its settings already include Subversion."
		DDEV_START_DESCRIPTION="Starting the local DDEV project..."
	fi
	echo "DDEV must be running to install the test programs and prepare the databases."
	echo
	anyape_wp_test_tools_run_logged "$DDEV_START_DESCRIPTION" ddev start
fi

if ! ddev exec --raw svn --version --quiet >/dev/null 2>&1; then
	echo "Subversion is required and is listed in the DDEV settings, but the running web container does not contain it."
	echo "Setup will rebuild the web container from the updated settings and restart the local project automatically."
	echo
	anyape_wp_test_tools_run_logged "Rebuilding the DDEV web container with Subversion and restarting the project..." ddev restart
fi

if ! ddev exec --raw svn --version --quiet >/dev/null 2>&1; then
	echo "ERROR: Subversion is still unavailable in the DDEV web container after it was rebuilt and restarted." >&2
	exit 1
fi

print_step "Install the test programs"

echo "This checks the exact PHP and browser-testing package versions recorded by Anyape WP Test Tools."
echo "Packages that are already correct are left in place; missing or outdated packages are installed."
echo
if [[ ! -f "$ANYAPE_WP_TEST_TOOLS_DIR/vendor/autoload.php" ]]; then
	PHP_PACKAGE_ACTION="Installing the PHP testing packages inside DDEV..."
else
	PHP_PACKAGE_ACTION="Checking the installed PHP testing packages..."
fi
anyape_wp_test_tools_run_logged "$PHP_PACKAGE_ACTION" ddev exec --dir=/var/www/html/.anyape-wp-test-tools composer install
if [[ ! -x "$ANYAPE_WP_TEST_TOOLS_DIR/node_modules/.bin/playwright" ]]; then
	BROWSER_PACKAGE_ACTION="Installing the browser-testing packages..."
else
	BROWSER_PACKAGE_ACTION="Checking the installed browser-testing packages..."
fi
anyape_wp_test_tools_run_logged "$BROWSER_PACKAGE_ACTION" npm --prefix "$ANYAPE_WP_TEST_TOOLS_DIR" install
anyape_wp_test_tools_run_logged "Checking the Chromium browser used by the browser tests..." bash -c 'cd "$1" && npx playwright install chromium' setup-browser "$ANYAPE_WP_TEST_TOOLS_DIR"

print_step "Choose the local WordPress database"

if [[ -z "$DATABASE_CHOICE" ]]; then
	if ((YES)); then
		echo "ERROR: --yes cannot choose what happens to the local WordPress database." >&2
		echo "Run again with --database=keep, --database=clean, or --database=pull after reviewing those choices in SETUP.md." >&2
		exit 1
	fi
	if ddev wp --path=/var/www/html core is-installed >/dev/null 2>&1; then
		echo "WordPress is currently installed in the local database. Choose 'keep' unless you deliberately want to erase it or replace it with a remote copy."
	else
		echo "WordPress is not currently installed in the local database. Choose 'clean' for a new site or 'pull' for a copy of a remote site."
	fi
	echo
	echo "Choose what the setup should do with the local working database named 'db':"
	echo
	echo "  keep  - Keep the current local WordPress site and all of its content unchanged."
	echo "          Choose this when WordPress is already installed locally and its data is the data you want to use."
	echo
	echo "  clean - Erase the local working database and create a new WordPress site."
	echo "          Choose this to start over locally. The old local databases are saved first, and exact typed confirmation is required."
	echo
	echo "  pull  - Replace the local database with a copy from a remote WordPress site."
	echo "          Choose this to work with remote-site content locally. The command shows the source, asks for confirmation, and saves the old local databases first."
	echo
	while [[ -z "$DATABASE_CHOICE" ]]; do
		printf 'Choose one option [keep/clean/pull]: '
		IFS= read -r DATABASE_CHOICE
		case "$DATABASE_CHOICE" in
			keep|clean|pull) ;;
			*)
				echo "Please enter exactly 'keep', 'clean', or 'pull'."
				echo
				DATABASE_CHOICE=""
				;;
		esac
	done
fi

case "$DATABASE_CHOICE" in
	keep)
		if ! ddev wp --path=/var/www/html core is-installed >/dev/null 2>&1; then
			echo "ERROR: 'keep' cannot be used because the local database does not contain an installed WordPress site." >&2
			echo "Run setup again and choose 'clean' to create a new site or 'pull' to copy a remote site." >&2
			exit 1
		fi
		echo "Complete: the existing local WordPress database and its content were kept unchanged."
		;;
	clean)
		if ((YES)); then
			echo "ERROR: A clean installation erases the local working database and needs an exact typed confirmation, a site address, and an administrator account. It cannot run without questions." >&2
			exit 1
		fi
		echo "WARNING: This permanently erases every table and all content in the local working database named 'db'."
		echo "The remote site and the separate PHP test database named 'anyape_wp_test_tools' are not changed."
		echo "Before erasing db, setup saves a restorable copy of every local DDEV database."
		echo
		printf "Type 'erase local db' to continue: "
		IFS= read -r CLEAN_CONFIRMATION
		if [[ "$CLEAN_CONFIRMATION" != "erase local db" ]]; then
			echo "Cancelled: the local database was not changed."
			exit 1
		fi
		echo
		echo "Enter the details for the new local WordPress site."
		DDEV_DEFAULTS=()
		while IFS= read -r -d '' value; do
			DDEV_DEFAULTS+=("$value")
		done < <(php "$ANYAPE_WP_TEST_TOOLS_DIR/bin/ddev-project-defaults.php" "$PROJECT_ROOT/.ddev/config.yaml")
		if ((${#DDEV_DEFAULTS[@]} != 4)); then
			echo "ERROR: Could not prepare the suggested values from .ddev/config.yaml." >&2
			exit 1
		fi
		DDEV_PROJECT_NAME="${DDEV_DEFAULTS[0]}"
		DDEV_DEFAULT_SITE_URL="${DDEV_DEFAULTS[1]}"
		DDEV_DEFAULT_SITE_TITLE="$DDEV_PROJECT_NAME"
		DDEV_DEFAULT_ADMIN_USER="${DDEV_DEFAULTS[2]}"
		DDEV_DEFAULT_ADMIN_EMAIL="${DDEV_DEFAULTS[3]}"
		echo "The suggested values come from this DDEV project's configured name and address. Press Enter to accept each one, or type a different value."
		echo "The administrator password is requested afterward and is not displayed while you type it."
		echo
		printf 'Local DDEV site address [%s]: ' "$DDEV_DEFAULT_SITE_URL"
		IFS= read -r SITE_URL
		SITE_URL="${SITE_URL:-$DDEV_DEFAULT_SITE_URL}"
		printf 'Name shown for the site [%s]: ' "$DDEV_DEFAULT_SITE_TITLE"
		IFS= read -r SITE_TITLE
		SITE_TITLE="${SITE_TITLE:-$DDEV_DEFAULT_SITE_TITLE}"
		printf 'Administrator login name [%s]: ' "$DDEV_DEFAULT_ADMIN_USER"
		IFS= read -r ADMIN_USER
		ADMIN_USER="${ADMIN_USER:-$DDEV_DEFAULT_ADMIN_USER}"
		printf 'Administrator email address [%s]: ' "$DDEV_DEFAULT_ADMIN_EMAIL"
		IFS= read -r ADMIN_EMAIL
		ADMIN_EMAIL="${ADMIN_EMAIL:-$DDEV_DEFAULT_ADMIN_EMAIL}"
		CLEAN_RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
		CLEAN_SNAPSHOT_NAME="before-clean-install-$CLEAN_RUN_ID"
		echo
		anyape_wp_test_tools_run_logged "Saving the current local databases as '$CLEAN_SNAPSHOT_NAME'..." ddev snapshot --name "$CLEAN_SNAPSHOT_NAME"
		(
			CLEAN_DATABASE_CHANGED=0
			# This function is called by the EXIT trap below.
			# shellcheck disable=SC2329
			restore_failed_clean_install() {
				local status=$?
				trap - EXIT
				if [[ -n "${ADMIN_PASSWORD_FILE:-}" ]]; then
					rm -f "$ADMIN_PASSWORD_FILE"
				fi
				if ((status != 0 && CLEAN_DATABASE_CHANGED)); then
					echo "The clean installation failed; restoring the saved local databases '$CLEAN_SNAPSHOT_NAME'..." >&2
					if ! ddev snapshot restore "$CLEAN_SNAPSHOT_NAME"; then
						echo "ERROR: Automatic restoration also failed. Restore manually with: composer restore -- $CLEAN_SNAPSHOT_NAME" >&2
					fi
				fi
				exit "$status"
			}
			trap restore_failed_clean_install EXIT
			while true; do
				printf 'Administrator password (input hidden): '
				IFS= read -r -s ADMIN_PASSWORD
				echo
				printf 'Repeat administrator password: '
				IFS= read -r -s ADMIN_PASSWORD_CONFIRMATION
				echo
				if [[ -z "$ADMIN_PASSWORD" ]]; then
					echo "The administrator password cannot be empty."
					echo
				elif [[ "$ADMIN_PASSWORD" != "$ADMIN_PASSWORD_CONFIRMATION" ]]; then
					echo "The two passwords did not match. Try again."
					echo
				else
					break
				fi
				done
			umask 077
			mkdir -p "$ANYAPE_WP_TEST_TOOLS_DIR/runtime"
			ADMIN_PASSWORD_FILE="$(mktemp "$ANYAPE_WP_TEST_TOOLS_DIR/runtime/clean-admin-password.XXXXXX")"
			printf '%s' "$ADMIN_PASSWORD" > "$ADMIN_PASSWORD_FILE"
			CONTAINER_PASSWORD_FILE="/var/www/html/.anyape-wp-test-tools/runtime/$(basename "$ADMIN_PASSWORD_FILE")"
			unset ADMIN_PASSWORD ADMIN_PASSWORD_CONFIRMATION
			CLEAN_DATABASE_CHANGED=1
			anyape_wp_test_tools_run_logged "Erasing and recreating the local working database..." ddev mysql -uroot -proot -e "DROP DATABASE IF EXISTS db; CREATE DATABASE db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON db.* TO 'db'@'%'; FLUSH PRIVILEGES;"
			anyape_wp_test_tools_run_logged "Installing the new local WordPress site..." ddev exec --quiet --raw bash -c '
				admin_password="$(< "$1")"
				wp --path=/var/www/html core install \
					--url="$2" \
					--title="$3" \
					--admin_user="$4" \
					--admin_email="$5" \
					--admin_password="$admin_password"
			' wp-clean-install "$CONTAINER_PASSWORD_FILE" "$SITE_URL" "$SITE_TITLE" "$ADMIN_USER" "$ADMIN_EMAIL"
			rm -f "$ADMIN_PASSWORD_FILE"
			ADMIN_PASSWORD_FILE=""
			CLEAN_DATABASE_CHANGED=0
			trap - EXIT
		)
		echo "Complete: created a new local WordPress site. Previous local databases: '$CLEAN_SNAPSHOT_NAME'."
		;;
	pull)
		if [[ ! -f "$ANYAPE_WP_TEST_TOOLS_DIR/db-refresh-local.php" ]]; then
			echo "ERROR: The remote database settings file is missing: .anyape-wp-test-tools/db-refresh-local.php" >&2
			echo "Copy .anyape-wp-test-tools/db-refresh-config-example.php to that name, read its instructions, and fill in its four values before choosing 'pull'." >&2
			exit 1
		fi
		if ((YES)); then
			ANYAPE_WP_TEST_TOOLS_LOG_FILE="$ANYAPE_WP_TEST_TOOLS_LOG_FILE" ANYAPE_WP_TEST_TOOLS_VERBOSE="$VERBOSE" bash "$ANYAPE_WP_TEST_TOOLS_DIR/database-host.sh" pull --yes
		else
			ANYAPE_WP_TEST_TOOLS_LOG_FILE="$ANYAPE_WP_TEST_TOOLS_LOG_FILE" ANYAPE_WP_TEST_TOOLS_VERBOSE="$VERBOSE" bash "$ANYAPE_WP_TEST_TOOLS_DIR/database-host.sh" pull
		fi
		;;
	*)
		echo "ERROR: Database choice must be exactly 'keep', 'clean', or 'pull'." >&2
		exit 1
		;;
esac

print_step "Prepare and check the separate PHP test database"

echo "PHP tests use a separate local database named 'anyape_wp_test_tools'. They do not run against the working WordPress database named 'db'."
echo "The setup will create anyape_wp_test_tools if it is missing, check the environment, and run Anyape WP Test Tools' own safety tests."
echo
anyape_wp_test_tools_run_logged "Creating the separate PHP test database if it is missing..." ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS anyape_wp_test_tools CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON anyape_wp_test_tools.* TO 'db'@'%'; FLUSH PRIVILEGES;"

anyape_wp_test_tools_run_logged "Checking the local test environment..." bash "$ANYAPE_WP_TEST_TOOLS_DIR/doctor-host.sh"
anyape_wp_test_tools_run_logged "Running Anyape WP Test Tools safety tests..." bash "$ANYAPE_WP_TEST_TOOLS_DIR/run-tests-host.sh" --profile=harness

print_step "Optional complete project test run"

echo "Anyape WP Test Tools' own safety tests have passed. You can now run the tests selected from this project's plugins and themes."
echo "PHP tests rebuild only the separate anyape_wp_test_tools database. Browser tests temporarily use the local site; they first save its database, uploads, must-use plugins, and any extra configured files, then restore them afterward."
echo "This can take several minutes. A real test failure will stop the test command and remain visible."
echo
if ((RUN_TESTS)); then
	ANYAPE_WP_TEST_TOOLS_LOG_FILE="$ANYAPE_WP_TEST_TOOLS_LOG_FILE" ANYAPE_WP_TEST_TOOLS_VERBOSE="$VERBOSE" bash "$ANYAPE_WP_TEST_TOOLS_DIR/run-all-host.sh"
elif ((!YES)) && confirm_change "Run the complete PHP and browser test set now?"; then
	ANYAPE_WP_TEST_TOOLS_LOG_FILE="$ANYAPE_WP_TEST_TOOLS_LOG_FILE" ANYAPE_WP_TEST_TOOLS_VERBOSE="$VERBOSE" bash "$ANYAPE_WP_TEST_TOOLS_DIR/run-all-host.sh"
else
	echo "Skipped: full test run. Run 'composer test' when ready."
fi

echo
echo "Guided setup complete."
echo "Review any lines marked Manual or Skipped, then use 'composer test' for the complete test run."
