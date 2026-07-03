# Functional E2E Suite: wp-now → @wp-playground/cli Migration — Design

**Plugin slug:** `the-another-multi-brand-global-styles`
**Date:** 2026-07-03
**Status:** Draft, approved by user in brainstorming session — proceeding to implementation plan

## Overview

`@wp-now/wp-now` is deprecated. Of the two e2e suites, `tests/e2e/check-plugin/` (Plugin Check /
PCP linter) is **already** migrated to `@wp-playground/cli` (its `run-blueprint` one-shot mode, no
browser — see `run-plugin-check.mjs`). Only `tests/e2e/functional/` — the Playwright suite that
drives a real browser against a long-running dev server — still starts that server via
`npx wp-now start`. This design migrates that remaining piece to `@wp-playground/cli server`,
while preserving the suite's current behavior, coverage, and control surface exactly.

`CLAUDE.md`'s existing gotcha log records one prior attempt at this exact migration, abandoned
after it hung in Docker right after the server reported ready (not cleanly reproduced on host,
never root-caused). This design's first finding is the root cause of that hang, discovered via
live empirical spikes against this plugin during brainstorming (see "What changes" §1).

## What stays as-is (already correct, not being redesigned)

- **`tests/e2e/check-plugin/`** — untouched. Already on `@wp-playground/cli`, out of scope here.
- **`functional-blueprint.json`** (`{"login": true, "steps": []}`) — unchanged. Both tools compile
  the top-level `"login"` property to the same kind of predefined-admin mechanism; the Blueprint
  itself doesn't need to change, only how it's invoked.
- **`e2e-environment.php`** (the permalink mu-plugin) — unchanged. Still required as an
  `init`-hooked mu-plugin, not a Blueprint step (Blueprint steps run before WordPress installation
  completes and get silently overwritten — this was already established for wp-now and holds for
  `@wp-playground/cli` too, since both bundle the same underlying install sequence).
- **All four spec files and `provision.setup.ts`** — no logic changes. Real-browser navigation and
  `RequestUtils`-based provisioning both carry cookies like a normal client, so nothing about how
  the tests authenticate or interact with wp-admin changes.
- **`wait-for-real-readiness.ts`'s polling logic** — unchanged (still polls `wp-login.php` via a
  cookie-carrying request context, still checks for bad-gateway/DB-not-ready text). Its role
  changes from "supplementary" to "sole real readiness gate" (see §2) but the code doesn't.
- **Clean separation between Docker and tooling.** `tests/e2e/Dockerfile`, `scripts/run-e2e.sh`,
  the `Makefile`'s `test-e2e`/`check-plugin` targets, and `.github/workflows/e2e.yml` all stay
  exactly as they are. None of them have ever known which dev-server tool the functional suite
  uses — that knowledge lives entirely in `playwright.config.ts`'s `webServer.command` today (for
  wp-now) and stays entirely there after migration (for `@wp-playground/cli`). Docker's only job
  is running the Make target; the Make target's only job is running `scripts/run-e2e.sh`, which
  has never had suite-tool-specific logic and doesn't gain any.
- **`retries: 2`** in `playwright.config.ts` — stays, as a general safety net (see §3 for why its
  originally-documented cause is being fixed, not just retried around).

## What changes

### 1. `webServer.command`: swap wp-now for `@wp-playground/cli server`, fixing the known hang

**Root cause of the prior hang (confirmed via live spike during brainstorming, reproduced against
this exact plugin):** `@wp-playground/cli`'s Blueprint `"login": true` step makes the WordPress
instance 302-redirect-to-self on *every* PHP-bootstrapped request from a client that doesn't
already carry the auth cookie it sets on that first hit — confirmed with plain `curl` (infinite
loop, never resolves) versus a cookie-jar `curl` (resolves in exactly 2 hops: `302` then `200`).
`wp-now`'s implementation of the identical Blueprint property does **not** do this — verified
side-by-side against the same plugin and blueprint: `wp-now` answers a cookie-less first request
with a direct `200`, no redirect at all. This is a genuine, confirmed behavioral difference
between the two tools' Blueprint-login implementations, not an environment- or Docker-specific
issue. (This same failure mode, hitting a different cookie-less client — Plugin Check's own
runtime checks and Playwright's `webServer.url` prober — is exactly what forced the check-plugin
suite's earlier, unrelated abandonment of Blueprint-driven login; see that suite's own history.)

