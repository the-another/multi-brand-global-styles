# The Another Multi-Brand Global Styles

Define **Brands** — URL match rules with per-Brand global-style overrides and content variables — on a **single** WordPress installation serving multiple domains and/or path sections.

- **Requires:** WordPress 6.9+, PHP 8.3+
- **License:** GPL-2.0-or-later
- **Homepage:** <https://theanother.org/plugin/multi-brand-global-styles/>

> This is the developer-facing README. For the WordPress.org listing, see [`readme.txt`](readme.txt). For working in this codebase with Claude Code, see [`CLAUDE.md`](CLAUDE.md).

## What it does

A **Brand** bundles three things and applies them wherever its URL rules match the incoming request — without touching the theme's `theme.json` or creating a child theme:

- **URL match rules** — whole domains (`auctionbill.com`, `beta.auctionbill.com`) or path sections of one or more sites (`site.com/farm/*`, `site2.com/farm/*`). Most-specific rule wins: host+path beats host-only, longer path prefix beats shorter, and prefixes match on path-segment boundaries.
- **Global style overrides** — each Brand carries its own theme.json-shaped styles (colors, typography, spacing, per-element and per-block), merged over the active theme at render time via the `wp_theme_json_data_user` filter.
- **Content variables** — tokens like `%%brand.name%%` in post content, template parts, widgets, or menus are substituted with the matched Brand's values in the rendered HTML.

An optional **default Brand** acts as the fallback for requests that match no rule. This is not WP Multisite and not separate installs — it's one install, one database, many Brands.

## Architecture

Container-based dependency injection (`Container` singleton + `HookManager`), with code organized by domain, not technical layer:

```
includes/
├── Container.php            # DI container (lazy factories, singletons)
├── HookManager.php          # tracked add_action/add_filter registration
├── Plugin.php               # orchestrator — the single hook-wiring map
├── Brand/                   # the mbgs_brand aggregate + URL matching
│   ├── BrandPostType.php    #   CPT, meta boxes, save handler
│   ├── UrlRuleRegistry.php  #   rule normalize/dedupe/conflict + cached rule map
│   ├── BrandRepository.php  #   read helpers
│   ├── BrandResolver.php    #   HTTP_HOST + REQUEST_URI → Brand id
│   └── AdminNotices.php     #   duplicate-rule rejection notice
├── GlobalStyles/            # per-Brand style override
│   ├── GlobalStylesPostService.php   # the per-Brand wp_global_styles post
│   └── GlobalStylesOverride.php      # wp_theme_json_data_user filter
└── ContentVariables/        # %%brand.*%% substitution
    ├── VariableParser.php
    └── VariableSubstitutionService.php   # template_redirect output buffer
```

The `mbgs_brand` custom post type is the aggregate root and is gated behind the `edit_theme_options` capability. See [`CLAUDE.md`](CLAUDE.md) for the full data model (meta keys, hooks, transients).

## Development

All quality/build targets run inside Docker for a reproducible toolchain. Two images: `Dockerfile` (Alpine + PHP 8.3 + Composer + wp-cli + Node) for lint/test/release, and `Dockerfile.e2e` (the above plus Chromium) for the end-to-end suites.

```bash
make install-dev     # composer install (with dev deps)
make lint            # PHPCS (WordPress + VIP-Go standards; errors gate)
make format          # PHPCBF (modifies source)
make test            # PHPUnit unit tests
make test-e2e        # functional @wp-playground/cli + Playwright suite
make check-plugin    # WordPress.org Plugin Check against the packaged zip
make release         # lint + test gates, then build/<name>-<version>.zip
make version-patch   # (or -minor / -major) bump all version markers
```

Run a single unit test: `./vendor/bin/phpunit --filter test_method_name`.

## Testing

- **Unit** (`tests/Unit/`) — PHPUnit 11 + Brain Monkey + Mockery, no WordPress test suite and no database (WP functions are mocked).
- **End-to-end** (`tests/e2e/`), two independent Playwright suites, both run inside `Dockerfile.e2e` via the shared `scripts/run-e2e.sh` entrypoint:
  - `tests/e2e/functional/` — `@wp-playground/cli` dev-mounted source: activation, save-time rule validation, per-URL style scoping, a Navigation-block render canary, and variable substitution.
  - `tests/e2e/check-plugin/` — `@wp-playground/cli` fresh-install-from-zip: WordPress.org's official Plugin Check (PCP) against the packaged release zip.

CI runs both via `.github/workflows/e2e.yml`.

> **Known limitation:** the `check-plugin` suite currently fails on 5 of Plugin Check's 32 checks (`enqueued_scripts_size`, `enqueued_styles_size`, `enqueued_styles_scope`, `enqueued_scripts_scope`, `non_blocking_scripts`) — a limitation of `@wp-playground/cli`'s WASM-hosted environment, not of the plugin. Its CI job runs with `continue-on-error` pending resolution. See [`CLAUDE.md`](CLAUDE.md).

## Contributing

See [`CONTRIBUTORS.md`](CONTRIBUTORS.md) for the contributor list and [`CHANGELOG.md`](CHANGELOG.md) for release history. Follow the existing conventions: namespace `TheAnother\Plugin\MultiBrandGlobalStyles`, underscore-free StudlyCaps file/class names, PSR-4, WordPress + VIP coding standards. Lint and tests must pass (`make lint && make test`) before a change is merged.

## License

GPL-2.0-or-later. See <https://www.gnu.org/licenses/gpl-2.0.html>.
