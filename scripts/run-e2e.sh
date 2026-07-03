#!/bin/sh
# Shared entrypoint for both e2e Make targets (test-e2e, check-plugin), run
# inside the e2e image (tests/e2e/Dockerfile). Keeping this logic in exactly
# one script — instead of duplicated across two Make recipes — is what
# guarantees the functional suite and the Plugin Check suite can never drift
# from what CI actually runs.
#
# Usage: sh scripts/run-e2e.sh <functional|plugin-check>
set -e

SUITE="$1"

if [ "$SUITE" != "functional" ] && [ "$SUITE" != "plugin-check" ]; then
	echo "Usage: run-e2e.sh <functional|plugin-check>" >&2
	exit 1
fi

npm ci --no-audit --no-fund

if [ "$SUITE" = "functional" ]; then
	npx playwright test --config tests/e2e/functional/playwright.config.ts
else
	rm -f build/the-another-multi-brand-global-styles-test.zip
	npm run plugin-zip:check
	# No Playwright/browser here: Plugin Check runs via its WP-CLI runner
	# inside @wp-playground/cli — see tests/e2e/check-plugin/run-plugin-check.mjs.
	node tests/e2e/check-plugin/run-plugin-check.mjs
fi
