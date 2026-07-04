/**
 * The admin-only `?mbgs_preview_brand=<id>` override (BrandResolver): lets a
 * capable, logged-in user preview any published Brand on any URL, while
 * leaving logged-out visitors completely unaffected.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { createBrand, createPage } from '../support/helpers';

// Unique per run so re-runs against a reused server (e.g. CI retries) don't
// self-conflict with an earlier run's brand rule / page slug — same
// reasoning as admin-rules.spec.ts.
const RUN = Date.now();

test.describe( 'Frontend Brand preview override', () => {
	test( 'admin sees the previewed Brand; logged-out visitors are unaffected', async ( {
		page,
		requestUtils,
		browser,
		baseURL,
	} ) => {
		await createPage( requestUtils, {
			title: 'Preview Probe',
			slug: `preview-probe-${ RUN }`,
			content: '<!-- wp:site-title /-->',
		} );

		// Rules match a host this suite never serves — only the override can
		// ever activate this Brand.
		const brandId = await createBrand( page, {
			title: 'Preview Brand',
			rules: `preview-only-${ RUN }.example`,
			identityTitle: 'Preview Brand Title',
		} );

		// Scope to `main`: the theme's own header/footer template parts render
		// their own site-title instance too (with the same overridden value,
		// since SiteIdentityOverride patches the option/theme-mod site-wide)
		// — same reasoning as style-scoping.spec.ts scoping the Navigation
		// assertion to `header`.
		await page.goto( `/preview-probe-${ RUN }/?mbgs_preview_brand=${ brandId }` );
		await expect(
			page.locator( 'main .wp-block-site-title' )
		).toContainText( 'Preview Brand Title' );

		// Same URL, no auth cookies: the parameter must be ignored.
		// `browser.newContext()` — even called directly, not through the
		// `page`/`context` fixtures — still inherits the project's `use`
		// defaults (confirmed via trace inspection: the admin's
		// `wordpress_logged_in_*` cookies showed up on this "anon" request
		// until storageState was explicitly cleared here), so storageState
		// must be reset to none explicitly for a genuinely logged-out
		// context. baseURL is passed through for the same reason (manual
		// newContext() does not otherwise pick it up as a default).
		const anonContext = await browser.newContext( {
			storageState: undefined,
			baseURL,
		} );
		const anonPage = await anonContext.newPage();
		await anonPage.goto( `/preview-probe-${ RUN }/?mbgs_preview_brand=${ brandId }` );
		await expect(
			anonPage.locator( 'main .wp-block-site-title' )
		).not.toContainText( 'Preview Brand Title' );
		await anonContext.close();
	} );
} );
