# E2E Functional Suite: Migrate from PHP-wasm Playground to Native PHP + SQLite

**Date:** 2026-07-03
**Status:** Approved design, pending implementation plan

## Problem

The functional e2e suite boots WordPress via `@wp-playground/cli server` (PHP-wasm +
SQLite). That engine forced five documented workarounds (explicit `--mount` flags,
`--site-url` CORS fix, `port`-not-`url` readiness, a custom cookie-jar readiness
poller, Blueprint-based activation replacing an untimed REST call) and still left
the suite unreliable: a clean `make test-e2e` pass has never been observed on the
development machine, PHP-wasm is slow under load (30s save timeouts, `force: true`
clicks because postboxes never settle), and the Playground team's own 2026
changelogs were still fixing this class of bug (worker file-locks, auto-mount
naming, CI flakiness pins).

Research into how major WordPress projects run e2e (WordPress core, Gutenberg,
WooCommerce, the May 2026 official developer-blog guidance) confirms:

- The ecosystem standard is **Playwright + `@wordpress/e2e-test-utils-playwright`**
  — which this suite already uses and keeps.
- The standard environment is **wp-env** (Docker, real PHP, MySQL). It cannot run
  inside this repo's single-container constraint (it manages its own Docker
  containers).
- **No major project runs its e2e suite on Playground.** Its acknowledged sweet
  spot is disposable fresh-install-from-zip instances — exactly this repo's
  *check-plugin* suite, which stays on Playground.
- Portable standard practices: auth once via storageState (already done),
  `workers: 1` against one shared install (already done; Gutenberg does the same),
  `retries: 2` in CI but **0 locally** (currently 2 everywhere — to fix), state
  seeding via REST/wp-cli rather than the UI (deviation kept, with a documented
  reason — see below).

## Goals (in priority order, from the drivers)

1. **Local reliability** — `make test-e2e` passes repeatedly on the dev machine.
2. **Best-practice alignment** — bespoke engine workarounds deleted; what remains
   is the standard Playwright/test-utils stack.
3. **Speed** — native PHP instead of PHP-wasm.

## Constraints

- **Single container, single make target**: the whole e2e run stays one
  `docker run` of the existing e2e image; the same `make test-e2e` works locally
  and in GitHub Actions. No wp-env, no docker compose, no Docker socket
  passthrough, no host toolchain beyond Docker.
- The plugin-check suite, all four functional specs, `helpers.ts`, and the
  `@wordpress/e2e-test-utils-playwright` layer are preserved.

## Decision

Replace `@wp-playground/cli` with **real WordPress on the image's native PHP 8.3**,
using the official `sqlite-database-integration` drop-in (the same database layer
Playground itself uses — zero DB-behavior change), provisioned by wp-cli, served by
PHP's built-in server via `wp server`. Only the functional suite's boot layer
changes.

Alternatives considered and rejected:
- **Harden Playground in place** — least churn, but the engine causing the
  flakiness and slowness remains, and no major project validates that path.
- **Single-container LAMP (MariaDB + PHP-FPM)** — real MySQL parity, but this
  plugin does no raw SQL (CPT + post meta + transients through core APIs), so the
  parity buys nothing; a multi-process container is bespoke maintenance.

## Section 1 — Environment & boot

### Image changes (`tests/e2e/Dockerfile`, still one image)

- Add PHP extensions: `php83-pdo`, `php83-pdo_sqlite`, `php83-sqlite3`, `php83-gd`.
- Bake at build time, version-pinned via Docker `ARG`:
  - WordPress core (`wp core download --version=$WP_VERSION`) into a cache path
    (e.g. `/opt/wp-core/`). `ARG WP_VERSION` is pinned to the exact latest stable
    release at implementation time (checked against wordpress.org then — an
    explicit `x.y.z`, never `latest`); bumping it later is a deliberate one-line
    PR that CI fully exercises.
  - The official `sqlite-database-integration` plugin, pinned release.
  - No network fetches at test run time.
- Install the `wp-cli/server-command` package (same mechanism as the existing
  `dist-archive-command`).
- Chromium, ffmpeg, Node, `python3`/`g++` (still needed by the check-plugin
  suite's `@wp-playground/cli` native-addon fallback) all stay.

### Boot script (new `tests/e2e/functional/serve-wp.sh`)

Invoked by Playwright's `webServer.command`. Steps:

1. Copy the baked core into a fresh temp dir — clean site every run, same
   ephemeral semantics as Playground's server mode today.
2. Place the SQLite drop-in (`wp-content/db.php` + plugin dir); write
   `wp-config.php` with `WP_DEBUG` and `WP_DEBUG_DISPLAY` true (keeps the
   errors-visible-in-screenshots debugging win).
3. `wp core install --url=http://localhost:8881 --admin_user=admin
   --admin_password=password` — exactly the defaults `RequestUtils` expects, so
   auth needs zero extra configuration.
4. Symlink only the plugin's runtime files (main file, `includes/`, `vendor/`,
   `readme.txt`) into
   `wp-content/plugins/the-another-multi-brand-global-styles/` — same
   explicit-files principle and real slug as today's `--mount` flags. Fallback to
   copying if symlinked plugin dirs misbehave (see Risks).
5. `wp plugin activate the-another-multi-brand-global-styles` and
   `wp rewrite structure '/%postname%/'` — permalinks become a real option write.
