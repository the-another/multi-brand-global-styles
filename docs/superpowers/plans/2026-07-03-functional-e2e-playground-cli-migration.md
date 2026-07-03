# Functional E2E Suite: wp-now → @wp-playground/cli Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate `tests/e2e/functional/`'s dev server from the deprecated `wp-now` to `@wp-playground/cli server`, without changing test behavior, coverage, or the suite's control surface.

**Architecture:** Swap `playwright.config.ts`'s `webServer.command` from `npx wp-now start ...` to `npx @wp-playground/cli server ...`, fix the resulting readiness-check incompatibility (`webServer.url` → `webServer.port`), mount the permalink mu-plugin directly via `--mount` instead of wp-now's shared-global-directory copy hack, and let `@wp-playground/cli`'s own `--workers` default replace wp-now's hardcoded, unconfigurable 5-request concurrency cap. `tests/e2e/check-plugin/` is untouched (already migrated in a prior change).

**Tech Stack:** `@wp-playground/cli` v3.1.43 (already a devDependency), `@playwright/test` v1.59, `@wordpress/e2e-test-utils-playwright` v1.43, Node (host has v24.7; no engines pin in this repo).

## Global Constraints

- Design doc: `docs/superpowers/specs/2026-07-03-functional-e2e-playground-cli-migration-design.md` — read it for the full root-cause analysis; this plan implements it verbatim.
- **Do not touch** `tests/e2e/Dockerfile`, `scripts/run-e2e.sh`, `Makefile`, or `.github/workflows/e2e.yml`. None of them know which dev-server tool the functional suite uses today, and none should gain that knowledge — it lives entirely in `tests/e2e/functional/playwright.config.ts`.
- Do not touch `tests/e2e/check-plugin/` — already migrated, out of scope.
- No `--reset` flag: `@wp-playground/cli server` uses ephemeral temp storage per spawn already (confirmed empirically during brainstorming).
- No CLI `--login` flag: the Blueprint's `"login": true` already supplies it; combining both caused a cookie-path conflict in this project's own history (see `CLAUDE.md`'s Plugin Check gotcha).
- No explicit `--workers=<n>` or `--workers=auto`: omit the flag entirely and let the CLI's own default (`min(6, cpus-1)`) apply — confirmed empirically that explicit `--workers=auto` is *uncapped* `cpus-1` (11 workers on a 12-core host vs. 6 with no flag), which on a small-core CI runner could undershoot the CLI's own documented safe floor of 6 workers (it prints a deadlock-risk warning below that). The capped default degrades more gracefully.
- Run `make test-e2e` only once, at the end (Task 4) — not after every task. Earlier tasks verify with faster, narrower checks (`playwright test --list`, manual boot+curl, `npm ls`, `grep`).
- Verification baseline to compare Task 4 against: 23 tests, 22 passed + 1 flaky-then-retried on first attempt, 0 failures after retries (last recorded wp-now CI run).

---

### Task 1: Point the functional suite's dev server at @wp-playground/cli

**Files:**
- Modify: `tests/e2e/functional/global-setup.ts`
- Delete: `tests/e2e/functional/global-teardown.ts`
- Modify: `tests/e2e/functional/playwright.config.ts`
- Modify: `tests/e2e/functional/wait-for-real-readiness.ts` (doc comment only, logic unchanged)

**Interfaces:**
- Consumes: `tests/e2e/functional/functional-blueprint.json` (unchanged, `{"login": true, "steps": []}`), `tests/e2e/functional/e2e-environment.php` (unchanged, `init`-hooked permalink mu-plugin).
- Produces: `webServer.port` and `webServer.command` in `playwright.config.ts` that Task 4's `make test-e2e` run depends on. `globalSetup`'s exported default function signature is unchanged (`(config: FullConfig) => Promise<void>`), still referenced by `playwright.config.ts`'s `globalSetup: './global-setup.ts'`.

This is the core, behavior-changing task. The other tasks are mechanical cleanup around it.

- [ ] **Step 1: Rewrite `global-setup.ts` — remove the wp-now shared-mu-plugins-directory copy logic**

The mu-plugin will be mounted directly by `@wp-playground/cli`'s `--mount` flag (Step 3), so the copy-into-`~/.wp-now/mu-plugins/`-before-every-run logic is no longer needed. Replace the full contents of `tests/e2e/functional/global-setup.ts` with:

```ts
import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import { waitForRealReadiness } from './wait-for-real-readiness';

export default async function globalSetup( config: FullConfig ) {
	const { baseURL } = config.projects[ 0 ].use as { baseURL: string };
	const storageStatePath = 'artifacts/storage-states/admin.json';

	const requestUtils = await RequestUtils.setup( {
		baseURL,
		storageStatePath,
	} );

	await waitForRealReadiness( requestUtils.request, baseURL );

	// @wp-playground/cli's --auto-mount auto-activates the mounted plugin in
	// plugin mode (confirmed empirically); this is the explicit safety net
	// (and the activation assertion for a freshly spawned server).
	try {
		await requestUtils.activatePlugin(
			'the-another-multi-brand-global-styles'
		);
	} catch {
		// Already active.
	}
}
```

- [ ] **Step 2: Delete `global-teardown.ts`**

Its only job was cleaning up the mu-plugin copy from Step 1's now-removed logic — nothing else in it. Delete the file:

```bash
rm tests/e2e/functional/global-teardown.ts
```

- [ ] **Step 3: Rewrite `playwright.config.ts` — swap the webServer command and fix the readiness check**

Replace the full contents of `tests/e2e/functional/playwright.config.ts` with:

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

export default defineConfig( {
	testDir: '.',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	timeout: process.env.CI ? 60_000 : 30_000,
	// A safety net for ordinary transient flakiness, not a load-bearing
	// workaround: @wp-playground/cli server's request concurrency is sized
	// by --workers (one worker THREAD per in-flight request — a different
	// "workers" than Playwright's own test-runner `workers: 1` below), and
	// we let it default to min(6, cpus-1) rather than override it — see the
	// webServer.command comment.
	retries: 2,
	workers: 1,
	reporter: 'list',
	// Keep failure artifacts at the repo root (same location as before the
	// config moved here): .gitignore's /test-results/ and CI's
	// upload-artifact path both point there.
	outputDir: path.join( ROOT, 'test-results' ),
	use: {
		baseURL: BASE_URL,
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
		// --auto-mount mode-detects this plugin from cwd (see cwd: ROOT
		// below), matching wp-now's old plugin-mode detection. --mount adds
		// the permalink mu-plugin directly into the running instance's
		// mu-plugins directory, replacing wp-now's old approach of copying
		// it into a shared, machine-global ~/.wp-now/mu-plugins/ directory.
		// No --reset: unlike wp-now, `server` mode uses ephemeral temp
		// storage per spawn already, so every fresh spawn starts from a
		// clean site with no flag needed. No --login: the Blueprint already
		// supplies "login": true, and combining it with a CLI --login flag
		// causes a cookie-path conflict (see the check-plugin suite's own
		// history in CLAUDE.md). No --workers override: the CLI's own
		// default (min(6, cpus-1)) already improves on wp-now's old
		// hardcoded, unconfigurable 5-instance concurrency cap — each
		// worker is an independent thread with its own PHP runtime, so
		// total concurrent request capacity literally equals the worker
		// count. An explicit `--workers=auto` (uncapped cpus-1) was
		// considered and rejected: on a small-core CI runner that can
		// undershoot the CLI's own documented safe floor of 6 workers (it
		// warns of file-lock deadlocks below that), where the capped
		// default degrades more gracefully.
		command: `npx @wp-playground/cli server --auto-mount --blueprint=tests/e2e/functional/functional-blueprint.json --mount=tests/e2e/functional/e2e-environment.php:/wordpress/wp-content/mu-plugins/e2e-environment.php --port=${ PORT } --php=8.3`,
		// @wp-playground/cli's --auto-mount detects from its cwd like
		// wp-now did; Playwright defaults webServer.cwd to this config
		// file's directory, which would mount tests/e2e/functional instead
		// of the plugin. Pin the repo root (also keeps the
		// --blueprint/--mount paths above stable).
		cwd: ROOT,
		// Not `url: BASE_URL`: @wp-playground/cli's Blueprint "login": true
		// step 302-redirects-to-self on every request from a client that
		// doesn't already carry the cookie it sets on that first hit —
		// confirmed empirically (a cookie-less client loops forever; a
		// cookie-jar client resolves in exactly 2 hops: 302 then 200).
		// Playwright's built-in `url` readiness poller (playwright-core's
		// httpRequest/isURLAvailable) follows redirects but carries no
		// cookies, so it would loop forever against that URL until
		// webServer.timeout kills the run — this, not anything Docker- or
		// musl-specific, is what a prior migration attempt's "hang right
		// after Ready!" (see CLAUDE.md) actually was. `port` is a pure
		// TCP-accept check with no HTTP semantics, so it's immune to the
		// loop. Real readiness (WordPress actually installed and
		// answering, not just the process having bound the port) is still
		// gated by globalSetup's waitForRealReadiness(), which uses a real
		// Playwright request context (a cookie jar, like a browser) and
		// already resolves the same redirect in 2 hops.
		port: PORT,
		reuseExistingServer: ! process.env.CI,
		timeout: 120_000,
	},
} );
```

- [ ] **Step 4: Update `wait-for-real-readiness.ts`'s doc comment**

Logic is unchanged — only the comment, which now needs to name the current tool and describe this function as the suite's *sole* real-readiness gate (previously supplementary to a URL-based poller that happened to work under wp-now). Replace the full contents of `tests/e2e/functional/wait-for-real-readiness.ts` with:

```ts
import type { APIRequestContext } from '@playwright/test';

/**
 * Playwright's webServer readiness check (webServer.port) only waits for
 * @wp-playground/cli's server to accept a TCP connection at all — the first
 * real requests after that (both global setup's activatePlugin() call and
 * the very first spec's page navigation) can still land in a startup window
 * where something in front of the real PHP runtime answers with a transient
 * error (observed as a bare "Bad Gateway" response body) before WordPress
 * installation has actually finished. global-setup.ts's activatePlugin()
 * call additionally swallows ALL errors on the assumption they mean
 * "already active" (true when Playwright reused an already-running server,
 * not true on a freshly spawned one), so a request fired into this window
 * fails silently there and the failure only surfaces later, in the first
 * spec test. Poll past the window ourselves before doing anything that
 * depends on a real WordPress response.
 *
 * This is now the suite's only real readiness gate: webServer.port proves
 * the process bound the port, not that WordPress is actually installed and
 * answering. A URL-based poller would prove that too, but
 * @wp-playground/cli's Blueprint-driven login makes any cookie-less URL
 * poll loop forever — see playwright.config.ts's webServer comment.
 */
export async function waitForRealReadiness(
	request: APIRequestContext,
	baseURL: string
): Promise< void > {
	const deadline = Date.now() + 60_000;
	while ( Date.now() < deadline ) {
		try {
			const response = await request.get( `${ baseURL }/wp-login.php` );
			const body = await response.text();
			if ( response.ok() && ! /bad gateway|service unavailable|database tables are unavailable|error establishing a database connection/i.test( body ) ) {
				return;
			}
		} catch {
			// Connection not accepted yet — keep polling.
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1_000 ) );
	}
	throw new Error( 'WordPress never became ready (still erroring after 60s)' );
}
```

- [ ] **Step 5: Fast syntax/config-load check (no server boot)**

Run:
```bash
npx playwright test --list --config tests/e2e/functional/playwright.config.ts
```
Expected: no errors, ends with `Total: 23 tests in 5 files` (same count as before this task — `--list` parses the config and every spec file, including `globalSetup`'s import chain, without starting `webServer`).

- [ ] **Step 6: Manual boot + curl smoke check**

This stands in for a unit test — there's no test harness for Playwright config files, so verify the new `webServer.command` actually boots, mounts the mu-plugin, and auto-activates the plugin, before paying for a full Playwright run in Task 4.

```bash
rm -rf /tmp/pg-plan-verify && mkdir -p /tmp/pg-plan-verify
nohup npx @wp-playground/cli server --auto-mount \
  --blueprint=tests/e2e/functional/functional-blueprint.json \
  --mount=tests/e2e/functional/e2e-environment.php:/wordpress/wp-content/mu-plugins/e2e-environment.php \
  --port=8895 --php=8.3 \
  > /tmp/pg-plan-verify/boot.log 2>&1 &
