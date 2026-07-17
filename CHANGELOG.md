# Changelog

All notable changes to The Another Multi-Brand Global Styles are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> How releases are cut: add notes under **[Unreleased]** as you work. Running `make version-patch|version-minor|version-major` promotes the `[Unreleased]` section here into a dated release entry, opens a fresh empty `[Unreleased]`, and retargets the comparison links below. (It separately appends a `* Version bump` stub to [`readme.txt`](readme.txt), the WordPress.org listing — replace that stub with the same notes when curating a release.)

## [Unreleased]

## [0.3.4] - 2026-07-17

### Fixed
- **Content fetched over the REST API on a Brand domain linked back to the canonical domain.** HTML fragments served over REST (infinite-scroll / AJAX card batches — e.g. an auction query loop's `format=html` endpoint) never pass through the page buffer, so on an opted-in Brand host the first server-rendered page linked to the Brand domain but every subsequently loaded batch carried canonical-host permalinks. `Urls\HostRewriter` now applies the authority rewrite to served REST payloads on `rest_pre_echo_response` — the REST egress point: it fires only for responses actually served over HTTP (internal `rest_do_request()` callers never see rewritten data) and it receives the payload after `_links`/`_embedded` are merged, which `rest_post_dispatch` would miss. Scoped to GET/HEAD reads and never `context=edit`, so block-editor reads can't save Brand-host URLs back into post content; a single early Brand resolution gates the walk, so non-Brand REST traffic pays one lookup. REST response *headers* (`Link`, `X-WP-Total`) still carry the canonical host — only the JSON body is rewritten.
- **Duplicate-rule e2e hardened against WordPress core update nags.** Once a newer core than the pinned e2e WordPress is released, every wp-admin page grows an update nag that is also a `.notice-warning`, failing the conflict-notice assertions on Playwright's strict-mode ambiguity (seen with WP 7.0.2). The locator now excludes `.update-nag`.

## [0.3.3] - 2026-07-07

### Fixed
- **Login on a Brand domain bounced the visitor back to the canonical domain.** Server-side redirects (`Location:` headers) never pass through the page buffer, so the URL-rewrite option didn't cover them — a WooCommerce My Account or `wp-login.php` login on a rewriting Brand's host sent the visitor to the origin domain, twice over: `wp_validate_redirect()` allowlists only the canonical home host, so a redirect target already on the Brand host (WooCommerce's referer-based login redirect, `wp-login.php`'s `redirect_to`) was rejected and swapped for its canonical-host fallback; and that fallback (built from `home_url()`, like logout/add-to-cart/PRG targets generally) carried the canonical host with nothing to rewrite it. `Urls\HostRewriter` now adds the browsed Brand host to `allowed_redirect_hosts` and rewrites canonical-host `Location:` targets to the browsed authority on the `wp_redirect` filter (frontend only). Loop guard: a GET/HEAD redirect whose rewritten target is exactly the current URL is left untouched — redirecting a GET to itself can only loop, and the only legitimate source of such a target is a www/apex canonicalizer (`Urls\HostCanonicalizer` or a web-server rule) whose cross-form 301 must survive; the comparison is scheme-strict on purpose so https-forcing redirects still move onto the Brand host.

## [0.3.2] - 2026-07-06

### Changed
- **Cheaper per-request image replacement.** `Media\ImageUrlReplacer` now uses a single `strtr()` pass instead of `str_replace()` with parallel key/value arrays. PHP's array `str_replace()` scans the whole page once *per mapping* (cost ∝ page-size × number of image replacements); `strtr()` scans once total and substitutes the longest match at each position, so the cost is ∝ page-size regardless of how many replacements a Brand defines — and it can't cascade a replacement URL into a later mapping. On an uncached site with ~20 replacements this dropped the pass from ~240 µs to ~80 µs on a 64 KB page (and ~2.0 ms to ~0.7 ms on 512 KB). The map's longest-first ordering is no longer relied upon here (`strtr()` is inherently longest-match).
- **`Urls\HostRewriter` rewrites all canonical hosts in one pass.** When `home` and `siteurl` resolve to different hosts it previously ran one full regex pass per host; the hosts are now combined into a single alternation so the page is scanned once. Same host-boundary safety and URL-form coverage (absolute, protocol-relative, JSON-escaped, any port).

### Fixed
- **Brand custom CSS silently dropped on save** on security-hardened sites (`DISALLOW_UNFILTERED_HTML`, or a security plugin that revokes `unfiltered_html`), where even the administrator lacks `edit_css`. The 0.3.1 preset fix rescued a Brand's palette but not its top-level custom CSS (`styles.css` — the "Additional CSS" a theme like GlobalAg ships): core's `wp_filter_global_styles_post()` keeps `css` only for users with `edit_css`, so it kept vanishing while the palette came back, leaving the Brand's styles half-applied. `GlobalStyles\GlobalStylesPostService::update_global_styles()` now applies core's value-level safety itself (`WP_Theme_JSON::remove_insecure_properties()` — origin-keyed presets kept, style values `safecss`-filtered), re-attaches the Brand's own custom CSS sanitized against `</style>`/markup breakout, and suspends only `wp_filter_global_styles_post` (at its real registered priority) for that one controlled write so the CSS survives. The Brand CPT is already gated at `edit_theme_options`, so this keeps the operator's own CSS without weakening any other sanitization.
- **Brand-host asset URLs inside custom CSS no longer stranded on the canonical host.** Because the custom CSS above was being dropped before it reached the page, any canonical-host asset URLs it referenced (e.g. `@font-face`/`background: url(...)` fonts and images) never made it into the buffer for `Urls\HostRewriter` to rewrite — so on a rewriting Brand's domain those assets appeared to load cross-host. With the custom CSS preserved, `HostRewriter` rewrites its canonical-host URLs to the browsed Brand host along with the rest of the page, so the assets load same-host.

## [0.3.1] - 2026-07-06

### Added
- **Cross-origin (CORS) headers for cross-brand assets** — a new `Cors\CorsHeaders` service, hooked to `send_headers`, validates the request `Origin` against the set of known hosts (the canonical `home`/`siteurl` plus every host from published Brands' URL rules) and reflects a matching origin back as `Access-Control-Allow-Origin` with `Vary: Origin` (both `http` and `https` accepted per host). This unblocks assets (CSS, JS, fonts) that a page rendered on a Brand domain loads from the canonical domain — previously handled externally via `WP_BRAND_HOSTS`, now derived from the plugin's own Brand configuration. Skipped for admin/AJAX/REST requests.

### Fixed
- **Global styles silently dropped on save** for any user without the `unfiltered_html` capability (multisite site admins; security-hardened single sites). Core registers `wp_filter_global_styles_post()` on `content_save_pre` for those users, which re-runs `WP_Theme_JSON::remove_insecure_properties()` over the saved post and preserves only origin-keyed presets — so a flat `settings.color.palette` list (what an admin pastes into the Global Styles box) collapsed to `{version, isGlobalStylesUserThemeJSON}`. `GlobalStyles\GlobalStylesPostService::update_global_styles()` now normalizes the submitted theme.json through `WP_Theme_JSON` before writing, so presets are stored in the origin-keyed form that survives that filter (and renders identically). kses still runs on the write — unsafe styles are sanitized exactly as core intends; the fix only hands core data in the shape it keeps.
- **Redirect loop** between the www and apex host forms when a web server (nginx/Apache) or another plugin canonicalizes the host in the opposite direction to a Brand's `canonical_host_form`. `Urls\HostCanonicalizer` now defers to the WordPress Site Address: for the install's own domain it will not 301 to a form that opposes the `home`/`siteurl` host form, so it can no longer fight core's `redirect_canonical` or a Site-Address-following web server. Multi-domain Brands (a different apex than the install) are unaffected — their chosen form is always honored. See `docs/superpowers/specs/2026-07-06-host-form-canonicalization-loop-fix-design.md`.

## [0.3.0] - 2026-07-06

### Added
- Per-Brand **canonical host form** (www/apex) for the URL Rewrite option: a new admin radio (`mbgs_url_rewrite_host_form`, stored as `canonical_host_form` in `url_rewrite`) lets a Brand declare `www` or `apex` as its preferred form. A new `Urls\HostCanonicalizer`, hooked to `template_redirect` at priority 1 (before `PageBuffer` opens its buffer), 301-redirects visitors on the non-preferred form to the preferred one; `Urls\HostForm` supplies the pure www↔apex transforms and `Urls\RequestAuthority` the shared current-request-authority helper (also adopted by `HostRewriter`). After the redirect the browsed host is already the preferred form, so `HostRewriter`'s existing canonical→browsed rewrite applies with no further changes.

## [0.2.0] - 2026-07-05

### Added
- Per-Brand **URL rewrite** option (new "URL Rewrite" meta box, opt-in): canonical `home`/`siteurl` URLs in the rendered HTML are rewritten to the domain being browsed — host/port only, never paths — covering absolute, protocol-relative, and JSON-escaped forms, via a new `Urls\HostRewriter` transformer that runs last in the page buffer. A companion "Force https" toggle upgrades rewritten URLs; without it, the visitor's current scheme is matched.
- `redirect_canonical` guard for rewriting Brands: host-only canonical redirects are cancelled so visitors stay on the Brand domain, while path/query canonicalization (and force-https upgrade redirects) still work on the browsed host.

### Changed
- All per-Brand data consolidated into a single `_mbgs_settings` meta entry, hydrated through a readonly `BrandSettings` value object by `BrandRepository` — one gateway with a per-request memo, per-Brand `mbgs_brand_settings_<id>` transients, and an `mbgs_default_brand` transient (all dropped automatically on Brand save, trash, untrash, and delete). The old per-concern `_mbgs_*` meta keys were removed outright (pre-production; no migration shipped).
- Functional e2e environment hardened for host-dependent testing: `wp server`'s router follows the incoming Host header, so the suite now pins the canonical URLs via an mu-plugin, and a new spec exercises URL rewriting using `127.0.0.1` as a second domain.

## [0.1.1] - 2026-07-04

### Added
- Developer documentation: `CLAUDE.md`, `README.md`, `CONTRIBUTORS.md`, and this `CHANGELOG.md`.
- End-to-end test infrastructure: a native-PHP (+ official SQLite drop-in) Playwright functional suite (`tests/e2e/functional/`) and a WP-CLI-runner WordPress.org Plugin Check suite (`tests/e2e/check-plugin/`) covering all checks including the 5 runtime ones, both installing the plugin from the packaged `-test` zip built fresh each run, a dedicated e2e image (`tests/e2e/Dockerfile`), shared suite-runner scripts (`scripts/tests/`), and a GitHub Actions PR gate.
- GitHub release pipeline (`.github/workflows/release.yml`): every push to `master` re-runs the full gate (PHPCS, PHPUnit, both e2e suites), builds the release zip, tags `v<version>` from `package.json`, and publishes a GitHub Release — powered by portable `scripts/setup/` + `scripts/tests/` shell scripts that run identically inside the local Docker images (now `ubuntu:24.04`-based) and natively on GitHub's `ubuntu-24.04` runners.
- Brand identity overrides — per-Brand logo, site title, tagline, and favicon (site icon), applied on the frontend via `pre_option_site_logo`, `theme_mod_custom_logo`, `pre_option_blogname`, `pre_option_blogdescription`, and `pre_option_site_icon` filters so core builds all the surrounding markup (srcset, alt, icon sizes).
- Per-Brand image replacements — swap any image for a per-Brand counterpart, set from a new "Image Replacements" meta box on the Brand or from an inspector panel on the core Image block. Replacement pairs are precomputed at save time into a flat URL map (full size + every registered size variant) so rendering costs one meta read and one `str_replace()` pass, not attachment queries; the map is kept truthful across attachment metadata edits and deletions.
- Block-editor Brand preview — a sidebar for picking a Brand and previewing its image/identity swaps directly on the editor canvas, plus a capability-gated `?mbgs_preview_brand=<id>` frontend preview link so admins can see a page exactly as a given Brand would render it (only honored after `init`; falls back to normal resolution before then).
- New `mbgs/v1` REST namespace (`ReplacementsController`) behind the editor UIs: `GET`/`POST /replacements` (per-image replacement rows), `GET /brands`, `GET /preview-map` — all gated by `edit_theme_options`.
- `@wordpress/scripts` editor build (`npm run build:editor` / `start:editor`, source in `src/`, output to `assets/build/`), wired into the release/test zip pipeline so the editor bundle is always built before packaging.
- A "View current global styles (JSON)" reference link under the raw-JSON textarea in the Brand styles meta box, opening core's `GET /wp/v2/global-styles/themes/{stylesheet}` REST route in a new tab so admins can see the active theme's valid style fields/values while composing overrides.

### Changed
- The frontend output buffer was refactored into a single `PageBuffer` (`includes/Rendering/`) that runs an ordered list of transformers — variable substitution, then image URL replacement — in one `ob_start()` pass. `VariableSubstitutionService` no longer owns the buffer itself; it now exposes `replace()` for `PageBuffer` to call.

### Fixed
- Array input to the Brand styles-JSON admin field (`mbgs_styles_json[]=`) caused a PHP `TypeError` fatal in the save handler; non-string input is now rejected.
- Cleared all six Plugin Check / PHPCS warnings (an exclusionary `post__not_in` query, default-Brand flag-lookup slow-query warnings, and the unsanitized styles-JSON input) — `make check-plugin` now reports 0 errors, 0 warnings.

## [0.1.0] - 2026-07-02

### Added
- Initial release.
- `mbgs_brand` custom post type as the Brand aggregate root, gated behind the `edit_theme_options` capability.
- URL rule matching (host and host+path), with most-specific-rule-wins resolution and path-segment-boundary prefix matching.
- Per-Brand global style overrides merged over the active theme at render time via the `wp_theme_json_data_user` filter — no theme edits, no child theme.
- `%%brand.*%%` content-variable substitution across the rendered page (skipped for REST, admin-ajax, and feeds).
- Optional default Brand as the fallback for unmatched requests.
- Duplicate-rule rejection with an admin notice; overlapping-but-different rules allowed by design.

[Unreleased]: https://github.com/theanother/the-another-multi-brand-global-styles/compare/v0.3.4...HEAD
[0.3.4]: https://github.com/theanother/the-another-multi-brand-global-styles/compare/v0.3.3...v0.3.4
[0.3.3]: https://github.com/theanother/the-another-multi-brand-global-styles/compare/v0.3.2...v0.3.3
[0.3.2]: https://github.com/theanother/the-another-multi-brand-global-styles/compare/v0.3.1...v0.3.2
[0.3.1]: https://github.com/theanother/the-another-multi-brand-global-styles/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/theanother/the-another-multi-brand-global-styles/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/theanother/the-another-multi-brand-global-styles/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/theanother/the-another-multi-brand-global-styles/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/theanother/the-another-multi-brand-global-styles/releases/tag/v0.1.0
