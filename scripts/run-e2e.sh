#!/bin/sh
# Shared entrypoint for both e2e Make targets (test-e2e, check-plugin), run
# inside Dockerfile.e2e. Keeping this logic in exactly one script — instead
# of duplicated across two Make recipes — is what guarantees the functional
# suite and the Plugin Check suite can never drift from what CI actually runs.
#
# Usage: sh scripts/run-e2e.sh <functional|plugin-check>
set -e

SUITE="$1"

if [ "$SUITE" != "functional" ] && [ "$SUITE" != "plugin-check" ]; then
	echo "Usage: run-e2e.sh <functional|plugin-check>" >&2
	exit 1
fi

npm install --no-audit --no-fund

if [ "$SUITE" = "functional" ]; then
	WP_BASE_URL=http://localhost:8881 npx playwright test --config playwright.config.ts
else
	rm -f build/the-another-multi-domain-global-styles-test.zip
	npm run plugin-zip:check
	npx playwright test --config playwright.check.config.ts
fi