echo $! > /tmp/pg-plan-verify/server.pid

deadline=$((SECONDS+90))
while [ $SECONDS -lt $deadline ]; do
  grep -qi "ready" /tmp/pg-plan-verify/boot.log 2>/dev/null && break
  sleep 2
done
cat /tmp/pg-plan-verify/boot.log
```
Expected: log shows `Mount .../tests/e2e/functional/e2e-environment.php -> /wordpress/wp-content/mu-plugins/e2e-environment.php`, `Mount .../the-another-multi-brand-global-styles -> /wordpress/wp-content/plugins/the-another-multi-brand-global-styles (auto-mount)`, and ends with `Ready! WordPress is running on http://127.0.0.1:8895 (N workers)` where N is `min(6, cpus-1)` for the current machine (6 on any machine with 7+ cores).

Then, with a cookie jar (a plain cookie-less `curl -L` would loop forever against the Blueprint's login redirect — see Step 3's comment):
```bash
rm -f /tmp/pg-plan-verify/cookies.txt
curl -s -c /tmp/pg-plan-verify/cookies.txt -b /tmp/pg-plan-verify/cookies.txt -o /dev/null http://127.0.0.1:8895/
curl -s -c /tmp/pg-plan-verify/cookies.txt -b /tmp/pg-plan-verify/cookies.txt http://127.0.0.1:8895/wp-admin/plugins.php -o /tmp/pg-plan-verify/plugins.html -w "plugins.php: %{http_code}\n"
grep -q "the-another-multi-brand-global-styles/the-another-multi-brand-global-styles.php.*Deactivate\|Deactivate.*the-another-multi-brand-global-styles" /tmp/pg-plan-verify/plugins.html && echo "PLUGIN ACTIVE: yes" || echo "PLUGIN ACTIVE: no (FAIL)"
curl -s -c /tmp/pg-plan-verify/cookies.txt -b /tmp/pg-plan-verify/cookies.txt -o /dev/null -w "sample-page pretty URL: %{http_code}\n" http://127.0.0.1:8895/sample-page/

kill $(cat /tmp/pg-plan-verify/server.pid) 2>/dev/null
pkill -f "wp-playground/cli server" 2>/dev/null
```
Expected: `plugins.php: 200`, `PLUGIN ACTIVE: yes`, `sample-page pretty URL: 200` (a 404 here would mean the mu-plugin's permalink flush didn't run — the plain-permalink form would be `?page_id=N`, not `/sample-page/`).

