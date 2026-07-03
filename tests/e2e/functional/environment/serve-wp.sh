#!/bin/sh
# Boot a real, ephemeral WordPress (native PHP + the official SQLite drop-in)
# for the functional e2e suite. Invoked by playwright.config.ts's
# webServer.command; requires the tests/e2e/Dockerfile image (baked core at
# /opt/wp-core, drop-in at /opt/sqlite-database-integration, wp-cli with the
# server-command package).
#
# Ordering matters twice here:
#  - the SQLite drop-in (wp-content/db.php) must be in place BEFORE
#    `wp core install`, or install would try to reach MySQL;
#  - installation completes BEFORE the server binds the port, which is what
#    makes Playwright's plain webServer.url readiness check truthful.
set -e

PORT="${WP_E2E_PORT:-8881}"
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"

ZIP="$REPO_ROOT/build/the-another-multi-brand-global-styles-test.zip"
if [ ! -f "$ZIP" ]; then
	echo "$ZIP missing — run via scripts/run-e2e.sh functional (or make test-e2e), which builds it" >&2
	exit 1
fi

# Fresh temp copy of the baked core: clean site every run, same ephemeral
# semantics the Playground server had.
WP_DIR="$(mktemp -d /tmp/mbgs-e2e-wp.XXXXXX)"
cp -a /opt/wp-core/. "$WP_DIR"/

# SQLite drop-in: plugin files first, then db.php generated from the
# plugin's own db.copy template (its documented manual-install procedure).
cp -a /opt/sqlite-database-integration "$WP_DIR/wp-content/plugins/"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP_DIR/wp-content/plugins/sqlite-database-integration#" \
	-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	"$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" \
	> "$WP_DIR/wp-content/db.php"

# DB credentials are dummies — the drop-in ignores them (hence --skip-check).
wp config create --path="$WP_DIR" --dbname=wordpress --dbuser=wordpress \
	--dbpass=wordpress --skip-check --allow-root
# Ephemeral test instance: PHP errors straight onto the page is pure upside
# (turns investigations into "check the screenshot").
wp config set WP_DEBUG true --raw --path="$WP_DIR" --allow-root
wp config set WP_DEBUG_DISPLAY true --raw --path="$WP_DIR" --allow-root

# admin/password are RequestUtils' defaults — keep them exactly.
wp core install --path="$WP_DIR" --url="http://localhost:$PORT" \
	--title="MBGS E2E" --admin_user=admin --admin_password=password \
	--admin_email=admin@example.com --skip-email --allow-root

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
