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
		await page
			.locator( 'input[name="mbgs_url_rewrite_enabled"]' )
			.check();
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
	} );
} );
