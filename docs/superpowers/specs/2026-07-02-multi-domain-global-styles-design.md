# Multi-Domain Global Styles — Design

**Plugin slug:** `the-another-multi-domain-global-styles`
**Date:** 2026-07-02
**Status:** Draft — pending review

## Overview

A standalone WordPress plugin that lets admins register additional domains pointing at this single WordPress install, group those domains under per-domain "Website" style profiles that override global styles without touching the theme's `theme.json` or requiring a child theme, and define per-Website content variables (e.g. `%%website.name%%`) that get substituted into output wherever they appear — post content, block attributes, template parts, patterns, widgets, and menus.

This is a single WordPress installation serving multiple domains (not WP Multisite, not separate installs). Domains are not locked 1:1 to styling — multiple domains can share one style/variable profile.

## Assumptions made during design (please confirm or correct)

These were decided by best judgment when you were unavailable to confirm live. Flag any of these during spec review if they're wrong:

1. **Bundled entity model** — one "Website" record holds domains + style overrides + variables together (rather than variables being definable per individual domain within a shared style profile).
2. **Default/fallback Website** — an optional flag lets one Website act as the fallback when the requested hostname doesn't match any registered domain, so a forgotten/misconfigured domain doesn't leak literal `%%tokens%%` or an unstyled page to visitors.
3. **Variable values are plain text, not HTML** — escaped via `esc_html()` before substitution. Injecting markup through a variable isn't supported in v1.
4. **Frontend-only substitution** — text-token replacement runs on frontend HTML page responses only (not REST API, admin-ajax, feeds, or emails).

## Data model

One custom post type, `mdgs_website` ("Website"), is the single entity for everything:

- **Domains** — a repeatable list of hostnames attached to the Website. Validated unique across all Websites at save time (a hostname can only belong to one Website).
- **Global styles** — a real, dedicated `wp_global_styles` post per Website (see "Style editing" below), holding the full theme.json-shaped settings/styles payload (palette, typography, spacing, elements, per-block-type overrides). This post is created automatically (empty/inherit-from-theme) the first time a Website is saved, so every Website always has exactly one — no null-post edge case for the override filter to handle.
- **Variables** — a repeatable key/value list (e.g. `name` → `Acme Auctions`, `phone` → `555-0100`), referenced in content as `%%website.name%%`, `%%website.phone%%`.
- **Default flag** — boolean marking this Website as the fallback for unmatched domains (only one Website may hold this flag at a time).

## Domain routing

Each request's `$_SERVER['HTTP_HOST']` is normalized (lowercase, strip `www.`, strip port) and looked up against a cached domain → Website map (object cache / transient, rebuilt whenever any Website is saved). No match → the default Website (if set) applies; otherwise the theme's own defaults apply untouched and any `%%tokens%%` in content render literally.

## Global styles override (no child theme, no theme edits)

Hooks the core `wp_theme_json_data_theme` / `wp_theme_json_data_user` filters (WP 6.1+): once a domain resolves to a Website, that Website's stored global-styles post content is merged over the theme's own via `update_with(...)`. This runs per-request, in memory — the theme's actual `theme.json` file is never modified, and no child theme is created.

Brand colors are registered as theme.json **color palette presets** (e.g. "Brand Primary"), not injected as raw hex values. When a content author picks that palette swatch in the block editor (rather than a manual hex picker), WordPress already renders it via `var(--wp--preset--color--brand-primary)` — so the same content automatically shows the correct color per domain, with no text-substitution logic involved. This only covers colors chosen *through the palette* going forward; it does not retroactively reinterpret hex values already hardcoded in existing content.

## Style editing UX — full parity via real editor reuse

**Requirement:** full feature parity with WordPress's native Global Styles editor, including per-block-type style overrides (e.g. a different heading color just for the Quote block) — not just top-level colors/typography/spacing.

**Why not build a custom UI:** core's Styles panel auto-generates per-block-type controls from each block's declared `supports` in `block.json`. Replicating that in a custom UI means re-implementing block-style-support discovery and then maintaining it forever as blocks change across core and the other plugins in this environment (`the-another-blocks-for-dokan`, `aucteeno`, etc.). That's a bigger, more fragile build than the alternative below.

