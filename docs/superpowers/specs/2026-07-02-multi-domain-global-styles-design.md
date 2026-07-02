# Multi-Domain Global Styles — Design

**Plugin slug:** `the-another-multi-domain-global-styles`
**Date:** 2026-07-02
**Status:** Draft — pending review

## Overview

A standalone WordPress plugin that lets admins define "Brands" — bundles of URL match rules, global-style overrides, and content variables — on a single WordPress install. A Brand can be scoped to whole domains (e.g. Brand "AuctionBill" matching `auctionbill.com` and `beta.auctionbill.com`) or to path sections of one or more sites (e.g. Brand "Farm" matching `site.com/farm/*` and `site2.com/farm/*`). Wherever a Brand matches, its global-style overrides apply without touching the theme's `theme.json` or requiring a child theme, and its content variables (e.g. `%%brand.name%%`) get substituted into output wherever they appear — post content, block attributes, template parts, patterns, widgets, and menus.

This is a single WordPress installation serving multiple domains and/or branded sections (not WP Multisite, not separate installs). URL rules are not locked 1:1 to styling — many rules can share one Brand.

## Assumptions made during design (please confirm or correct)

These were decided by best judgment when you were unavailable to confirm live. Flag any of these during spec review if they're wrong:

1. **Bundled entity model** — one "Brand" record holds URL rules + style overrides + variables together (rather than variables being definable per individual rule within a shared style profile).
2. **Default/fallback Brand** — an optional flag lets one Brand act as the fallback when the requested URL doesn't match any rule, so a forgotten/misconfigured rule doesn't leak literal `%%tokens%%` or an unstyled page to visitors.
3. **Variable values are plain text, not HTML** — escaped via `esc_html()` before substitution. Injecting markup through a variable isn't supported in v1.
4. **Frontend-only substitution** — text-token replacement runs on frontend HTML page responses only (not REST API, admin-ajax, feeds, or emails).
5. **Entity name "Brand" and token prefix `%%brand.*%%`** — chosen as the rename for the earlier "Website" entity when page-level (URL path) scoping was added; both examples given (AuctionBill = a brand across two domains, Farm = a sub-brand across path sections of two sites) read naturally as brands. Rename is mechanical if a different term is preferred.

## Data model

One custom post type, `mdgs_brand` ("Brand"), is the single entity for everything:

- **URL rules** — a repeatable list of match rules attached to the Brand. Each rule is either a bare hostname (`auctionbill.com` — matches the whole domain) or a hostname plus path prefix (`site.com/farm` — matches that section only; a trailing `/*` or `/` in admin input is accepted and normalized away). Validated unique across all Brands at save time: the *exact same* rule can only belong to one Brand, but overlapping rules across Brands (e.g. `site.com` on Brand A and `site.com/farm` on Brand "Farm") are explicitly allowed — that overlap is the feature.
- **Global styles** — a real, dedicated `wp_global_styles` post per Brand (see "Style editing" below), holding the full theme.json-shaped settings/styles payload (palette, typography, spacing, elements, per-block-type overrides). This post is created automatically (empty/inherit-from-theme) the first time a Brand is saved, so every Brand always has exactly one — no null-post edge case for the override filter to handle.
- **Variables** — a repeatable key/value list (e.g. `name` → `Acme Auctions`, `phone` → `555-0100`), referenced in content as `%%brand.name%%`, `%%brand.phone%%`.
- **Default flag** — boolean marking this Brand as the fallback for unmatched requests (only one Brand may hold this flag at a time).

## URL rule matching

Each request is matched by hostname **and** path:

- The hostname from `$_SERVER['HTTP_HOST']` is normalized (lowercase, strip `www.`, strip port); the path from `$_SERVER['REQUEST_URI']` is normalized (strip query string, strip trailing slash, lowercase).
- Rules are looked up in a cached rule map (host → list of path prefixes → Brand ID; transient rebuilt whenever any Brand is saved, trashed, or deleted).
- Among the rules registered for the request's host, the **most specific match wins**: a host+path rule beats a host-only rule, and a longer path prefix beats a shorter one. Path prefixes match on segment boundaries — rule `site.com/farm` matches `/farm` and `/farm/anything`, but not `/farmhouse`.
- No rule matches → the default Brand (if set) applies; otherwise the theme's own defaults apply untouched and any `%%tokens%%` in content render literally.

## Global styles override (no child theme, no theme edits)

Hooks the core `wp_theme_json_data_theme` / `wp_theme_json_data_user` filters (WP 6.1+): once the request URL resolves to a Brand, that Brand's stored global-styles post content is merged over the theme's own via `update_with(...)`. This runs per-request, in memory — the theme's actual `theme.json` file is never modified, and no child theme is created.

Brand colors are registered as theme.json **color palette presets** (e.g. "Brand Primary"), not injected as raw hex values. When a content author picks that palette swatch in the block editor (rather than a manual hex picker), WordPress already renders it via `var(--wp--preset--color--brand-primary)` — so the same content automatically shows the correct color per Brand, with no text-substitution logic involved. This only covers colors chosen *through the palette* going forward; it does not retroactively reinterpret hex values already hardcoded in existing content.

