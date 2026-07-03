# E2E Artifact Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Both e2e suites install the plugin from the same packaged `-test` zip on the same native-PHP engine, and `@wp-playground/cli` leaves the toolchain entirely.

**Architecture:** Part 1 — the zip build hoists into `scripts/run-e2e.sh`'s shared path and `serve-wp.sh` installs from the zip instead of copying four source paths. Part 2 — the WordPress-provisioning steps extract into a sourced library (`tests/e2e/lib/provision-wp.sh`); the check-plugin suite provisions natively via that library, installs a pinned Plugin Check zip baked into the Docker image, and runs `wp plugin check` with real argv/stdout — deleting the Playground boot, its Blueprint, the argv/stdout shim, the network retry loop, and the `python3`/`g++` image packages.

**Tech Stack:** POSIX sh, wp-cli (native, `--allow-root`), `sqlite-database-integration` drop-in, WordPress.org Plugin Check (PCP) WP-CLI runner, Node (orchestrator only), Docker (Alpine).

**Spec:** `docs/superpowers/specs/2026-07-03-e2e-zip-based-provisioning-design.md`

## Global Constraints

- Single container, single make target: `make test-e2e` / `make check-plugin` stay `docker build` + one `docker run … sh scripts/run-e2e.sh <suite>`, identical locally and in CI. `.github/workflows/e2e.yml`, the Makefile targets, and the Playwright config are untouched.
- All version pins exact (`x.y.z`), never `latest`; resolved once, hardcoded as Dockerfile `ARG` defaults.
- Both suites consume exactly `build/the-another-multi-brand-global-styles-test.zip`, built fresh every run by `run-e2e.sh` (`rm -f` + `npm run plugin-zip:check`). No rebuild-only-if-missing, no source-mount mode.
- Admin credentials exactly `admin`/`password`; SQLite drop-in placed before `wp core install`; every `wp` invocation carries `--allow-root`.
- Empirically-hard-won orderings that MUST be preserved: PCP installs **before** our zip (reverse order historically broke PCP activation with "database tables are unavailable"); PCP's `object-cache.copy.php` drop-in is re-`cp`'d before **each** of the two check runs (PCP's per-run cleanup deletes it).
- Two-run structure preserved: run 1 = full default check set; run 2 = the 5 runtime checks (`enqueued_scripts_size`, `enqueued_styles_size`, `enqueued_styles_scope`, `enqueued_scripts_scope`, `non_blocking_scripts`) explicitly, as the loud canary.
- Gating policy preserved: ERROR findings gate; WARNINGs report only; structural failures (missing run, `early_init=no`, fatals, unparseable lines) always gate. Trust parsed output, not exit codes, for the check runs themselves.
- `build/plugin-check-results.txt` keeps existing (CI uploads it on failure).
- The historical spec/plan docs under `docs/superpowers/` are point-in-time artifacts — do not update them.
- The functional suite's four specs, `provision.setup.ts`, and `helpers.ts` stay behaviorally unchanged.
- Comment style: document *why*-constraints, matching each file's existing convention.

---

### Task 1: Functional suite installs from the packaged zip (Part 1)

**Files:**
- Modify: `scripts/run-e2e.sh` (full rewrite below)
- Modify: `tests/e2e/functional/environment/serve-wp.sh` (two hunk replacements below)

**Interfaces:**
- Consumes: the existing zip pipeline (`npm run plugin-zip:check` → `composer build` + `wp dist-archive` → `build/the-another-multi-brand-global-styles-test.zip`, inner dirname = real slug).
- Produces: `run-e2e.sh` builds the `-test` zip in the shared path for BOTH suites (Task 4's runner relies on it existing); `serve-wp.sh` installs the plugin via `wp plugin install <zip> --activate` (Task 2 refactors this same file — coordinate on the exact content below).

- [ ] **Step 1: Rewrite `scripts/run-e2e.sh`**

Replace the entire file with:

```sh
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

# Both suites test the SAME packaged artifact: build the -test zip fresh
# every run (a stale zip would silently test old code). `composer build`
# inside this pipeline (install --no-dev + optimized autoload) is also what
# provides vendor/ on fresh CI checkouts — no separate vendor bootstrap.
# Side effect: a local vendor/ is left in no-dev state afterwards
# (`make install-dev` restores dev tooling for lint/test).
rm -f build/the-another-multi-brand-global-styles-test.zip
npm run plugin-zip:check

if [ "$SUITE" = "functional" ]; then
	npx playwright test --config tests/e2e/functional/playwright.config.ts
else
	# No Playwright/browser here: Plugin Check runs via its WP-CLI runner —
	# see tests/e2e/check-plugin/run-plugin-check.mjs.
	node tests/e2e/check-plugin/run-plugin-check.mjs
fi
```

- [ ] **Step 2: Point `serve-wp.sh` at the zip**

In `tests/e2e/functional/environment/serve-wp.sh`, replace the vendor guard:

```sh
if [ ! -f "$REPO_ROOT/vendor/autoload.php" ]; then
	echo "vendor/autoload.php missing — run 'make install' first" >&2
	exit 1
fi
```

with:

```sh
ZIP="$REPO_ROOT/build/the-another-multi-brand-global-styles-test.zip"
if [ ! -f "$ZIP" ]; then
	echo "$ZIP missing — run via scripts/run-e2e.sh functional (or make test-e2e), which builds it" >&2
	exit 1
fi
```

and replace the copy block plus the separate activation:

```sh
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
```

with:

```sh
# The same packaged artifact the check-plugin suite gates — never a
# file-by-file source copy, so packaging bugs (missing file, wrong
# autoloader, bad .distignore exclusion) fail functionally too. The zip's
# inner dirname is already the real slug (dist-archive's --plugin-dirname).
wp plugin install "$ZIP" --activate --path="$WP_DIR" --allow-root
```

The now-unused `PLUGIN_SLUG=` variable line at the top of the script can stay or go — remove it if nothing else references it after this edit (check with grep).

- [ ] **Step 3: Run the functional suite**

Run: `make test-e2e`
Expected: 23/23 pass. Runtime grows by the zip build (~20–40s) — record the wall-clock in your report.

- [ ] **Step 4: Guard + artifact-fidelity checks**

```bash
# Guard: without the zip, serve-wp.sh fails fast with the new message.
docker run --rm -v "$PWD":/app -w /app the-another-multi-brand-global-styles-e2e-runner:latest \
	sh -c 'mv build/the-another-multi-brand-global-styles-test.zip /tmp/z.zip 2>/dev/null;
		sh tests/e2e/functional/environment/serve-wp.sh; echo "exit=$?";
		mv /tmp/z.zip build/the-another-multi-brand-global-styles-test.zip 2>/dev/null'
```
Expected: the "missing — run via scripts/run-e2e.sh functional" message and `exit=1` (no confusing mid-boot error).

```bash
# Fidelity: the INSTALLED plugin dir contains no dev/test files.
docker run --rm -v "$PWD":/app -w /app the-another-multi-brand-global-styles-e2e-runner:latest \
	sh -c 'unzip -l build/the-another-multi-brand-global-styles-test.zip | grep -E "tests/|node_modules/|Makefile|CLAUDE.md" || echo "fidelity-ok"'
```
Expected: `fidelity-ok` (`.distignore` is now load-bearing for the functional suite too).

- [ ] **Step 5: Commit**

```bash
git add scripts/run-e2e.sh tests/e2e/functional/environment/serve-wp.sh
git commit -m "test(e2e): install the functional suite's plugin from the packaged -test zip"
```

---

### Task 2: Extract the shared provisioning library

**Files:**
- Create: `tests/e2e/lib/provision-wp.sh`
- Modify: `tests/e2e/functional/environment/serve-wp.sh` (full rewrite below)

**Interfaces:**
- Consumes: Task 1's zip-installing `serve-wp.sh`; the image paths `/opt/wp-core/`, `/opt/sqlite-database-integration/db.copy`.
- Produces: a sourced POSIX-sh library exposing `provision_wp()`, which creates a fresh install and sets `$WP_DIR`. Contract for callers (Task 4 is the second caller): set `$WP_SITE_URL` (optional, default `http://localhost:8881`) before calling; every internal `wp` call uses `--allow-root`; drop-in-before-install ordering lives inside the function.

- [ ] **Step 1: Create `tests/e2e/lib/provision-wp.sh`**

```sh
# Shared native-PHP WordPress provisioning for both e2e suites. POSIX sh,
# meant to be SOURCED (callers: functional/environment/serve-wp.sh,
# check-plugin/provision-pcp-wp.sh). Requires the tests/e2e/Dockerfile
# image: baked core at /opt/wp-core, SQLite drop-in at
# /opt/sqlite-database-integration.
#
# Contract: provision_wp() creates a fresh ephemeral install and sets
# WP_DIR. Ordering inside is load-bearing: the SQLite drop-in
# (wp-content/db.php) must exist before `wp core install`, or install
# tries to reach MySQL. Site URL comes from $WP_SITE_URL (default
# http://localhost:8881); admin credentials are exactly admin/password —
# @wordpress/e2e-test-utils-playwright RequestUtils' hardcoded defaults.

provision_wp() {
	# Fresh temp copy of the baked core: clean site every run.
	WP_DIR="$(mktemp -d /tmp/mbgs-e2e-wp.XXXXXX)"
	cp -a /opt/wp-core/. "$WP_DIR"/

	# SQLite drop-in: plugin files first, then db.php generated from the
	# plugin's own db.copy template (its documented manual-install
	# procedure).
	cp -a /opt/sqlite-database-integration "$WP_DIR/wp-content/plugins/"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP_DIR/wp-content/plugins/sqlite-database-integration#" \
		-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
		"$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" \
		> "$WP_DIR/wp-content/db.php"

	# DB credentials are dummies — the drop-in ignores them (hence
	# --skip-check).
	wp config create --path="$WP_DIR" --dbname=wordpress --dbuser=wordpress \
		--dbpass=wordpress --skip-check --allow-root
	# Ephemeral test instance: PHP errors straight onto the page is pure
	# upside (turns investigations into "check the screenshot").
	wp config set WP_DEBUG true --raw --path="$WP_DIR" --allow-root
	wp config set WP_DEBUG_DISPLAY true --raw --path="$WP_DIR" --allow-root

	wp core install --path="$WP_DIR" --url="${WP_SITE_URL:-http://localhost:8881}" \
		--title="MBGS E2E" --admin_user=admin --admin_password=password \
		--admin_email=admin@example.com --skip-email --allow-root
}
```

- [ ] **Step 2: Rewrite `serve-wp.sh` to source it**

Replace the entire file with (this preserves Task 1's zip logic and the existing spool/permalink tails verbatim — if the current file's comment wording differs slightly, keep the current wording for the unchanged parts):

```sh
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
```

- [ ] **Step 3: Verify the refactor changed nothing behaviorally**

Run: `make test-e2e`
Expected: 23/23 pass.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/lib/provision-wp.sh tests/e2e/functional/environment/serve-wp.sh
git commit -m "test(e2e): extract shared native-PHP WordPress provisioning into tests/e2e/lib"
```

---

### Task 3: Bake pinned Plugin Check into the e2e image

**Files:**
- Modify: `tests/e2e/Dockerfile` (the native-WP block added by the previous migration)

**Interfaces:**
- Produces: `/opt/plugin-check.zip` inside the image (Task 4's provisioning script hardcodes that path) and `ARG PCP_VERSION`.

- [ ] **Step 1: Resolve the PCP pin**

```bash
curl -s "https://api.wordpress.org/plugins/info/1.0/plugin-check.json" | grep -o '"version":"[^"]*"' | head -1
```
Expected: a concrete version string (e.g. `"version":"1.6.0"`) — use it in Step 2 (the value there is an example).

- [ ] **Step 2: Add the ARG + download**

In `tests/e2e/Dockerfile`, extend the existing native-WP block: add alongside the other `ARG`s (`WP_VERSION`, `SQLITE_PLUGIN_VERSION`, …):

```dockerfile
ARG PCP_VERSION=1.6.0
```

and add to the same `RUN` that downloads the SQLite plugin (before its final line, keeping one layer), or as its own `RUN` directly after it:

```dockerfile
# WordPress.org Plugin Check, pinned — the check-plugin suite installs it
# from here instead of downloading at test time (reproducible CI, zero
# network in the run; new upstream checks arrive via deliberate ARG bumps).
RUN curl -sSL "https://downloads.wordpress.org/plugin/plugin-check.${PCP_VERSION}.zip" -o /opt/plugin-check.zip
```

- [ ] **Step 3: Build and verify**

```bash
make docker-build-e2e
docker run --rm the-another-multi-brand-global-styles-e2e-runner:latest \
	sh -c 'test -f /opt/plugin-check.zip && unzip -l /opt/plugin-check.zip | grep -m1 "plugin-check/drop-ins/object-cache.copy.php" && echo pcp-ok'
```
Expected: the drop-in path listed and `pcp-ok` (Task 4 hardcodes `drop-ins/object-cache.copy.php` inside the installed plugin).

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/Dockerfile
git commit -m "test(e2e): bake pinned Plugin Check into the e2e image"
```

---

### Task 4: Check-plugin suite on native PHP

**Files:**
- Create: `tests/e2e/check-plugin/provision-pcp-wp.sh`
- Create: `tests/e2e/check-plugin/pcp-early-init-marker.php`
- Modify: `tests/e2e/check-plugin/run-plugin-check.mjs` (full rewrite below)
- Delete: `tests/e2e/check-plugin/check-plugin-blueprint.json`
- Delete: `tests/e2e/check-plugin/pcp-cli-shim.php`

**Interfaces:**
- Consumes: `provision_wp()` from `tests/e2e/lib/provision-wp.sh` (Task 2); `/opt/plugin-check.zip` (Task 3); the `-test` zip built by `run-e2e.sh` (Task 1).
- Produces: `provision-pcp-wp.sh` prints `WP_DIR=<path>` as its last stdout line (the mjs parses exactly that); `pcp-early-init-marker.php` prints `pcp_early_init=yes|no` on its own line at process shutdown.

- [ ] **Step 1: Create `tests/e2e/check-plugin/provision-pcp-wp.sh`**

```sh
#!/bin/sh
# Provision the ephemeral WordPress for the Plugin Check suite: shared
# native-PHP provisioning (tests/e2e/lib/provision-wp.sh), then Plugin
# Check (baked, pinned — /opt/plugin-check.zip) installed BEFORE our -test
# zip: the reverse order broke PCP's activation with a persistent
# "database tables are unavailable" error (verified empirically in the
# Playground-era suite; root cause never pinned). No server is started —
# PCP's WP-CLI runner makes no HTTP requests.
#
# Prints WP_DIR=<path> as its last line; run-plugin-check.mjs parses it.
set -e

REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"

. "$REPO_ROOT/tests/e2e/lib/provision-wp.sh"
provision_wp

wp plugin install /opt/plugin-check.zip --activate --path="$WP_DIR" --allow-root
wp plugin install "$REPO_ROOT/build/the-another-multi-brand-global-styles-test.zip" \
	--activate --path="$WP_DIR" --allow-root

echo "WP_DIR=$WP_DIR"
```

- [ ] **Step 2: Create `tests/e2e/check-plugin/pcp-early-init-marker.php`**

This is the surviving core of the old shim — detection only, no argv/stdout patching (real wp-cli needs neither):

```php
<?php
/**
 * wp-cli --require marker: proves Plugin Check's CLI runner actually
 * early-initialized. Only an early-initialized runner (object-cache.php
 * drop-in present + canonical argv) allows runtime checks at all —
 * CLI_Runner::allow_runtime_checks() returns false otherwise, and PCP then
 * SILENTLY omits runtime checks from a full run. Checking after WordPress
 * loads, from wp-cli's own hook, is what lets run-plugin-check.mjs
 * distinguish "runtime checks ran and found nothing" from "runtime checks
 * silently never ran" (the failure mode the old AJAX-based suite had —
 * see the Plugin Check gotcha in CLAUDE.md).
 *
 * @package MultiBrandGlobalStyles
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::add_hook(
	'after_wp_load',
	static function () {
		$GLOBALS['pcp_early_init'] =
			class_exists( 'WordPress\\Plugin_Check\\Utilities\\Plugin_Request_Utility' )
			&& null !== \WordPress\Plugin_Check\Utilities\Plugin_Request_Utility::get_runner();
	}
);

register_shutdown_function(
	static function () {
		echo "\npcp_early_init=" . ( empty( $GLOBALS['pcp_early_init'] ) ? 'no' : 'yes' ) . "\n";
	}
);
```

- [ ] **Step 3: Rewrite `run-plugin-check.mjs`**

Replace the entire file with:

```js
#!/usr/bin/env node
/**
 * Plugin Check (PCP) suite — runs WordPress.org's official Plugin Check
 * against the packaged release zip in a fresh, natively-provisioned
 * WordPress (real PHP + the SQLite drop-in; see provision-pcp-wp.sh), via
 * Plugin Check's WP-CLI runner.
 *
 * Why WP-CLI and not the wp-admin AJAX flow: PCP's AJAX flow swaps the
 * whole table set (users, options included) to a freshly-installed pc_-
 * prefixed environment on runtime-check requests, and nothing carries the
 * requester's auth (user row, salts, roles) into it — so its 5 runtime
 * checks always die unauthenticated ("0"). The WP-CLI runner is upstream's
 * only behat-tested path for runtime checks and needs no auth at all. See
 * the "Plugin Check runtime checks" gotcha in CLAUDE.md.
 *
 * Structure (preserved from the Playground-era suite):
 * - PCP installs BEFORE our zip; the object-cache.php drop-in (PCP's
 *   early-init hack) is re-placed before EACH run (PCP cleanup deletes it).
 * - Run 1 = PCP's full default check set. Run 2 = the 5 runtime checks
 *   explicitly: if early-init ever regresses, PCP reports those slugs as
 *   nonexistent and this run errors — a loud canary against the silent
 *   under-coverage failure mode the AJAX-era suite had. Each run also
 *   carries pcp-early-init-marker.php (--require), which prints
 *   pcp_early_init=yes|no at shutdown.
 *
 * Pass/fail: ERROR-type findings gate; WARNING-type findings are reported
 * but don't fail the suite. Structural failures (missing runs,
 * early_init=no, fatals, unparseable report lines) always gate. `wp plugin
 * check`'s own exit code is NOT trusted for findings (it may be non-zero
 * when findings exist) — parsed output is the source of truth; only spawn
 * failures and the provisioning script's exit code gate directly.
 */

import { spawnSync } from 'node:child_process';
import { existsSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const ROOT = path.resolve( HERE, '../../..' );
const PLUGIN_SLUG = 'the-another-multi-brand-global-styles';
const ZIP_PATH = path.join( ROOT, 'build', `${ PLUGIN_SLUG }-test.zip` );
const RESULTS_FILE = path.join( ROOT, 'build', 'plugin-check-results.txt' );
const MARKER_REQUIRE = path.join( HERE, 'pcp-early-init-marker.php' );

const RUNTIME_CHECKS = [
	'enqueued_scripts_size',
	'enqueued_styles_size',
	'enqueued_styles_scope',
	'enqueued_scripts_scope',
	'non_blocking_scripts',
];

const failures = [];

function fail( message ) {
	failures.push( message );
	console.error( `✗ ${ message }` );
}

if ( ! existsSync( ZIP_PATH ) ) {
	console.error(
		`✗ Missing ${ path.relative( ROOT, ZIP_PATH ) } — run via scripts/run-e2e.sh plugin-check (or make check-plugin), which builds it.`
	);
	process.exit( 1 );
}

console.log( 'Provisioning ephemeral WordPress (native PHP + SQLite drop-in)…' );
const prov = spawnSync( 'sh', [ path.join( HERE, 'provision-pcp-wp.sh' ) ], {
	cwd: ROOT,
	encoding: 'utf8',
	timeout: 5 * 60_000,
	maxBuffer: 64 * 1024 * 1024,
} );
const provLog = `${ prov.stdout ?? '' }${ prov.stderr ?? '' }`;
if ( prov.error || prov.status !== 0 ) {
	console.error( provLog );
	console.error(
		`✗ Provisioning failed${ prov.error ? `: ${ prov.error.message }` : ` (exit ${ prov.status })` }`
	);
	process.exit( 1 );
}
const WP_DIR = ( prov.stdout ?? '' ).match( /^WP_DIR=(.+)$/m )?.[ 1 ];
if ( ! WP_DIR ) {
	console.error( provLog );
	console.error( '✗ provision-pcp-wp.sh did not report WP_DIR.' );
	process.exit( 1 );
}

const DROPIN_SRC = path.join(
	WP_DIR,
	'wp-content/plugins/plugin-check/drop-ins/object-cache.copy.php'
);
const DROPIN_DST = path.join( WP_DIR, 'wp-content/object-cache.php' );

/**
 * Places the early-init drop-in and runs one `wp plugin check` pass.
 *
 * @param {string[]} extraArgs Extra wp-cli args (e.g. --checks=…).
 * @return {?Object} { cmd, stdout, stderr, earlyInit } or null on spawn failure.
 */
function runCheck( extraArgs ) {
	// PCP's per-run cleanup deletes the drop-in — re-place before EACH run.
	const cp = spawnSync( 'cp', [ DROPIN_SRC, DROPIN_DST ], { encoding: 'utf8' } );
	if ( cp.status !== 0 ) {
		fail( `Could not place PCP's object-cache drop-in: ${ cp.stderr }` );
		return null;
	}

	const args = [
		'plugin',
		'check',
		PLUGIN_SLUG,
		'--format=json',
		`--require=${ MARKER_REQUIRE }`,
		`--path=${ WP_DIR }`,
		'--allow-root',
		...extraArgs,
	];
	const cmd = `wp ${ args.join( ' ' ) }`;
	console.log( `Running: ${ cmd }` );
	const run = spawnSync( 'wp', args, {
		cwd: ROOT,
		encoding: 'utf8',
		timeout: 10 * 60_000,
		maxBuffer: 64 * 1024 * 1024,
	} );
	if ( run.error ) {
		fail( `Failed to run wp-cli: ${ run.error.message }` );
		return null;
	}
	return {
		cmd,
		stdout: run.stdout ?? '',
		stderr: run.stderr ?? '',
		earlyInit: /pcp_early_init=yes/.test( run.stdout ?? '' ),
	};
}

