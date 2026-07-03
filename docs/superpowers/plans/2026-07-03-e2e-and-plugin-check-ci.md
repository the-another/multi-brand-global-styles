# E2E + Plugin Check CI Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Formalize the existing wp-now/Playwright functional e2e suite and the uncommitted `@wp-playground/cli` Plugin Check suite into two Blueprint-driven, Docker-containerized, CI-ready pipelines that share one Docker image and one shell script.

**Architecture:** Both suites keep their current engines (wp-now dev-mounted source for the functional suite; `@wp-playground/cli` fresh-from-zip for Plugin Check — confirmed necessary because wp-now has no `--mount`-equivalent flag). Ad hoc bootstrap hacks (a mu-plugin copied into a shared, machine-global `~/.wp-now/mu-plugins/` directory; a manual `wp-login.php` form POST) are replaced with declarative WordPress Playground Blueprint JSON files using the officially documented `login` and `runPHP` steps. A new, second Alpine-based Docker image (`Dockerfile.e2e`) adds Alpine's native `chromium` package (Playwright's own downloaded Chromium is glibc-linked and does not run on musl/Alpine) alongside the same PHP+Composer+wp-cli toolchain as the existing image, so it can build the release zip itself. One shared shell script (`scripts/run-e2e.sh <functional|plugin-check>`) is the only place either pipeline's run logic lives; two Make targets and one new GitHub Actions workflow all call it identically.

**Tech Stack:** `@wordpress/e2e-test-utils-playwright`, `@playwright/test` 1.59.x, `@wp-now/wp-now` 0.1.75 (wraps `@wp-playground/*`), `@wp-playground/cli`, Alpine 3.24.1, PHP 8.3, Node (Alpine package).

## Global Constraints

- Do not change any `Brand`/`GlobalStyles`/`ContentVariables` plugin logic — this plan is test/CI infrastructure only (spec: "Out of scope").
- Keep wp-now for the functional suite and `@wp-playground/cli` for Plugin Check — do not unify onto one engine (spec: "What stays as-is").
- Keep `createBrand()`'s real-admin-form provisioning and `wait-for-real-readiness.ts` — do not replace with Blueprint-driven provisioning or remove the readiness poll (spec: "What stays as-is").
- Stay on Alpine for both Docker images (project decision) — do not introduce a Debian/Ubuntu or Microsoft Playwright base image.
- The existing `Dockerfile`/`$(DOCKER_IMAGE)` used by `install`/`lint`/`format`/`test`/`release` must not change.
- Every Blueprint predefined-admin-user login must use the documented `admin`/`password` credentials (WordPress Playground Blueprint schema default) — never a custom user.
- Both new Make targets (`test-e2e`, `check-plugin`) must call the same shared shell script, `scripts/run-e2e.sh`, inside the same new Docker image (spec: "critical that both make targets... call single shell script").

---

### Task 1: Functional suite — explicit predefined-admin Blueprint (permalink mu-plugin stays)

