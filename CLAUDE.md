# CLAUDE.md

This file guides Claude Code (claude.ai/code) when working in this repository. It is specific to **this** plugin — do not assume conventions from the sibling Aucteeno plugins (they differ; see "Conventions" below).

## Project Overview

**The Another Multi-Brand Global Styles** lets a WordPress admin define **Brands** — bundles of URL match rules, per-Brand global-style overrides, and content variables — on a **single** WordPress installation serving multiple domains and/or path sections. It is not WP Multisite and not separate installs.

A Brand can be scoped to:
- whole domains (`auctionbill.com`, `beta.auctionbill.com`), or
- path sections of one or more sites (`site.com/farm/*`, `site2.com/farm/*`).

Wherever a Brand's rules match the incoming request, five things happen at render time, without touching the theme's `theme.json` or creating a child theme:
1. **Global-style override** — the Brand's stored styles merge over the active theme via the `wp_theme_json_data_user` filter.
2. **Content-variable substitution** — `%%brand.name%%`-style tokens in the final HTML are replaced with the Brand's values.
3. **Site-identity override** — logo, title, tagline, site icon served from the Brand's identity settings.
4. **Image replacement** — mapped attachment URLs swapped for the Brand's replacements.
5. **URL host rewrite** (opt-in per Brand) — canonical-host URLs in the final HTML are rewritten to the domain being browsed, and core's canonical redirect is guarded so visitors stay on the Brand domain.

Most-specific rule wins (host+path beats host-only; longer path prefix beats shorter; prefixes match on path-segment boundaries).

**Standalone plugin** — no dependency on the other Aucteeno plugins.

## Requirements

- PHP **8.3+**
- WordPress **6.9+**
- Composer (PSR-4 autoload for `includes/`); Node + Docker for the build/e2e tooling
- No WooCommerce/Dokan/other-plugin dependency

## Architecture

### DI container + hook manager (infrastructure)

- `includes/Container.php` — singleton DI container (`get_instance()`, `register()`, `get()`, `has()`, `get_hook_manager()`), lazy factories, per-key singleton tracking.
- `includes/HookManager.php` — tracked `add_action`/`add_filter` registration with dedupe and bulk deregistration.
- `includes/Plugin.php` — orchestrator. `Plugin::get_instance()->start()` (fired on `plugins_loaded`) registers all services and wires every hook. **This file is the single wiring map** — read it first to see what runs when.

### Bounded contexts (domain, organized by concept not layer)

- `includes/Brand/` — the Brand aggregate + URL rule matching:
  - `BrandPostType.php` — the `mbgs_brand` CPT (aggregate root), meta boxes (rules / variables / default / styles / identity / image replacements / **URL rewrite**), and save handler. `POST_TYPE = 'mbgs_brand'`. Enqueues `assets/admin/brand-media.js` (plain JS, committed, no build step) for the `wp.media` pickers on the identity and image-replacement meta boxes.
  - `UrlRuleRegistry.php` — normalize/parse/dedupe URL rules, exact-rule conflict detection, and the cached host→path→Brand rule map.
  - `BrandSettings.php` — readonly value object over the single `_mbgs_settings` meta entry; ALL normalization/defaulting of stored Brand data lives here.
  - `BrandRepository.php` — the single gateway to per-Brand data: `get_settings()` hydrates `BrandSettings` behind a per-request memo + a per-Brand `mbgs_brand_settings_<id>` transient; `save_settings()`/`update_settings()` write and flush; thin per-concern getters delegate to it.
  - `BrandResolver.php` — `HTTP_HOST` + `REQUEST_URI` → Brand id (most-specific rule wins); per-request memoization plus a capability-gated `?mbgs_preview_brand` override (see Gotchas).
  - `AdminNotices.php` — renders the duplicate-rule rejection notice.
- `includes/GlobalStyles/` — the per-Brand style-override mechanism:
  - `GlobalStylesPostService.php` — creates/reads the dedicated `wp_global_styles` post each Brand owns.
  - `GlobalStylesOverride.php` — the `wp_theme_json_data_user` filter (frontend merge).
