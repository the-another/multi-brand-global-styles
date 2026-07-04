# CLAUDE.md

This file guides Claude Code (claude.ai/code) when working in this repository. It is specific to **this** plugin — do not assume conventions from the sibling Aucteeno plugins (they differ; see "Conventions" below).

## Project Overview

**The Another Multi-Brand Global Styles** lets a WordPress admin define **Brands** — bundles of URL match rules, per-Brand global-style overrides, and content variables — on a **single** WordPress installation serving multiple domains and/or path sections. It is not WP Multisite and not separate installs.

A Brand can be scoped to:
- whole domains (`auctionbill.com`, `beta.auctionbill.com`), or
- path sections of one or more sites (`site.com/farm/*`, `site2.com/farm/*`).

Wherever a Brand's rules match the incoming request, three things happen at render time, without touching the theme's `theme.json` or creating a child theme:
1. **Global-style override** — the Brand's stored styles merge over the active theme via the `wp_theme_json_data_user` filter.
2. **Content-variable substitution** — `%%brand.name%%`-style tokens in the final HTML are replaced with the Brand's values.
3. Most-specific rule wins (host+path beats host-only; longer path prefix beats shorter; prefixes match on path-segment boundaries).

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
  - `BrandPostType.php` — the `mbgs_brand` CPT (aggregate root), meta boxes (rules / variables / default / styles / **identity** / **image replacements**), and save handler. `POST_TYPE = 'mbgs_brand'`. Enqueues `assets/admin/brand-media.js` (plain JS, committed, no build step) for the `wp.media` pickers on the identity and image-replacement meta boxes.
  - `UrlRuleRegistry.php` — normalize/parse/dedupe URL rules, exact-rule conflict detection, and the cached host→path→Brand rule map.
  - `BrandRepository.php` — read helpers (rules, variables, default Brand, global-styles post id, identity, image map(s), brand-id listings).
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
  - `ImageMapBuilder.php` — turns a Brand's `original attachment => replacement attachment` pairs into a flat, longest-key-first URL map (full size + every registered size variant, matched by size name) and persists both `_mbgs_image_map` and the derived `_mbgs_image_url_map`.
  - `ImageUrlReplacer.php` — one `str_replace()` pass over the rendered HTML using the precomputed URL map — no attachment queries at render time.
  - `AttachmentLifecycle.php` — keeps every Brand's URL map truthful when attachments change: rebuilds affected Brands' maps when `_wp_attachment_metadata` is written, prunes pairs referencing a deleted attachment (either side).
- `includes/Rendering/` — the single frontend output buffer:
  - `PageBuffer.php` — one `template_redirect`-started `ob_start()`; applies an ordered list of transformers (variable substitution, then image URL replacement) to the final HTML in one pass — one buffer, N passes, no nesting. Skipped for admin/AJAX/feeds/REST.
- `includes/Rest/` — the editor-facing REST surface:
  - `ReplacementsController.php` — the `mbgs/v1` namespace: `GET`/`POST /replacements` (per-image replacement rows for the Image-block panel), `GET /brands` and `GET /preview-map` (Brand list + per-Brand preview payload for the editor preview sidebar). All routes gated by `edit_theme_options`.
- `includes/Editor/` — block editor integration:
  - `EditorAssets.php` — enqueues the `@wordpress/scripts`-built bundle from `assets/build/` (never the release-zip `build/` directory — see Gotchas) on `enqueue_block_editor_assets`, using the generated `*.asset.php` for dependencies and cache-busting.

### Data model (all on the `mbgs_brand` CPT)

Post meta (authoritative keys — grep these, not the design doc):
- `_mbgs_rules` — array of normalized URL rules (`host` or `host/path/prefix`).
- `_mbgs_variables` — assoc array of variable key → value.
- `_mbgs_is_default` — `'1'` on the single fallback Brand for unmatched requests.
- `_mbgs_global_styles_post_id` — id of this Brand's dedicated `wp_global_styles` post.
- `_mbgs_identity` — assoc array of `logo_id` / `icon_id` / `title` / `tagline`; each key is present only when that field is actually set (no empty-string/zero placeholders).
- `_mbgs_image_map` — assoc array of `original attachment ID => replacement attachment ID`.
- `_mbgs_image_url_map` — **derived, precomputed at save time** by `ImageMapBuilder` from `_mbgs_image_map` (`original URL => replacement URL`, keys sorted longest-first). Render-time code (`ImageUrlReplacer`) reads only this key — never `_mbgs_image_map` — so it costs one meta fetch and zero attachment queries.

Admin form fields (POST): `mbgs_rules`, `mbgs_variables`, `mbgs_is_default`, `mbgs_styles_json`, `mbgs_logo_id`, `mbgs_icon_id`, `mbgs_title`, `mbgs_tagline`, `mbgs_image_map_original[]`, `mbgs_image_map_replacement[]` (parallel arrays, same index = one pair); nonce field `mbgs_brand_nonce` / action `mbgs_save_brand`.

