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
  - `BrandPostType.php` — the `mbgs_brand` CPT (aggregate root), meta boxes, and save handler. `POST_TYPE = 'mbgs_brand'`.
  - `UrlRuleRegistry.php` — normalize/parse/dedupe URL rules, exact-rule conflict detection, and the cached host→path→Brand rule map.
  - `BrandRepository.php` — read helpers (rules, variables, default Brand, global-styles post id).
  - `BrandResolver.php` — `HTTP_HOST` + `REQUEST_URI` → Brand id (most-specific rule wins).
  - `AdminNotices.php` — renders the duplicate-rule rejection notice.
- `includes/GlobalStyles/` — the per-Brand style-override mechanism:
  - `GlobalStylesPostService.php` — creates/reads the dedicated `wp_global_styles` post each Brand owns.
  - `GlobalStylesOverride.php` — the `wp_theme_json_data_user` filter (frontend merge).
- `includes/ContentVariables/` — the `%%brand.*%%` token substitution:
  - `VariableParser.php` — `key = value` textarea → assoc array.
  - `VariableSubstitutionService.php` — a `template_redirect`-started whole-page output buffer, one `preg_replace_callback` pass over the final HTML (skipped for REST/admin-ajax/feeds).

### Data model (all on the `mbgs_brand` CPT)

Post meta (authoritative keys — grep these, not the design doc):
- `_mbgs_rules` — array of normalized URL rules (`host` or `host/path/prefix`).
- `_mbgs_variables` — assoc array of variable key → value.
- `_mbgs_is_default` — `'1'` on the single fallback Brand for unmatched requests.
- `_mbgs_global_styles_post_id` — id of this Brand's dedicated `wp_global_styles` post.

Admin form fields (POST): `mbgs_rules`, `mbgs_variables`, `mbgs_is_default`, `mbgs_styles_json`; nonce field `mbgs_brand_nonce` / action `mbgs_save_brand`.

Transients: `mbgs_rule_map` (cached rule map, rebuilt on any Brand save via `UrlRuleRegistry::invalidate_cache()`); `mbgs_rule_conflict_<post_id>` (one-shot conflict-notice payload).

### Key hooks (wired in `Plugin::start()`)

| Hook | Callback | Purpose |
|------|----------|---------|
| `init` | `BrandPostType::register` | register the `mbgs_brand` CPT |
| `add_meta_boxes` | `BrandPostType::register_meta_boxes` | rules / variables / default / styles boxes |
| `save_post_mbgs_brand` | `BrandPostType::save`, then `UrlRuleRegistry::invalidate_cache` | persist + bust rule-map cache |
| `wp_theme_json_data_user` | `GlobalStylesOverride::filter_theme_json` | merge the matched Brand's styles at render |
| `template_redirect` | `VariableSubstitutionService::start_buffer` | open the output buffer for token substitution |
| `admin_notices` | `AdminNotices::render` | duplicate-rule rejection notice |

### Security / access

The `mbgs_brand` CPT is gated behind **`edit_theme_options`** (all its `capabilities` are mapped to it in `BrandPostType`), so only admins/theme-editors can create or edit Brands — a plain Author cannot brand the site.

## Development Commands

