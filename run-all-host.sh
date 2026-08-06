#!/usr/bin/env bash

set -euo pipefail

TOOLKIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if (($#)); then
	echo "ERROR: 'composer test' does not accept runner-specific arguments." >&2
	echo "Use 'composer test:php -- <PHPUnit arguments>' or 'composer test:e2e -- <Playwright arguments>'." >&2
	exit 2
fi

"$TOOLKIT_DIR/run-tests-host.sh"
exec "$TOOLKIT_DIR/run-e2e-host.sh"