- [ ] **Step 7: Commit**

```bash
git add tests/e2e/functional/global-setup.ts tests/e2e/functional/playwright.config.ts tests/e2e/functional/wait-for-real-readiness.ts
git rm tests/e2e/functional/global-teardown.ts
git commit -m "test(e2e): point the functional suite's dev server at @wp-playground/cli

Swaps wp-now (deprecated) for @wp-playground/cli server. Root cause of a
previously abandoned attempt: @wp-playground/cli's Blueprint login step
302-redirects-to-self for cookie-less clients, which is exactly what
Playwright's built-in webServer.url readiness poller is — switched to
webServer.port (a TCP-only check) instead. Mu-plugin now mounts directly
via --mount rather than wp-now's shared ~/.wp-now/mu-plugins/ copy hack."
```

---

### Task 2: Drop the wp-now dependency and reword tool-specific comments

**Files:**
- Modify: `package.json`
- Modify: `package-lock.json` (regenerated by `npm install`, not hand-edited)
- Modify: `tests/e2e/functional/helpers.ts` (comment only)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: nothing consumed by later tasks — purely mechanical cleanup, independently reviewable.

- [ ] **Step 1: Remove the `@wp-now/wp-now` devDependency**

In `package.json`, remove this line from `devDependencies`:
```json
		"@wp-now/wp-now": "^0.1.74",
```
Resulting `devDependencies` block:
```json
	"devDependencies": {
		"@playwright/test": "^1.59.1",
		"@wordpress/e2e-test-utils-playwright": "^1.43.0",
		"@wp-playground/cli": "^3.1.43"
	}
```

