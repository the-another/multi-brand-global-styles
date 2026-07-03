/**
 * Shared E2E helpers for creating Brands (via the real admin form, so
 * BrandPostType::save() — nonce, rule parsing, conflict rejection — is
 * exercised end-to-end) and for seeding page/post content over REST.
 */

import { expect } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

export interface CreateBrandOptions {
	title: string;
	/** One rule per line, exactly as an admin would type them. */
	rules: string;
	/** One `key = value` per line. */
	variables?: string;
	/** theme.json-shaped `{ settings, styles }` payload. */
	stylesJson?: object;
	isDefault?: boolean;
}

/**
 * Create a Brand through the classic-editor admin form and publish it.
 * Resolves once the published edit screen has loaded.
 */
export async function createBrand(
	page: Page,
	options: CreateBrandOptions
): Promise< void > {
	await page.goto( '/wp-admin/post-new.php?post_type=mbgs_brand' );

	await page.locator( '#title' ).fill( options.title );
	await page
		.locator( 'textarea[name="mbgs_rules"]' )
		.fill( options.rules );

	if ( options.variables !== undefined ) {
		await page
			.locator( 'textarea[name="mbgs_variables"]' )
			.fill( options.variables );
	}

	if ( options.stylesJson !== undefined ) {
		await page
			.locator( 'textarea[name="mbgs_styles_json"]' )
			.fill( JSON.stringify( options.stylesJson ) );
	}

	if ( options.isDefault ) {
		await page.locator( 'input[name="mbgs_is_default"]' ).check();
	}

	// force: the classic publish button is a plain form submit, but WP
	// admin's postbox layout under the PHP-wasm engine never settles
	// enough to pass Playwright's "stable" actionability check.
	await page.locator( '#publish' ).click( { force: true } );

	// Classic editor redirects back to post.php with a success notice.
	// Generous timeout: the first post save under php-wasm is slow.
	await expect(
		page.locator( '#message.notice-success, #message.updated' )
	).toBeVisible( { timeout: 30_000 } );
}

/**
 * Find a page ID by slug via REST (any status).
 */
export async function getPageIdBySlug(
	requestUtils: RequestUtils,
	slug: string
): Promise< number > {
	const pages = await requestUtils.rest< Array< { id: number } > >( {
		method: 'GET',
		path: '/wp/v2/pages',
		params: { slug, status: 'publish,draft' },
	} );
	if ( ! pages.length ) {
		throw new Error( `No page found for slug "${ slug }"` );
	}
	return pages[ 0 ].id;
}

/**
 * Overwrite a page's content (and publish it) via REST.
 */
export async function setPageContent(
	requestUtils: RequestUtils,
	pageId: number,
	content: string
): Promise< void > {
	await requestUtils.rest( {
		method: 'POST',
		path: `/wp/v2/pages/${ pageId }`,
		data: { content, status: 'publish' },
	} );
}

/**
 * Create a published page via REST; returns its ID.
 */
export async function createPage(
	requestUtils: RequestUtils,
	data: {
		title: string;
		slug: string;
		content: string;
		parent?: number;
	}
): Promise< number > {
	const page = await requestUtils.rest< { id: number } >( {
		method: 'POST',
		path: '/wp/v2/pages',
		data: { ...data, status: 'publish' },
	} );
	return page.id;
}

/**
 * Overwrite a post's content via REST.
 */
export async function setPostContent(
	requestUtils: RequestUtils,
	postId: number,
	content: string
): Promise< void > {
	await requestUtils.rest( {
		method: 'POST',
		path: `/wp/v2/posts/${ postId }`,
		data: { content, status: 'publish' },
	} );
}

/**
 * Read a CSS custom property as computed on the document root.
 */
export async function getCssVariable(
	page: Page,
	name: string
): Promise< string > {
	return page.evaluate(
		( varName ) =>
			getComputedStyle( document.documentElement )
				.getPropertyValue( varName )
				.trim(),
		name
	);
}

/**
 * Read the computed body background color (e.g. "rgb(255, 238, 238)").
 */
export async function getBodyBackground( page: Page ): Promise< string > {
	return page.evaluate(
		() => getComputedStyle( document.body ).backgroundColor
	);
}

/** Brand fixtures shared between provisioning and specs. */
export const ROOT_BRAND = {
	title: 'Root Brand',
	rules: 'localhost',
	variables: 'name = Root Brand\ntagline = everything under this host',
	stylesJson: {
		settings: {
			color: {
				palette: [
					{
						slug: 'brand-primary',
						color: '#ff0000',
						name: 'Brand Primary',
					},
				],
			},
		},
		styles: { color: { background: '#ffeeee' } },
	},
	expectedBackground: 'rgb(255, 238, 238)',
	expectedPrimary: '#ff0000',
};

export const SECTION_BRAND = {
	title: 'Farm Brand',
	rules: 'localhost/sample-page/*',
	variables: 'name = Farm Brand',
	stylesJson: {
		settings: {
			color: {
				palette: [
					{
						slug: 'brand-primary',
						color: '#00ff00',
						name: 'Brand Primary',
					},
				],
			},
		},
		styles: { color: { background: '#eeffee' } },
	},
	expectedBackground: 'rgb(238, 255, 238)',
	expectedPrimary: '#00ff00',
};
