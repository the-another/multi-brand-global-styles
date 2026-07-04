/**
 * Brand Identity meta box: title/tagline/logo override matched sections via
 * the same site options/theme mods the header template parts use
 * (SiteIdentityOverride), and leave unmatched sections untouched.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { createBrand, createPage, uploadTestImage } from '../support/helpers';

test.describe( 'Brand identity overrides', () => {
	test( 'matched section shows Brand title, tagline, and logo; root shows defaults', async ( {
		page,
		requestUtils,
	} ) => {
		const logo = await uploadTestImage( requestUtils, 'brand-logo.png' );

		// Identity blocks in page content render via the same site
		// options/theme mods the header template parts use — this keeps the
		// assertion theme-independent.
		const identityContent =
			'<!-- wp:site-title /--><!-- wp:site-tagline /--><!-- wp:site-logo /-->';

		await createPage( requestUtils, {
			title: 'Identity Section',
			slug: 'identity-section',
			content: identityContent,
		} );
		await createPage( requestUtils, {
			title: 'Identity Root',
			slug: 'identity-root',
			content: identityContent,
		} );

		await createBrand( page, {
			title: 'Identity Brand',
			rules: 'localhost/identity-section/*',
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
		await page.goto( '/identity-section/' );
		await expect(
			page.locator( 'main .wp-block-site-title' )
		).toContainText( 'Second Brand Co' );
		await expect(
			page.locator( 'main .wp-block-site-tagline' )
		).toContainText( 'Farm fresh auctions' );
		await expect(
			page.locator( 'main .wp-block-site-logo img' )
		).toHaveAttribute( 'src', new RegExp( 'brand-logo' ) );

		await page.goto( '/identity-root/' );
		await expect(
			page.locator( 'main .wp-block-site-title' )
		).not.toContainText( 'Second Brand Co' );
	} );
} );
