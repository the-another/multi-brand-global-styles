/**
 * %%brand.*%% content-variable substitution: per-URL values, literal
 * passthrough for undefined tokens, and the feed skip.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'content variables', () => {
	test( 'root URL substitutes the host-wide brand variables', async ( {
		page,
	} ) => {
		await page.goto( '/' );
		const body = page.locator( 'body' );

		await expect( body ).toContainText(
			'Welcome to Root Brand — everything under this host.'
		);
		await expect( body ).not.toContainText( '%%brand.name%%' );
	} );

	test( 'undefined tokens stay literal (never blanked)', async ( {
		page,
	} ) => {
		await page.goto( '/' );

		await expect( page.locator( 'body' ) ).toContainText(
			'Undefined token %%brand.missing%% stays literal.'
		);
	} );

	test( '/sample-page/ substitutes the section brand variables', async ( {
		page,
	} ) => {
		await page.goto( '/sample-page/' );

		await expect( page.locator( 'body' ) ).toContainText(
			'You are browsing Farm Brand.'
		);
	} );

	test( 'child page substitutes the section brand variables', async ( {
		page,
	} ) => {
		await page.goto( '/sample-page/tractors/' );

		await expect( page.locator( 'body' ) ).toContainText(
			'Section child sees Farm Brand.'
		);
	} );

	test( 'segment boundary: /sample-pagex/ gets the host-wide values', async ( {
		page,
	} ) => {
		await page.goto( '/sample-pagex/' );

		await expect( page.locator( 'body' ) ).toContainText(
			'Boundary page sees Root Brand.'
		);
	} );

	test( 'feeds are skipped: tokens stay literal in RSS', async ( {
		request,
	} ) => {
		const response = await request.get( '/feed/' );
		expect( response.status() ).toBe( 200 );

		const xml = await response.text();
		expect( xml ).toContain( '%%brand.name%%' );
		expect( xml ).not.toContain( 'Welcome to Root Brand' );
	} );
} );