**REVISED 2026-07-03 after an empirical spike by the Task 1 implementer hit a real blocker.** The original version of this task planned to replace `tests/e2e/e2e-environment.php` (a mu-plugin copied into wp-now's shared `~/.wp-now/mu-plugins/` directory that sets pretty permalinks on first load) with a Blueprint `runPHP` step. That does not work: tracing `node_modules/@wp-now/wp-now/main.js`'s `startWPNow()`, blueprint steps (`runBlueprintSteps`) execute *before* `installationStep2(php)` — the call that actually installs WordPress (creates tables, sets default options). Empirically confirmed by starting wp-now directly with a blueprint containing the exact `runPHP` permalink code from the original plan: the live site continued serving plain-permalink links (`/?page_id=2`, `?feed=rss2`) and the REST API's discovery `Link` header still advertised `index.php?rest_route=/`, proving `installationStep2` resets `permalink_structure` back to empty *after* the blueprint's write. There is no blueprint step or ordering override available in this wp-now version to run PHP after installation completes — only a persistent runtime hook (like the existing mu-plugin's `init` action, which fires on every real HTTP request, long after installation is done) can do it. So: **the mu-plugin stays, unchanged.** The shared-`~/.wp-now/mu-plugins/`-directory characteristic this was trying to avoid turns out to be an inherent trait of wp-now's "plugin mode" itself — `mountMuPlugins()` in `main.js` hardcodes that shared directory as the *only* mu-plugins mount point for every project mode; wp-now's own bundled mu-plugins (`0-allow-wp-org.php`, `1-pretty-permalinks.php`, `2-deactivate-sqlite-plugin.php`) already live there unconditionally, so this isn't a risk our code specifically introduced.

What Task 1 keeps from the original design: the user's actual ask was a Blueprint with a **predefined admin user** so the suite doesn't have to deal with the auth process — that part works fine and is unaffected by the install-ordering bug (the `login` step defines a `PLAYGROUND_AUTO_LOGIN_AS_USER` PHP constant, a wp-config.php-level change, not a database write, so `installationStep2` can't reset it). wp-now already calls this unconditionally after every blueprint run regardless, so adding it explicitly is redundant in practice but makes the predefined-admin-user intent explicit and inspectable in a checked-in file, matching what was actually asked for.

**Files:**
- Create: `tests/e2e/functional-blueprint.json`
- Modify: `playwright.config.ts`

**Interfaces:**
- Consumes: nothing new
- Produces: nothing new consumed by later tasks. `tests/e2e/e2e-environment.php` and `tests/e2e/global-setup.ts` are explicitly OUT of scope for this task — do not touch them.

- [ ] **Step 1: Confirm the current suite passes before changing anything**

Run: `npx playwright install chromium && WP_BASE_URL=http://localhost:8881 npx playwright test --config playwright.config.ts`
Expected: All specs in `activation.spec.ts`, `admin-rules.spec.ts`, `style-scoping.spec.ts`, `variables.spec.ts` PASS (this is the baseline "before" state — record any pre-existing failures unrelated to this task before proceeding).

- [ ] **Step 2: Create the functional Blueprint (predefined admin login only — no steps)**

```json
{
	"login": true,
	"steps": []
}
```

Save as `tests/e2e/functional-blueprint.json`.

- [ ] **Step 3: Update playwright.config.ts — pass the blueprint**

In `playwright.config.ts`, change the `webServer.command` line from:

```ts
		command: `npx wp-now start --port=${ PORT } --php=8.3 --reset --skip-browser`,
```

to:

```ts
		command: `npx wp-now start --port=${ PORT } --php=8.3 --reset --skip-browser --blueprint=tests/e2e/functional-blueprint.json`,
```

Do not touch `globalTeardown` or any other line in this file — the mu-plugin mechanism it supports is unchanged.

- [ ] **Step 4: Run the suite and confirm it still passes**

Run: `WP_BASE_URL=http://localhost:8881 npx playwright test --config playwright.config.ts`
Expected: All specs still PASS, exactly as in Step 1's baseline — passing the (redundant but explicit) `--blueprint` flag must not change behavior. If anything fails differently than the Step 1 baseline, the blueprint flag is interfering with wp-now's own startup — stop and report BLOCKED with the diff in behavior; do not attempt to work around it by modifying the mu-plugin or global-setup.ts.

- [ ] **Step 5: Commit**

```bash
git add tests/e2e/functional-blueprint.json playwright.config.ts
git commit -m "test(e2e): add explicit predefined-admin Blueprint for the functional suite"
```

---

### Task 2: Plugin Check suite — Blueprint-driven predefined admin login

**Files:**
- Modify: `tests/e2e/check-plugin-blueprint.json`
- Modify: `tests/e2e/check-plugin-global-setup.ts`

**Interfaces:**
- Consumes: `waitForRealReadiness()` from `tests/e2e/wait-for-real-readiness.ts` (unchanged, still called first)
- Produces: nothing new consumed by later tasks.

Background: same `compileBlueprint`/`login` mechanism as Task 1 — `@wp-playground/cli server`'s Blueprint gains `"login": true`, which auto-authenticates every request as `admin` via the `PLAYGROUND_AUTO_LOGIN_AS_USER` PHP constant, independent of cookies. This replaces the manual `wp-login.php` form POST in `check-plugin-global-setup.ts`. Playwright's `use.storageState` config option (in `playwright.check.config.ts`) still requires the file to exist before any browser context is created — `RequestUtils`'s own `storageState()` method always writes a valid (possibly cookie-less) file, which is sufficient since auth no longer depends on cookies.

- [ ] **Step 1: Add the login step to the Plugin Check Blueprint**

Replace the full contents of `tests/e2e/check-plugin-blueprint.json` with:

```json
{
	"login": true,
	"steps": [
		{
			"step": "installPlugin",
			"pluginData": {
				"resource": "wordpress.org/plugins",
				"slug": "plugin-check"
			},
			"options": { "activate": true }
		},
		{
			"step": "installPlugin",
			"pluginData": {
				"resource": "vfs",
				"path": "/tests-artifacts/the-another-multi-domain-global-styles-test.zip"
			},
			"options": { "activate": true }
		}
	]
}
```

(Only the `"login": true` line was added; step order/content is otherwise unchanged — including the existing plugin-check-before-our-zip install order, which must not be reordered per the file's original comment history.)

- [ ] **Step 2: Simplify check-plugin-global-setup.ts — remove the manual login POST**

Replace the full contents of `tests/e2e/check-plugin-global-setup.ts` with:

```ts
import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import { waitForRealReadiness } from './wait-for-real-readiness';

/**
 * The Blueprint's top-level `"login": true` (check-plugin-blueprint.json)
 * compiles to a `login` step that defines the PLAYGROUND_AUTO_LOGIN_AS_USER
 * PHP constant server-side (confirmed in @wp-playground/blueprints source)
 * — every request this WordPress instance handles is auto-authenticated as
 * admin regardless of cookies, so no real login POST or session cookie is
 * needed. Playwright's `storageState` config option still requires the
 * file to exist before any browser context is created; RequestUtils'
 * storageState() always writes a valid file even with an empty cookie jar.
 */
export default async function globalSetup( config: FullConfig ) {
	const { baseURL } = config.projects[ 0 ].use as { baseURL: string };
	const storageStatePath = process.env.STORAGE_STATE_PATH;

	if ( ! storageStatePath ) {
		throw new Error( 'STORAGE_STATE_PATH must be set before running global setup.' );
	}

	const requestUtils = await RequestUtils.setup( { baseURL, storageStatePath } );
	await waitForRealReadiness( requestUtils.request, baseURL );

	await requestUtils.request.storageState( { path: storageStatePath } );
}
```

- [ ] **Step 3: Run the Plugin Check suite end-to-end**

Run:
```bash
rm -f build/the-another-multi-domain-global-styles-test.zip
npm run plugin-zip:check
npx playwright install chromium
npm run check:plugin
```
Expected: `plugin-check.spec.ts`'s test PASSES — specifically, the `page.goto('/wp-admin/tools.php?page=plugin-check...')` navigation must reach the real Plugin Check admin page (asserted via `toContainText('Plugin Check')`), not a login wall or a "Sorry, you are not allowed to access this page" error. **If it fails at that assertion**, the auto-login-by-constant mechanism did not take effect for this CLI invocation — as a fallback, restore a real login POST in this file's `globalSetup`, using the exact approach the file had before this task (`requestUtils.request.post('wp-login.php', { form: { log: 'admin', pwd: 'password' } })` after `waitForRealReadiness`, then `requestUtils.request.storageState({ path: storageStatePath })`), and re-run this step.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/check-plugin-blueprint.json tests/e2e/check-plugin-global-setup.ts
git commit -m "test(e2e): drive Plugin Check auth from the Blueprint's predefined admin login"
```

---

### Task 3: Explicit plugin-activation test

**Files:**
- Modify: `tests/e2e/activation.spec.ts`

**Interfaces:**
- Consumes: `RequestUtils.deactivatePlugin(slug)` / `RequestUtils.activatePlugin(slug)` (both take the plugin's kebab-case slug, e.g. `'the-another-multi-domain-global-styles'` — confirmed in `node_modules/@wordpress/e2e-test-utils-playwright/build/request-utils/plugins.js`), `RequestUtils.rest()`

Background: wp-now unconditionally auto-activates a mounted plugin-mode project on first run — there is no CLI flag to suppress this. Rather than fight that at the environment-bootstrap level, this test itself explicitly deactivates the plugin and re-activates it through the real wp-admin Plugins screen, which is what actually exercises the plugin's activation-hook code path.

- [ ] **Step 1: Add the explicit activation test**

In `tests/e2e/activation.spec.ts`, add this test inside the existing `test.describe( 'activation', () => { ... } )` block, after the `'plugin is active'` test:

```ts
	test( 'plugin can be deactivated and reactivated through wp-admin', async ( {
		page,
		requestUtils,
	} ) => {
		await requestUtils.deactivatePlugin(
			'the-another-multi-domain-global-styles'
		);

		await page.goto( '/wp-admin/plugins.php' );
		const row = page.locator(
			'tr[data-slug="the-another-multi-domain-global-styles"]'
		);
		await row.getByRole( 'link', { name: 'Activate' } ).click();

		await expect( page.locator( '#message.updated' ) ).toContainText(
			'activated'
		);

		const plugins = await requestUtils.rest<
			Array< { plugin: string; status: string } >
		>( {
			method: 'GET',
			path: '/wp/v2/plugins',
		} );
		const ours = plugins.find( ( p ) =>
			p.plugin.includes( 'the-another-multi-domain-global-styles' )
		);
		expect( ours ).toBeDefined();
		expect( ours!.status ).toBe( 'active' );
	} );
```

- [ ] **Step 2: Run it to verify it fails first if run against the pre-Task-3 file (sanity check the test is meaningful)**

Run: `WP_BASE_URL=http://localhost:8881 npx playwright test --config playwright.config.ts --grep "deactivated and reactivated"`
Expected: PASS on first run (there's no separate "make it fail first" step here since the activation feature itself already exists and works — this test is new coverage of existing behavior, not a new feature). If it fails, inspect whether `tr[data-slug="..."]` matches the actual rendered row — WordPress core's plugins list table renders this attribute from the plugin's directory slug; confirm via `page.content()` if the locator doesn't match.

- [ ] **Step 3: Run the full functional suite to confirm no regressions**

Run: `WP_BASE_URL=http://localhost:8881 npx playwright test --config playwright.config.ts`
Expected: All specs PASS, including the new test and the pre-existing `'plugin is active'` test (since this test leaves the plugin active afterward, subsequent tests are unaffected).

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/activation.spec.ts
git commit -m "test(e2e): exercise the real wp-admin deactivate/activate flow"
```

---

### Task 4: Navigation-block canary (theme.json merge regression check)

**Files:**
- Modify: `tests/e2e/style-scoping.spec.ts`

Background: this is not a test of WordPress's Navigation block — it's a regression canary for our own `GlobalStylesOverride` theme.json merge. If a future change to the merge logic ever emits a malformed theme.json structure, this is what would catch it breaking a real block on a real page; the existing CSS-custom-property assertions in this file only prove values are read correctly, not that the merged structure is well-formed enough for the block system to render around it.

- [ ] **Step 1: Add the canary tests**

In `tests/e2e/style-scoping.spec.ts`, add these two tests inside the existing `test.describe( 'global styles scoping', () => { ... } )` block, after the `'same palette slug resolves to different colors per URL'` test:

```ts
	test( 'root URL: Navigation block still renders under the merged theme.json', async ( {
		page,
	} ) => {
		await page.goto( '/' );

		const nav = page.locator( 'nav.wp-block-navigation' );
		await expect( nav ).toBeVisible();
		await expect( nav.getByRole( 'link' ).first() ).toBeVisible();
	} );

	test( '/sample-page/: Navigation block still renders under the merged theme.json', async ( {
		page,
	} ) => {
		await page.goto( '/sample-page/' );

		const nav = page.locator( 'nav.wp-block-navigation' );
		await expect( nav ).toBeVisible();
		await expect( nav.getByRole( 'link' ).first() ).toBeVisible();
	} );
```

- [ ] **Step 2: Run the new tests**

Run: `WP_BASE_URL=http://localhost:8881 npx playwright test --config playwright.config.ts --grep "Navigation block"`
Expected: Both PASS. If `nav.wp-block-navigation` isn't found, the default theme wp-now downloaded may render the Navigation block under a different wrapper class for its major version — inspect via `page.content()` on `/` and adjust the locator to match the actual rendered markup (the assertion's intent — a visible nav landmark with at least one link, under each Brand's merged styles — must be preserved).

- [ ] **Step 3: Run the full functional suite to confirm no regressions**

Run: `WP_BASE_URL=http://localhost:8881 npx playwright test --config playwright.config.ts`
Expected: All specs PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/style-scoping.spec.ts
git commit -m "test(e2e): add Navigation-block canary for the theme.json merge"
```

---

### Task 5: `Dockerfile.e2e` — Alpine + system Chromium + PHP/Node toolchain

**Files:**
- Create: `Dockerfile.e2e`
- Modify: `.distignore`

**Interfaces:**
- Produces: a Docker image tag `the-another-multi-domain-global-styles-e2e-runner:latest`, consumed by Task 6's `docker-build-e2e`/`test-e2e`/`check-plugin` Make targets. Sets `ENV CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser`, consumed by Task 6's Playwright config changes.

Background: Playwright's own downloaded Chromium is glibc-linked and does not run on Alpine (musl). Per project decision, we stay on Alpine and use Alpine's own native `chromium` package instead — the standard, widely-used recipe for headless Chromium on Alpine. This is a **second** image, separate from the existing `Dockerfile`, so `install`/`lint`/`format`/`test`/`release` stay small and fast.

- [ ] **Step 1: Create Dockerfile.e2e**

```dockerfile
FROM alpine:3.24.1

# PHP 8.3 toolchain, matching the existing Dockerfile exactly — this image
# builds the release zip itself (composer build + wp dist-archive) before
# running Plugin Check against it, so it needs the same PHP/Composer/wp-cli
# stack, plus Node for Playwright and Alpine's own Chromium for the browser.
RUN apk add --no-cache \
	php83 \
	php83-cli \
	php83-common \
	php83-ctype \
	php83-curl \
	php83-dom \
	php83-fileinfo \
	php83-iconv \
	php83-json \
	php83-mbstring \
	php83-openssl \
	php83-phar \
	php83-session \
	php83-simplexml \
	php83-tokenizer \
	php83-xml \
	php83-xmlreader \
	php83-xmlwriter \
	php83-zip \
	nodejs \
	npm \
	make \
	git \
	zip \
	curl

RUN ln -sf /usr/bin/php83 /usr/local/bin/php && \
	curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php && \
	php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
	rm /tmp/composer-setup.php

# wp-cli + dist-archive-command, pinned to the same version as the existing
# Dockerfile for the same reason (see that file's comment: v3.2.x requires
# wp-cli ^2.13, which has not been released).
RUN curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp && \
	chmod +x /usr/local/bin/wp && \
	wp package install https://github.com/wp-cli/dist-archive-command/archive/refs/tags/v3.1.0.zip --allow-root

# Alpine's own Chromium build (musl-compatible). PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD
# stops `npm install`/`npx playwright install` from fetching Playwright's own
# glibc-linked Chromium, which does not run here. playwright.config.ts and
# playwright.check.config.ts point launchOptions.executablePath at
# CHROMIUM_EXECUTABLE_PATH when it's set, so an unmodified host run (macOS/glibc
# Linux, this var unset) still uses Playwright's own downloaded browser.
RUN apk add --no-cache \
	chromium \
	nss \
	freetype \
	freetype-dev \
	harfbuzz \
	ca-certificates \
	ttf-freefont

ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
ENV CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser

# The project directory is always bind-mounted to /app at run time, so the
# image deliberately contains no project files.
WORKDIR /app

CMD ["sh"]
```

- [ ] **Step 2: Add the exclusion to .distignore**

In `.distignore`, change:

```
/Makefile
/Dockerfile
/playwright.config.ts
```

to:

```
/Makefile
/Dockerfile
/Dockerfile.e2e
/playwright.config.ts
```

- [ ] **Step 3: Build the image and verify the toolchain**

Run:
```bash
docker build -f Dockerfile.e2e -t the-another-multi-domain-global-styles-e2e-runner:latest .
docker run --rm the-another-multi-domain-global-styles-e2e-runner:latest sh -c "php -v && composer --version && node -v && npm -v && wp --info --allow-root && chromium-browser --version --no-sandbox"
```
Expected: the build completes, and every command in the `sh -c` chain prints a version string with no errors. **If `chromium-browser --version` fails** (binary not found or a different name on this Alpine release), run `docker run --rm the-another-multi-domain-global-styles-e2e-runner:latest sh -c "apk info -L chromium | grep bin"` to find the actual installed binary path and correct `CHROMIUM_EXECUTABLE_PATH` in this Dockerfile before proceeding.

- [ ] **Step 4: Commit**

```bash
git add Dockerfile.e2e .distignore
git commit -m "build: add Dockerfile.e2e with Alpine's native Chromium for Playwright"
```

---

### Task 6: Shared shell script + Make targets wiring

**Files:**
- Create: `scripts/run-e2e.sh`
- Modify: `Makefile`
- Modify: `playwright.config.ts`
- Modify: `playwright.check.config.ts`

**Interfaces:**
- Consumes: `Dockerfile.e2e`'s `CHROMIUM_EXECUTABLE_PATH` env var (Task 5); `npm run plugin-zip:check` / `npm run check:plugin` / the `test:e2e` env-prefix convention (all pre-existing `package.json` scripts, unchanged)
- Produces: Make targets `docker-build-e2e`, `test-e2e`, `check-plugin` — consumed by Task 7's GitHub Actions workflow.

- [ ] **Step 1: Create the shared script**

```sh
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
```

Save as `scripts/run-e2e.sh` and make it executable: `chmod +x scripts/run-e2e.sh`.

- [ ] **Step 2: Add Chromium executablePath override to playwright.config.ts**

In `playwright.config.ts`, change the `use` block from:

```ts
	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on',
	},
```

to:

```ts
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
```

- [ ] **Step 3: Add the same override to playwright.check.config.ts**

In `playwright.check.config.ts`, change the `use` block from:

```ts
	use: {
		baseURL: BASE_URL,
		storageState: STORAGE_STATE_PATH,
	},
```

to:

```ts
	use: {
		baseURL: BASE_URL,
		storageState: STORAGE_STATE_PATH,
		launchOptions: process.env.CHROMIUM_EXECUTABLE_PATH
			? {
					executablePath: process.env.CHROMIUM_EXECUTABLE_PATH,
					args: [ '--no-sandbox' ],
			  }
			: {},
	},
```

- [ ] **Step 4: Update the Makefile**

At the top of `Makefile`, change:

```makefile
.PHONY: docker-build install install-dev require update dump-autoload lint format test release check-plugin version-patch version-minor version-major all clean

# Docker image name
DOCKER_IMAGE = the-another-multi-domain-global-styles-runner:latest
DOCKER_RUN = docker run --rm -v $(PWD):/app -w /app $(DOCKER_IMAGE)
```

to:

```makefile
.PHONY: docker-build docker-build-e2e install install-dev require update dump-autoload lint format test test-e2e release check-plugin version-patch version-minor version-major all clean

# Docker image names
DOCKER_IMAGE = the-another-multi-domain-global-styles-runner:latest
DOCKER_RUN = docker run --rm -v $(PWD):/app -w /app $(DOCKER_IMAGE)

# Separate, Chromium-capable image for the e2e/Plugin Check Make targets —
# kept apart from DOCKER_IMAGE so lint/test/release stay small and fast.
DOCKER_IMAGE_E2E = the-another-multi-domain-global-styles-e2e-runner:latest
DOCKER_RUN_E2E = docker run --rm -v $(PWD):/app -w /app $(DOCKER_IMAGE_E2E)

# Build the e2e Docker image
docker-build-e2e:
	docker build -f Dockerfile.e2e -t $(DOCKER_IMAGE_E2E) .
```

Then replace the **entire existing `check-plugin` target** (the one starting `check-plugin: docker-build` and its preceding comment block) with:

```makefile
# Run the functional wp-now + Playwright suite (activation, admin rules,
# style scoping, content variables) inside Docker. Both this target and
# check-plugin below call the same shared script — see scripts/run-e2e.sh.
test-e2e: docker-build-e2e
	$(DOCKER_RUN_E2E) sh scripts/run-e2e.sh functional

# Build a throwaway release zip (labeled -test, never the real version —
# see scripts/version-zip.js's --label flag) and run WordPress.org's
# official Plugin Check against it in a fresh WordPress instance installed
# FROM that zip — catches packaging bugs (missing files, wrong autoloader)
# a source-directory mount would never surface. Entirely inside Docker via
# the same shared script as test-e2e.
check-plugin: docker-build-e2e
	$(DOCKER_RUN_E2E) sh scripts/run-e2e.sh plugin-check
```

- [ ] **Step 5: Run both targets end-to-end and confirm they pass**

Run: `make test-e2e`
Expected: the e2e image builds, then the full functional suite (including Tasks 3 and 4's new tests) PASSES inside the container.

Run: `make check-plugin`
Expected: the Plugin Check suite PASSES inside the container with 0 ERROR-level issues (per `plugin-check.spec.ts`'s existing assertion).

If either fails specifically on Chromium launch (not on WordPress/plugin logic), re-verify Task 5's Step 3 diagnostic (`chromium-browser --version --no-sandbox` inside the image) and confirm `CHROMIUM_EXECUTABLE_PATH` matches the real binary path before re-running.

- [ ] **Step 6: Commit**

```bash
git add scripts/run-e2e.sh Makefile playwright.config.ts playwright.check.config.ts
git commit -m "build: wire test-e2e and check-plugin through a shared Dockerized script"
```

---

### Task 7: GitHub Actions workflow

**Files:**
- Create: `.github/workflows/e2e.yml`

**Interfaces:**
- Consumes: `make test-e2e`, `make check-plugin` (Task 6) — the workflow does not reimplement any pipeline logic, it only invokes these two Make targets.

- [ ] **Step 1: Create the workflow**

```yaml
name: E2E and Plugin Check

on:
  pull_request:
    branches:
      - master
      - main
      - 'release/**'
  workflow_dispatch:

permissions:
  contents: read

jobs:
  test-e2e:
    name: Functional E2E
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Run functional e2e suite
        run: make test-e2e

      - name: Upload Playwright report on failure
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report-functional
          path: |
            playwright-report/
            test-results/
          retention-days: 7

  check-plugin:
    name: Plugin Check (PCP)
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Run Plugin Check suite
        run: make check-plugin

      - name: Upload Playwright report on failure
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright-report-plugin-check
          path: |
            playwright-report/
            test-results/
          retention-days: 7
```

Save as `.github/workflows/e2e.yml`.

- [ ] **Step 2: Validate the workflow syntax**

Run: `docker run --rm -v $(pwd)/.github/workflows/e2e.yml:/e2e.yml mikefarah/yq eval /e2e.yml` (or any available YAML validator/linter)
Expected: no parse errors. If no YAML linter is available in the environment, visually re-check indentation against the block above — this file cannot be exercised locally beyond syntax validation, since it requires an actual GitHub Actions runner to execute (this repo has no GitHub remote configured yet; wiring one is out of scope for this plan).

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/e2e.yml
git commit -m "ci: add GitHub Actions workflow for e2e and Plugin Check"
```

---

## Final verification

- [ ] Run `make test-e2e` and `make check-plugin` one more time from a clean checkout state (`git status` shows no uncommitted changes) to confirm the whole pipeline is green end-to-end and reproducible from source control alone.
