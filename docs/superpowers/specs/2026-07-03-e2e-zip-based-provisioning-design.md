# Functional E2E: Install the Plugin from the Packaged Zip (Same Logic as Plugin Check)

**Date:** 2026-07-03
**Status:** Approved design, pending implementation plan
**Builds on:** `docs/superpowers/specs/2026-07-03-e2e-native-php-migration-design.md`

## Problem

The functional e2e suite installs the plugin into its ephemeral WordPress by
copying four source paths (`the-another-multi-brand-global-styles.php`,
`includes/`, `vendor/`, `readme.txt`) file-by-file from the repo mount
(`tests/e2e/functional/environment/serve-wp.sh`). The check-plugin suite,
by contrast, builds the packaged `-test` zip and installs from it. The two
suites therefore test different artifacts: a packaging defect (missing file,
wrong autoloader, bad `.distignore` exclusion) that changes runtime behavior
would pass the functional suite and only surface in Plugin Check — or worse,
only in production.

## Decision

The functional suite adopts the check-plugin suite's artifact logic: build
`build/the-another-multi-brand-global-styles-test.zip` once per run, install
WordPress-native from that zip (`wp plugin install … --activate`), and never
copy source paths file-by-file.

Accepted trade-offs (explicitly confirmed):
- Every `make test-e2e` run rebuilds the zip first (roughly 20–40s per run,
  including debug iterations). No stale-zip mode, no rebuild-only-if-missing.
- `composer build` (inside the zip pipeline) flips the host-mounted `vendor/`
  to no-dev on every run — already true for `make check-plugin`. Running
  `make test`/`make lint` afterwards requires `make install-dev` again.
- This deviates from the ecosystem norm (dev-mounted source for functional
  suites) deliberately: both suites now gate the exact artifact that ships,
  and there is no live-mount iteration path (source edits require a rebuilt
  zip, which every run performs anyway).

Rejected alternatives:
- **Duplicate the build lines into the functional branch** of `run-e2e.sh` —
  same behavior, two copies to keep in sync.
- **Build the zip inside `serve-wp.sh`** — wrong layer: the boot script is
  WordPress-only and runs under Playwright's `webServer` timeout budget; the
  npm/composer toolchain belongs in the suite entrypoint.
- **Source-mount escape hatch / rebuild-only-when-missing** — offered,
  declined: dual modes and stale-zip footguns outweigh the speed win.

## Changes

### 1. `scripts/run-e2e.sh` — hoist the zip build into the shared path

The two lines the check-plugin branch already runs move above the `if`,
executing for both suites (after `npm ci`):

```sh
rm -f build/the-another-multi-brand-global-styles-test.zip
npm run plugin-zip:check
```

with a comment noting that both suites test the same packaged artifact, and
that `composer build` inside this pipeline is also what provides `vendor/`
on fresh CI checkouts (subsuming the old guard's job). The functional
branch's `composer install --no-dev` vendor-guard block is deleted outright.
The check-plugin branch keeps only its runner invocation.

### 2. `tests/e2e/functional/environment/serve-wp.sh` — install from the zip

- The `vendor/autoload.php` guard is replaced by a zip-existence guard:
  missing `build/the-another-multi-brand-global-styles-test.zip` fails fast
  with "run via scripts/run-e2e.sh functional or make test-e2e".
- The `mkdir` + four `cp` lines + separate `wp plugin activate` are replaced
  by a single
  `wp plugin install "$REPO_ROOT/build/the-another-multi-brand-global-styles-test.zip" --activate --path="$WP_DIR" --allow-root`.
  The zip's inner dirname is already the real slug (dist-archive's
  `--plugin-dirname`), so the installed path is identical to today's.
- The copy-not-symlink comment block goes away with the copies (the
  underlying lore stays recorded in the superseded native-PHP spec).
- Everything else in the boot (core copy, SQLite drop-in, install ordering,
  permalinks, `PHP_CLI_SERVER_WORKERS=6`, output spooling) is unchanged.

### 3. CLAUDE.md

- Testing bullet: "the plugin's four runtime paths … are copied in at the
  real slug" becomes "the plugin is installed from the same packaged `-test`
  zip the check-plugin suite gates (built once per run by `run-e2e.sh`)".
- Gotchas: the "copied, never symlinked" bullet is replaced by a bullet
  stating the functional suite installs the built zip — packaging bugs now
  fail functionally too, and there is no live source mount (edits require
  the rebuild every run already performs). It also notes that `make
  test-e2e`, like `make check-plugin`, leaves `vendor/` in no-dev state
  (`make install-dev` restores dev tooling).

### 4. Out of scope

- The historical spec/plan docs under `docs/superpowers/` are not updated
  (repo precedent: they are point-in-time artifacts).
- `make`/CI shape, Playwright config, specs, and the check-plugin suite are
  untouched.

## Verification

1. `make test-e2e` — 23/23 pass; runtime grows by the zip build (record it).
2. `make check-plugin` — still passes (its build lines moved to the shared
   path; behavior identical).
3. Guard check: with the zip deleted, running `serve-wp.sh` directly fails
   fast with the new message (not a confusing mid-boot error).
4. Artifact-fidelity check: the installed plugin dir inside the ephemeral
   WordPress contains no dev/test files (e.g. no `tests/`, no `node_modules/`
   — `.distignore` is now load-bearing for the functional suite too).