- `includes/ContentVariables/` — the `%%brand.*%%` token substitution:
  - `VariableParser.php` — `key = value` textarea → assoc array.
  - `VariableSubstitutionService.php` — looks up the resolved Brand's variables and exposes `replace()`, one `preg_replace_callback` pass over HTML handed to it by `PageBuffer`. It no longer starts its own output buffer (see `includes/Rendering/` below).
- `includes/Identity/` — per-Brand site identity override:
  - `SiteIdentityOverride.php` — 5 option/theme-mod filters (`pre_option_site_logo`, `theme_mod_custom_logo`, `pre_option_blogname`, `pre_option_blogdescription`, `pre_option_site_icon`) so core builds all the surrounding markup (srcset, alt, link wrapping, icon sizes) itself. Frontend-only (admin/AJAX/REST short-circuit to null); deliberately does **not** exclude feeds — see Gotchas.
- `includes/Media/` — per-Brand image replacement:
  - `ImageMapBuilder.php` — turns a Brand's `original attachment => replacement attachment` pairs into a flat, longest-key-first URL map (full size + every registered size variant, matched by size name) and persists `image_map` + the derived `image_url_map` into the consolidated `_mbgs_settings` entry (via `BrandRepository::update_settings()`).
  - `ImageUrlReplacer.php` — one `str_replace()` pass over the rendered HTML using the precomputed URL map — no attachment queries at render time.
  - `AttachmentLifecycle.php` — keeps every Brand's URL map truthful when attachments change: rebuilds affected Brands' maps when `_wp_attachment_metadata` is written, prunes pairs referencing a deleted attachment (either side).
- `includes/Rendering/` — the single frontend output buffer:
  - `PageBuffer.php` — one `template_redirect`-started `ob_start()`; applies an ordered list of transformers (variable substitution, then image URL replacement, then host rewrite) to the final HTML in one pass — one buffer, N passes, no nesting. Skipped for admin/AJAX/feeds/REST.
- `includes/Urls/` — per-Brand URL host rewriting:
  - `HostForm.php` — pure www↔apex authority transforms (`to_www`/`to_apex`/`matches`/`apply`), the shared form logic.
  - `RequestAuthority.php` — the sanitized, validated current-request authority (host[:port]); single source of truth shared by `HostRewriter` (rewrite target) and `HostCanonicalizer` (redirect decision).
  - `HostRewriter.php` — the LAST PageBuffer transformer: for Brands with the option on, swaps the canonical `home`/`siteurl` authority (host[:port]) in the final HTML for the authority being browsed — absolute, protocol-relative, and JSON-escaped forms; path/query never touched. Also keeps server-side redirects on the browsed host: filters `redirect_canonical` so core can't bounce visitors back to the canonical host (returns false when only the host differed), adds the browsed host to `allowed_redirect_hosts` so `wp_validate_redirect()` stops rejecting brand-host targets (WooCommerce/wp-login `redirect_to` flows), and rewrites canonical-host `Location:` targets on the `wp_redirect` filter — with a GET-self-redirect guard so `HostCanonicalizer`'s www↔apex 301 survives (see Gotchas).
  - `HostCanonicalizer.php` — `template_redirect` @ priority 1: for opted-in Brands with a `canonical_host_form`, 301-redirects visitors on the non-preferred www/apex form to the preferred one. HTML is untouched — after the redirect the browsed host already IS the preferred form, so `HostRewriter` follows for free. Has a loop guard (`site_address_opposes()`): for the install's **own** domain it will NOT redirect to a form that opposes the WordPress Site Address (`home`/`siteurl`) host form — see Gotchas.
- `includes/Rest/` — the editor-facing REST surface:
  - `ReplacementsController.php` — the `mbgs/v1` namespace: `GET`/`POST /replacements` (per-image replacement rows for the Image-block panel), `GET /brands` and `GET /preview-map` (Brand list + per-Brand preview payload for the editor preview sidebar). All routes gated by `edit_theme_options`.
- `includes/Editor/` — block editor integration:
  - `EditorAssets.php` — enqueues the `@wordpress/scripts`-built bundle from `assets/build/` (never the release-zip `build/` directory — see Gotchas) on `enqueue_block_editor_assets`, using the generated `*.asset.php` for dependencies and cache-busting.

### Data model (all on the `mbgs_brand` CPT)