- [ ] **Step 2: Regenerate the lockfile**

```bash
npm install
```
Expected: completes without error; `package-lock.json` is modified (removes the `@wp-now/wp-now` entry and its now-unused transitive dependencies).

- [ ] **Step 3: Verify the dependency is actually gone**

```bash
npm ls @wp-now/wp-now
```
Expected: exits non-zero, prints `(empty)` or `npm error ... invalid: ... missing` / `npm error code ELSPROBLEMS` — confirms it's no longer installed. And:
```bash
npm ls @wp-playground/cli
```
Expected: prints the installed version (`the-another-multi-brand-global-styles@0.1.0 ... @wp-playground/cli@3.1.x`), confirming it's still present.

- [ ] **Step 4: Reword the wp-now-specific comment in `helpers.ts`**

In `tests/e2e/functional/helpers.ts`, find:
```ts
	// force: the classic publish button is a plain form submit, but WP
	// admin's postbox layout under wp-now never settles enough to pass
	// Playwright's "stable" actionability check.
	await page.locator( '#publish' ).click( { force: true } );
```
Replace with:
```ts
	// force: the classic publish button is a plain form submit, but WP
	// admin's postbox layout under the PHP-wasm engine never settles
	// enough to pass Playwright's "stable" actionability check.
	await page.locator( '#publish' ).click( { force: true } );
```

