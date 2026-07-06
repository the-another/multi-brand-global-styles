=== The Another Multi-Brand Global Styles ===
Contributors: theanother, ziontrooper
Tags: multi-brand, global styles, branding, theme-json, variables
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Define Brands — URL match rules with per-Brand global style overrides and content variables — on a single WordPress install.

== Description ==

Multi-Brand Global Styles lets administrators define Brands on a single WordPress installation. A Brand bundles:

* **URL match rules** — a Brand can cover whole domains (`auctionbill.com`, `beta.auctionbill.com`) or path sections of one or more sites (`site.com/farm/*`, `site2.com/farm/*`). The most specific rule wins, and prefixes match on path segment boundaries.
* **Global style overrides** — each Brand carries its own theme.json-shaped styles (colors, typography, spacing, per-element and per-block styles) merged over the active theme at request time. The theme itself is never modified and no child theme is created.
* **Content variables** — tokens like `%%brand.name%%` in post content, template parts, widgets, or menus are replaced with the matched Brand's values in the rendered page, so identical content renders with per-Brand text.
* **Brand identity** — an optional per-Brand logo, site title, tagline, and favicon override the site's own, wherever that Brand's rules match.
* **Image replacements** — swap any image for a per-Brand replacement, either from a central "Image Replacements" meta box on the Brand or right from the Image block's inspector panel while editing.
* **Editor preview** — a Brand preview sidebar in the block editor swaps images/identity on the canvas for a quick look, plus a frontend preview link (`?mbgs_preview_brand=`) for admins to see a page exactly as a given Brand would render it.

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




= 0.3.0 - 2026-07-06 =
* Add: per-Brand canonical host form (www or apex) for the URL Rewrite option — a new admin radio lets a Brand declare its preferred form; visitors browsing the non-preferred form are 301-redirected to it before the page renders, so the existing URL-rewrite pass then applies for free.

= 0.2.0 - 2026-07-05 =
* Add: per-Brand URL rewrite option — links pointing at the canonical site address are rewritten in the rendered page to the domain being browsed (domain/port only, never paths), covering absolute, protocol-relative, and JSON-escaped URLs.
* Add: "Force https" companion option for rewritten URLs; when off, rewritten links match the visitor's current scheme.
* Add: WordPress's canonical redirect is guarded for rewriting Brands, so visitors stay on the Brand domain instead of being bounced back to the canonical one.
* Refactor: all per-Brand data consolidated into a single settings entry behind a typed, cached settings object — per-request memoization plus persistent caches that are dropped automatically on save, trash, and delete.

= 0.1.1 - 2026-07-04 =
* Add: Brand identity overrides — per-Brand logo, site title, tagline, and favicon applied wherever the Brand's rules match.
* Add: per-Brand image replacements — swap any image for a Brand-specific counterpart, from the "Image Replacements" meta box on the Brand or from the Image block's inspector panel; replacements are precomputed at save time so rendering stays fast.
* Add: block-editor Brand preview sidebar (image/identity swaps on the canvas) and an admin-only `?mbgs_preview_brand` frontend preview link.
* Add: "View current global styles (JSON)" reference link in the Brand styles meta box, showing the active theme's global styles as a reference for valid fields and values.
* Fix: array input to the Brand styles-JSON field caused a PHP fatal on save; non-string input is now rejected.
* Fix: cleared all Plugin Check / PHPCS warnings.
* Refactor: variable substitution and image URL replacement now run through a single frontend output buffer.
* Chore: end-to-end test suites (Playwright functional + WordPress.org Plugin Check) and an automated GitHub release pipeline.

= 0.1.0 =
* Initial release: Brand custom post type, URL rule matching (host and host+path), per-Brand global style overrides via theme.json filters, %%brand.*%% content variable substitution, default Brand fallback, duplicate-rule rejection.