Playwright Test's built-in `webServer.url` readiness poller (`playwright-core`'s
`httpRequest`/`isURLAvailable`, confirmed by reading the installed source) follows redirects but
carries no cookies — exactly the cookie-less client that loops forever. Today's config points
`webServer.url` at `BASE_URL` (`/`), which is harmless under wp-now (no loop there) but would loop
forever under `@wp-playground/cli` until `webServer.timeout` (120s) kills the run — this is what
"hung right after ready" actually was. Fixed in §2.

New `webServer.command` (functional structure; exact flag order/quoting finalized during
implementation):

```
npx @wp-playground/cli server \
  --auto-mount \
  --blueprint=tests/e2e/functional/functional-blueprint.json \
  --mount=<repo>/tests/e2e/functional/e2e-environment.php:/wordpress/wp-content/mu-plugins/e2e-environment.php \
  --port=${PORT} --php=8.3 --workers=auto
```

- `--auto-mount` replaces wp-now's automatic plugin-mode detection — confirmed via spike: it finds
  and mounts this plugin identically (same "Mount ... (auto-mount)" log line, same
  `/wordpress/wp-content/plugins/<slug>` target).
- No `--reset`: `server` (unlike `start`) uses ephemeral temp storage per spawn by default —
  confirmed in spike log (`Native temp dir for VFS root: .../node-playground-cli-site-...`), so
  every fresh spawn is already a clean site with no flag needed. `reuseExistingServer: !CI` in
  Playwright config is unaffected — it governs whether Playwright spawns a new process at all, not
  what that process does once spawned.
- No CLI `--login` flag: the Blueprint already supplies login, and the check-plugin suite's history
  shows combining a CLI `--login` flag with a Blueprint `"login": true` step causes a cookie-path
  conflict. Blueprint-only, as today.
- `--workers=auto` — see §3.

### 2. Readiness check: `webServer.url` → `webServer.port`

Switch `playwright.config.ts`'s `webServer` block from `url: BASE_URL` to `port: PORT`. This is a
pure TCP-accept check with no HTTP semantics, so it's structurally immune to the redirect loop in
§1 regardless of which tool is running. Real readiness (WordPress actually installed and
answering, not just the process having bound the port) stays gated by the suite's own
`waitForRealReadiness()` in `globalSetup`, which uses a real Playwright request context (a cookie
jar, like a browser) and already resolves the login redirect in 2 hops — unaffected by any of
this. Update that file's doc comment: it currently frames itself as covering a startup race
*underneath* the `url` poller; after this change it's the *only* check standing between
"port is open" and "tests may run," so the comment should say so.

### 3. Concurrency: `--workers=auto` replaces the undocumented, unconfigurable 5-instance cap

wp-now's bundled PHP-wasm engine hardcoded a 5-concurrent-request pool with no CLI flag, blueprint
step, or public API to change it (documented in `CLAUDE.md`) — the reason `retries: 2` exists, to
absorb the transient 502s an asset-heavy admin/editor screen can trigger by exceeding it.
`@wp-playground/cli server`'s architecture is different and directly addresses this: each
`--workers=N` is an independent worker *thread* running its own single-instance PHP runtime, so
total concurrent request capacity literally equals the worker count — a first-class, documented
flag ("useful for multi-client workloads ... that need more than 6 in-flight requests"). Confirmed
via spike: booting with `--workers=2` prints the CLI's own warning that fewer than 6 workers risks
file-lock deadlocks — i.e. the tool's own guidance is to stay at-or-above its default, never below
it, which rules out hand-picking a low fixed number.

