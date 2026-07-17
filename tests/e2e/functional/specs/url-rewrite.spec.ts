/**
 * Per-Brand URL host rewrite.
 *
 * The e2e install is canonical at http://localhost:<port>; http://127.0.0.1:<port>
 * hits the same server under a different Host header — a real second domain with
 * zero extra infrastructure. A Brand rule for `127.0.0.1` scopes the option to it.
 *
 * force-https is not covered here (the wp server has no TLS) — unit tests own it.
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { createBrand } from '../support/helpers';

const PORT = Number( process.env.WP_E2E_PORT ) || 8881;
const CANONICAL_AUTHORITY = `localhost:${ PORT }`;
const ALT_URL = `http://127.0.0.1:${ PORT }`;

test.describe( 'Brand URL rewrite', () => {
	test( 'rewrites canonical URLs to the browsed domain only once the option is enabled', async ( {
		page,
	} ) => {
		// Phase 1: Brand matches 127.0.0.1 but the option is OFF —
		// canonical-host URLs must survive in the served HTML (whether the
		// request 200s or canonical-redirects back, the final payload links
		// to the canonical host).
		const brandId = await createBrand( page, {
			title: 'Alt Host Brand',
			rules: '127.0.0.1',
		} );

		const before = await page.request.get( `${ ALT_URL }/sample-page/` );
		expect( before.ok() ).toBe( true );
		expect( await before.text() ).toContain( CANONICAL_AUTHORITY );

		// Phase 2: enable the option through the real edit form.
		await page.goto( `/wp-admin/post.php?post=${ brandId }&action=edit` );
		// force: WP-admin postbox instability — see the checkbox comment in
		// helpers.ts. The toBeChecked() round-trip below proves persistence.
		await page
			.locator( 'input[name="mbgs_url_rewrite_enabled"]' )
			.check( { force: true } );
		// Same force+guard dance as createBrand() — see helpers.ts.
		await page
			.locator( '#publish:not(.disabled)' )
			.click( { force: true } );
		await expect(
			page.locator( '#message.notice-success, #message.updated' )
		).toBeVisible();

		// The checkbox round-trips.
		await page.goto( `/wp-admin/post.php?post=${ brandId }&action=edit` );
		await expect(
			page.locator( 'input[name="mbgs_url_rewrite_enabled"]' )
		).toBeChecked();

		// Phase 3: the alt host now serves directly (no canonical bounce)
		// and every canonical-authority URL is rewritten.
		const after = await page.request.get( `${ ALT_URL }/sample-page/`, {
			maxRedirects: 0,
		} );
		expect( after.status() ).toBe( 200 );

		const html = await after.text();
		expect( html ).not.toContain( CANONICAL_AUTHORITY );
		expect( html ).toContain( `127.0.0.1:${ PORT }` );

		// Canonical link tag specifically stays on the browsed host.
		expect( html ).toMatch(
			new RegExp(
				`<link rel="canonical" href="http://127\\.0\\.0\\.1:${ PORT }[^"]*"`
			)
		);

		// Phase 4: the canonical host itself is untouched (no-op guard).
		const canonical = await page.request.get( `/sample-page/` );
		expect( canonical.ok() ).toBe( true );
		expect( await canonical.text() ).toContain( CANONICAL_AUTHORITY );

		// Phase 5: server-side redirects (Location headers never pass through
		// the HTML buffer) also stay on the browsed host. Both halves of the
		// "login bounces back to the origin domain" bug:
		//
		// (a) a redirect target already on the browsed host must survive
		// wp_validate_redirect() — without the allowed_redirect_hosts filter
		// it is rejected (only the canonical host is allowlisted) and swapped
		// for the canonical-host fallback.
		const loginWithTarget = await page.request.post(
			`${ ALT_URL }/wp-login.php`,
			{
				form: {
					log: 'admin',
					pwd: 'password',
					redirect_to: `${ ALT_URL }/sample-page/`,
				},
				maxRedirects: 0,
			}
		);
		expect( loginWithTarget.status() ).toBe( 302 );
		expect( loginWithTarget.headers()[ 'location' ] ).toBe(
			`${ ALT_URL }/sample-page/`
		);

		// (b) a canonical-host target (wp-login's default is admin_url(), the
		// same home_url()-derived shape as WooCommerce's My Account fallback)
		// must be rewritten onto the browsed host by the wp_redirect filter.
		const loginDefault = await page.request.post(
			`${ ALT_URL }/wp-login.php`,
			{
				form: {
					log: 'admin',
					pwd: 'password',
				},
				maxRedirects: 0,
			}
		);
		expect( loginDefault.status() ).toBe( 302 );
		expect( loginDefault.headers()[ 'location' ] ).toBe(
			`${ ALT_URL }/wp-admin/`
		);

		// Phase 6: served REST payloads (HTML fragments for infinite-scroll /
		// AJAX flows never pass through the HTML buffer) are rewritten too —
		// including _links, which are merged after rest_post_dispatch and
		// would survive anything but the rest_pre_echo_response egress pass.
		const rest = await page.request.get(
			`${ ALT_URL }/wp-json/wp/v2/pages`
		);
		expect( rest.ok() ).toBe( true );
		const restBody = await rest.text();
		expect( restBody ).not.toContain( CANONICAL_AUTHORITY );
		expect( restBody ).toContain( `127.0.0.1:${ PORT }` );

		// The canonical host's own REST payload is untouched.
		const restCanonical = await page.request.get( `/wp-json/wp/v2/pages` );
		expect( restCanonical.ok() ).toBe( true );
		expect( await restCanonical.text() ).toContain( CANONICAL_AUTHORITY );
	} );
} );
