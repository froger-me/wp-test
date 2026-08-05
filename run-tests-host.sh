#!/usr/bin/env bash

set -euo pipefail

ACTIVE_PLUGINS_FILE=".test-tools/active-plugins.json"

ddev wp option get active_plugins --format=json > "$ACTIVE_PLUGINS_FILE"

php -r '
	$file = $argv[1];
	$data = json_decode(
		file_get_contents($file),
		true,
		512,
		JSON_THROW_ON_ERROR
	);

	if (! is_array($data)) {
		fwrite(STDERR, "The active plugin list is invalid.\n");
		exit(1);
	}
' "$ACTIVE_PLUGINS_FILE"

exec ddev exec --raw \
	/var/www/html/.test-tools/run-tests.sh \
	"$@"