- [ ] **Step 5: Verify**

```bash
grep -n "wp-now" tests/e2e/functional/helpers.ts package.json
```
Expected: no output (no matches in either file).

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json tests/e2e/functional/helpers.ts
git commit -m "build: drop the deprecated @wp-now/wp-now devDependency

Both e2e suites now run on @wp-playground/cli."
```

---

### Task 3: Update documentation

**Files:**
- Modify: `CLAUDE.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: the root-cause findings and fixes from Task 1 (must describe them accurately).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Update `CLAUDE.md`'s Development Commands section**

Change line 94 from:
```
make test-e2e         # functional wp-now + Playwright suite (tests/e2e/functional/)
```
to:
```
make test-e2e         # functional @wp-playground/cli + Playwright suite (tests/e2e/functional/)
```

- [ ] **Step 2: Update `CLAUDE.md`'s functional-suite description**

Change (in the "End-to-end, split into two independent suites" section):
```
- `tests/e2e/functional/` — **wp-now** dev-mounted source, Playwright. Config `tests/e2e/functional/playwright.config.ts`. Covers activation (incl. a real deactivate→reactivate-via-wp-admin flow), save-time rule validation, per-URL style scoping, a Navigation-block render canary (guards the theme.json merge), and `%%brand.*%%` substitution. Provisions Brands through the **real admin form** (`createBrand()` in `functional/helpers.ts`) so `BrandPostType::save()`'s nonce/validation/conflict path is exercised end-to-end. A predefined-admin login comes from `functional/functional-blueprint.json`; permalinks come from the `functional/e2e-environment.php` mu-plugin (see gotcha below).
```
to:
```
- `tests/e2e/functional/` — **`@wp-playground/cli`** dev-mounted source, Playwright. Config `tests/e2e/functional/playwright.config.ts`. Covers activation (incl. a real deactivate→reactivate-via-wp-admin flow), save-time rule validation, per-URL style scoping, a Navigation-block render canary (guards the theme.json merge), and `%%brand.*%%` substitution. Provisions Brands through the **real admin form** (`createBrand()` in `functional/helpers.ts`) so `BrandPostType::save()`'s nonce/validation/conflict path is exercised end-to-end. A predefined-admin login comes from `functional/functional-blueprint.json`; permalinks come from the `functional/e2e-environment.php` mu-plugin, mounted directly via `--mount` (see gotcha below).
```

- [ ] **Step 3: Replace the wp-now gotchas with the current, root-caused findings**