Chosen setting: `--workers=auto` (one worker per CPU core, minus one) rather than a fixed number —
scales with whatever machine runs the suite (matches local dev cores; honors the GitHub Actions
runner's actual core count in CI) instead of a number tuned for one machine and wrong for another.

`retries: 2` stays as a general safety net (unrelated transient flakiness can still happen), but
its doc comment is reworded: today it describes an unfixable hardcoded engine ceiling; after this
change the specific 502-from-concurrency-starvation case it was originally written for should no
longer occur, and retries become ordinary insurance rather than a load-bearing workaround.

### 4. Mu-plugin mounting: drop the shared-global-directory copy/cleanup, use `--mount` directly

wp-now has no per-invocation way to mount an mu-plugin, so `global-setup.ts` copies
`e2e-environment.php` into wp-now's shared, machine-global `~/.wp-now/mu-plugins/` directory
before tests run, and `global-teardown.ts` deletes it afterward so it doesn't linger for unrelated
wp-now usage on the same machine. `@wp-playground/cli server --mount=<host-path>:<vfs-path>`
mounts an individual file directly into the running instance's `wp-content/mu-plugins/` — confirmed
working via spike (`Mount after WP install: .../mu-plugins/e2e-environment.php -> ...`). This
removes the need for a shared global directory entirely: add the mount to `webServer.command`
(§1) and delete the copy logic from `global-setup.ts` and the cleanup logic from
`global-teardown.ts` (if teardown then has nothing left to do, delete the file and its
`globalTeardown` config entry too — confirm during implementation whether anything else still
needs it).

### 5. Mechanical cleanup

- `package.json`: remove the `@wp-now/wp-now` devDependency (already have `@wp-playground/cli`).
  Rename `WP_NOW_PORT` → a tool-neutral name (e.g. `WP_E2E_PORT`) everywhere it's read
  (`playwright.config.ts`) and set (`test:e2e`/`test:e2e:ui` npm scripts).
- `helpers.ts`: reword the comment attributing the postbox actionability workaround to "wp-now" —
  it's the shared PHP-wasm engine's rendering behavior either way, not wp-now-specific phrasing.
- `CLAUDE.md`: rewrite the wp-now gotcha entries — replace the "unexplained hang ... isolate first"
  note (now root-caused, §1) and the "5-request cap, not configurable" note (now solved, §3) with
  what was actually found and fixed. Update the functional-suite description line and the
  `make test-e2e` comment to name the current tool.
- `README.md` / `CHANGELOG.md`: mechanical wp-now → `@wp-playground/cli` name swaps where they
  appear; no structural changes.

## Verification

Apply all changes above, then run `make test-e2e` once — the same path CI uses, no bespoke Docker
commands, no separate early checkpoint — and compare against the last recorded wp-now baseline (23
tests, 22 passed + 1 flaky-then-retried on first attempt, 0 failures after retries). Confirms, in
one pass: no webServer hang (§1–2), pretty permalinks still apply via the mounted mu-plugin (§4),
plugin auto-activation still happens (`--auto-mount` + Blueprint), admin login/session still works
for both real browser navigation and `RequestUtils`, and the flaky-502 pattern is gone or reduced
(§3 — if it still appears, that's a signal to revisit the `--workers` choice, not a blocker to
merging, since `retries: 2` remains as a safety net either way).

## Out of scope

- `tests/e2e/check-plugin/` — already migrated, not touched.
- Any change to `tests/e2e/Dockerfile`, `scripts/run-e2e.sh`, `Makefile`, or
  `.github/workflows/e2e.yml` — the clean separation described above means none of them need to
  change for this migration, and introducing changes there would be scope creep.
- Raising or lowering `retries` — left at `2`, unrelated to this migration's goals.
