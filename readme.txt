=== The Another Multi-Brand Global Styles ===
Contributors: theanother, ziontrooper
Tags: multi-brand, global styles, branding, theme-json, variables
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Define Brands — URL match rules with per-Brand global style overrides and content variables — on a single WordPress install.

== Description ==

Multi-Brand Global Styles lets administrators define Brands on a single WordPress installation. A Brand bundles three things:

* **URL match rules** — a Brand can cover whole domains (`auctionbill.com`, `beta.auctionbill.com`) or path sections of one or more sites (`site.com/farm/*`, `site2.com/farm/*`). The most specific rule wins, and prefixes match on path segment boundaries.
* **Global style overrides** — each Brand carries its own theme.json-shaped styles (colors, typography, spacing, per-element and per-block styles) merged over the active theme at request time. The theme itself is never modified and no child theme is created.
* **Content variables** — tokens like `%%brand.name%%` in post content, template parts, widgets, or menus are replaced with the matched Brand's values in the rendered page, so identical content renders with per-Brand text.

An optional default Brand acts as a fallback for requests that match no rule.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/the-another-multi-brand-global-styles` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Create Brands under the new "Brands" menu: add URL rules (one per line), content variables (`key = value` per line), styles JSON, and optionally mark one Brand as the default.

== Frequently Asked Questions ==

= Does this require WordPress Multisite? =

No. It runs on a single installation serving multiple domains and/or branded path sections.

= Who can manage Brands? =

Users with the `edit_theme_options` capability (administrators by default) — the same capability WordPress uses for global styles and theme editing.

= Can two Brands share the same URL rule? =

No — registering the exact same rule twice is rejected with an admin notice. Overlapping-but-different rules (e.g. `site.com` on one Brand and `site.com/farm` on another) are allowed by design.

== Changelog ==

= 0.1.0 =
* Initial release: Brand custom post type, URL rule matching (host and host+path), per-Brand global style overrides via theme.json filters, %%brand.*%% content variable substitution, default Brand fallback, duplicate-rule rejection.
