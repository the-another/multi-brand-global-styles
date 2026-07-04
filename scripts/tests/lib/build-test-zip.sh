# Shared pre-flight for BOTH e2e suites (sourced by tests/e2e.sh and
# tests/plugin-check.sh — keeping it in exactly one file is what guarantees
# the functional suite and the Plugin Check suite can never drift).
#
# Both suites test the SAME packaged artifact: build the -test zip fresh
# every run (a stale zip would silently test old code). `composer build`
# inside this pipeline (install --no-dev + optimized autoload) is also what
# provides vendor/ on fresh CI checkouts — no separate vendor bootstrap.
# Side effect: a local vendor/ is left in no-dev state afterwards
# (`make install-dev` restores dev tooling for lint/test).
#
# Expects: CWD = repo root, `set -e` active in the sourcing script.

npm ci --no-audit --no-fund

rm -f build/the-another-multi-brand-global-styles-test.zip
npm run plugin-zip:check

# EditorAssets::enqueue() no-ops silently when assets/build/index.asset.php
# is missing (no enqueue call at all), so a packaging regression that strips
# assets/build/ or assets/admin/ from the zip would leave nothing for any
# later gate — including Plugin Check's runtime checks — to flag: a
# silent-no-op enqueue means a stripped bundle passes all gates. Fail loudly
# here instead, right after the artifact both suites test is built.
ZIP="build/the-another-multi-brand-global-styles-test.zip"
for required in assets/build/index.js assets/build/index.asset.php assets/admin/brand-media.js; do
	if ! unzip -l "$ZIP" | grep -qF "$required"; then
		echo "FATAL: packaged zip is missing required editor asset: $required" >&2
		exit 1
	fi
done