const runs = [
	runCheck( [] ),
	runCheck( [ `--checks=${ RUNTIME_CHECKS.join( ',' ) }` ] ),
].filter( Boolean );

// Keep the CI failure artifact (uploaded by .github/workflows/e2e.yml).
writeFileSync(
	RESULTS_FILE,
	runs
		.map(
			( r ) =>
				`===RUN=== early_init=${ r.earlyInit ? 'yes' : 'no' } cmd=${ r.cmd }\n${ r.stdout }\n--- stderr ---\n${ r.stderr }\n===END===\n`
		)
		.join( '' )
);

if ( runs.length !== 2 ) {
	fail(
		`Expected 2 Plugin Check runs (full set + runtime canary), completed ${ runs.length }.`
	);
}

const canary = runs.find( ( r ) => r.cmd.includes( '--checks=' ) );
if ( ! canary ) {
	fail( 'The runtime-checks canary run (--checks=…) is missing.' );
} else {
	for ( const slug of RUNTIME_CHECKS ) {
		if ( ! canary.cmd.includes( slug ) ) {
			fail( `Runtime canary run does not include the "${ slug }" check.` );
		}
	}
}

for ( const run of runs ) {
	if ( ! run.earlyInit ) {
		// Without early init, PCP silently omits all runtime checks from
		// the full run (and errors on the canary) — exactly the silent
		// under-coverage this suite exists to prevent.
		fail(
			`Plugin Check did NOT early-initialize for "${ run.cmd }" — runtime checks cannot have run.`
		);
	}
	// Fatals on stderr cut a run short — always gate. wp-cli's own phar
	// deprecation notices under PHP 8.3 are expected noise, not failures.
	for ( const line of run.stderr.split( '\n' ) ) {
		if ( /Fatal error/.test( line ) && ! /Deprecated/.test( line ) ) {
			fail( `PHP fatal error during "${ run.cmd }": ${ line.slice( 0, 300 ) }` );
		}
	}
}

