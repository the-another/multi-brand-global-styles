# E2E Native PHP Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `@wp-playground/cli` (PHP-wasm) with real WordPress on native PHP 8.3 + the official SQLite drop-in as the functional e2e suite's environment, inside the existing single e2e Docker container.

**Architecture:** The e2e Docker image bakes a version-pinned WordPress core and the `sqlite-database-integration` drop-in at build time. A new boot script (`serve-wp.sh`) copies that core to a fresh temp dir, installs WordPress via wp-cli, copies in the plugin's runtime files, and serves with PHP's built-in server (`wp server`, 6 worker processes). Playwright's `webServer` invokes the script; auth moves from Playground's Blueprint auto-login to the standard storageState pattern. The plugin-check suite stays on Playground, untouched.

**Tech Stack:** Docker (Alpine, musl PHP 8.3), wp-cli + `wp-cli/server-command`, `sqlite-database-integration` drop-in, Playwright `^1.59`, `@wordpress/e2e-test-utils-playwright ^1.43`.

**Spec:** `docs/superpowers/specs/2026-07-03-e2e-native-php-migration-design.md`

## Global Constraints

- Single container, single make target: `make test-e2e` stays `docker build` + one `docker run … sh scripts/run-e2e.sh functional`, identical locally and in GitHub Actions. No compose, no wp-env, no Docker socket passthrough.
- All version pins are exact (`x.y.z` / exact tag), never `latest` — resolved once in Task 1, then hardcoded as Dockerfile `ARG` defaults.
- WordPress admin credentials must be exactly `admin` / `password` (the defaults `@wordpress/e2e-test-utils-playwright`'s `RequestUtils` assumes).
- Port stays `8881` (overridable via `WP_E2E_PORT`, as today).
- The plugin is installed from exactly four runtime paths — `the-another-multi-brand-global-styles.php`, `includes/`, `vendor/`, `readme.txt` — at the real slug `the-another-multi-brand-global-styles`. **Copy, don't symlink**: PHP resolves symlinks in `__FILE__`, and WordPress only realpath-maps whole-directory plugin symlinks, so per-file symlinks can break `plugin_basename()`.
- The check-plugin suite (`tests/e2e/check-plugin/`), `@wp-playground/cli` in `package.json`, and the `python3`/`g++` Alpine packages must NOT be touched (all still used by check-plugin).
- All four functional specs and `provision.setup.ts` stay behaviorally unchanged; Brands keep being provisioned through the real admin form (`createBrand()`), because `BrandPostType::save()` is the plugin's only write path.
- wp-cli runs as root inside the container: every `wp` invocation needs `--allow-root`.
- Coding/comment style: this repo documents *why*-constraints in comments; keep new comments in that spirit but do not reproduce the deleted PHP-wasm lore.

---

### Task 1: Bake native WordPress into the e2e image

**Files:**
- Modify: `tests/e2e/Dockerfile`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces (paths inside the image that Task 2's script depends on): `/opt/wp-core/` (unpacked WordPress core, default theme included), `/opt/sqlite-database-integration/` (unpacked drop-in plugin, containing `db.copy`), a working `wp server` subcommand, PHP modules `pdo_sqlite`, `sqlite3`, `gd`, and the `unzip` binary.

- [ ] **Step 1: Resolve the three version pins**

Run these on the host and note the results:

```bash
# Current WordPress stable (use offers[0].version):
curl -s https://api.wordpress.org/core/version-check/1.7/ | head -c 400

# Current sqlite-database-integration release:
curl -s "https://api.wordpress.org/plugins/info/1.0/sqlite-database-integration.json" | grep -o '"version":"[^"]*"' | head -1

# Latest wp-cli/server-command tag (expect something like v2.1.0):
curl -s https://api.github.com/repos/wp-cli/server-command/releases/latest | grep '"tag_name"'
```

Expected: three concrete version strings. Substitute them into the `ARG` defaults in Step 2 (the values shown there are examples — use what the APIs return).

- [ ] **Step 2: Add the native-WP layers to the Dockerfile**

In `tests/e2e/Dockerfile`, insert the following block **after** the existing wp-cli/dist-archive `RUN` (the one ending `--allow-root`) and **before** the Chromium `apk add` block:

```dockerfile
# --- Native-PHP WordPress for the functional e2e suite -----------------------
# (see tests/e2e/functional/serve-wp.sh). Core and the official SQLite drop-in
# are baked in at build time, version-pinned, so test runs make no network
# fetches. Bumping WP_VERSION is a deliberate one-line change that CI fully
# exercises. Credentials/DB config happen at boot time, not here.
ARG WP_VERSION=6.9.1
ARG SQLITE_PLUGIN_VERSION=2.2.3
ARG WP_CLI_SERVER_COMMAND_VERSION=v2.1.0

# Extensions the SQLite drop-in and WordPress need at runtime (pdo_sqlite,
# sqlite3), plus gd for WP image handling and unzip for the plugin download.
RUN apk add --no-cache \
	php83-pdo \
	php83-pdo_sqlite \
	php83-sqlite3 \
	php83-gd \
	unzip

RUN wp core download --version=${WP_VERSION} --path=/opt/wp-core --allow-root && \
	curl -sSL "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_PLUGIN_VERSION}.zip" -o /tmp/sqlite.zip && \
	unzip -q /tmp/sqlite.zip -d /opt && \
	rm /tmp/sqlite.zip && \
	wp package install "https://github.com/wp-cli/server-command/archive/refs/tags/${WP_CLI_SERVER_COMMAND_VERSION}.zip" --allow-root
```

Replace the three `ARG` defaults with the versions resolved in Step 1.

- [ ] **Step 3: Build the image and verify the baked artifacts**

```bash
make docker-build-e2e
docker run --rm the-another-multi-brand-global-styles-e2e-runner:latest sh -c '
	php -m | grep -E "pdo_sqlite|sqlite3|gd" &&
	test -f /opt/wp-core/wp-load.php && echo core-ok &&
	test -f /opt/sqlite-database-integration/db.copy && echo dropin-ok &&
	ls /opt/wp-core/wp-content/themes/ &&
	wp server --help --allow-root | head -3'
```

Expected: the three PHP modules listed, `core-ok`, `dropin-ok`, at least one `twentytwenty*` theme directory, and `wp server` usage text. If `db.copy` is at a different path inside the zip (e.g. nested), fix the `unzip -d` target so `/opt/sqlite-database-integration/db.copy` exists — Task 2 hardcodes that path.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/Dockerfile
git commit -m "test(e2e): bake pinned WordPress core, SQLite drop-in, and wp server into the e2e image"
```

---

### Task 2: Boot script (`serve-wp.sh`), verified standalone

**Files:**
- Create: `tests/e2e/functional/serve-wp.sh`

**Interfaces:**
- Consumes: image paths from Task 1 (`/opt/wp-core/`, `/opt/sqlite-database-integration/db.copy`, `wp server`).
- Produces: a running WordPress at `http://localhost:${WP_E2E_PORT:-8881}` with admin `admin`/`password`, the plugin active at slug `the-another-multi-brand-global-styles`, pretty permalinks (`/%postname%/`), `WP_DEBUG`+`WP_DEBUG_DISPLAY` on. Installation completes **before** the port binds (Task 3's readiness model depends on this ordering). The script `exec`s the server as its final statement so Playwright's process-group kill stops it.

- [ ] **Step 1: Write the script**

Create `tests/e2e/functional/serve-wp.sh`:

```sh
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
```

- [ ] **Step 2: Run the script standalone in the container — expect a serving WordPress**

```bash
docker run --rm -v "$PWD":/app -w /app -p 8881:8881 \
	the-another-multi-brand-global-styles-e2e-runner:latest \
	sh -c 'sh tests/e2e/functional/serve-wp.sh & 
		for i in $(seq 1 60); do curl -sf -o /dev/null http://localhost:8881/ && break; sleep 1; done
		echo "--- homepage:";        curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8881/
		echo "--- pretty permalink:"; curl -s -o /dev/null -w "%{http_code}\n" -L http://localhost:8881/sample-page/
		echo "--- REST index:";      curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8881/wp-json/
		echo "--- concurrency (6 parallel):"; seq 1 6 | xargs -P6 -I{} curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8881/'
```

Expected output: `200` for homepage, `200` for `/sample-page/` (proves pretty permalinks + router), `200` for the REST index, and six `200`s from the parallel burst (proves `PHP_CLI_SERVER_WORKERS` works — if these serialize into timeouts or errors, that's the musl/workers risk from the spec: stop and investigate before proceeding). Also confirm the boot log shows `Plugin 'the-another-multi-brand-global-styles' activated.`

If `wp core install` fails complaining about MySQL: the drop-in isn't being loaded — verify `wp-content/db.php` exists and its two sed-substituted paths are real (`{SQLITE_IMPLEMENTATION_FOLDER_PATH}` / `{SQLITE_PLUGIN}` are the tokens in the plugin's `db.copy`; if the tokens ever change upstream, read the file and adjust the seds).

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/functional/serve-wp.sh
git commit -m "test(e2e): native-PHP WordPress boot script for the functional suite"
```

---

### Task 3: Rewire Playwright onto the native boot (config, auth, deletions)

**Files:**
- Modify: `tests/e2e/functional/playwright.config.ts` (full rewrite below)
- Modify: `tests/e2e/functional/global-setup.ts` (full rewrite below)
- Modify: `tests/e2e/functional/helpers.ts:52-63` (publish click)
- Modify: `scripts/run-e2e.sh:21`
- Delete: `tests/e2e/functional/wait-for-real-readiness.ts`
- Delete: `tests/e2e/functional/functional-blueprint.json`
- Delete: `tests/e2e/functional/e2e-environment.php`

**Interfaces:**
- Consumes: `serve-wp.sh` from Task 2 (its ordering guarantee: install-before-listen; its credentials: `admin`/`password`).
- Produces: a passing `make test-e2e`. Auth contract for all specs: `global-setup.ts` writes storageState to `artifacts/storage-states/admin.json` via `requestUtils.setupRest()`; the config points both `use.storageState` and `process.env.STORAGE_STATE_PATH` at that file, so browser contexts AND the per-worker `requestUtils` fixture are authenticated. (Until now, login rode on Playground's Blueprint `login: true` auto-login — that magic is gone, so this wiring is load-bearing, not optional.)

- [ ] **Step 1: Rewrite `playwright.config.ts`**

Replace the entire file with:

```ts
import { defineConfig } from '@playwright/test';
import * as path from 'node:path';

// This config lives WITH the functional suite it drives; everything that
// must resolve against the repo root does so explicitly via ROOT below.
const ROOT = path.resolve( __dirname, '../../..' );

const PORT = Number( process.env.WP_E2E_PORT ) || 8881;
const BASE_URL = `http://localhost:${ PORT }`;

// RequestUtils from @wordpress/e2e-test-utils-playwright reads WP_BASE_URL
// (defaults to localhost:8889). Override it so it matches our e2e port.
process.env.WP_BASE_URL = BASE_URL;

// Written by global-setup.ts (RequestUtils.setup + setupRest). Both the
// browser contexts (use.storageState) and the per-worker requestUtils
// fixture (which reads this env var) start authenticated as admin from it.
const STORAGE_STATE_PATH = path.join(
	ROOT,
	'artifacts/storage-states/admin.json'
);
process.env.STORAGE_STATE_PATH = STORAGE_STATE_PATH;

export default defineConfig( {
	testDir: '.',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	timeout: process.env.CI ? 60_000 : 30_000,
	// Gutenberg's pattern: retries only in CI. Locally, flakiness should
	// surface, not be masked by silent re-runs.
	retries: process.env.CI ? 2 : 0,
	// One shared WordPress install → one worker (the ecosystem standard;
	// Gutenberg and the @wordpress/scripts base config do the same).
	workers: 1,
	reporter: 'list',
	// Keep failure artifacts at the repo root: .gitignore's /test-results/
	// and CI's upload-artifact path both point there.
	outputDir: path.join( ROOT, 'test-results' ),
	use: {
		baseURL: BASE_URL,
		storageState: STORAGE_STATE_PATH,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on',
		launchOptions: process.env.CHROMIUM_EXECUTABLE_PATH
			? {
					executablePath: process.env.CHROMIUM_EXECUTABLE_PATH,
					args: [ '--no-sandbox' ],
			  }
			: {},
	},
	projects: [
		{
			name: 'setup',
			testMatch: '*.setup.ts',
			retries: 0,
		},
		{
			name: 'default',
			testMatch: '*.spec.ts',
			dependencies: [ 'setup' ],
		},
	],
	globalSetup: './global-setup.ts',
	webServer: {
		// Native-PHP WordPress (real PHP 8.3 + the official SQLite
		// drop-in) — see serve-wp.sh. The script finishes installing
		// WordPress BEFORE the server binds the port, so a plain URL
		// readiness poll is truthful here (no Playground login-redirect
		// or readiness-window workarounds needed anymore).
		command: 'sh tests/e2e/functional/serve-wp.sh',
		// Playwright defaults webServer.cwd to this config file's
		// directory; pin the repo root so the script path resolves.
		cwd: ROOT,
		url: BASE_URL,
		reuseExistingServer: ! process.env.CI,
		timeout: 120_000,
	},
} );
```

- [ ] **Step 2: Rewrite `global-setup.ts`**

Replace the entire file with:

```ts
import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

// Logs in as admin (RequestUtils' default admin/password credentials —
// serve-wp.sh installs WordPress with exactly those) and persists the
// authenticated storage state to STORAGE_STATE_PATH, where the config's
// use.storageState and the per-worker requestUtils fixture pick it up.
export default async function globalSetup( config: FullConfig ) {
	const { baseURL } = config.projects[ 0 ].use as { baseURL: string };

	const requestUtils = await RequestUtils.setup( {
		baseURL,
		storageStatePath: process.env.STORAGE_STATE_PATH,
	} );
	await requestUtils.setupRest();
}
```

- [ ] **Step 3: Delete the three obsolete files**

```bash
git rm tests/e2e/functional/wait-for-real-readiness.ts \
	tests/e2e/functional/functional-blueprint.json \
	tests/e2e/functional/e2e-environment.php
```

- [ ] **Step 4: Remove the PHP-wasm compensations from `helpers.ts`**

In `createBrand()`, replace:

```ts
	// force: the classic publish button is a plain form submit, but WP
	// admin's postbox layout under the PHP-wasm engine never settles
	// enough to pass Playwright's "stable" actionability check.
	await page.locator( '#publish' ).click( { force: true } );

	// Classic editor redirects back to post.php with a success notice.
	// Generous timeout: the first post save under php-wasm is slow.
	await expect(
		page.locator( '#message.notice-success, #message.updated' )
	).toBeVisible( { timeout: 30_000 } );
```

with:

```ts
	await page.locator( '#publish' ).click();

	// Classic editor redirects back to post.php with a success notice.
	await expect(
		page.locator( '#message.notice-success, #message.updated' )
	).toBeVisible();
```

(If the publish click proves flaky even on native PHP — i.e. the postbox
instability was never wasm-specific — restore `{ force: true }` with a
comment saying exactly that, and keep the default timeout.)

- [ ] **Step 5: Drop the redundant env prefix in `scripts/run-e2e.sh`**

Replace the functional branch line:

```sh
	WP_BASE_URL=http://localhost:8881 npx playwright test --config tests/e2e/functional/playwright.config.ts
```

with:

```sh
	npx playwright test --config tests/e2e/functional/playwright.config.ts
```

(The config itself sets `WP_BASE_URL` from the port.)

- [ ] **Step 6: Run the full suite — expect a clean pass**

```bash
make test-e2e
```

Expected: all setup + spec tests pass, `retries` shows 0 locally. Triage guide for likely first-run failures:
- Login/401/`Unauthorized` in specs → storageState wiring: confirm `artifacts/storage-states/admin.json` was written by global setup and `use.storageState` points at it.
- `webServer` timeout → run Task 2's standalone check again; the script must reach "ready" line within ~120s (it should take seconds).
- Publish-click flake in `createBrand` → apply the documented `force: true` fallback from Step 4.

- [ ] **Step 7: Commit**

```bash
git add -A tests/e2e/functional scripts/run-e2e.sh
git commit -m "test(e2e): run the functional suite against native PHP + SQLite instead of PHP-wasm playground"
```

---

### Task 4: Verification gates (reliability, speed, artifacts, check-plugin)

**Files:**
- No permanent changes (a temporary assertion edit, reverted in-place).

**Interfaces:**
- Consumes: the passing suite from Task 3.
- Produces: the spec's acceptance evidence. Do not proceed to Task 5 unless gate 1 passes.

- [ ] **Step 1: Reliability gate — 3 consecutive clean local runs, timed**

```bash
for i in 1 2 3; do time make test-e2e; done
```

Expected: three passes, zero retries (local `retries: 0` makes any flake a hard failure — that's the point). Record the three wall-clock times; compare against the old suite's CI durations (GitHub Actions history for the `Functional E2E` job) for the speed claim, since the old suite never passed cleanly on this machine.

If any run fails: STOP. Diagnose with the trace/video in `test-results/` (native PHP + WP_DEBUG_DISPLAY means real errors show in screenshots). Fix before continuing — this gate existing is the whole reason for the migration.

- [ ] **Step 2: Failure-artifact check**

Temporarily break one assertion — in `tests/e2e/functional/activation.spec.ts`, change `expect( response!.status() ).toBe( 200 );` to `.toBe( 418 );` — then:

```bash
make test-e2e; ls -R test-results/ | head -40
git checkout tests/e2e/functional/activation.spec.ts
```

Expected: the run fails on that one test, and `test-results/` contains a trace zip, video, and screenshot for it (CI's upload-artifact path depends on this). Then the checkout restores the spec.

- [ ] **Step 3: Check-plugin suite untouched**

```bash
make check-plugin
```

Expected: passes exactly as before (still Playground-based; nothing in this migration touches it).

No commit — this task changes nothing permanently.

---

### Task 5: Documentation rewrite (CLAUDE.md)

**Files:**
- Modify: `CLAUDE.md` (three regions: Development Commands, Testing, Gotchas)

**Interfaces:**
- Consumes: final behavior from Tasks 1–4 (including whether the `force: true` fallback was needed — adjust the new gotcha text to match reality).
- Produces: CLAUDE.md that matches the shipped setup.

- [ ] **Step 1: Update the Development Commands e2e line**

Change:

```
make test-e2e         # functional @wp-playground/cli + Playwright suite (tests/e2e/functional/)
```

to:

```
make test-e2e         # functional native-PHP + Playwright suite (tests/e2e/functional/)
```

- [ ] **Step 2: Replace the Testing section's functional-suite bullet**

Replace the `tests/e2e/functional/` bullet (the one beginning "**`@wp-playground/cli`** dev-mounted source, Playwright…") with:

```markdown
- `tests/e2e/functional/` — **native PHP 8.3 + the official SQLite drop-in**, Playwright. Config `tests/e2e/functional/playwright.config.ts`; WordPress is booted by `tests/e2e/functional/serve-wp.sh` (Playwright's `webServer.command`): a version-pinned core baked into the e2e image (`ARG WP_VERSION` in `tests/e2e/Dockerfile`) is copied to a fresh temp dir, installed via wp-cli (admin/password — `RequestUtils`' defaults), the plugin's four runtime paths (main file, `includes/`, `vendor/`, `readme.txt`) are copied in at the real slug, pretty permalinks set via `wp rewrite structure`, then served by `wp server` with `PHP_CLI_SERVER_WORKERS=6`. Auth is standard storageState: `global-setup.ts` writes `artifacts/storage-states/admin.json` via `requestUtils.setupRest()`. Covers activation (incl. a real deactivate→reactivate-via-wp-admin flow), save-time rule validation, per-URL style scoping, a Navigation-block render canary (guards the theme.json merge), and `%%brand.*%%` substitution. Provisions Brands through the **real admin form** (`createBrand()` in `functional/helpers.ts`) so `BrandPostType::save()`'s nonce/validation/conflict path is exercised end-to-end — a deliberate deviation from the seed-via-REST norm, because that save handler is the plugin's only write path.
```

- [ ] **Step 3: Rewrite the Gotchas section**

Delete these six bullets entirely (identified by their bold lead-ins — they document the removed PHP-wasm/Playground functional-suite hazards):
1. "**Pretty permalinks for the functional suite live in the `init`-hooked mu-plugin…**"
2. "**`@wp-playground/cli`'s Blueprint `"login": true` step 302-redirects-to-self…**"
3. "**`@wp-playground/cli`'s `--auto-mount` names the plugin's `wp-content/plugins/` folder…**"
4. "**`RequestUtils`'s `rest()` helper … sets no timeout…**"
5. "**`@wp-playground/cli` defaults WordPress's own site URL to `http://127.0.0.1:<port>`…**"
6. "**`@wp-playground/cli server`'s concurrency is sized by `--workers`…**" AND the follow-on bullet "**Residual flakiness after all four fixes above…**"

Keep unchanged: the Plugin Check runtime-checks bullet, the `wp_theme_json_data_user` bullet, and the no-uninstall-hooks bullet.

Add these new bullets where the deleted ones were:

```markdown
- **The functional suite runs on native PHP, not Playground — keep it that way unless the single-container constraint changes.** The previous `@wp-playground/cli` (PHP-wasm) boot required five separate workarounds (mount naming, CORS `--site-url`, a cookie-jar readiness poller, Blueprint-based activation replacing an untimed REST call, a `--workers` floor) and still never produced a clean local pass; the full history lives in `docs/superpowers/specs/2026-07-03-e2e-native-php-migration-design.md`. `@wp-playground/cli` is still a dependency — the check-plugin suite uses it, where fresh-install-from-zip is exactly its sweet spot.
- **`serve-wp.sh`'s ordering is load-bearing twice**: the SQLite drop-in (`wp-content/db.php`) must exist before `wp core install` (or install tries to reach MySQL), and installation must finish before `wp server` binds the port (that's what makes Playwright's plain `webServer.url` readiness check truthful — no custom poller needed).
- **Admin credentials must stay exactly `admin`/`password`** — they're `@wordpress/e2e-test-utils-playwright` `RequestUtils`' hardcoded defaults, and both the storageState written by `global-setup.ts` and the per-worker `requestUtils` fixture assume them.
- **Plugin files are copied, never symlinked, into the test WordPress**: PHP resolves symlinks in `__FILE__` and WordPress only realpath-maps whole-directory plugin symlinks (`wp_register_plugin_realpath()`), so per-file symlinks can break `plugin_basename()`-keyed behavior.
- **`PHP_CLI_SERVER_WORKERS=6` on `wp server` is required, not tuning**: with a single built-in-server worker, WordPress's own loopback requests (cron spawn, site health) deadlock the one PHP process.
```

If Task 3 ended up needing the `force: true` publish click, append a sixth bullet documenting that the classic-editor postbox instability is engine-independent.

- [ ] **Step 4: Verify CLAUDE.md consistency**

Re-read the edited CLAUDE.md top to bottom once: no remaining references to `functional-blueprint.json`, `e2e-environment.php`, `wait-for-real-readiness`, or wp-now/Playground *in the functional-suite context* (check-plugin references stay). Grep to confirm:

```bash
grep -n "functional-blueprint\|e2e-environment\|wait-for-real-readiness" CLAUDE.md
```

Expected: no matches.

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: rewrite e2e sections of CLAUDE.md for the native-PHP functional suite"
```
