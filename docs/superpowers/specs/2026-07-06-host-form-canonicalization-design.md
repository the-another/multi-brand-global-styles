# Per-Brand www/apex Host-Form Canonicalization

**Date:** 2026-07-06
**Status:** Approved (design)
**Target version:** 0.3.0 (feature — minor bump, not the 0.2.1 patch)
**Branch:** `feat/host-form-canonicalization`

## Problem

A Brand's domain can be reached in two host forms — apex (`farmauctionguide.local`)
and `www` (`www.farmauctionguide.local`). Today the plugin treats them as
interchangeable: `UrlRuleRegistry::normalize_host()` strips a leading `www.` so both
forms match the same Brand, and `HostRewriter` rewrites the canonical host to
*whatever raw host the visitor is browsing*. There is no way to declare one form
canonical, so a Brand can be served under both forms simultaneously (duplicate-content
/ SEO split, mixed-host links).

Operators need to pick, per Brand, a single canonical host form and have visitors on
the other form redirected to it.

## Goal

A per-Brand setting that selects the canonical host form (`www`, `apex`, or off). When
set and enabled:

1. **Redirect** — a visitor whose browsed host is the non-preferred form is 301-redirected
   to the same URL on the preferred form.
2. **Rewrite** — all rendered URLs use the preferred form (achieved for free: after the
   redirect the browsed host *is* the preferred form, and `HostRewriter` already rewrites
   the canonical host to the browsed host).

Off (default) preserves today's behavior exactly.

## Non-goals

- Per-domain-rule granularity (one Brand can have several domains). The setting is
  per-Brand; the chosen form applies to whichever of the Brand's hosts the visitor is on.
  YAGNI until a concrete multi-domain-with-mixed-form Brand appears.
- Changing request→Brand matching. Matching stays `www`-insensitive; both forms continue
  to resolve to the Brand. Canonicalization is purely an output/redirect concern.
- Canonicalizing the *canonical* (home/siteurl) host's own form, or any host other than
  the browsed Brand host.

## Data model

Add one key to the existing `_mbgs_settings['url_rewrite']` sub-array (owned by
`BrandSettings`, the single place all Brand-data normalization lives):

- `canonical_host_form`: one of `'www'`, `'apex'`, or absent/`''` (off — the default).

`BrandSettings::from_meta()` hydrates it via a new normalizer that accepts only the two
known tokens and otherwise yields `''`. New readonly property + getter
`url_rewrite_host_form(): string`.

## Admin surface

In the existing **URL Rewrite** meta box (`BrandPostType::render_url_rewrite_meta_box`),
below the two checkboxes, add a radio group:

- "Follow browsed host" (value `''`, default/checked when unset)
- "Force www" (value `www`)
- "Force apex (no www)" (value `apex`)

POST field: `mbgs_url_rewrite_host_form`. `BrandPostType::collect_url_rewrite()` reads and
whitelists it to `{'', 'www', 'apex'}` before storing (only stores the key when non-empty,
consistent with how `enabled`/`force_https` are only present when set). Saved through the
same single `update_settings()` merge-write as the rest of the form — no new write path.

A short `<p class="description">` notes that this only takes effect when "Rewrite URLs" is
enabled, and that it applies to the Brand's own domain(s).

## Host-form helper

One place computes host-form transforms so the redirect (and any future consumer) never
duplicate the logic. A small final class `includes/Urls/HostForm.php` with pure static
methods operating on an authority (`host[:port]`):

- `to_www(string $authority): string` — ensure a single leading `www.` on the host (port
  preserved, untouched if already present).
- `to_apex(string $authority): string` — strip a single leading `www.` from the host (port
  preserved, no-op if absent).
- `matches(string $authority, string $form): bool` — whether the authority is already in
  the given form (`'www'` → host starts with `www.`; `'apex'` → it does not).

`to_www`/`to_apex` are exact inverses on the `www`-prefix, which is what makes the redirect
loop-safe. Subdomains (`beta.x.com`) are handled literally: `to_www` → `www.beta.x.com`,
`to_apex` → unchanged. That literal behavior is intended and operator-owned (documented in
the meta-box description and CLAUDE.md gotchas).

## Redirect component

New class `includes/Urls/HostCanonicalizer.php`, constructed with `BrandResolver` +
`BrandRepository` (same deps as `HostRewriter`). One public method `maybe_redirect(): void`,
hooked to `template_redirect` at an **early priority (1)** — before `redirect_canonical`
(priority 10) and before `PageBuffer::start_buffer`, so the redirect wins and no output
buffer is opened for a request that is about to 301.