/**
 * Parses one run's stdout into findings.
 *
 * Body format (from `wp plugin check --format=json`):
 *   FILE: includes/foo.js
 *   [{"line":0,...,"type":"WARNING","code":"...","message":"..."}]
 *
 * @param {string} body Run stdout.
 * @param {string} cmd  The wp-cli command (for failure messages).
 * @return {Array<Object>} Findings with a `file` property added.
 */
function parseFindings( body, cmd ) {
	const findings = [];
	let currentFile = '(unknown file)';

	for ( const rawLine of body.split( '\n' ) ) {
		const line = rawLine.replace( /<br\s*\/?>/g, '' ).trim();

		const fileMatch = line.match( /^FILE: (.+)$/ );
		if ( fileMatch ) {
			currentFile = fileMatch[ 1 ].trim();
			continue;
		}

		if ( line.startsWith( '[' ) ) {
			try {
				for ( const finding of JSON.parse( line ) ) {
					findings.push( { file: currentFile, ...finding } );
				}
			} catch {
				fail(
					`Unparseable report line from "${ cmd }" (Plugin Check output format drift?): ${ line.slice( 0, 200 ) }`
				);
			}
			continue;
		}

		// A fatal anywhere means the run was cut short — always gate.
		if ( /Fatal error/.test( line ) ) {
			fail( `PHP fatal error during "${ cmd }": ${ line }` );
			continue;
		}

		/*
		 * PHP problems raised by THIS PLUGIN's code while Plugin Check
		 * exercised it (the URL-aware runtime checks actually render
		 * pages) are real defects that never appear as PCP findings —
		 * gate on them. Notices from other code (wp-cli's phar
		 * deprecations, PCP itself, core) are upstream noise we can't
		 * act on, so they're deliberately not matched.
		 */
		if (
			/(Warning|Notice|Deprecated)(<\/b>)?:/.test( line ) &&
			line.includes( `plugins/${ PLUGIN_SLUG }/` )
		) {
			fail( `PHP problem in plugin code during "${ cmd }": ${ line.slice( 0, 300 ) }` );
		}
	}

	return findings;
}

