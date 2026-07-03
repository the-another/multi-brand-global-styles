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

All quality/build targets run inside Docker for a reproducible toolchain. Two images: `tests/Unit/Dockerfile` (Alpine + PHP 8.3 + Composer + wp-cli + Node) for lint/test/release, and `tests/e2e/Dockerfile` (the above plus Chromium and ffmpeg) for the end-to-end suites.

```bash
make install-dev     # composer install (with dev deps)
make lint            # PHPCS (WordPress + VIP-Go standards; errors gate)
make format          # PHPCBF (modifies source)
make test            # PHPUnit unit tests
make test-e2e        # functional native-PHP + Playwright suite
make check-plugin    # WordPress.org Plugin Check against the packaged zip
make release         # lint + test gates, then build/<name>-<version>.zip
make version-patch   # (or -minor / -major) bump all version markers
```

Run a single unit test: `./vendor/bin/phpunit --filter test_method_name`.

## Testing

- **Unit** (`tests/Unit/`) — PHPUnit 11 + Brain Monkey + Mockery, no WordPress test suite and no database (WP functions are mocked).
- **End-to-end** (`tests/e2e/`), two independent suites, both run inside `tests/e2e/Dockerfile` via the shared `scripts/run-e2e.sh` entrypoint, and both installing the plugin from the packaged `-test` zip built fresh each run:
  - `tests/e2e/functional/` — native PHP 8.3 + the official SQLite drop-in, driven by Playwright: activation, save-time rule validation, per-URL style scoping, a Navigation-block render canary, and variable substitution.
  - `tests/e2e/check-plugin/` — no browser: a plain Node runner provisions WordPress the same way and runs WordPress.org's official Plugin Check (PCP) natively against the packaged zip — all checks, including the 5 runtime ones. ERROR findings gate; WARNINGs are reported only.

CI runs both via `.github/workflows/e2e.yml`.

## Contributing

See [`CONTRIBUTORS.md`](CONTRIBUTORS.md) for the contributor list and [`CHANGELOG.md`](CHANGELOG.md) for release history. Follow the existing conventions: namespace `TheAnother\Plugin\MultiBrandGlobalStyles`, underscore-free StudlyCaps file/class names, PSR-4, WordPress + VIP coding standards. Lint and tests must pass (`make lint && make test`) before a change is merged.

## License

GPL-2.0-or-later. See <https://www.gnu.org/licenses/gpl-2.0.html>.
