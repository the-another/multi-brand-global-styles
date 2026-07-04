# Contributors

The Another Multi-Brand Global Styles is maintained by The Another and the people listed below.

The WordPress.org contributor usernames are mirrored in the `Contributors:` header of [`readme.txt`](readme.txt).

## Maintainers

| Name / handle | WordPress.org | Role |
| --- | --- | --- |
| The Another | [`theanother`](https://profiles.wordpress.org/theanother/) | Author / maintainer |
| ziontrooper | [`ziontrooper`](https://profiles.wordpress.org/ziontrooper/) | Contributor |

## Contact

- Website: <https://theanother.org>
- Email: <hello@theanother.org>
- Plugin homepage: <https://theanother.org/plugin/multi-brand-global-styles/>

## Architecture

Container-based dependency injection (`Container` singleton + `HookManager`), with code organized by domain, not technical layer:

```
includes/
├── Container.php            # DI container (lazy factories, singletons)
├── HookManager.php          # tracked add_action/add_filter registration
├── Plugin.php               # orchestrator — the single hook-wiring map
├── Brand/                   # the mbgs_brand aggregate + URL matching
│   ├── BrandPostType.php    #   CPT, meta boxes (incl. identity + image replacements), save handler
│   ├── UrlRuleRegistry.php  #   rule normalize/dedupe/conflict + cached rule map
│   ├── BrandRepository.php  #   read helpers
│   ├── BrandResolver.php    #   HTTP_HOST + REQUEST_URI → Brand id, + ?mbgs_preview_brand override
│   └── AdminNotices.php     #   duplicate-rule rejection notice
├── GlobalStyles/            # per-Brand style override
│   ├── GlobalStylesPostService.php   # the per-Brand wp_global_styles post
│   └── GlobalStylesOverride.php      # wp_theme_json_data_user filter
├── ContentVariables/        # %%brand.*%% substitution
│   ├── VariableParser.php
│   └── VariableSubstitutionService.php   # token replacement (runs inside Rendering/PageBuffer)
├── Identity/                # per-Brand logo/title/tagline/favicon
│   └── SiteIdentityOverride.php      # option/theme-mod filters
├── Media/                   # per-Brand image replacement
│   ├── ImageMapBuilder.php  #   pairs → precomputed URL map
│   ├── ImageUrlReplacer.php #   HTML URL swap at render
│   └── AttachmentLifecycle.php # keeps URL maps in sync with attachment changes
├── Rendering/                # the frontend output buffer
│   └── PageBuffer.php        #   template_redirect buffer, runs the transformers above
├── Rest/                     # editor-facing REST surface
│   └── ReplacementsController.php   # mbgs/v1 namespace
└── Editor/                   # block editor integration
    └── EditorAssets.php      # enqueues the built assets/build/ bundle
```

The `mbgs_brand` custom post type is the aggregate root and is gated behind the `edit_theme_options` capability. See [`CLAUDE.md`](CLAUDE.md) for the full data model (meta keys, hooks, transients).

## Development

The canonical toolchain/suite logic lives in portable shell scripts — `scripts/setup/{unit,e2e}.sh` install the toolchain (all version pins live there), `scripts/tests/{lint,unit,e2e,plugin-check}.sh` run the suites. The `make` targets run those scripts inside Docker for local reproducibility (two `ubuntu:24.04` images: `tests/Unit/Dockerfile` for lint/test/release, `tests/e2e/Dockerfile` for the end-to-end suites); GitHub Actions runs the same scripts natively on `ubuntu-24.04` runners.

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

The block-editor UI (Image-block replacements panel, Brand preview sidebar) is built with `@wordpress/scripts` from source in `src/` to `assets/build/`: `npm run build:editor` (one-off) or `npm run start:editor` (watch mode). You don't need to run this by hand before `make release`/`make test-e2e`/`make check-plugin` — the zip pipeline (`npm run plugin-zip` / `plugin-zip:check`) builds the editor bundle automatically before packaging.

## Testing

- **Unit** (`tests/Unit/`) — PHPUnit 11 + Brain Monkey + Mockery, no WordPress test suite and no database (WP functions are mocked).
- **End-to-end** (`tests/e2e/`), two independent suites, run via `scripts/tests/e2e.sh` / `scripts/tests/plugin-check.sh` (shared zip-build pre-flight in `scripts/tests/lib/build-test-zip.sh`), both installing the plugin from the packaged `-test` zip built fresh each run:
  - `tests/e2e/functional/` — native PHP 8.3 + the official SQLite drop-in, driven by Playwright: activation, save-time rule validation, per-URL style scoping, a Navigation-block render canary, variable substitution, brand identity overrides, image replacement, and editor preview override.
  - `tests/e2e/check-plugin/` — no browser: a plain Node runner provisions WordPress the same way and runs WordPress.org's official Plugin Check (PCP) natively against the packaged zip — all checks, including the 5 runtime ones. ERROR findings gate; WARNINGs are reported only.

CI: `.github/workflows/ci.yml` gates PRs (PHPCS, PHPUnit, both e2e suites); `.github/workflows/release.yml` re-runs the same four gates on every push to `master`, then builds the release zip, tags `v<version>` (from `package.json`), and publishes a GitHub Release with the zip attached (an already-existing tag skips the release steps).

## Conventions

Follow the existing conventions: namespace `TheAnother\Plugin\MultiBrandGlobalStyles`, underscore-free StudlyCaps file/class names, PSR-4, WordPress + VIP coding standards. Lint and tests must pass (`make lint && make test`) before a change is merged.

## Adding yourself

When you land a change, add your name to the table above and your WordPress.org username to the `Contributors:` line in [`readme.txt`](readme.txt) (comma-separated). Keep the two lists in sync.
