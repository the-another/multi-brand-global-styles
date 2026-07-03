/**
 * Save-time rule validation through the real admin form: exact duplicates
 * are rejected with an admin notice, overlapping rules coexist.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { createBrand } from './helpers';

test.describe( 'admin rule validation', () => {
	test( 'exact duplicate rule is rejected with a warning notice', async ( {
		page,
	} ) => {
		// "localhost" is already owned by Root Brand (provisioned).
		await createBrand( page, {
			title: 'Dupe Brand',
			rules: 'localhost',
		} );

		await expect( page.locator( '.notice-warning' ) ).toContainText(
			'already assigned to another Brand'
		);
		await expect( page.locator( '.notice-warning' ) ).toContainText(
			'localhost'
		);

		// The conflicting rule was dropped, not saved.
		await expect(
			page.locator( 'textarea[name="mbgs_rules"]' )
		).toHaveValue( '' );
	} );

	test( 'overlapping-but-different rules coexist (host vs host/path)', async ( {
		page,
	} ) => {
		// Both provisioned brands kept their rules even though one is a
		// path inside the other's host scope.
		await page.goto( '/wp-admin/edit.php?post_type=mbgs_brand' );

		const rootRow = page.getByRole( 'link', {
			name: 'Root Brand',
			exact: true,
		} );
		const farmRow = page.getByRole( 'link', {
			name: 'Farm Brand',
			exact: true,
		} );
		await expect( rootRow ).toBeVisible();
		await expect( farmRow ).toBeVisible();

		await farmRow.click();
		await expect(
			page.locator( 'textarea[name="mbgs_rules"]' )
		).toHaveValue( 'localhost/sample-page' );
	} );

	test( 'admin input is normalized (scheme, www, wildcard stripped)', async ( {
		page,
	} ) => {
		// Unique per run so re-runs against a reused server don't
		// self-conflict with an earlier run's brand.
		const section = `section-${ Date.now() }`;

		await createBrand( page, {
			title: 'Normalize Brand',
			rules: `https://WWW.Example.com:8080/${ section.toUpperCase() }/*`,
		} );

		await expect(
			page.locator( 'textarea[name="mbgs_rules"]' )
		).toHaveValue( `example.com/${ section }` );
	} );
} );