**Why not embed core's React Styles components directly:** Gutenberg deliberately locks its internal Global Styles UI components behind a private-API mechanism (`unlock()`), specifically to prevent third-party plugins from depending on them. Direct component reuse is not a viable path.

**Chosen approach:** each Website gets a real `wp_global_styles` post. When an admin clicks "Edit styles" for a Website (from our custom Website edit screen), they're redirected into the actual Site Editor Styles route. For that admin's editing context, we hook the PHP-level resolution that decides which global-styles post is "the one being edited" — `WP_Theme_JSON_Resolver::get_user_global_styles_post_id()` and the ID the editor's REST calls bootstrap from — so it resolves to that Website's post instead of the theme's single canonical one. The native editor then loads and saves through its normal REST flow: full parity, including every block-level panel, staying current automatically as blocks change, because it *is* the real editor.

Frontend rendering is separate and already covered above: domain resolves to a Website, and that Website's stored global-styles post content merges in via the theme.json filters at render time — independent of whatever admin editing context is active.

**Known risk:** this hooks a core resolver method that isn't a stable public API for this kind of use. It's plain PHP (not Gutenberg's locked JS internals), but its behavior could shift on a major WP core upgrade and will need a compatibility check/re-verification pass when the site upgrades WordPress major versions. This is an accepted, explicit trade-off in exchange for automatic, always-current per-block-type parity without reimplementing Gutenberg's block-style discovery ourselves.

## Variable substitution (text tokens)

A single whole-page output buffer, started on `template_redirect` and flushed on `shutdown`, for frontend HTML responses only (skipped for REST/admin-ajax/feeds). One `preg_replace_callback` pass matches `%%[a-z0-9_.]+%%` against the resolved Website's variable map, run *after* post content, template parts, patterns, widgets, and menus are all assembled into final HTML — one mechanism covers every content source, instead of hooking a dozen individual WP filters (`the_content`, `widget_text_content`, `nav_menu_item_title`, `render_block`, etc.) and risking gaps in third-party block markup.

Undefined tokens are left as the literal `%%token%%` string rather than silently blanked, so a missing variable is obvious in QA. Variable values are escaped via `esc_html()` before substitution (safe in both text and attribute contexts).

## Code conventions

Follows the house style seen in `aucteeno-nexus` / `globalag-router`:
- PHP 8.3+, WordPress 6.9+
- Namespace: `TheAnother\Plugin\MultiDomainGlobalStyles`
- Composer-managed, PSR-4 autoload (`includes/`)
- Container-based DI (`Container` singleton + `HookManager`) rather than scattered `add_action` calls; all namespaces, class names, and file names use StudlyCaps without underscores
- Standalone plugin — no dependency on the other Aucteeno plugins

## Error handling & edge cases

- Duplicate domain registration across Websites is rejected at save time with an admin-visible error.
- A hostname with no matching Website and no default Website configured: theme defaults apply, `%%tokens%%` render literally (visible signal of misconfiguration rather than a silent failure).
- Output buffer is skipped entirely for non-HTML responses (REST, admin-ajax, XML/JSON feeds) to avoid corrupting structured output.
- If the core resolver hook used for real-editor reuse fails to load (e.g. after an incompatible WP core upgrade), the Website edit screen surfaces an explicit admin notice rather than silently falling back to editing the wrong (theme-wide) global styles record.

## Testing

PHPUnit, matching the existing `composer test` setup:
- Domain normalization/matching edge cases (www, ports, case, duplicate rejection)
- theme.json merge correctness (palette/typography/spacing presence for matched Website; absence for unmatched)
- Global-styles-post-ID resolution redirection (admin editing context resolves to the correct Website's post, not the theme's canonical one)
- Variable-regex correctness (adjacent/nested tokens, undefined tokens, HTML escaping)
- Output buffer does not run on non-HTML responses, and does not interfere with full-page caching (cache keys must already vary by domain in a multi-domain setup)

## Out of scope for v1

- Retroactively converting hardcoded hex values in existing content to palette references
- Non-frontend substitution contexts (emails, PDFs, REST responses)
- Per-domain variable overrides within a shared style profile (variables are bundled per-Website, per the assumption above)
