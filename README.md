# The Another Multi-Brand Global Styles

Define **Brands** — URL match rules with per-Brand global-style overrides and content variables — on a **single** WordPress installation serving multiple domains and/or path sections.

- **Requires:** WordPress 6.9+, PHP 8.3+
- **License:** GPL-2.0-or-later
- **Homepage:** <https://theanother.org/plugin/multi-brand-global-styles/>

> For the WordPress.org listing, see [`readme.txt`](readme.txt). For development setup, architecture, testing, and how to contribute, see [`CONTRIBUTORS.md`](CONTRIBUTORS.md).

## What it does

A **Brand** bundles three things and applies them wherever its URL rules match the incoming request — without touching the theme's `theme.json` or creating a child theme:

- **URL match rules** — whole domains (`auctionbill.com`, `beta.auctionbill.com`) or path sections of one or more sites (`site.com/farm/*`, `site2.com/farm/*`). Most-specific rule wins: host+path beats host-only, longer path prefix beats shorter, and prefixes match on path-segment boundaries.
- **Global style overrides** — each Brand carries its own theme.json-shaped styles (colors, typography, spacing, per-element and per-block), merged over the active theme at render time via the `wp_theme_json_data_user` filter.
- **Content variables** — tokens like `%%brand.name%%` in post content, template parts, widgets, or menus are substituted with the matched Brand's values in the rendered HTML.
- **Brand identity** — an optional per-Brand logo, site title, tagline, and favicon.
- **Image replacements** — swap any image for a per-Brand replacement, from a central meta box on the Brand or from the Image block's inspector panel.
- **Editor preview** — a block-editor Brand preview sidebar (canvas image/identity swap) plus a frontend preview link for admins.

An optional **default Brand** acts as the fallback for requests that match no rule. This is not WP Multisite and not separate installs — it's one install, one database, many Brands.

Only users with the `edit_theme_options` capability (admins/theme editors) can create or edit Brands.

## Contributing

Contributor guidelines, the code architecture, and all development/testing commands live in [`CONTRIBUTORS.md`](CONTRIBUTORS.md). Release history is in [`CHANGELOG.md`](CHANGELOG.md).

## License

GPL-2.0-or-later. See <https://www.gnu.org/licenses/gpl-2.0.html>.