Replace these two bullets (the permalink/blueprint-ordering one and the shared-mu-plugins-directory one):
```
- **wp-now blueprint steps run BEFORE WordPress installation completes.** A blueprint `runPHP`/`setSiteOptions` step that writes an option (e.g. `permalink_structure`) is silently reset by `installationStep2()` afterward — verified empirically. Pretty permalinks for the functional suite therefore **must** stay in the `init`-hooked mu-plugin (`functional/e2e-environment.php`), which fires on every real request long after install. Do not try to move it into the blueprint.
- **The functional mu-plugin loads from wp-now's shared `~/.wp-now/mu-plugins/` directory** — that's inherent to wp-now's plugin mode (its own bundled mu-plugins live there too), not something to "fix."
- **wp-now's PHP-wasm engine caps concurrent requests at 5 with a 5s acquire timeout** (`@php-wasm/universal`'s `PHPProcessManager`; wp-now bundles `@wp-playground/wordpress` internally, so this is the same engine the check-plugin suite uses — not configurable via any CLI flag, blueprint step, or the public `bootWordPress()` API, verified by reading the installed package source). A real browser can exceed 5 concurrent requests on asset-heavy admin/editor screens; the engine answers overflow with a transient 502 instead of queuing past the timeout. This is why `playwright.config.ts` runs with `retries: 2` — the 502 clears on retry (empirically, in well under a second) — rather than something to chase further upstream. Migrating this suite off wp-now onto `@wp-playground/cli` directly (attempted 2026-07-03) does not remove this ceiling (same engine) and introduced a worse, unexplained hang immediately after the server reported ready in Docker (reproduced twice; not reproduced identically on host) — reverted. If retried, isolate that hang first before touching anything else.
```
with:
```
- **Pretty permalinks for the functional suite live in the `init`-hooked mu-plugin (`functional/e2e-environment.php`), not a Blueprint step.** Confirmed necessary under wp-now: its Blueprint steps run before WordPress installation completes, so a `runPHP`/`setSiteOptions` step writing `permalink_structure` was silently reset by `installationStep2()` afterward. The mu-plugin approach was kept unchanged for `@wp-playground/cli` (the Blueprint-step alternative wasn't re-tested there, since the mu-plugin already works and now mounts directly via `--mount=<host-path>:/wordpress/wp-content/mu-plugins/e2e-environment.php` — no more shared, machine-global `~/.wp-now/mu-plugins/` copy step).
- **`@wp-playground/cli`'s Blueprint `"login": true` step 302-redirects-to-self on every request from a client that doesn't already carry the cookie it sets on that first hit.** Confirmed empirically: a cookie-less client (plain `curl`) loops forever; a cookie-jar client resolves in exactly 2 hops (`302` then `200`). A real browser or Playwright's `RequestUtils`/`APIRequestContext` are both cookie-jar clients and are unaffected. Playwright Test's own built-in `webServer.url` readiness poller is **not** — it follows redirects but carries no cookies (confirmed by reading `playwright-core`'s `httpRequest`/`isURLAvailable`) — so it loops forever against a URL affected by this. This, not anything Docker- or musl-specific, is what an earlier migration attempt's "unexplained hang immediately after the server reported ready" actually was. Fix: `playwright.config.ts`'s `webServer` uses `port`, not `url` — a pure TCP-accept check with no HTTP semantics — and leans on `wait-for-real-readiness.ts`'s own cookie-jar-based poll as the real readiness gate.
- **`@wp-playground/cli server`'s concurrency is sized by `--workers`, unlike wp-now's hardcoded, unconfigurable 5-instance `PHPProcessManager` pool.** Each worker is an independent thread running its own single-instance PHP runtime, so total concurrent request capacity literally equals the worker count — confirmed by reading the installed package source and by triggering the CLI's own boot-time warning at `--workers=2` ("Running fewer than 6 workers may increase the likelihood of deadlock due to workers blocking on file locks"). `playwright.config.ts`'s `webServer.command` passes no `--workers` flag, relying on the CLI's own default (`min(6, cpus-1)`) rather than hardcoding a number tuned for one machine. `retries: 2` stays as a general safety net for unrelated flakiness, not because this specific concurrency ceiling is unfixable.
```

- [ ] **Step 4: Update `README.md`**

Change line 55 from:
```
make test-e2e        # functional wp-now + Playwright suite
```
to:
```
make test-e2e        # functional @wp-playground/cli + Playwright suite
```

Change line 67 from:
```
  - `tests/e2e/functional/` — wp-now dev-mounted source: activation, save-time rule validation, per-URL style scoping, a Navigation-block render canary, and variable substitution.
```
to:
```
  - `tests/e2e/functional/` — `@wp-playground/cli` dev-mounted source: activation, save-time rule validation, per-URL style scoping, a Navigation-block render canary, and variable substitution.
```

- [ ] **Step 5: Update `CHANGELOG.md`**

