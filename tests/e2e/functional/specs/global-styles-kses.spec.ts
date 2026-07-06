/**
 * Regression: a Brand's raw-JSON global styles must survive core's kses
 * sanitization on save.
 *
 * For any user WITHOUT the `unfiltered_html` capability (every multisite site
 * admin, and any security-hardened single site) core registers
 * wp_filter_global_styles_post() on `content_save_pre`. That filter re-runs
 * WP_Theme_JSON::remove_insecure_properties() over the saved post_content, and
 * it only preserves presets stored in their origin-keyed form
 * (settings.color.palette.custom). A flat theme.json preset list — exactly what
 * an admin pastes into the Global Styles box — was silently dropped, so the
 * whole styles payload collapsed to `{version, isGlobalStylesUserThemeJSON}`.
 *
 * The e2e admin normally HAS unfiltered_html, so this path is invisible. The
 * mbgs-e2e-force-kses mu-plugin (see environment/serve-wp.sh) drops the cap
 * ONLY while saving a Brand whose title carries the `[kses]` sentinel, so this
 * spec exercises the real kses filter while every other spec is untouched.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { createBrand } from '../support/helpers';

const PALETTE_STYLES = {
	settings: {
		color: {
			palette: [
				{ slug: 'accent-1', color: '#1E40AF', name: 'Accent 1' },
				{ slug: 'accent-2', color: '#DB2777', name: 'Accent 2' },
				{ slug: 'accent-3', color: '#059669', name: 'Accent 3' },
			],
		},
	},
};

test.describe( 'global styles survive kses on save', () => {
	test( 'flat theme.json palette is preserved (not stripped to the empty skeleton)', async ( {
		page,
	} ) => {
		// The `[kses]` sentinel in the title makes the mu-plugin drop
		// unfiltered_html for this save, so core's wp_filter_global_styles_post
		// runs — the exact condition under which the palette used to vanish.
		const brandId = await createBrand( page, {
			title: 'Kses Palette Brand [kses]',
			rules: 'localhost/kses-test/*',
			stylesJson: PALETTE_STYLES,
		} );

		// Reload the edit screen and read back what actually persisted.
		await page.goto(
			`/wp-admin/post.php?post=${ brandId }&action=edit`
		);

		const stored = await page
			.locator( 'textarea[name="mbgs_styles_json"]' )
			.inputValue();

		const parsed = JSON.parse( stored );

		// The whole payload must not have collapsed to the empty skeleton.
		expect( parsed.settings ).toBeTruthy();

		// Every color survived, regardless of flat vs origin-keyed storage
		// (WP_Theme_JSON normalizes the flat list into the keyed form that
		// survives the kses filter; both render identically).
		const paletteNode = parsed.settings?.color?.palette;
		const colors = Array.isArray( paletteNode )
			? paletteNode
			: paletteNode?.custom;

		expect( colors ).toHaveLength( 3 );
		expect( colors.map( ( c: { color: string } ) => c.color ) ).toEqual( [
			'#1E40AF',
			'#DB2777',
			'#059669',
		] );
	} );
} );