## Style editing UX — full parity via real editor reuse

**Requirement:** full feature parity with WordPress's native Global Styles editor, including per-block-type style overrides (e.g. a different heading color just for the Quote block) — not just top-level colors/typography/spacing.

**Why not build a custom UI:** core's Styles panel auto-generates per-block-type controls from each block's declared `supports` in `block.json`. Replicating that in a custom UI means re-implementing block-style-support discovery and then maintaining it forever as blocks change across core and the other plugins in this environment (`the-another-blocks-for-dokan`, `aucteeno`, etc.). That's a bigger, more fragile build than the alternative below.

**Why not embed core's React Styles components directly:** Gutenberg deliberately locks its internal Global Styles UI components behind a private-API mechanism (`unlock()`), specifically to prevent third-party plugins from depending on them. Direct component reuse is not a viable path.

**Chosen approach:** each Brand gets a real `wp_global_styles` post. When an admin clicks "Edit styles" for a Brand (from our custom Brand edit screen), they're redirected into the actual Site Editor Styles route. For that admin's editing context, we hook the PHP-level resolution that decides which global-styles post is "the one being edited" — `WP_Theme_JSON_Resolver::get_user_global_styles_post_id()` and the ID the editor's REST calls bootstrap from — so it resolves to that Brand's post instead of the theme's single canonical one. The native editor then loads and saves through its normal REST flow: full parity, including every block-level panel, staying current automatically as blocks change, because it *is* the real editor.

Frontend rendering is separate and already covered above: the request URL resolves to a Brand, and that Brand's stored global-styles post content merges in via the theme.json filters at render time — independent of whatever admin editing context is active.

**Known risk:** this hooks a core resolver method that isn't a stable public API for this kind of use. It's plain PHP (not Gutenberg's locked JS internals), but its behavior could shift on a major WP core upgrade and will need a compatibility check/re-verification pass when the site upgrades WordPress major versions. This is an accepted, explicit trade-off in exchange for automatic, always-current per-block-type parity without reimplementing Gutenberg's block-style discovery ourselves.

## Variable substitution (text tokens)

A single whole-page output buffer, started on `template_redirect` and flushed on `shutdown`, for frontend HTML responses only (skipped for REST/admin-ajax/feeds). One `preg_replace_callback` pass matches `%%[a-z0-9_.]+%%` against the resolved Brand's variable map, run *after* post content, template parts, patterns, widgets, and menus are all assembled into final HTML — one mechanism covers every content source, instead of hooking a dozen individual WP filters (`the_content`, `widget_text_content`, `nav_menu_item_title`, `render_block`, etc.) and risking gaps in third-party block markup.

Undefined tokens are left as the literal `%%token%%` string rather than silently blanked, so a missing variable is obvious in QA. Variable values are escaped via `esc_html()` before substitution (safe in both text and attribute contexts).

## Code conventions

Follows the house style seen in `aucteeno-nexus` / `globalag-router`:
- PHP 8.3+, WordPress 6.9+
- Namespace: `TheAnother\Plugin\MultiDomainGlobalStyles`
- Composer-managed, PSR-4 autoload (`includes/`)
- Container-based DI (`Container` singleton + `HookManager`) rather than scattered `add_action` calls; all namespaces, class names, and file names use StudlyCaps without underscores
- Standalone plugin — no dependency on the other Aucteeno plugins

## Error handling & edge cases

- Registering a URL rule already owned by another Brand is rejected at save time with an admin-visible error (overlapping-but-different rules are allowed by design).
- A request URL with no matching rule and no default Brand configured: theme defaults apply, `%%tokens%%` render literally (visible signal of misconfiguration rather than a silent failure).
- Output buffer is skipped entirely for non-HTML responses (REST, admin-ajax, XML/JSON feeds) to avoid corrupting structured output.
- If the core resolver hook used for real-editor reuse fails to load (e.g. after an incompatible WP core upgrade), the Brand edit screen surfaces an explicit admin notice rather than silently falling back to editing the wrong (theme-wide) global styles record.

## Testing

PHPUnit, matching the existing `composer test` setup:
- URL rule normalization/matching edge cases (www, ports, case, trailing /*, path segment boundaries, most-specific-match precedence, duplicate rejection)
- theme.json merge correctness (palette/typography/spacing presence for matched Brand; absence for unmatched)
- Global-styles-post-ID resolution redirection (admin editing context resolves to the correct Brand's post, not the theme's canonical one)
- Variable-regex correctness (adjacent/nested tokens, undefined tokens, HTML escaping)
- Output buffer does not run on non-HTML responses, and does not interfere with full-page caching (cache keys must vary by full URL, which page caches do by default)

## Out of scope for v1

- Retroactively converting hardcoded hex values in existing content to palette references
- Non-frontend substitution contexts (emails, PDFs, REST responses)
- Per-rule variable overrides within a shared Brand (variables are bundled per-Brand, per the assumption above)
