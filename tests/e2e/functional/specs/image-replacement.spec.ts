/**
 * Per-Brand image replacement: the meta-box-driven pair map swaps mapped
 * image URLs on the matched section only (ImageUrlReplacer), and the
 * mbgs/v1 replacements REST route sets/unsets a pair directly.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { createBrand, createPage, uploadTestImage } from '../support/helpers';

test.describe( 'Per-Brand image replacement', () => {
	test( 'mapped image swaps on the matched section only', async ( {
		page,
		requestUtils,
	} ) => {
		const original = await uploadTestImage( requestUtils, 'swap-original.png' );
		const replacement = await uploadTestImage(
			requestUtils,
			'swap-replacement.png'
		);

		const imageContent = `<!-- wp:image {"id":${ original.id }} --><figure class="wp-block-image"><img src="${ original.source_url }" class="wp-image-${ original.id }"/></figure><!-- /wp:image -->`;

		await createPage( requestUtils, {
			title: 'Swap Section',
			slug: 'swap-section',
			content: imageContent,
		} );
		await createPage( requestUtils, {
			title: 'Swap Root',
			slug: 'swap-root',
			content: imageContent,
		} );

		const brandId = await createBrand( page, {
			title: 'Swap Brand',
			rules: 'localhost/swap-section/*',
		} );

		// Set the replacement through the central meta box: add a row, set
		// the hidden picker inputs directly, save through the real handler.
		await page.goto( `/wp-admin/post.php?post=${ brandId }&action=edit` );
		// force: same postbox-layout-never-settles reasoning documented for
		// the classic-editor #publish click (helpers.ts) — this button lives
		// in the same postbox layout and hits the same actionability stall.
		await page.locator( '.mbgs-image-map-add' ).click( { force: true } );
		const row = page.locator( '.mbgs-image-map-rows .mbgs-image-map-row' ).last();
		await row
			.locator( 'input[name="mbgs_image_map_original[]"]' )
			.evaluate( ( input, id ) => {
				( input as HTMLInputElement ).value = String( id );
			}, original.id );
		await row
			.locator( 'input[name="mbgs_image_map_replacement[]"]' )
			.evaluate( ( input, id ) => {
				( input as HTMLInputElement ).value = String( id );
			}, replacement.id );
		await page.locator( '#publish' ).click( { force: true } );
		await expect(
			page.locator( '#message.notice-success, #message.updated' )
		).toBeVisible();

		await page.goto( '/swap-section/' );
		await expect( page.locator( '.wp-block-image img' ) ).toHaveAttribute(
			'src',
			new RegExp( 'swap-replacement' )
		);

		await page.goto( '/swap-root/' );
		await expect( page.locator( '.wp-block-image img' ) ).toHaveAttribute(
			'src',
			new RegExp( 'swap-original' )
		);
	} );

	test( 'replacements REST route sets and unsets a pair', async ( {
		page,
		requestUtils,
	} ) => {
		const original = await uploadTestImage( requestUtils, 'rest-original.png' );
		const replacement = await uploadTestImage(
			requestUtils,
			'rest-replacement.png'
		);

		// The REST assertions below need at least one published Brand;
		// create one directly rather than assuming another spec already ran
		// (specs must not depend on cross-file ordering).
		await createBrand( page, {
			title: 'REST Fixture Brand',
			rules: 'rest-fixture.example',
		} );

		const brands = await requestUtils.rest< Array< { brand_id: number } > >( {
			method: 'GET',
			path: '/mbgs/v1/brands',
		} );
		expect( brands.length ).toBeGreaterThan( 0 );
		const brandId = brands[ 0 ].brand_id;

		const row = await requestUtils.rest< { replacement_id: number } >( {
			method: 'POST',
			path: '/mbgs/v1/replacements',
			data: {
				brand_id: brandId,
				original_id: original.id,
				replacement_id: replacement.id,
			},
		} );
		expect( row.replacement_id ).toBe( replacement.id );

		const previewMap = await requestUtils.rest< {
			images: Record< string, string >;
		} >( {
			method: 'GET',
			path: '/mbgs/v1/preview-map',
			params: { brand: brandId },
		} );
		expect( previewMap.images[ String( original.id ) ] ).toContain(
			'rest-replacement'
		);

		const cleared = await requestUtils.rest< { replacement_id: null } >( {
			method: 'POST',
			path: '/mbgs/v1/replacements',
			data: {
				brand_id: brandId,
				original_id: original.id,
				replacement_id: null,
			},
		} );
		expect( cleared.replacement_id ).toBeNull();
	} );
} );
