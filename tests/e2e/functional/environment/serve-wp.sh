#!/bin/sh
# Boot a real, ephemeral WordPress for the functional e2e suite. Invoked by
# playwright.config.ts's webServer.command; requires the tests/e2e/Dockerfile
# image. Provisioning (baked core, SQLite drop-in, config, install) lives in
# the shared tests/e2e/lib/provision-wp.sh — this script adds only the
# functional-suite specifics: the packaged -test zip, pretty permalinks, and
# the actual server.
#
# Installation completes BEFORE the server binds the port — that ordering is
# what makes Playwright's plain webServer.url readiness check truthful.
set -e

PORT="${WP_E2E_PORT:-8881}"
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"

ZIP="$REPO_ROOT/build/the-another-multi-brand-global-styles-test.zip"
if [ ! -f "$ZIP" ]; then
	echo "$ZIP missing — run via scripts/run-e2e.sh functional (or make test-e2e), which builds it" >&2
	exit 1
fi

WP_SITE_URL="http://localhost:$PORT"
. "$REPO_ROOT/tests/e2e/lib/provision-wp.sh"
provision_wp

# The same packaged artifact the check-plugin suite gates — never a
# file-by-file source copy, so packaging bugs (missing file, wrong
# autoloader, bad .distignore exclusion) fail functionally too. The zip's
# inner dirname is already the real slug (dist-archive's --plugin-dirname).
wp plugin install "$ZIP" --activate --path="$WP_DIR" --allow-root

# Pretty permalinks: path-scoped Brand rules need real path URLs. A direct
# option write via wp-cli (unlike the admin UI, it doesn't sanitize the
# structure based on server rewrite support); wp server's router handles
# the actual /pretty/paths at request time.
wp rewrite structure '/%postname%/' --path="$WP_DIR" --allow-root
wp rewrite flush --path="$WP_DIR" --allow-root

# Multiple built-in-server workers so WordPress's own loopback requests
# (cron spawn, site health) can't deadlock the single PHP process. The
# running server's output is spooled to a file rather than Playwright's
# console: php -S logs every request (Accepted/Closing/status lines), which
# drowns the test output. Boot-phase output above still reaches the console,
# and real PHP errors still surface on-page via WP_DEBUG_DISPLAY (and thus
# in failure screenshots); the spool file covers the rest if a run needs a
# post-mortem inside the container.
echo "MBGS e2e WordPress ready: serving $WP_DIR on port $PORT (server log: $WP_DIR/php-server.log)"
PHP_CLI_SERVER_WORKERS=6 exec wp server --host=0.0.0.0 --port="$PORT" \
	--path="$WP_DIR" --allow-root >>"$WP_DIR/php-server.log" 2>&1
