#!/bin/sh
# Boot a real, ephemeral WordPress for the functional e2e suite. Invoked by
# playwright.config.ts's webServer.command; requires the environment
# provisioned by scripts/setup/e2e.sh, including its wp-cli server-command
# package (the `wp server`
# subcommand this script execs). Provisioning (pinned core, SQLite drop-in,
# config, install) lives in the shared tests/e2e/lib/provision-wp.sh — this
# script adds only the functional-suite specifics: the packaged -test zip,
# pretty permalinks, and the actual server.
#
# Installation completes BEFORE the server binds the port — that ordering is
# what makes Playwright's plain webServer.url readiness check truthful.
set -e

PORT="${WP_E2E_PORT:-8881}"
REPO_ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"

ZIP="$REPO_ROOT/build/the-another-multi-brand-global-styles-test.zip"
if [ ! -f "$ZIP" ]; then
	echo "$ZIP missing — run via scripts/tests/e2e.sh (or make test-e2e), which builds it" >&2
	exit 1
fi

WP_SITE_URL="http://localhost:$PORT"
. "$REPO_ROOT/tests/e2e/lib/provision-wp.sh"
provision_wp

# wp-cli's server-command router rewrites home/siteurl to the incoming Host
# header on EVERY request, which silently defeats any e2e test of
# host-dependent behavior: the environment "follows" the browsed host even
# with the plugin inactive. Real servers take the canonical host from the DB
# options, so pin both options back to the install URL at max priority —
# covering both the pre_option_* short-circuit and the option_* value filter,
# whichever the router hooks. No-op for requests on the canonical host.
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cat > "$WP_DIR/wp-content/mu-plugins/mbgs-e2e-pin-canonical.php" <<PHP
<?php
add_filter( 'pre_option_home', static fn() => '$WP_SITE_URL', PHP_INT_MAX );
add_filter( 'pre_option_siteurl', static fn() => '$WP_SITE_URL', PHP_INT_MAX );
add_filter( 'option_home', static fn() => '$WP_SITE_URL', PHP_INT_MAX );
add_filter( 'option_siteurl', static fn() => '$WP_SITE_URL', PHP_INT_MAX );
PHP

# Global-styles save must survive core's kses sanitization. Core registers
# wp_filter_global_styles_post() on content_save_pre for any user WITHOUT the
# unfiltered_html capability (every multisite site admin; any security-hardened
# single site), and that filter drops flat theme.json presets a Brand's raw
# JSON stores. The e2e admin HAS unfiltered_html, so that path is invisible by
# default — drop the cap, but ONLY while saving a Brand whose title carries the
# [kses] sentinel, so the reproduction stays scoped to global-styles-kses.spec
# and every other spec's Brand saves are untouched.
cat > "$WP_DIR/wp-content/mu-plugins/mbgs-e2e-force-kses.php" <<'PHP'
<?php
add_filter(
	'map_meta_cap',
	static function ( $caps, $cap ) {
		if ( 'unfiltered_html' === $cap
			&& isset( $_POST['post_type'], $_POST['post_title'] )
			&& 'mbgs_brand' === $_POST['post_type']
			&& is_string( $_POST['post_title'] )
			&& str_contains( wp_unslash( $_POST['post_title'] ), '[kses]' ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	},
	10,
	2
);
PHP

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