Post meta — ONE entry (authoritative key — grep this, not the design doc):
- `_mbgs_settings` — everything, hydrated through `BrandSettings`:
  - `rules` — array of normalized URL rules (`host` or `host/path/prefix`).
  - `variables` — assoc array of variable key → value.
  - `is_default` — bool; true on the single fallback Brand.
  - `identity` — assoc array of `logo_id` / `icon_id` / `title` / `tagline`; each key present only when set.
  - `image_map` — assoc array of `original attachment ID => replacement attachment ID`.
  - `image_url_map` — **derived, precomputed at save time** by `ImageMapBuilder` (`original URL => replacement URL`, longest-first). Render-time code reads only this key.
  - `global_styles_post_id` — id of this Brand's dedicated `wp_global_styles` post.
  - `url_rewrite` — assoc array of `enabled` / `force_https` (bools) / `canonical_host_form` (`'www'` or `'apex'`; present only when set), present only when checked.

Admin form fields (POST): `mbgs_rules`, `mbgs_variables`, `mbgs_is_default`, `mbgs_styles_json`, `mbgs_logo_id`, `mbgs_icon_id`, `mbgs_title`, `mbgs_tagline`, `mbgs_image_map_original[]`, `mbgs_image_map_replacement[]` (parallel arrays, same index = one pair), `mbgs_url_rewrite_enabled`, `mbgs_url_rewrite_force_https`, `mbgs_url_rewrite_host_form`; nonce field `mbgs_brand_nonce` / action `mbgs_save_brand`.