const allFindings = runs.flatMap( ( r ) => parseFindings( r.stdout, r.cmd ) );

// The canary's findings are a subset of the full run's — dedupe.
const seen = new Set();
const findings = allFindings.filter( ( f ) => {
	const key = `${ f.file }|${ f.code }|${ f.line }|${ f.column }|${ f.message }`;
	if ( seen.has( key ) ) {
		return false;
	}
	seen.add( key );
	return true;
} );

report(
	findings.filter( ( f ) => f.type === 'ERROR' ),
	findings.filter( ( f ) => f.type !== 'ERROR' )
);

/**
 * Prints the summary and exits with the suite's pass/fail status.
 *
 * @param {Array<Object>} errors   ERROR-type findings (gate).
 * @param {Array<Object>} warnings Everything else (reported only).
 */
function report( errors, warnings ) {
	console.log(
		`\nPlugin Check: ${ errors.length } error(s), ${ warnings.length } warning(s)`
	);
	for ( const f of [ ...errors, ...warnings ] ) {
		console.log(
			`  [${ f.type }] ${ f.file }:${ f.line } ${ f.code } — ${ f.message }`
		);
	}

	if ( errors.length > 0 ) {
		fail( 'Plugin Check found ERROR-level issues (see above).' );
	}

	if ( failures.length > 0 ) {
		console.error( `\n✗ Plugin Check suite FAILED (${ failures.length } failure(s)).` );
		process.exit( 1 );
	}
	console.log( '\n✓ Plugin Check suite passed.' );
	process.exit( 0 );
}
```

- [ ] **Step 4: Delete the Playground-era files**

```bash
git rm tests/e2e/check-plugin/check-plugin-blueprint.json tests/e2e/check-plugin/pcp-cli-shim.php
```

- [ ] **Step 5: Run the suite**

Run: `make check-plugin`
Expected: same result profile as before the migration — 0 errors, the same 6 pre-existing warnings, both runs present, both `early_init=yes`. If run 2 errors with "invalid check slugs" or the marker prints `pcp_early_init=no`: the drop-in placement or argv handling regressed — under real wp-cli argv is canonical, so suspect the drop-in path (Step 3's `DROPIN_SRC`) first. Also record the wall-clock (should drop — no WordPress/PCP/wp-cli downloads).

- [ ] **Step 6: Runtime-check tripwire test (temporary sabotage, then revert)**

In `run-plugin-check.mjs`, temporarily change `DROPIN_SRC` to a nonexistent path (e.g. append `.nope`), run `make check-plugin`, and confirm the suite FAILS loudly (the drop-in `cp` failure and/or `early_init=no` structural failures — not a silent pass with fewer checks). Then revert:

```bash
git checkout tests/e2e/check-plugin/run-plugin-check.mjs
```

Re-run `make check-plugin` once more to confirm it passes again after the revert.

- [ ] **Step 7: Commit**

```bash
git add -A tests/e2e/check-plugin
git commit -m "test(e2e): run Plugin Check natively via wp-cli instead of @wp-playground/cli"
```

---

### Task 5: Remove @wp-playground/cli and its image support packages

**Files:**
- Modify: `package.json` + `package-lock.json` (dependency removal via npm)
- Modify: `tests/e2e/Dockerfile` (remove the python3/g++ block)

**Interfaces:**
- Consumes: Task 4 (nothing imports `@wp-playground/cli` anymore — verify, don't assume).

- [ ] **Step 1: Verify nothing references the dependency**

```bash
grep -rn "wp-playground" --include="*.{ts,mjs,js,json,sh}" scripts tests package.json | grep -v package-lock
```
Expected: no matches outside comments/docs. If a code reference remains, STOP — Task 4 missed something.

- [ ] **Step 2: Remove the dependency (inside the container, so the lockfile updates with the pinned npm)**

```bash
docker run --rm -v "$PWD":/app -w /app the-another-multi-brand-global-styles-e2e-runner:latest \
	npm uninstall --no-audit --no-fund @wp-playground/cli
