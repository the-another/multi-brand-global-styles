/**
 * Per-URL global-styles scoping: the root brand styles `/`, the section
 * brand styles `/sample-page/*`, most-specific rule wins, and prefix
 * matching respects path segment boundaries.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	getBodyBackground,
	getCssVariable,
	ROOT_BRAND,
	SECTION_BRAND,
} from '../support/helpers';

test.describe( 'global styles scoping', () => {
	test( 'root URL gets the host-wide brand styles', async ( { page } ) => {
		await page.goto( '/' );

		expect( await getBodyBackground( page ) ).toBe(
			ROOT_BRAND.expectedBackground
		);
		expect(
			await getCssVariable( page, '--wp--preset--color--brand-primary' )
		).toBe( ROOT_BRAND.expectedPrimary );
	} );

	test( '/sample-page/ gets the section brand styles (path beats host)', async ( {
		page,
	} ) => {
		await page.goto( '/sample-page/' );

		expect( await getBodyBackground( page ) ).toBe(
			SECTION_BRAND.expectedBackground
		);
		expect(
			await getCssVariable( page, '--wp--preset--color--brand-primary' )
		).toBe( SECTION_BRAND.expectedPrimary );
	} );

	test( 'child page under the section inherits the section brand', async ( {
		page,
	} ) => {
		await page.goto( '/sample-page/tractors/' );

		expect( await getBodyBackground( page ) ).toBe(
			SECTION_BRAND.expectedBackground
		);
	} );

	test( 'segment boundary: /sample-pagex/ stays on the host-wide brand', async ( {
		page,
	} ) => {
		await page.goto( '/sample-pagex/' );

		expect( await getBodyBackground( page ) ).toBe(
			ROOT_BRAND.expectedBackground
		);
		expect(
			await getCssVariable( page, '--wp--preset--color--brand-primary' )
		).toBe( ROOT_BRAND.expectedPrimary );
	} );

	test( 'same palette slug resolves to different colors per URL', async ( {
		page,
	} ) => {
		await page.goto( '/' );
		const rootPrimary = await getCssVariable(
			page,
			'--wp--preset--color--brand-primary'
		);

		await page.goto( '/sample-page/' );
		const sectionPrimary = await getCssVariable(
			page,
			'--wp--preset--color--brand-primary'
		);

		expect( rootPrimary ).not.toBe( sectionPrimary );
	} );

	test( 'root URL: Navigation block still renders under the merged theme.json', async ( {
		page,
	} ) => {
		await page.goto( '/' );

		// The default theme's header template part renders the header
		// Navigation block; the footer template part renders two more
		// nav.wp-block-navigation instances (footer link columns), so scope
		// to the header landmark to get the one real site-navigation nav.
		const nav = page.locator( 'header nav.wp-block-navigation' );
		await expect( nav ).toBeVisible();
		await expect( nav.getByRole( 'link' ).first() ).toBeVisible();
	} );

	test( '/sample-page/: Navigation block still renders under the merged theme.json', async ( {
		page,
	} ) => {
		await page.goto( '/sample-page/' );

		// See note above: scope to the header landmark to avoid matching the
		// footer's nav.wp-block-navigation instances too.
		const nav = page.locator( 'header nav.wp-block-navigation' );
		await expect( nav ).toBeVisible();
		await expect( nav.getByRole( 'link' ).first() ).toBeVisible();
	} );

	test( 'wp-admin keeps theme defaults (no brand background leak)', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/index.php' );
		const background = await getBodyBackground( page );

		expect( background ).not.toBe( ROOT_BRAND.expectedBackground );
		expect( background ).not.toBe( SECTION_BRAND.expectedBackground );
	} );
} );