Algorithm (fails open — any unusable condition returns without redirecting):

1. Short-circuit on `is_admin() || wp_doing_ajax() || is_feed() || REST_REQUEST` and on
   `PHP_SAPI === 'cli'` / no `HTTP_HOST`. (Mirror `PageBuffer`'s guard set.)
2. Resolve the Brand via `BrandResolver::resolve_current_request()`; return if null.
3. Load settings; return unless `url_rewrite_enabled()` **and**
   `url_rewrite_host_form()` is `'www'` or `'apex'`.
4. Read and validate the browsed authority (same regex as
   `HostRewriter::current_authority()`); return if invalid.
5. If `HostForm::matches(authority, form)` → already canonical, return (no loop).
6. Compute the target authority (`to_www`/`to_apex`), the scheme
   (`url_rewrite_force_https() || is_ssl() ? 'https' : 'http'`), and rebuild the URL as
   `scheme://target_authority . REQUEST_URI` (REQUEST_URI carries path + query,
   sanitized/unslashed).
7. `wp_redirect( $target, 301 ); exit;` — **not** `wp_safe_redirect`, because the target is
   a cross-host (www↔apex) transform of the already-validated current host, which
   `wp_safe_redirect`'s allowlist would reject. The host is regex-validated and derived
   solely from the current request, so there is no open-redirect surface.

`HostRewriter` and its `filter_redirect_canonical` guard are unchanged. Because the
canonicalization redirect fires earlier and `exit`s, core's `redirect_canonical` never runs
on a to-be-redirected request; on an already-canonical request the existing guard behaves
exactly as today.

## Wiring (`Plugin.php`)

- Register a `host_canonicalizer` factory alongside `host_rewriter`.
- `register_action( 'template_redirect', array( $host_canonicalizer, 'maybe_redirect' ), 1 )`
  — priority 1, ahead of the existing `template_redirect` registrations for
  `page_buffer` (`start_buffer`).

## Testing

Unit (PHPUnit 11 + Brain Monkey + Mockery), mirroring `includes/` under `tests/Unit/`:

- `HostForm`: `to_www`/`to_apex`/`matches` across apex, www, subdomain, port-bearing, and
  already-normalized inputs; inverse property (apply→apply-inverse is a no-op).
- `BrandSettings`: `canonical_host_form` hydration — valid tokens kept, junk/absent → `''`.
- `BrandPostType::collect_url_rewrite`: whitelisting of the POST value.
- `HostCanonicalizer::maybe_redirect`: the decision matrix — off/enabled combinations,
  each form vs each browsed form (redirect vs no-op), guard short-circuits, invalid host,
  and force_https scheme selection. Assert on the `wp_redirect` call + status via Brain
  Monkey; structure `maybe_redirect` so `exit` is isolated (e.g. return after the redirect
  call is issued, or wrap the terminal call) to keep it unit-testable — match whatever
  pattern existing redirecting code in the plugin uses, or introduce a thin seam.

E2e is out of scope for this change: the functional suite runs a single `wp server` host on
`127.0.0.1` and cannot exercise a genuine www↔apex host pair without new provisioning. The
redirect decision is fully covered by unit tests. (Note this explicitly in the PR so the
gap is a decision, not an oversight.)

## Documentation

- `CLAUDE.md`: add `canonical_host_form` to the `_mbgs_settings['url_rewrite']` data-model
  entry; add the `mbgs_url_rewrite_host_form` POST field; add `HostCanonicalizer` to the
  `includes/Urls/` description and the key-hooks table (`template_redirect` @ priority 1);
  add a gotcha covering the subdomain literal-`www` behavior and the `wp_redirect`
  (not `wp_safe_redirect`) rationale.

## Risks / consequences

- **Redirect loops** — prevented by the `matches()` early-return and the exact-inverse
  `to_www`/`to_apex` pair. Covered by unit tests.
- **Interaction with the dropped infra host switch** — this feature assumes the separate
  `wp-infra` change (static `WP_HOME`/`WP_SITEURL` + plugin-owned rewriting) is in effect.
  It composes cleanly regardless: with the infra switch still on, `canonical_host_form`
  simply canonicalizes the (already brand-host) form.
- **Off by default** — existing Brands are unaffected until an operator opts in.