Transients: `mbgs_rule_map` (cached rule map); `mbgs_brand_settings_<brand_id>` (one Brand's raw settings array — created on first read, dropped on any save/update/delete of that Brand); `mbgs_default_brand` (default-Brand id, `0` sentinel = none flagged); `mbgs_rule_conflict_<user_id>` (one-shot conflict-notice payload). The first three are flushed from the same `save_post_mbgs_brand` / `deleted_post` hooks.

### Key hooks (wired in `Plugin::start()`)

| Hook | Callback | Purpose |
|------|----------|---------|
| `init` | `BrandPostType::register` | register the `mbgs_brand` CPT |
| `add_meta_boxes` | `BrandPostType::register_meta_boxes` | rules / variables / default / styles / identity / image-replacement / URL-rewrite boxes |
| `save_post_mbgs_brand` | `BrandPostType::save`, then `UrlRuleRegistry::invalidate_cache` | persist + bust rule-map cache |
| `wp_theme_json_data_user` | `GlobalStylesOverride::filter_theme_json` | merge the matched Brand's styles at render |
| `template_redirect` (prio 1) | `HostCanonicalizer::handle` | redirect opted-in Brands' visitors to the canonical www/apex host form |
| `template_redirect` | `PageBuffer::start_buffer` | open the whole-page buffer; runs variable substitution, then image URL replacement, then host rewrite |
| `redirect_canonical` | `HostRewriter::filter_redirect_canonical` | keep opted-in Brands' visitors on the browsed host |
| `allowed_redirect_hosts` | `HostRewriter::filter_allowed_redirect_hosts` | let `wp_validate_redirect()` accept the browsed Brand host (login `redirect_to` flows) |
| `wp_redirect` | `HostRewriter::filter_wp_redirect` | rewrite canonical-host `Location:` targets to the browsed host (login/logout/PRG flows) |
| `save_post_mbgs_brand` | `BrandRepository::flush_brand_caches` | drop the Brand's settings + default-Brand transients |
| `pre_option_site_logo` | `SiteIdentityOverride::filter_logo_option` | per-Brand logo (block themes / REST-facing option) |
| `theme_mod_custom_logo` | `SiteIdentityOverride::filter_logo_theme_mod` | per-Brand logo (`get_custom_logo()` / classic themes) |
| `pre_option_blogname` | `SiteIdentityOverride::filter_blogname` | per-Brand site title |
| `pre_option_blogdescription` | `SiteIdentityOverride::filter_blogdescription` | per-Brand tagline |
| `pre_option_site_icon` | `SiteIdentityOverride::filter_site_icon` | per-Brand favicon |
| `admin_notices` | `AdminNotices::render` | duplicate-rule rejection notice |
| `admin_enqueue_scripts` | `BrandPostType::enqueue_admin_assets` | `wp.media` pickers, Brand edit screen only |
| `enqueue_block_editor_assets` | `EditorAssets::enqueue` | editor bundle (Image-block panel, Brand preview sidebar) |
| `rest_api_init` | `ReplacementsController::register_routes` | register the `mbgs/v1` REST routes |
| `added_post_meta` / `updated_post_meta` | `AttachmentLifecycle::on_attachment_meta_saved` | rebuild affected Brands' image URL maps on attachment metadata writes |
| `delete_attachment` | `AttachmentLifecycle::on_delete_attachment` | prune image-map pairs referencing a deleted attachment |

### Security / access

The `mbgs_brand` CPT is gated behind **`edit_theme_options`** (all its `capabilities` are mapped to it in `BrandPostType`), so only admins/theme-editors can create or edit Brands — a plain Author cannot brand the site. The `mbgs/v1` REST routes (`ReplacementsController`) and the `?mbgs_preview_brand` frontend override (`BrandResolver`) are both gated by the same `edit_theme_options` check via `current_user_can()`.

## Development Commands

The canonical toolchain/suite logic lives in **portable shell scripts** shared by the local Docker flow and GitHub Actions:
- `scripts/setup/unit.sh` (PHP 8.3 + Composer + Node ≥ 24 + wp-cli/dist-archive) and `scripts/setup/e2e.sh` (adds WP core → `/opt/wp-core`, SQLite drop-in, Plugin Check zip, wp-cli server-command, Playwright Chromium/ffmpeg) — **all version pins live in these two scripts**, nowhere else.
- `scripts/tests/{lint,unit,e2e,plugin-check}.sh` — one runner per suite (the two e2e runners share `scripts/tests/lib/build-test-zip.sh`).

`make` targets run those scripts **inside Docker** for local reproducibility; CI runs the same scripts natively on `ubuntu-24.04` runners (no make, no Docker in CI). Two images, both thin `ubuntu:24.04` wrappers over the setup scripts:
- `tests/Unit/Dockerfile` → `the-another-multi-brand-global-styles-runner` (runs `scripts/setup/unit.sh`): lint / test / release / version bump.
- `tests/e2e/Dockerfile` → `the-another-multi-brand-global-styles-e2e-runner` (runs `scripts/setup/e2e.sh`; bakes Playwright's Chromium at build time from the lockfile): the two e2e suites. Kept separate so the lint/test image stays small.

```bash
# Dependencies (Docker)
make install          # composer install --no-dev
make install-dev      # composer install (needed for lint/test)

# Quality (Docker)
make lint             # composer phpcs  (WordPress + VIP-Go standards; errors gate, warnings don't)
make format           # composer phpcbf (MODIFIES source)
make test             # PHPUnit unit tests (clears .phpunit.cache first)

# End-to-end (Docker, tests/e2e/Dockerfile)
make test-e2e         # functional native-PHP + Playwright suite (tests/e2e/functional/)
make check-plugin     # WordPress.org Plugin Check against the packaged zip (tests/e2e/check-plugin/)

# Release / versioning (Docker)
make release          # install-dev + lint + test gates, then build/<name>-<version>.zip
make version-patch|version-minor|version-major   # bump all version markers; review + commit yourself

make all              # install-dev + lint + test
make clean            # rm vendor/ node_modules/ build/ caches
```

Composer scripts (run directly if not using Docker): `composer phpcs`, `composer phpcbf`, `composer test`, `composer build` (install --no-dev + optimized dump-autoload, used by the release pipeline).

npm scripts: `build:editor` / `start:editor` (`@wordpress/scripts` build/watch of the block-editor bundle, `src/` → `assets/build/`), `test:e2e`, `test:e2e:ui`, `check:plugin`, `plugin-zip`, `plugin-zip:check`, `version:patch|minor|major`. The zip pipeline (`plugin-zip` / `plugin-zip:check`, and therefore `make release`/`make test-e2e`/`make check-plugin`) runs `build:editor` automatically before packaging — you don't need to build the editor bundle by hand before those.

Run a single unit test: `./vendor/bin/phpunit --filter test_method_name` or `./vendor/bin/phpunit tests/Unit/Path/To/FileTest.php`.

## Testing

### Unit (PHPUnit 11 + Brain Monkey + Mockery)

- Location: `tests/Unit/` (mirrors `includes/`), bootstrap `tests/Unit/bootstrap.php`.
- **No WordPress test suite, no database** — WP functions are mocked via Brain Monkey. Test namespace: `TheAnother\Plugin\MultiBrandGlobalStyles\Tests\`.
- Bootstrap policy: WP function stubs are added **per-test** (not globally) so a clean-cache run can't be masked by stale ordering. Keep it that way.

### End-to-end, split into two independent suites

- `tests/e2e/functional/` — **native PHP 8.3 + the official SQLite drop-in**, Playwright. Config `tests/e2e/functional/playwright.config.ts`; WordPress is booted by `tests/e2e/functional/environment/serve-wp.sh` (Playwright's `webServer.command`), which sources the shared `tests/e2e/lib/provision-wp.sh` for provisioning: a version-pinned core (the `WP_VERSION` pin in `scripts/setup/e2e.sh`, provisioned to `/opt/wp-core`) is copied to a fresh temp dir, the SQLite drop-in is wired in, and `wp core install` runs (admin/password — `RequestUtils`' defaults). `serve-wp.sh` then installs the plugin from the same packaged `-test` zip the check-plugin suite gates (`wp plugin install`, zip built fresh each run by the shared `scripts/tests/lib/build-test-zip.sh` pre-flight), sets pretty permalinks via `wp rewrite structure`, and serves via `wp server` with `PHP_CLI_SERVER_WORKERS=6`. Auth is standard storageState: `global-setup.ts` writes `artifacts/storage-states/admin.json` via `requestUtils.setupRest()`. Covers activation (incl. a real deactivate→reactivate-via-wp-admin flow), save-time rule validation, per-URL style scoping, a Navigation-block render canary (guards the theme.json merge), `%%brand.*%%` substitution, Brand identity overrides, per-Brand image replacement (incl. the `mbgs/v1` REST route), and the capability-gated `?mbgs_preview_brand` override. Provisions Brands through the **real admin form** (`createBrand()` in `functional/support/helpers.ts`) so `BrandPostType::save()`'s nonce/validation/conflict path is exercised end-to-end — a deliberate deviation from the seed-via-REST norm, because that save handler is the plugin's only write path.
- `tests/e2e/check-plugin/` — **no Playwright/browser**: a plain Node runner (`run-plugin-check.mjs`) provisions WordPress via `provision-pcp-wp.sh`, which sources the same shared `tests/e2e/lib/provision-wp.sh` (no server started), installs the pinned Plugin Check (`PCP_VERSION` in `scripts/setup/e2e.sh`, fetched to `/opt/plugin-check.zip`) and then the packaged `-test` zip, and runs `wp plugin check` natively — all checks, including the 5 runtime ones. Catches packaging bugs a source mount would miss. ERROR findings gate; WARNINGs are reported only. Two runs per invocation: the full default check set, plus the 5 runtime checks explicitly as a canary, each run carrying `pcp-early-init-marker.php` (wp-cli `--require`) so runtime checks can never silently stop running again (see gotcha below). Results land in `build/plugin-check-results.txt`.

Both suites run via `scripts/tests/e2e.sh` / `scripts/tests/plugin-check.sh` (shared zip-build pre-flight: `scripts/tests/lib/build-test-zip.sh`) — locally inside the e2e image via the Make targets, natively on `ubuntu-24.04` runners in CI. CI: `.github/workflows/ci.yml` (PR gate: PHPCS, PHPUnit, both e2e suites) and `.github/workflows/release.yml` (same four gates on every master push, then zip build + `v<version>` tag from `package.json` + GitHub Release; an already-existing tag skips the release steps).

## Conventions (specific to this plugin)

- **Namespace:** `TheAnother\Plugin\MultiBrandGlobalStyles`.
- **Naming:** underscore-free **StudlyCaps** for namespaces, class names, and file names (`BrandPostType.php`, not `class-brand-post-type.php`). PSR-4, one class per file under `includes/`. **This deliberately differs from the sibling plugins** (`aucteeno-nexus` etc. use `The_Another\Plugin\Aucteeno_Nexus` with `class-*.php` snake files) — do **not** copy their convention here.
- **DI, not scattered hooks:** register services on the `Container` and wire hooks through the `HookManager` in `Plugin::start()`; don't sprinkle `add_action` across classes.
- **WordPress idioms stay first-class:** the CPT is the aggregate, core filters/`get_post_meta`/`wp_insert_post` are used directly (not wrapped in extra abstraction), and hooks are the extensibility mechanism.
- **Coding standards:** `WordPress` + `WordPress-VIP-Go` via `.phpcs.xml.dist` (`testVersion 8.3-`, min WP 6.9). Lint gate is errors-only.
- **Escaping:** variable values are `esc_html()`-escaped before substitution (plain text only, no HTML injection); undefined `%%tokens%%` are left literal, never blanked.

## Gotchas & hard-won knowledge

- **The functional suite runs on native PHP, not Playground — keep it that way unless the single-container constraint changes.** The previous `@wp-playground/cli` (PHP-wasm) boot required five separate workarounds (mount naming, CORS `--site-url`, a cookie-jar readiness poller, Blueprint-based activation replacing an untimed REST call, a `--workers` floor) and still never produced a clean local pass; the full history lives in `docs/superpowers/specs/2026-07-03-e2e-native-php-migration-design.md`. `@wp-playground/cli` has since been removed entirely — the check-plugin suite now provisions natively too (see `docs/superpowers/specs/2026-07-03-e2e-zip-based-provisioning-design.md`).
- **The provisioning/boot ordering is load-bearing twice**: the SQLite drop-in (`wp-content/db.php`) must exist before `wp core install` (inside `tests/e2e/lib/provision-wp.sh` — or install tries to reach MySQL), and installation must finish before `wp server` binds the port (in `serve-wp.sh` — that's what makes Playwright's plain `webServer.url` readiness check truthful, no custom poller needed).
- **Admin credentials must stay exactly `admin`/`password`** — they're `@wordpress/e2e-test-utils-playwright` `RequestUtils`' hardcoded defaults, and both the storageState written by `global-setup.ts` and the per-worker `requestUtils` fixture assume them.
- **Both suites install the plugin from the packaged `-test` zip — never from a source mount or file-by-file copy.** The shared `scripts/tests/lib/build-test-zip.sh` pre-flight builds the zip fresh every run for both suites, so `.distignore` is load-bearing for the functional suite too, and packaging bugs fail functionally, not just in Plugin Check. Two consequences: source edits require the rebuild every run already performs (no live mount), and `make test-e2e`/`make check-plugin` both leave `vendor/` in no-dev state (`composer build` runs inside the zip pipeline) — run `make install-dev` before `make test`/`make lint` afterwards.
- **CI runners are pinned to `ubuntu-24.04`, never `ubuntu-latest`.** PHP 8.3 (the plugin's target) is that image's native series, and `scripts/setup/unit.sh` hard-fails on any other PHP — a `-latest` rollover to a newer Ubuntu would break every job at setup. Bump the runner label and the Docker base images together, deliberately.
- **`PHP_CLI_SERVER_WORKERS=6` on `wp server` is required, not tuning**: with a single built-in-server worker, WordPress's own loopback requests (cron spawn, site health) deadlock the one PHP process.
- **The classic-editor publish click in `createBrand()` needs `{ force: true }`** — WP admin's postbox layout never settles enough to pass Playwright's actionability/stability check, confirmed engine-independent on native PHP (no wasm involved; the plain click hung until test timeout on the first run). Don't "clean it up" by removing the force option. The same postbox instability also hangs Playwright's `.check()` on the Brand meta-box checkboxes, so every checkbox interaction in `createBrand()` (and the url-rewrite spec's edit-screen check) uses `{ force: true }` too — the round-trip `toBeChecked()` assertions are what prove the forced clicks persisted.
- **wp-cli's `wp server` router follows the Host header — the functional e2e pins the canonical URLs against it.** The server-command router rewrites `home`/`siteurl` to `http://$_SERVER['HTTP_HOST']` on every request, for any host, plugin or no plugin — which silently defeats any test of host-dependent behavior (the off-state of the URL-rewrite option becomes unobservable, and the on-state can't be attributed to the plugin). `tests/e2e/functional/environment/serve-wp.sh` therefore writes an mu-plugin into the ephemeral install pinning both options at `PHP_INT_MAX` (both `pre_option_*` and `option_*` layers) to the install URL. Don't remove it, and don't move it into the shared `tests/e2e/lib/provision-wp.sh` (the check-plugin suite never serves requests and doesn't need it).
- **URL rewrite trade-offs to know before "fixing" them.** (a) `_mbgs_settings` is one blob written read-merge-write with no atomicity — two concurrent writers (classic-form save vs the editor's REST replacements panel in another tab) can interleave and silently revert each other's keys; rare, self-healing on re-save, accepted for now. (b) With the option on the default/fallback Brand, links and `rel="canonical"` follow ANY validated Host header that reaches the site — behind wildcard vhosts or Host-agnostic CDNs that is a cache-poisoning/SEO consideration the operator owns (the host is regex-validated, so there is no injection risk). (c) `PageBuffer` excludes feeds, so a rewriting Brand's feed still carries canonical-host links even though the `redirect_canonical` guard keeps the feed request on the Brand host — consistent with the buffer's scope, just newly observable. (d) `HostRewriter` matches the canonical HOST on any port (`(?::\d+)?` swallows the port so it is replaced, never doubled) — a canonical-host URL on an unusual port is rewritten too; that is intended. (e) Server-side redirects are kept on the browsed host by two filters on `HostRewriter` (`allowed_redirect_hosts` + `wp_redirect`) — this is what keeps a WooCommerce/wp-login login on the Brand domain. The `wp_redirect` rewrite deliberately skips a GET/HEAD redirect whose REWRITTEN target is exactly the current URL: redirecting a GET to itself can only loop, and the only legitimate source of such a target is a www↔apex canonicalizer (`HostCanonicalizer` or a web-server rule) whose cross-form redirect must survive — don't "simplify" the guard away, and don't make it scheme-insensitive (an http→https upgrade of the current URL is NOT a self-redirect; suppressing its rewrite would send https-forcing redirects back to the canonical host). Known corner: `HostCanonicalizer` + `force_https` on the install's own domain resolves in two 301 hops instead of one (http-www → https-www → https-apex) — converges, accepted. The `wp_redirect` filter is frontend-only (admin/AJAX/REST short-circuit); the login flows are e2e-covered in `url-rewrite.spec.ts` (Phase 5), the guard matrix is unit-tested.
- **`HostCanonicalizer` defers to the WordPress Site Address to avoid redirect loops it cannot otherwise see.** The plugin's canonical redirect and *WordPress core's* `redirect_canonical` are already reconciled (`HostRewriter::filter_redirect_canonical` cancels core), so the plugin never loops against WordPress alone — verified exhaustively against real WP across every home/form/scheme/path/force_https combination. But a **web-server-level** www↔apex redirect (nginx/Apache) or another plugin, running in the *opposite* direction to `canonical_host_form`, is invisible to the plugin and produces a hard `apex→www→apex` loop (two cached 301s can't be broken statelessly). Guard: `site_address_opposes()` — when the browsed host's registrable domain equals the `home`/`siteurl` host's domain but that Site Address is in the **opposite** www/apex form, `HostCanonicalizer` returns null (renders on the browsed host) instead of redirecting. Contract for the install's own domain: **`canonical_host_form` must match the WordPress Address** (set the Site Address to the form you want, Brand form to match); a server hardcoded opposite to the Site Address is a pre-existing broken config that fights core with or without this plugin. Multi-domain Brands (different apex than the install) skip the guard entirely — their form is always honored. Not e2e-covered (single-host `127.0.0.1`); the decision matrix incl. the guard is unit-tested. See `docs/superpowers/specs/2026-07-06-host-form-canonicalization-loop-fix-design.md`.
- **`HostCanonicalizer` uses `wp_redirect`, not `wp_safe_redirect`** — the www↔apex target is a cross-host transform of the already-regex-validated current host, which `wp_safe_redirect`'s allowlist would reject; the target derives solely from the current request, so there's no open-redirect surface. For subdomains (`beta.x.com`) "force www" prepends literally (`www.beta.x.com`) and "force apex" strips only a leading `www.` — intended, operator-owned. No e2e coverage: the functional suite is single-host on `127.0.0.1` and can't exercise a real www↔apex pair; the decision matrix is unit-tested.
- **Plugin Check runtime checks only work via PCP's WP-CLI runner — never go back to the wp-admin AJAX flow for them.** PCP's 5 runtime checks (`enqueued_scripts_size`, `enqueued_styles_size`, `enqueued_styles_scope`, `enqueued_scripts_scope`, `non_blocking_scripts`) swap the ENTIRE table set (users, usermeta, options included) to a freshly-installed `wp_pc_*` environment via an `object-cache.php` drop-in, and nothing carries the requester's identity into it: the cookie's user doesn't exist in `wp_pc_users`, the pc_-swapped install keeps auth salts in DB options (fresh random ones), and the roles option is written as `wp_user_roles` but read back as `wp_pc_user_roles`. Result: the AJAX request arrives unauthenticated and admin-ajax dies with a bare `0` (HTTP 400). Verified 2026-07 by grafting user+salts+roles into the pc\_ tables, which made all 5 checks pass. (An earlier "loopback HTTP request" theory was wrong — no loopback exists in those checks.) The CLI runner needs no auth at all, but requires the drop-in pre-placed AND a canonical `$_SERVER['argv']`. Under real wp-cli, argv and stdout are canonical; `pcp-early-init-marker.php` (wp-cli `--require`) still records whether PCP early-initialized, which is the tripwire distinguishing "runtime checks found nothing" from "runtime checks silently never ran". This is upstream-reportable (PCP identity gap).
- **`wp_theme_json_data_user` (not `wp_theme_json_data_theme`) is the filter actually used** for the per-Brand override — match the code, not the earlier design draft.
- **No activation/deactivation/uninstall hooks yet** and no `uninstall.php`. Cleanup of a Brand's `wp_global_styles` post on delete, and uninstall cleanup, are known open follow-ups — don't assume they exist.
- **The editor JS builds to `assets/build/`, never `build/`.** Both are `.gitignore`d build output, but they're different directories with different lifecycles: `build/` is where `make release`/the zip pipeline write the final `<name>-<version>.zip`, while `assets/build/` is where `@wordpress/scripts` writes the compiled editor bundle that `EditorAssets` enqueues — and that must survive packaging *into* the zip. Don't point `--output-path` (or anything else) at plain `build/` — that's the wrong directory entirely.
- **`scripts/dist-archive.sh`'s tar excludes are anchored (`./build`, `./.git`, `./node_modules`), not bare (`build`).** Unanchored tar `--exclude` patterns match any path component anywhere in the tree, so a bare `build` would also match `assets/build/` and silently strip the shipped editor bundle out of the packaged zip. Anchoring to `./build` scopes the exclusion to the top-level `build/` directory only.
- **`?mbgs_preview_brand` is capability-gated and only honored after `init`.** `BrandResolver::resolve_preview_override()` checks `did_action( 'init' )` before calling `current_user_can()`, deliberately — calling `current_user_can()` before `init` forces early user determination (a known WP footgun). Any code path that reads Brand-scoped options before `init` (there shouldn't be any in this plugin, but a future filter could add one) will silently get the normal rule-map resolution instead of the preview override — that's intended, not a bug to "fix" by moving the check earlier.
- **`SiteIdentityOverride`'s frontend guard deliberately does NOT exclude feeds.** `is_feed()` is unsafe to call before the main query has run (unlike `is_admin()`/`wp_doing_ajax()`/`REST_REQUEST`, which are all safe pre-query signals) — and a feed served on a Brand's matched host should carry that Brand's title/tagline anyway, so there's no reason to special-case it even once the query does run. `PageBuffer::start_buffer()` still excludes feeds for the variable-substitution/image-replacement HTML passes — only the identity option filters differ.
- **In Playwright, `browser.newContext()` inherits the project's `use.storageState` even when called directly (not through the `page`/`context` fixtures).** `preview-override.spec.ts` needed a genuinely logged-out context to prove `?mbgs_preview_brand` is ignored for anonymous visitors; the admin's `wordpress_logged_in_*` cookies showed up anyway until the context was created with `storageState: undefined` explicitly (confirmed via trace inspection). `baseURL` isn't inherited either — pass it explicitly too when hand-rolling a context this way.