Transients: `mbgs_rule_map` (cached rule map, rebuilt on any Brand save via `UrlRuleRegistry::invalidate_cache()`); `mbgs_rule_conflict_<post_id>` (one-shot conflict-notice payload).

### Key hooks (wired in `Plugin::start()`)

| Hook | Callback | Purpose |
|------|----------|---------|
| `init` | `BrandPostType::register` | register the `mbgs_brand` CPT |
| `add_meta_boxes` | `BrandPostType::register_meta_boxes` | rules / variables / default / styles / identity / image-replacement boxes |
| `save_post_mbgs_brand` | `BrandPostType::save`, then `UrlRuleRegistry::invalidate_cache` | persist + bust rule-map cache |
| `wp_theme_json_data_user` | `GlobalStylesOverride::filter_theme_json` | merge the matched Brand's styles at render |
| `template_redirect` | `PageBuffer::start_buffer` | open the whole-page buffer; runs variable substitution then image URL replacement |
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

All `make` targets run **inside Docker** for a reproducible toolchain. There are **two** images:
- `tests/Unit/Dockerfile` → `the-another-multi-brand-global-styles-runner` (Alpine + PHP 8.3 + Composer + wp-cli + Node): lint / test / release / version bump.
- `tests/e2e/Dockerfile` → `the-another-multi-brand-global-styles-e2e-runner` (the above **plus** Alpine's native Chromium and ffmpeg): the two e2e suites. Kept separate so the lint/test image stays small.

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

- `tests/e2e/functional/` — **native PHP 8.3 + the official SQLite drop-in**, Playwright. Config `tests/e2e/functional/playwright.config.ts`; WordPress is booted by `tests/e2e/functional/environment/serve-wp.sh` (Playwright's `webServer.command`), which sources the shared `tests/e2e/lib/provision-wp.sh` for provisioning: a version-pinned core baked into the e2e image (`ARG WP_VERSION` in `tests/e2e/Dockerfile`) is copied to a fresh temp dir, the SQLite drop-in is wired in, and `wp core install` runs (admin/password — `RequestUtils`' defaults). `serve-wp.sh` then installs the plugin from the same packaged `-test` zip the check-plugin suite gates (`wp plugin install`, zip built fresh each run by `scripts/run-e2e.sh`), sets pretty permalinks via `wp rewrite structure`, and serves via `wp server` with `PHP_CLI_SERVER_WORKERS=6`. Auth is standard storageState: `global-setup.ts` writes `artifacts/storage-states/admin.json` via `requestUtils.setupRest()`. Covers activation (incl. a real deactivate→reactivate-via-wp-admin flow), save-time rule validation, per-URL style scoping, a Navigation-block render canary (guards the theme.json merge), `%%brand.*%%` substitution, Brand identity overrides, per-Brand image replacement (incl. the `mbgs/v1` REST route), and the capability-gated `?mbgs_preview_brand` override. Provisions Brands through the **real admin form** (`createBrand()` in `functional/support/helpers.ts`) so `BrandPostType::save()`'s nonce/validation/conflict path is exercised end-to-end — a deliberate deviation from the seed-via-REST norm, because that save handler is the plugin's only write path.
- `tests/e2e/check-plugin/` — **no Playwright/browser**: a plain Node runner (`run-plugin-check.mjs`) provisions WordPress via `provision-pcp-wp.sh`, which sources the same shared `tests/e2e/lib/provision-wp.sh` (no server started), installs the image-baked, pinned Plugin Check (`ARG PCP_VERSION` in `tests/e2e/Dockerfile`, fetched to `/opt/plugin-check.zip`) and then the packaged `-test` zip, and runs `wp plugin check` natively — all checks, including the 5 runtime ones. Catches packaging bugs a source mount would miss. ERROR findings gate; WARNINGs are reported only. Two runs per invocation: the full default check set, plus the 5 runtime checks explicitly as a canary, each run carrying `pcp-early-init-marker.php` (wp-cli `--require`) so runtime checks can never silently stop running again (see gotcha below). Results land in `build/plugin-check-results.txt`.

Both suites run inside the e2e image (`tests/e2e/Dockerfile`) via the single shared entrypoint `scripts/run-e2e.sh <functional|plugin-check>` — the only place the e2e run logic lives (both Make targets and CI call it). CI: `.github/workflows/e2e.yml`.

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
- **Both suites install the plugin from the packaged `-test` zip — never from a source mount or file-by-file copy.** `scripts/run-e2e.sh` builds the zip fresh every run for both suites, so `.distignore` is load-bearing for the functional suite too, and packaging bugs fail functionally, not just in Plugin Check. Two consequences: source edits require the rebuild every run already performs (no live mount), and `make test-e2e`/`make check-plugin` both leave `vendor/` in no-dev state (`composer build` runs inside the zip pipeline) — run `make install-dev` before `make test`/`make lint` afterwards.
- **`PHP_CLI_SERVER_WORKERS=6` on `wp server` is required, not tuning**: with a single built-in-server worker, WordPress's own loopback requests (cron spawn, site health) deadlock the one PHP process.
- **The classic-editor publish click in `createBrand()` needs `{ force: true }`** — WP admin's postbox layout never settles enough to pass Playwright's actionability/stability check, confirmed engine-independent on native PHP (no wasm involved; the plain click hung until test timeout on the first run). Don't "clean it up" by removing the force option.
- **Plugin Check runtime checks only work via PCP's WP-CLI runner — never go back to the wp-admin AJAX flow for them.** PCP's 5 runtime checks (`enqueued_scripts_size`, `enqueued_styles_size`, `enqueued_styles_scope`, `enqueued_scripts_scope`, `non_blocking_scripts`) swap the ENTIRE table set (users, usermeta, options included) to a freshly-installed `wp_pc_*` environment via an `object-cache.php` drop-in, and nothing carries the requester's identity into it: the cookie's user doesn't exist in `wp_pc_users`, the pc_-swapped install keeps auth salts in DB options (fresh random ones), and the roles option is written as `wp_user_roles` but read back as `wp_pc_user_roles`. Result: the AJAX request arrives unauthenticated and admin-ajax dies with a bare `0` (HTTP 400). Verified 2026-07 by grafting user+salts+roles into the pc\_ tables, which made all 5 checks pass. (An earlier "loopback HTTP request" theory was wrong — no loopback exists in those checks.) The CLI runner needs no auth at all, but requires the drop-in pre-placed AND a canonical `$_SERVER['argv']`. Under real wp-cli, argv and stdout are canonical; `pcp-early-init-marker.php` (wp-cli `--require`) still records whether PCP early-initialized, which is the tripwire distinguishing "runtime checks found nothing" from "runtime checks silently never ran". This is upstream-reportable (PCP identity gap).
- **`wp_theme_json_data_user` (not `wp_theme_json_data_theme`) is the filter actually used** for the per-Brand override — match the code, not the earlier design draft.
- **No activation/deactivation/uninstall hooks yet** and no `uninstall.php`. Cleanup of a Brand's `wp_global_styles` post on delete, and uninstall cleanup, are known open follow-ups — don't assume they exist.
- **The editor JS builds to `assets/build/`, never `build/`.** Both are `.gitignore`d build output, but they're different directories with different lifecycles: `build/` is where `make release`/the zip pipeline write the final `<name>-<version>.zip`, while `assets/build/` is where `@wordpress/scripts` writes the compiled editor bundle that `EditorAssets` enqueues — and that must survive packaging *into* the zip. Don't point `--output-path` (or anything else) at plain `build/` — that's the wrong directory entirely.
- **`scripts/dist-archive.sh`'s tar excludes are anchored (`./build`, `./.git`, `./node_modules`), not bare (`build`).** Unanchored tar `--exclude` patterns match any path component anywhere in the tree, so a bare `build` would also match `assets/build/` and silently strip the shipped editor bundle out of the packaged zip. Anchoring to `./build` scopes the exclusion to the top-level `build/` directory only.
- **`?mbgs_preview_brand` is capability-gated and only honored after `init`.** `BrandResolver::resolve_preview_override()` checks `did_action( 'init' )` before calling `current_user_can()`, deliberately — calling `current_user_can()` before `init` forces early user determination (a known WP footgun). Any code path that reads Brand-scoped options before `init` (there shouldn't be any in this plugin, but a future filter could add one) will silently get the normal rule-map resolution instead of the preview override — that's intended, not a bug to "fix" by moving the check earlier.
- **`SiteIdentityOverride`'s frontend guard deliberately does NOT exclude feeds.** `is_feed()` is unsafe to call before the main query has run (unlike `is_admin()`/`wp_doing_ajax()`/`REST_REQUEST`, which are all safe pre-query signals) — and a feed served on a Brand's matched host should carry that Brand's title/tagline anyway, so there's no reason to special-case it even once the query does run. `PageBuffer::start_buffer()` still excludes feeds for the variable-substitution/image-replacement HTML passes — only the identity option filters differ.
- **In Playwright, `browser.newContext()` inherits the project's `use.storageState` even when called directly (not through the `page`/`context` fixtures).** `preview-override.spec.ts` needed a genuinely logged-out context to prove `?mbgs_preview_brand` is ignored for anonymous visitors; the admin's `wordpress_logged_in_*` cookies showed up anyway until the context was created with `storageState: undefined` explicitly (confirmed via trace inspection). `baseURL` isn't inherited either — pass it explicitly too when hand-rolling a context this way.
