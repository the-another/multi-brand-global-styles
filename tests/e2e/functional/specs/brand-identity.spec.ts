/**
 * Brand Identity meta box: title/tagline/logo override matched sections via
 * the same site options/theme mods the header template parts use
 * (SiteIdentityOverride), and leave unmatched sections untouched.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { createBrand, createPage, uploadTestImage } from '../support/helpers';

// Unique per run so re-runs against a reused server (e.g. CI retries) don't
// self-conflict with an earlier run's brand rule / page slugs — same
// reasoning as admin-rules.spec.ts.
const RUN = Date.now();

test.describe( 'Brand identity overrides', () => {
	test( 'matched section shows Brand title, tagline, and logo; root shows defaults', async ( {
		page,
		requestUtils,
	} ) => {
		const logo = await uploadTestImage(
			requestUtils,
			`brand-logo-${ RUN }.png`
		);

		// Identity blocks in page content render via the same site
		// options/theme mods the header template parts use — this keeps the
		// assertion theme-independent.
		const identityContent =
			'<!-- wp:site-title /--><!-- wp:site-tagline /--><!-- wp:site-logo /-->';

		await createPage( requestUtils, {
			title: 'Identity Section',
			slug: `identity-section-${ RUN }`,
			content: identityContent,
		} );
		await createPage( requestUtils, {
			title: 'Identity Root',
			slug: `identity-root-${ RUN }`,
			content: identityContent,
		} );

		await createBrand( page, {
			title: 'Identity Brand',
			rules: `localhost/identity-section-${ RUN }/*`,
			identityTitle: 'Second Brand Co',
			identityTagline: 'Farm fresh auctions',
			logoId: logo.id,
		} );

		// Scope to `main`: the theme's own header/footer template parts render
		// their own site-title/tagline instances (with the SAME overridden
		// value, since SiteIdentityOverride patches the underlying
		// option/theme-mod site-wide) — same reasoning as style-scoping.spec.ts
		// scoping the Navigation block assertion to `header` to dodge the
		// footer's link-column navs.
		await page.goto( `/identity-section-${ RUN }/` );
		await expect(
			page.locator( 'main .wp-block-site-title' )
		).toContainText( 'Second Brand Co' );
		await expect(
			page.locator( 'main .wp-block-site-tagline' )
		).toContainText( 'Farm fresh auctions' );
		await expect(
			page.locator( 'main .wp-block-site-logo img' )
		).toHaveAttribute( 'src', new RegExp( `brand-logo-${ RUN }` ) );

		await page.goto( `/identity-root-${ RUN }/` );
		await expect(
			page.locator( 'main .wp-block-site-title' )
		).not.toContainText( 'Second Brand Co' );
	} );
} );
