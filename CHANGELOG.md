# Changelog

All notable changes to The Another Multi-Brand Global Styles are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> How releases are cut: add notes under **[Unreleased]** as you work. Running `make version-patch|version-minor|version-major` promotes the `[Unreleased]` section here into a dated release entry, opens a fresh empty `[Unreleased]`, and retargets the comparison links below. (It separately appends a `* Version bump` stub to [`readme.txt`](readme.txt), the WordPress.org listing — replace that stub with the same notes when curating a release.)

## [Unreleased]

### Added
- Developer documentation: `CLAUDE.md`, `README.md`, `CONTRIBUTORS.md`, and this `CHANGELOG.md`.
- End-to-end test infrastructure: a native-PHP (+ official SQLite drop-in) Playwright functional suite (`tests/e2e/functional/`) and a WP-CLI-runner WordPress.org Plugin Check suite (`tests/e2e/check-plugin/`) covering all checks including the 5 runtime ones, both installing the plugin from the packaged `-test` zip built fresh each run, a dedicated e2e image (`tests/e2e/Dockerfile`), a shared `scripts/run-e2e.sh` entrypoint, and a GitHub Actions workflow.

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

[Unreleased]: https://github.com/theanother/the-another-multi-brand-global-styles/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/theanother/the-another-multi-brand-global-styles/releases/tag/v0.1.0
