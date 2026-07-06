/**
 * Styles round-trip: verify that saving styles through the admin form and
 * re-opening the Brand edit screen shows the correct settings/styles JSON
 * without wrapper keys (version, isGlobalStylesUserThemeJSON), and that
 * empty subtrees encode as {} (objects) not [] (arrays).
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { createBrand } from '../support/helpers';

test.describe( 'styles round-trip', () => {
	test( 'saved styles survive a round-trip without wrapper keys', async ( {
		page,
	} ) => {
		const stylesPayload = {
			settings: {
				color: {
					palette: [
						{
							slug: 'roundtrip-color',
							color: '#abcdef',
							name: 'Roundtrip Color',
						},
					],
				},
			},
			styles: {
				color: { background: '#112233' },
			},
		};

		const brandId = await createBrand( page, {
			title: 'Round-Trip Brand',
			rules: 'roundtrip.example.com',
			stylesJson: stylesPayload,
		} );

		// Re-open the Brand edit screen.
		await page.goto(
			`/wp-admin/post.php?post=${ brandId }&action=edit`
		);

		const textarea = page.locator( 'textarea[name="mbgs_styles_json"]' );
		await expect( textarea ).toBeVisible();

		const raw = await textarea.inputValue();
		const parsed = JSON.parse( raw );

		// Must contain only settings/styles — no version or wrapper keys.
		expect( Object.keys( parsed ).sort() ).toEqual( [
			'settings',
			'styles',
		] );
		expect( parsed.settings.color.palette[ 0 ].color ).toBe( '#abcdef' );
		expect( parsed.styles.color.background ).toBe( '#112233' );
	} );

	test( 'empty styles encode as JSON objects not arrays', async ( {
		page,
	} ) => {
		const brandId = await createBrand( page, {
			title: 'Empty-Styles Brand',
			rules: 'empty-styles.example.com',
			stylesJson: { settings: {}, styles: {} },
		} );

		// Re-open the Brand edit screen.
		await page.goto(
			`/wp-admin/post.php?post=${ brandId }&action=edit`
		);

		const textarea = page.locator( 'textarea[name="mbgs_styles_json"]' );
		await expect( textarea ).toBeVisible();

		const raw = await textarea.inputValue();

		// Empty subtrees must be {} (objects), never [].
		expect( raw ).toContain( '"settings": {}' );
		expect( raw ).toContain( '"styles": {}' );
	} );
} );
