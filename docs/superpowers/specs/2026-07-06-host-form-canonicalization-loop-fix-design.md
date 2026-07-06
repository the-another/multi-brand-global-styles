# Host-Form Canonicalization — Redirect-Loop Fix

**Date:** 2026-07-06
**Status:** Approved (design + implemented)
**Target version:** 0.3.1 (patch — bug fix on top of 0.3.0)
**Branch:** `feat/host-form-canonicalization`
**Follows:** `2026-07-06-host-form-canonicalization-design.md`

## Symptom

After enabling a Brand's `canonical_host_form`, visiting `www.farmauctionguide.local`
301-redirects to `farmauctionguide.local` and back, forever
(`ERR_TOO_MANY_REDIRECTS`).

## Investigation (what was ruled out)

The interaction between `HostCanonicalizer` (301 → preferred form, `template_redirect`
priority 1) and `HostRewriter::filter_redirect_canonical` (cancels core's counter-redirect)
was modelled two independent ways:

1. A faithful PHP harness running the **real** plugin classes against a line-accurate port
   of core's `redirect_canonical()` host logic (`wp-includes/canonical.php`, WP 6.9/7.0,
   lines 601–736), following redirects until a loop or a settled render.
2. A **live WordPress** reproduction (wp-now, real core) with the plugin active and a Brand
   seeded, driving `curl` across the `www`/`apex` pair via `Host`-header routing.

Both swept every combination of `home`/`siteurl` form, browsed form, `force_https`,
`is_ssl`, trailing-slash vs not, the front page vs a real page, https-stored home, and the
default Brand. **Every single-brand configuration settled with no loop.** The pre-existing
`HostRewriter` guard reliably neutralises core's canonical redirect.

## Confirmed root cause

The loop only appears when a **redirector outside WordPress** — a web-server rule
(nginx/Apache; the affected site is Docker-hosted) or another plugin — canonicalizes the
host in the **opposite** www/apex direction to the Brand's `canonical_host_form`:

```
apex  →  301 → www    (web server: "always add www")
www   →  301 → apex    (HostCanonicalizer: canonical_host_form = apex)
→ apex → www → apex → www … ∞
```

Reproduced live by placing a server-level `apex → www` redirect in front of a Brand with
`canonical_host_form = apex` — the exact reported bounce.

This is **new on this branch**: before `HostCanonicalizer`, the plugin issued no host
redirects, so it never fought a server-level one. `HostRewriter` cancels *WordPress core's*
canonical redirect, but nothing can cancel the *web server's* — the plugin cannot see it.

Two opposing **301s** cannot be reconciled statelessly: 301s are cached by the browser, so
any cookie/query-param loop-breaker is bypassed on the cached hop. The fix must remove the
*plugin's* side of the conflict, not try to detect the server's redirect at request time.

## Fix — defer to WordPress's Site Address

The one canonical signal the plugin *can* read is WordPress's own Site Address
(`home`/`siteurl`). Core's `redirect_canonical()` already canonicalizes the install's own
domain to the Site Address host's www/apex form, and a correctly-configured web server
canonicalizes to that same Site Address. So:

> **`HostCanonicalizer` must not redirect the install's own domain to a form that opposes
> the Site Address host form.** If the Brand's `canonical_host_form` disagrees with the
> Site Address form for the *same registrable domain*, the plugin defers (renders on the
> browsed host, no redirect) instead of looping.

Concretely, `maybe_redirect()` gains a guard (`site_address_opposes()`) after the existing
`HostForm::matches()` early-return:

- Take the browsed host's apex form (`HostForm::to_apex`).
- For each of `home` / `siteurl`: if the option host's apex form **equals** the browsed
  host's apex form (same domain) **and** the option host is **not** already in the target
  `form`, return `null` (defer).
- Otherwise proceed exactly as before.

### Why this is correct, not a workaround

- **Legitimate configs are untouched.** If the operator sets `canonical_host_form` to match
  their Site Address form (the only non-self-contradictory choice for the install domain),
  the guard never triggers and canonicalization works as designed.
- **Multi-domain Brands are untouched.** A Brand whose domain differs from the install's
  Site Address (a different apex) skips the guard entirely — its form is honoured, because
  the Site Address form has no bearing on a different domain.
- **It closes the only conflict the plugin can create against WordPress + a
  Site-Address-following server.** The residual case — a server hardcoded *opposite* to the
  Site Address — is a pre-existing broken server config that fights core with or without
  this plugin; it is documented, not silently patched.

The contract becomes: *for the install's own domain, `canonical_host_form` must agree with
the WordPress Site Address; set the Site Address to the form you want and the Brand form to
match. For other Brand domains, any form is honoured.*

## Admin surface

The URL-Rewrite meta-box description for the host-form radio gains one sentence: the chosen
form must match the site's WordPress Address (and any server-level redirect) for the
install's own domain, or an infinite redirect loop results.

## Testing

- **Unit** (`HostCanonicalizerTest`): stub `get_option('home'/'siteurl')`. New cases:
  defer (`null`) when the Site Address is the same domain in the opposite form; still
  redirect when the Site Address agrees; still redirect when the Site Address is a different
  domain (multi-domain). Existing decision-matrix tests keep passing with the Site Address
  stubbed to a different domain.
- **Live validation** (not committed): the wp-now reproduction with a server-level opposite
  redirect loops before the fix and settles after it; the agree/multi-domain cases keep
  redirecting.
- **E2e**: still out of scope — the functional suite is single-host on `127.0.0.1` and
  cannot exercise a real www↔apex pair (unchanged from the prior spec).

## Docs

- `CLAUDE.md`: gotcha describing the external-redirect conflict and the Site-Address
  deference guard.
- `CHANGELOG.md` / `readme.txt`: 0.3.1 fix entry.
