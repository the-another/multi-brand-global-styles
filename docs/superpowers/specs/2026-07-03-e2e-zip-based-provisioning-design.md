# E2E Artifact Unification: Zip-Based Functional Provisioning + Check-Plugin on Native PHP

**Date:** 2026-07-03
**Status:** Approved design, pending implementation plan
**Builds on:** `docs/superpowers/specs/2026-07-03-e2e-native-php-migration-design.md`

Two parts, one direction: both e2e suites converge on the same artifact (the
packaged `-test` zip) and the same engine (native PHP + SQLite drop-in),
removing `@wp-playground/cli` from the toolchain entirely.

- **Part 1** — the functional suite installs the plugin from the packaged
  zip instead of copying source paths file-by-file.
- **Part 2** — the check-plugin suite migrates off Playground onto the same
  native-PHP provisioning the functional suite uses.

# Part 1 — Functional Suite Installs from the Packaged Zip

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

### 4. Part 1 verification

1. `make test-e2e` — 23/23 pass; runtime grows by the zip build (record it).
2. Guard check: with the zip deleted, running `serve-wp.sh` directly fails
   fast with the new message (not a confusing mid-boot error).
3. Artifact-fidelity check: the installed plugin dir inside the ephemeral
   WordPress contains no dev/test files (e.g. no `tests/`, no `node_modules/`
   — `.distignore` is now load-bearing for the functional suite too).

# Part 2 — Check-Plugin Suite on Native PHP (Playground Removed)

## Problem

With the functional suite on native PHP, the check-plugin suite is the last
consumer of `@wp-playground/cli` — and of everything that exists solely to
compensate for it: `pcp-cli-shim.php`'s argv patch (Playground mangles
`$_SERVER['argv']`, defeating PCP's positional `argv[1]==='plugin'` test)
and marker-delimited file-append output capture (Playground swallows
wp-cli stdout), the runtime network downloads of WordPress + plugin-check +
wp-cli.phar with their retry-flake logic, and the `python3`/`g++` Alpine
packages needed only for Playground's `fs-ext-extra-prebuilt` node-gyp
fallback. Real wp-cli — already in the image, already provisioning the
functional suite's WordPress — has none of these problems, and PCP's WP-CLI
runner is upstream's canonical, behat-tested path.

## Decision

The check-plugin suite runs PCP's WP-CLI runner against a natively
provisioned ephemeral WordPress — the same baked core + SQLite drop-in +
`wp core install` flow the functional suite uses — with the PCP plugin
baked into the image at a pinned version (explicitly confirmed: pinned over
tracking-latest; reproducible CI and zero test-time network beat automatic
upstream check updates, which now arrive via deliberate one-line `ARG`
bumps).

## Changes

### 5. Shared provisioning library — `tests/e2e/lib/provision-wp.sh`

The WordPress-provisioning steps both suites need extract from
`serve-wp.sh` into a sourced helper: baked core → fresh `mktemp` dir,
SQLite drop-in placement (before install — the ordering stays load-bearing),
`wp config create` + `WP_DEBUG`/`WP_DEBUG_DISPLAY`, `wp core install`
(admin/password). It exposes the provisioned `$WP_DIR` to the sourcing
script. `serve-wp.sh` becomes: source lib → provision → install the
functional `-test` zip → permalinks → `exec wp server` (its server-specific
tail unchanged). The check-plugin runner sources the same lib and never
starts a server — PCP's WP-CLI runner makes no HTTP requests.

### 6. Dockerfile

- `ARG PCP_VERSION` (exact `x.y.z`, resolved at implementation time), PCP
  release zip baked to `/opt/plugin-check.zip`.
- Remove `python3`/`g++` and the fs-ext workaround comment (that gotcha
  class dies with Playground).

### 7. Check-plugin flow (replacing the Playground boot + Blueprint)

Preserving the two empirically-hard-won orderings:
1. Provision WordPress via the shared lib.
2. `wp plugin install /opt/plugin-check.zip --activate` **before**
   installing our `-test` zip (the reverse order broke PCP's activation
   historically), then install + activate our zip.
3. Before **each** of the two check runs, `cp` PCP's
   `drop-ins/object-cache.copy.php` → `wp-content/object-cache.php`
   (PCP's per-run cleanup deletes it).
4. Two runs, structurally identical to today: the full default check set,
   then the 5 runtime checks (`enqueued_scripts_size`,
   `enqueued_styles_size`, `enqueued_styles_scope`,
   `enqueued_scripts_scope`, `non_blocking_scripts`) explicitly as the loud
   canary — both via plain `wp plugin check <slug> --format=json` with real
   argv and real stdout.
5. `run-plugin-check.mjs` keeps its job (orchestrate, parse JSON, gate
   ERRORs / report WARNINGs, structural-failure tripwires) but spawns local
   `wp` instead of booting Playground, reads stdout directly, and loses the
   network retry loop (nothing downloads at test time anymore). The
   early-init marker survives only as a minimal `--require` if run 2's own
   output can't prove the drop-in ran (implementation detail; keep the
   marker if cheap — it is the explicit tripwire against silent
   runtime-check under-coverage).

### 8. Deletions and dependency removals

- `tests/e2e/check-plugin/check-plugin-blueprint.json` — deleted.
- `tests/e2e/check-plugin/pcp-cli-shim.php` — deleted, or reduced to the
  marker-only require per §7.
- `@wp-playground/cli` removed from `package.json` (no remaining consumer).

### 9. Docs (CLAUDE.md)

- The PCP gotcha keeps its load-bearing core (runtime checks only via the
  WP-CLI runner; the pc_-table/AJAX auth story stays true and must never be
  retried) but drops the Playground argv/stdout paragraphs.
- The check-plugin Testing bullet rewritten: native wp-cli, baked pinned
  PCP, same two-run canary structure.
- The e2e image description updates (no more python3/g++; no Playground).
- The Part 1 gotcha wording ("`@wp-playground/cli` is still a dependency —
  the check-plugin suite uses it") is corrected to reflect its removal.

## Part 2 verification

1. `make check-plugin` — same result profile as today: 0 errors, the same 6
   pre-existing warnings, all 32 checks, with the 5 runtime checks present
   in run 2's output.
2. `make test-e2e` — 23/23 (proves the shared-lib extraction didn't change
   the functional boot).
3. Runtime-check tripwire test: temporarily sabotage the object-cache
   drop-in `cp`, expect the canary (run 2) to fail loudly, revert.
4. `npm ci` completes without `@wp-playground/cli` and the image builds
   without `python3`/`g++`.

# Out of scope (both parts)

- The historical spec/plan docs under `docs/superpowers/` are not updated
  (repo precedent: they are point-in-time artifacts).
- `make`/CI shape, Playwright config, and the functional specs are
  untouched.
