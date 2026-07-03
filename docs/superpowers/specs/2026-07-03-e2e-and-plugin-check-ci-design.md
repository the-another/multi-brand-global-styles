# E2E + Plugin Check CI Pipeline — Design

**Plugin slug:** `the-another-multi-domain-global-styles`
**Date:** 2026-07-03
**Status:** Draft — auto-approved per user instruction (2026-07-03), proceeding to implementation

## Overview

The plugin already has a working wp-now + Playwright functional e2e suite (`activation.spec.ts`, `admin-rules.spec.ts`, `style-scoping.spec.ts`, `variables.spec.ts`) and an uncommitted, separate Plugin Check (PCP) suite driven by `@wp-playground/cli` against a freshly-installed-from-zip WordPress instance (`plugin-check.spec.ts`). Both are functionally solid but were built ad hoc: a shared mu-plugin is copied into wp-now's global `~/.wp-now/mu-plugins` directory to fix permalinks, authentication is a hand-rolled `wp-login.php` form POST, and neither pipeline is wired into Docker or CI. This design formalizes both into two independent, Docker-driven, CI-ready pipelines that share one Docker image and one shell script, while keeping the parts of the existing implementation that are already correct and well-justified.

## What stays as-is (already correct, not being redesigned)

- **wp-now for the functional suite** (dev-mounted plugin source) and **`@wp-playground/cli` for the Plugin Check suite** (fresh install from the packaged release zip via `--mount` + a Blueprint's `installPlugin` VFS step) — confirmed by reading `node_modules/@wp-now/wp-now/main.js` that wp-now's CLI has no `--mount`-equivalent flag, so it cannot install a plugin from an arbitrary host zip path the way `@wp-playground/cli server` can. The split is deliberate and stays.
- **`wait-for-real-readiness.ts`** — a proven, necessary workaround for `@wp-playground/cli server`'s startup race (an early placeholder Express layer answers requests before real PHP is up). Unrelated to authentication; stays as-is.
- **`createBrand()` real-admin-form provisioning** (`helpers.ts`, used by `provision.setup.ts` and `admin-rules.spec.ts`) — typing into the real classic-editor form and clicking Publish, which exercises `BrandPostType::save()`'s nonce/validation/conflict-rejection logic end-to-end. Confirmed to keep: Blueprint-driven provisioning would bypass that save-time validation coverage entirely.
- **The existing four functional specs' assertions** — activation, duplicate-rule rejection, per-URL style scoping, variable substitution. These are extended, not replaced.

## What changes

### 1. Blueprint-driven auth and environment setup (replacing ad hoc hacks)

Both suites gain (or already had, now made explicit) a Blueprint JSON with a predefined admin user, per the official WordPress Playground Blueprints schema (`@wp-playground/blueprints`, confirmed in `node_modules/@wp-playground/blueprints/index.js`): a top-level `"login"` property — `true` logs in as `admin`/`password` — or an explicit `{username, password}` step.

- **Functional suite:** new `tests/e2e/functional-blueprint.json` (`{"login": true, "steps": []}`), passed to `wp-now start --blueprint=...`. **Correction, found empirically during implementation:** the permalink mu-plugin (`e2e-environment.php`, copied into wp-now's shared `~/.wp-now/mu-plugins/` directory) cannot be replaced with a Blueprint step. Tracing `@wp-now/wp-now`'s `startWPNow()` shows blueprint steps run *before* `installationStep2()` (the actual WordPress install), so a Blueprint `runPHP` step's `set_permalink_structure()`/`flush_rewrite_rules()` gets silently reset when installation runs afterward — confirmed by starting wp-now directly with that exact step and observing the live site still served plain-permalink links. Only a persistent runtime hook (the mu-plugin's `init` action, which fires on every real request, long after install) can do this in wp-now's current version. The mu-plugin stays unchanged; the shared-directory characteristic it relies on turns out to be inherent to wp-now's plugin mode (its own bundled mu-plugins live in that same shared directory unconditionally), not something this project's code specifically introduced. The Blueprint's `"login": true` is still added on its own merits — it doesn't touch the database (it defines a wp-config.php-level constant), so it's unaffected by the install-ordering issue, and it makes the predefined-admin-user intent explicit and inspectable, matching what was actually asked for.
- **Plugin Check suite:** `check-plugin-blueprint.json` gains an explicit `login` entry. `check-plugin-global-setup.ts` drops the manual `wp-login.php` form POST. `waitForRealReadiness` is still called first, since it's about server startup ordering, not login mechanics. **Correction, found empirically during implementation:** `playwright.check.config.ts`'s `webServer.command` already passed a pre-existing `--login` CLI flag straight to `@wp-playground/cli server` (predating this plan). Combined with the Blueprint's own `"login": true`, the two auto-login mechanisms clobbered each other's auth-cookie path, causing the server to boot into a genuine infinite self-redirect loop (`/` → 302 → `/` forever) — confirmed via a controlled A/B toggling only the Blueprint property. Fix: remove the now-redundant `--login` CLI flag, since the Blueprint supplies the same predefined-admin login declaratively.

### 2. Explicit plugin-activation test (functional suite only)

wp-now unconditionally auto-activates a mounted plugin-mode project on first run (confirmed in `main.js`: no CLI flag suppresses it), so we don't fight that for environment bootstrap. Instead, `activation.spec.ts` gains a test that explicitly deactivates the plugin (`requestUtils.deactivatePlugin()`) and then activates it through the real wp-admin Plugins screen (a genuine UI click), asserting success — this is what actually exercises the activation-hook code path, regardless of wp-now's bootstrap convenience behavior. The Plugin Check suite's Blueprint keeps `activate: true` for our plugin's install step; activation-flow testing is out of scope there (that's the functional suite's job) and Plugin Check needs the plugin active to run checks against it.

### 3. Navigation-block canary (proves the theme.json merge doesn't corrupt the page)

This is **not** a test of WordPress's Navigation block — it's a regression canary for our own `GlobalStylesOverride` (the `wp_theme_json_data_theme`/`wp_theme_json_data_user` filter merge). New assertions (added to `style-scoping.spec.ts`) load `/` and `/sample-page/` and assert the theme's header template part's Navigation block still renders real markup (a `nav.wp-block-navigation` with at least one link) under each Brand's merged theme.json. If a future change to the merge logic ever emits a malformed theme.json structure, this is what would catch it breaking a real block on a real page — the existing CSS-custom-property assertions only prove values are read correctly, not that the merged structure is well-formed enough for the block system to render around it.

### 4. Two Docker images, one shared shell script, two Make targets

Alpine (the existing `Dockerfile`/image, used for `install`/`lint`/`test`/`release`) cannot run Playwright's own downloaded Chromium — Playwright's prebuilt browser binaries are glibc-linked and Alpine is musl. Per your direction, we stay Alpine (not Debian/Ubuntu, not Microsoft's Playwright image), using Alpine's own native `chromium` package (the standard, widely-used Alpine+headless-Chromium recipe) — but in a **second**, separate Alpine-based image, so the existing lint/test/release image stays small and fast and unaffected.

- **New `Dockerfile.e2e`:** same Alpine base/pinning convention as the existing `Dockerfile`, plus Node/npm (already needed), Alpine's `chromium` package and its runtime dependencies (`nss`, `freetype`, `harfbuzz`, `ttf-freefont`, `ca-certificates`, etc.), and the same PHP 8.3 + Composer + wp-cli + dist-archive-command toolchain as the existing image (needed because this image must build the release zip itself for the Plugin Check pipeline). `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` (or equivalent) prevents `npm install` from fetching the incompatible glibc Chromium.
- **Playwright config:** both configs point Chromium's `launchOptions.executablePath` at Alpine's system Chromium binary, gated behind an env var so an unmodified local (non-Docker, glibc) dev run still uses Playwright's own downloaded browser unchanged.
- **One shared shell script, `scripts/run-e2e.sh`:** takes one positional argument, `functional` or `plugin-check`. Common prologue (`npm ci`), then branches: `functional` runs `npx playwright test --config playwright.config.ts`; `plugin-check` builds the test zip (`npm run plugin-zip:check`) and runs `npx playwright test --config playwright.check.config.ts`. Both new Make targets call this same script inside the same new Docker image — the thing that makes it "critical that both call a single script" is that there is exactly one place the e2e pipeline logic lives, so the two suites can never drift from each other or from what CI runs.
- **Two Make targets:** `test-e2e` (new `docker-build-e2e` prerequisite, then `$(DOCKER_RUN_E2E) sh scripts/run-e2e.sh functional`) and `check-plugin` (rewritten to run entirely in Docker: `$(DOCKER_RUN_E2E) sh scripts/run-e2e.sh plugin-check`, replacing the current host-side `npx playwright install chromium` + `npm run check:plugin` split). The existing `install`/`lint`/`format`/`test`/`release` targets and their Docker image are untouched.
- **`.distignore`:** add `/Dockerfile.e2e` alongside the existing `/Dockerfile` exclusion.

### 5. GitHub Actions CI

New `.github/workflows/e2e.yml` (this repo has no GitHub Actions workflows yet at all, unlike sibling plugins — adding a general PHP lint/phpunit CI workflow mirroring siblings is a reasonable follow-up but is explicitly out of scope here; this design only adds what was asked for). Triggers on `pull_request` + `workflow_dispatch`, `ubuntu-latest` (Docker preinstalled). Two jobs, each just running `make test-e2e` / `make check-plugin` — the workflow does not reimplement any pipeline logic; it calls the exact same Make targets a developer runs locally, which call the exact same shared shell script inside the exact same Docker image. On failure, upload Playwright's trace/screenshot/video/HTML report and `test-results/` as a build artifact for debugging.

## Testing

- All four existing functional specs continue passing, now against Blueprint-provisioned environment instead of the mu-plugin hack.
- New: explicit deactivate→activate UI-driven test in `activation.spec.ts`.
- New: Navigation-block-render canary assertions in `style-scoping.spec.ts` for both Brand-scoped URLs.
- Plugin Check suite continues asserting zero ERROR-level issues on the packaged zip; login now Blueprint-driven.
- `make test-e2e` and `make check-plugin` both verified to pass end-to-end inside the new `Dockerfile.e2e` image before this is considered done.

## Out of scope

- A general lint/phpunit GitHub Actions workflow mirroring sibling plugins (`ci.yml`/`package.yml`) — not requested here; natural follow-up.
- Wiring an actual GitHub remote for this repo (currently has none) — the workflow file is added regardless; making it actually run on GitHub requires the repo to exist there first.
- Migrating the functional suite off wp-now onto `wp-env` or any other engine — not requested, and the current tool choice per suite is already justified.
- Any change to `Brand`/`GlobalStyles`/`ContentVariables` plugin logic itself — this design is CI/test-infrastructure only.
