/**
 * Plugin activation and admin-surface smoke checks.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'activation', () => {
	test( 'plugin is active', async ( { requestUtils } ) => {
		const plugins = await requestUtils.rest<
			Array< { plugin: string; status: string } >
		>( {
			method: 'GET',
			path: '/wp/v2/plugins',
		} );

		const ours = plugins.find( ( p ) =>
			p.plugin.includes( 'the-another-multi-domain-global-styles' )
		);
		expect( ours ).toBeDefined();
		expect( ours!.status ).toBe( 'active' );
	} );

	test( 'frontend responds without fatals', async ( { page } ) => {
		const response = await page.goto( '/' );
		expect( response!.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Fatal error'
		);
	} );

	test( 'Brands admin menu and list screen exist', async ( { page } ) => {
		await page.goto( '/wp-admin/edit.php?post_type=mdgs_brand' );
		await expect(
			page.getByRole( 'heading', { name: 'Brands' } )
		).toBeVisible();
	} );

	test( 'Brand edit screen renders all four meta boxes', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/post-new.php?post_type=mdgs_brand' );
		await expect( page.locator( '#mdgs_rules' ) ).toBeVisible();
		await expect( page.locator( '#mdgs_variables' ) ).toBeVisible();
		await expect( page.locator( '#mdgs_default' ) ).toBeVisible();
		await expect( page.locator( '#mdgs_styles' ) ).toBeVisible();
	} );
} );