```
Expected: `package.json` devDependencies no longer lists `@wp-playground/cli`; `package-lock.json` updated.

- [ ] **Step 3: Remove the python3/g++ block from `tests/e2e/Dockerfile`**

Delete this entire block (comment + RUN) — it existed only for `@wp-playground/cli`'s `fs-ext-extra-prebuilt` node-gyp fallback:

```dockerfile
# @wp-playground/cli (boots the check-plugin suite's WordPress; see
# tests/e2e/check-plugin/run-plugin-check.mjs) depends on
# fs-ext-extra-prebuilt, a native addon shipped only as glibc-linked prebuilt
# .node binaries — they fail to `require()` under musl here (same class of
# issue as Chromium and ffmpeg above), and npm's own install script silently
# swallows that failure and reports a generic "no prebuilt binary" error that
# obscures the real cause. Its install script auto-falls-back to compiling
# from bundled source via node-gyp when the prebuilt require fails, so a
# plain C/C++ toolchain here is enough — no other project code changes.
RUN apk add --no-cache python3 g++
```

(If the current file's comment wording drifted, delete the block whose RUN is `apk add --no-cache python3 g++`.)

- [ ] **Step 4: Rebuild and run both suites**

```bash
make docker-build-e2e   # image now builds without python3/g++
make check-plugin        # fresh npm ci without @wp-playground/cli, suite passes
make test-e2e            # 23/23
```
Expected: all three succeed. `npm ci` inside the runs is the proof the lockfile is coherent without the removed dependency.

- [ ] **Step 5: Commit**

```bash
git add package.json package-lock.json tests/e2e/Dockerfile
git commit -m "chore(e2e): drop @wp-playground/cli and its musl build-toolchain image packages"
```

---

### Task 6: Documentation rewrite (CLAUDE.md)

**Files:**
- Modify: `CLAUDE.md` (four regions)

**Interfaces:**
- Consumes: the final shipped behavior of Tasks 1–5 — verify each factual claim against the actual files before writing it.

- [ ] **Step 1: Update the two-image description**

In the Development Commands section's e2e-image line ("`tests/e2e/Dockerfile` → …-e2e-runner (the above **plus** Alpine's native Chromium, ffmpeg, and python3/g++)…"), drop "and python3/g++" and adjust the sentence to match reality (Chromium + ffmpeg remain).

- [ ] **Step 2: Rewrite both Testing bullets**

Functional bullet: replace the clause "the plugin's four runtime paths (main file, `includes/`, `vendor/`, `readme.txt`) are copied in at the real slug" with "the plugin is installed from the same packaged `-test` zip the check-plugin suite gates (`wp plugin install`, zip built fresh each run by `scripts/run-e2e.sh`)". Also mention provisioning now lives in the shared `tests/e2e/lib/provision-wp.sh`.

Check-plugin bullet: replace "boots `@wp-playground/cli` fresh-install-from-zip and runs … via **PCP's WP-CLI runner**" with a native description: provisions WordPress via the same shared lib (no server), installs the image-baked pinned Plugin Check (`ARG PCP_VERSION`) then the `-test` zip, runs `wp plugin check` twice (full set + the 5 runtime checks as canary) with the `pcp-early-init-marker.php` require; ERROR findings gate, WARNINGs report; results still land in `build/plugin-check-results.txt`.

- [ ] **Step 3: Update the Gotchas**

1. The native-PHP gotcha's closing sentence ("`@wp-playground/cli` is still a dependency — the check-plugin suite uses it, where fresh-install-from-zip is exactly its sweet spot.") becomes: "`@wp-playground/cli` has since been removed entirely — the check-plugin suite now provisions natively too (see `docs/superpowers/specs/2026-07-03-e2e-zip-based-provisioning-design.md`)."
2. Replace the "Plugin files are copied, never symlinked" bullet with:

```markdown
- **Both suites install the plugin from the packaged `-test` zip — never from a source mount or file-by-file copy.** `scripts/run-e2e.sh` builds the zip fresh every run for both suites, so `.distignore` is load-bearing for the functional suite too, and packaging bugs fail functionally, not just in Plugin Check. Two consequences: source edits require the rebuild every run already performs (no live mount), and `make test-e2e`/`make check-plugin` both leave `vendor/` in no-dev state (`composer build` runs inside the zip pipeline) — run `make install-dev` before `make test`/`make lint` afterwards.
```

3. In the big Plugin Check gotcha: keep the pc_-table/AJAX auth story and "never go back to the wp-admin AJAX flow" (still true, still load-bearing), keep the CLI-runner + drop-in-pre-placed requirement, but delete the Playground-specific argv sentence ("under `@wp-playground/cli`, argv is an empty string and the real argv has `--path=` injected at index 1 …") and the playground stdout-swallowing parenthetical, replacing them with one sentence: "Under real wp-cli, argv and stdout are canonical; `pcp-early-init-marker.php` (wp-cli `--require`) still records whether PCP early-initialized, which is the tripwire distinguishing 'runtime checks found nothing' from 'runtime checks silently never ran'." Drop the "(playground argv/stdout)" upstream-reportable note, keep the PCP identity-gap one.

- [ ] **Step 4: Consistency check**

```bash
grep -n "wp-playground\|playground\|python3\|four runtime paths\|copied, never symlinked" CLAUDE.md
```
Expected: remaining "playground/Playground" mentions only in historical-contrast context (the native-PHP gotcha's history sentence and the Plugin Check gotcha's AJAX history); no python3, no four-runtime-paths copy claims. Read the edited file top-to-bottom once for coherence.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md for zip-based provisioning and the native Plugin Check suite"
```