6. Serve: `PHP_CLI_SERVER_WORKERS=6 wp server --port=8881` — PHP's built-in web
   server with 6 worker processes, so WordPress's own loopback requests cannot
   self-deadlock.

### Readiness & auth

Installation completes **before** the server starts listening, so readiness
checking becomes trivial (`webServer.port` or `url` both work truthfully).
`global-setup.ts` shrinks to a single `RequestUtils.setup()` call writing the
admin storageState.

## Section 2 — Provisioning, specs & Playwright config

- **Brands keep being created through the real admin form** (`createBrand()`).
  This deliberately deviates from the "seed state via REST/wp-cli" ecosystem norm
  because `BrandPostType::save()` is the plugin's only write path — rule
  normalization, conflict detection, and styles-post creation all live there;
  wp-cli seeding (`wp post create --meta_input`) would bypass them and produce
  broken fixtures (e.g. `_mbgs_global_styles_post_id` never set). Page/post
  *content* seeding stays REST via `requestUtils`, which does follow the norm.
  Native PHP removes the reason the norm exists (slow, flaky UI seeding).
- **Playwright config changes**:
  - `retries: process.env.CI ? 2 : 0` — Gutenberg's pattern; local flakiness gets
    surfaced, not masked.
  - `workers: 1` stays — the ecosystem standard for a single shared install.
  - The `setup` provisioning project and its idempotency guard stay.
  - Attempt to remove the `force: true` publish click and the 30-second save
    timeout in `helpers.ts` — both were compensations for PHP-wasm jank. If the
    classic-editor postbox instability turns out not to be wasm-specific, keep the
    force click and document it honestly (one-line revert, not a design problem).
- **Untouched**: all four specs, `helpers.ts`'s API, the
  `@wordpress/e2e-test-utils-playwright` layer, trace/video/screenshot settings,
  `reuseExistingServer` locally.

## Section 3 — Deletions & documentation cleanup

Files deleted:
- `tests/e2e/functional/wait-for-real-readiness.ts` — readiness now truthful.
- `tests/e2e/functional/functional-blueprint.json` — activation and login move to
  wp-cli / `RequestUtils.setup()`.
- `tests/e2e/functional/e2e-environment.php` — permalinks move to the boot script.

Config shrinkage: `playwright.config.ts` loses the five PHP-wasm workaround
comment blocks (mount naming, CORS `--site-url`, login-redirect loop,
`--workers=6` floor, no-`--reset` lore). `webServer.command` becomes
`sh tests/e2e/functional/serve-wp.sh`.

Dependencies: `@wp-playground/cli` stays in `package.json` — the check-plugin
suite still uses it.

CLAUDE.md: the e2e section and Playground gotchas get rewritten. Gotchas that
still apply to the check-plugin suite (PCP CLI runner, argv shim, pc_-table
identity gap) stay; the functional-suite Playground lore (mount basename, untimed
REST hang, login redirect loop, `--site-url` CORS, workers floor, "never a clean
local pass") is replaced by a short description of the native-PHP boot.

## Section 4 — Make & CI (shape unchanged)

- `make test-e2e` keeps the exact same interface: build the e2e image,
  `docker run … sh scripts/run-e2e.sh functional`. Single container, single
  target, identical locally and in GitHub Actions.
- `scripts/run-e2e.sh` unchanged except the functional branch no longer needs the
  redundant `WP_BASE_URL` export (the config already sets it).
- `.github/workflows/e2e.yml` unchanged. No CI sharding: with 4 specs + setup,
  Gutenberg-style sharding is YAGNI; the speed win comes from native PHP.
- `make check-plugin` completely untouched.

## Section 5 — Verification plan & risks

Verification (acceptance gates):
1. `make test-e2e` passes **3 consecutive runs on the dev machine** with
   `retries: 0` locally — the thing that never happened under Playground.
2. Wall-clock recorded before/after for the speed claim.
3. `make check-plugin` run once to confirm it is untouched.
4. One deliberate failure to confirm trace/video/screenshot artifacts still land
   in `test-results/` for CI upload.

Risks and mitigations:
- **SQLite drop-in + wp-cli interplay**: `wp core install` must run with the
  drop-in already in place. Well-trodden (Playground and the drop-in's docs do
  exactly this) but the most likely surprise point — the boot script gets built
  and verified standalone, before touching Playwright.
- **`wp server` under musl**: PHP's built-in server on Alpine is widely used, but
  `PHP_CLI_SERVER_WORKERS` behavior gets verified explicitly (concurrent request
  check) during boot-script development.
- **Classic-editor jank theory**: covered in Section 2 — keep `force: true` if it
  proves engine-independent.
- **Symlinks vs copies**: if WordPress/wp-cli misbehaves with symlinked plugin
  dirs (`plugin_basename()` resolution), fall back to copying the four runtime
  paths; the boot script is the only place that changes.

## Out of scope (noted for the future)

- Real multi-domain testing (multiple hostnames resolving to the container) —
  the native server makes this feasible later via `/etc/hosts` entries; today's
  specs cover host+path scoping on `localhost` only.
- CI matrix over WordPress/PHP versions — `ARG WP_VERSION` makes this a natural
  extension, not part of this migration.
- wp-env adoption — blocked by the single-container constraint; revisit only if
  that constraint changes.
