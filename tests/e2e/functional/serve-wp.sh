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
PLUGIN_SLUG="the-another-multi-brand-global-styles"
REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"

if [ ! -f "$REPO_ROOT/vendor/autoload.php" ]; then
	echo "vendor/autoload.php missing — run 'make install' first" >&2
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

# Exactly the plugin's four runtime paths, copied (not symlinked: PHP
# resolves symlinks in __FILE__ and WordPress only realpath-maps whole-dir
# plugin symlinks, so per-file symlinks can break plugin_basename()).
PLUGIN_DIR="$WP_DIR/wp-content/plugins/$PLUGIN_SLUG"
mkdir -p "$PLUGIN_DIR"
cp "$REPO_ROOT/$PLUGIN_SLUG.php" "$PLUGIN_DIR/"
cp "$REPO_ROOT/readme.txt" "$PLUGIN_DIR/"
cp -a "$REPO_ROOT/includes" "$PLUGIN_DIR/includes"
cp -a "$REPO_ROOT/vendor" "$PLUGIN_DIR/vendor"

wp plugin activate "$PLUGIN_SLUG" --path="$WP_DIR" --allow-root

# Pretty permalinks: path-scoped Brand rules need real path URLs. A direct
# option write via wp-cli (unlike the admin UI, it doesn't sanitize the
# structure based on server rewrite support); wp server's router handles
# the actual /pretty/paths at request time.
wp rewrite structure '/%postname%/' --path="$WP_DIR" --allow-root
wp rewrite flush --path="$WP_DIR" --allow-root

# Multiple built-in-server workers so WordPress's own loopback requests
# (cron spawn, site health) can't deadlock the single PHP process.
echo "MBGS e2e WordPress ready: serving $WP_DIR on port $PORT"
PHP_CLI_SERVER_WORKERS=6 exec wp server --host=0.0.0.0 --port="$PORT" \
	--path="$WP_DIR" --allow-root