All `make` targets run **inside Docker** for a reproducible toolchain. There are **two** images:
- `tests/Unit/Dockerfile` → `the-another-multi-brand-global-styles-runner` (Alpine + PHP 8.3 + Composer + wp-cli + Node): lint / test / release / version bump.
- `tests/e2e/Dockerfile` → `the-another-multi-brand-global-styles-e2e-runner` (the above **plus** Alpine's native Chromium, ffmpeg, and python3/g++): the two e2e suites. Kept separate so the lint/test image stays small.

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

npm scripts: `test:e2e`, `test:e2e:ui`, `check:plugin`, `plugin-zip`, `plugin-zip:check`, `version:patch|minor|major`.

Run a single unit test: `./vendor/bin/phpunit --filter test_method_name` or `./vendor/bin/phpunit tests/Unit/Path/To/FileTest.php`.

## Testing

### Unit (PHPUnit 11 + Brain Monkey + Mockery)

- Location: `tests/Unit/` (mirrors `includes/`), bootstrap `tests/Unit/bootstrap.php`.
- **No WordPress test suite, no database** — WP functions are mocked via Brain Monkey. Test namespace: `TheAnother\Plugin\MultiBrandGlobalStyles\Tests\`.
- Bootstrap policy: WP function stubs are added **per-test** (not globally) so a clean-cache run can't be masked by stale ordering. Keep it that way.

### End-to-end, split into two independent suites

- `tests/e2e/functional/` — **native PHP 8.3 + the official SQLite drop-in**, Playwright. Config `tests/e2e/functional/playwright.config.ts`; WordPress is booted by `tests/e2e/functional/serve-wp.sh` (Playwright's `webServer.command`): a version-pinned core baked into the e2e image (`ARG WP_VERSION` in `tests/e2e/Dockerfile`) is copied to a fresh temp dir, installed via wp-cli (admin/password — `RequestUtils`' defaults), the plugin's four runtime paths (main file, `includes/`, `vendor/`, `readme.txt`) are copied in at the real slug, pretty permalinks set via `wp rewrite structure`, then served by `wp server` with `PHP_CLI_SERVER_WORKERS=6`. Auth is standard storageState: `global-setup.ts` writes `artifacts/storage-states/admin.json` via `requestUtils.setupRest()`. Covers activation (incl. a real deactivate→reactivate-via-wp-admin flow), save-time rule validation, per-URL style scoping, a Navigation-block render canary (guards the theme.json merge), and `%%brand.*%%` substitution. Provisions Brands through the **real admin form** (`createBrand()` in `functional/helpers.ts`) so `BrandPostType::save()`'s nonce/validation/conflict path is exercised end-to-end — a deliberate deviation from the seed-via-REST norm, because that save handler is the plugin's only write path.
- `tests/e2e/check-plugin/` — **no Playwright/browser**: a plain Node runner (`run-plugin-check.mjs`) boots `@wp-playground/cli` fresh-install-from-zip and runs WordPress.org's official Plugin Check (PCP) via **PCP's WP-CLI runner** — all 32 checks including the 5 runtime ones. Catches packaging bugs a source mount would miss. ERROR findings gate; WARNINGs are reported only. Two runs per invocation: the full default check set, plus the 5 runtime checks explicitly as a canary (plus `early_init` markers from `pcp-cli-shim.php`) so runtime checks can never silently stop running again (see gotcha below).

Both suites run inside the e2e image (`tests/e2e/Dockerfile`) via the single shared entrypoint `scripts/run-e2e.sh <functional|plugin-check>` — the only place the e2e run logic lives (both Make targets and CI call it). CI: `.github/workflows/e2e.yml`.

## Conventions (specific to this plugin)

- **Namespace:** `TheAnother\Plugin\MultiBrandGlobalStyles`.
- **Naming:** underscore-free **StudlyCaps** for namespaces, class names, and file names (`BrandPostType.php`, not `class-brand-post-type.php`). PSR-4, one class per file under `includes/`. **This deliberately differs from the sibling plugins** (`aucteeno-nexus` etc. use `The_Another\Plugin\Aucteeno_Nexus` with `class-*.php` snake files) — do **not** copy their convention here.
- **DI, not scattered hooks:** register services on the `Container` and wire hooks through the `HookManager` in `Plugin::start()`; don't sprinkle `add_action` across classes.
- **WordPress idioms stay first-class:** the CPT is the aggregate, core filters/`get_post_meta`/`wp_insert_post` are used directly (not wrapped in extra abstraction), and hooks are the extensibility mechanism.
- **Coding standards:** `WordPress` + `WordPress-VIP-Go` via `.phpcs.xml.dist` (`testVersion 8.3-`, min WP 6.9). Lint gate is errors-only.
- **Escaping:** variable values are `esc_html()`-escaped before substitution (plain text only, no HTML injection); undefined `%%tokens%%` are left literal, never blanked.

## Gotchas & hard-won knowledge

- **The functional suite runs on native PHP, not Playground — keep it that way unless the single-container constraint changes.** The previous `@wp-playground/cli` (PHP-wasm) boot required five separate workarounds (mount naming, CORS `--site-url`, a cookie-jar readiness poller, Blueprint-based activation replacing an untimed REST call, a `--workers` floor) and still never produced a clean local pass; the full history lives in `docs/superpowers/specs/2026-07-03-e2e-native-php-migration-design.md`. `@wp-playground/cli` is still a dependency — the check-plugin suite uses it, where fresh-install-from-zip is exactly its sweet spot.
- **`serve-wp.sh`'s ordering is load-bearing twice**: the SQLite drop-in (`wp-content/db.php`) must exist before `wp core install` (or install tries to reach MySQL), and installation must finish before `wp server` binds the port (that's what makes Playwright's plain `webServer.url` readiness check truthful — no custom poller needed).
- **Admin credentials must stay exactly `admin`/`password`** — they're `@wordpress/e2e-test-utils-playwright` `RequestUtils`' hardcoded defaults, and both the storageState written by `global-setup.ts` and the per-worker `requestUtils` fixture assume them.
- **Plugin files are copied, never symlinked, into the test WordPress**: PHP resolves symlinks in `__FILE__` and WordPress only realpath-maps whole-directory plugin symlinks (`wp_register_plugin_realpath()`), so per-file symlinks can break `plugin_basename()`-keyed behavior.
- **`PHP_CLI_SERVER_WORKERS=6` on `wp server` is required, not tuning**: with a single built-in-server worker, WordPress's own loopback requests (cron spawn, site health) deadlock the one PHP process.
- **The classic-editor publish click in `createBrand()` needs `{ force: true }`** — WP admin's postbox layout never settles enough to pass Playwright's actionability/stability check, confirmed engine-independent on native PHP (no wasm involved; the plain click hung until test timeout on the first run). Don't "clean it up" by removing the force option.
- **Plugin Check runtime checks only work via PCP's WP-CLI runner — never go back to the wp-admin AJAX flow for them.** PCP's 5 runtime checks (`enqueued_scripts_size`, `enqueued_styles_size`, `enqueued_styles_scope`, `enqueued_scripts_scope`, `non_blocking_scripts`) swap the ENTIRE table set (users, usermeta, options included) to a freshly-installed `wp_pc_*` environment via an `object-cache.php` drop-in, and nothing carries the requester's identity into it: the cookie's user doesn't exist in `wp_pc_users`, playground keeps auth salts in DB options (fresh random ones in the pc\_ install), and the roles option is written as `wp_user_roles` but read back as `wp_pc_user_roles`. Result: the AJAX request arrives unauthenticated and admin-ajax dies with a bare `0` (HTTP 400). Verified 2026-07 by grafting user+salts+roles into the pc\_ tables, which made all 5 checks pass. (An earlier "loopback HTTP request" theory was wrong — no loopback exists in those checks.) The CLI runner needs no auth at all, but requires the drop-in pre-placed AND a canonical `$_SERVER['argv']` — under `@wp-playground/cli`, argv is an empty string and the real argv has `--path=` injected at index 1, defeating PCP's positional `argv[1]==='plugin'` test; `tests/e2e/check-plugin/pcp-cli-shim.php` (wp-cli `--require`) fixes both and captures the report (playground's `wp-cli` step also swallows stdout). Both quirks are upstream-reportable (PCP identity gap + argv parsing; playground argv/stdout).
- **`wp_theme_json_data_user` (not `wp_theme_json_data_theme`) is the filter actually used** for the per-Brand override — match the code, not the earlier design draft.
- **No activation/deactivation/uninstall hooks yet** and no `uninstall.php`. Cleanup of a Brand's `wp_global_styles` post on delete, and uninstall cleanup, are known open follow-ups — don't assume they exist.
