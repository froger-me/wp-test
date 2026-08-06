#!/usr/bin/env bash

set -euo pipefail

TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ACTION="${1:-check}"

case "$ACTION" in
	check)
		EXECUTABLE="$TOOLKIT_DIR/vendor/bin/phpcs"
		;;
	fix)
		EXECUTABLE="$TOOLKIT_DIR/vendor/bin/phpcbf"
		;;
	*)
		echo "ERROR: Expected WPCS action 'check' or 'fix'." >&2
		exit 1
		;;
esac

if [[ ! -x "$EXECUTABLE" ]]; then
	echo "ERROR: WPCS dependencies are missing; run Composer install in .test-tools." >&2
	exit 1
fi

FILES=()

while IFS= read -r -d '' file; do
	if [[ -f "$TOOLKIT_DIR/$file" ]]; then
		FILES+=("$TOOLKIT_DIR/$file")
	fi
done < <(
	git -C "$TOOLKIT_DIR" ls-files \
		--cached \
		--others \
		--exclude-standard \
		-z \
		-- \
		'*.php'
)

if ((${#FILES[@]} == 0)); then
	echo "No non-ignored PHP files to check."
	exit 0
fi

if "$EXECUTABLE" \
	--standard="$TOOLKIT_DIR/phpcs.xml.dist" \
	"${FILES[@]}"; then
	status=0
else
	status=$?
fi

if [[ "$ACTION" == "fix" && "$status" == "1" ]]; then
	exit 0
fi

exit "$status"