In the `## [Unreleased]` section, change the `### Added` bullet:
```
- End-to-end test infrastructure: wp-now + Playwright functional suite (`tests/e2e/functional/`) and an `@wp-playground/cli` Plugin Check suite (`tests/e2e/check-plugin/`), a dedicated `Dockerfile.e2e`, a shared `scripts/run-e2e.sh` entrypoint, and a GitHub Actions workflow.
```
to:
```
- End-to-end test infrastructure: a Playwright + `@wp-playground/cli` functional suite (`tests/e2e/functional/`) and an `@wp-playground/cli` WP-CLI-runner Plugin Check suite (`tests/e2e/check-plugin/`), a dedicated `Dockerfile.e2e`, a shared `scripts/run-e2e.sh` entrypoint, and a GitHub Actions workflow.
```

Add a new `### Changed` subsection directly after `### Added` (before `### Known issues`):
```
### Changed
- Functional e2e suite's dev server switched from the deprecated `wp-now` to `@wp-playground/cli`, matching the Plugin Check suite. No test behavior changed; see `CLAUDE.md`'s gotchas for the readiness-check and concurrency fixes this required.
```

- [ ] **Step 6: Verify no wp-now mentions remain anywhere tracked**

```bash
grep -rln "wp-now\|wp_now\|wpNow" --include="*.md" --include="*.txt" --include="*.ts" --include="*.js" --include="*.json" . 2>/dev/null | grep -v node_modules | grep -v .superpowers | grep -v docs/superpowers/specs | grep -v docs/superpowers/plans
```
Expected: no output. (The spec and this plan document are excluded from the grep on purpose — they're historical records of the migration and are expected to keep saying "wp-now" when describing what was replaced.)

- [ ] **Step 7: Commit**

```bash
git add CLAUDE.md README.md CHANGELOG.md
git commit -m "docs: update wp-now references after the @wp-playground/cli migration

Replaces the unresolved 'unexplained hang, isolate first' gotcha with
the actual root cause and fix, and the 'not configurable' concurrency
cap note with the --workers-based fix."
```

---

### Task 4: Full suite verification

**Files:** none (verification only).

**Interfaces:**
- Consumes: everything from Tasks 1–3.
- Produces: nothing — this is the migration's acceptance gate.

- [ ] **Step 1: Run the full functional suite exactly as CI will**

```bash
make test-e2e
```

- [ ] **Step 2: Compare against the baseline**

Expected, matching the last recorded wp-now CI run: 23 tests total, all passing (0 failures) — allow up to a couple of flaky-then-retried tests absorbed by `retries: 2`, but no test should fail all 3 attempts. Specifically confirm:
- No `webServer` boot timeout / hang (would show as Playwright erroring before any test runs, around the 120s `webServer.timeout` mark).
- `activation.spec.ts`'s tests pass, including the real deactivate→reactivate-via-wp-admin flow (proves login/session and admin UI interaction both work).
- `provision.setup.ts` succeeds (proves the real-admin-form Brand creation and the mu-plugin's pretty permalinks both work — `admin-rules.spec.ts`/`style-scoping.spec.ts`/`variables.spec.ts` all depend on it).
- If the 502-from-concurrency-starvation pattern still appears in retried tests' logs, that's a signal to revisit — see Step 3.

- [ ] **Step 3: If it fails, diagnose against what changed**

- **`webServer` never becomes ready / times out around 120s:** re-check `playwright.config.ts` uses `port: PORT`, not `url: BASE_URL` (Task 1 Step 3) — this exact symptom is the redirect-loop bug this migration fixes.
- **Pretty-permalink URLs 404 (e.g. `/sample-page/`):** re-check the `--mount` flag's paths in `webServer.command` — host path is relative to `cwd: ROOT`, so it must be `tests/e2e/functional/e2e-environment.php` (not an absolute path, not missing the `tests/e2e/functional/` prefix).
- **Plugin not active / `activatePlugin` errors surface as real failures, not swallowed "already active":** re-check `--auto-mount` is present in `webServer.command`.
- **Transient 502s persist across retries (not just single-retry flakiness):** re-check no stray `--workers=N` was left in `webServer.command` — the plan calls for omitting the flag entirely.

Fix forward, re-run `make test-e2e`, and do not proceed until it passes. No commit for this task unless a fix from this diagnosis step requires one — in that case, amend the relevant Task 1–3 commit's file(s) as a new, separately-described commit (do not amend prior commits).
