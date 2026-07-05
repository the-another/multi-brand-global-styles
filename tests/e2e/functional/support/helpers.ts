/**
 * Shared E2E helpers for creating Brands (via the real admin form, so
 * BrandPostType::save() — nonce, rule parsing, conflict rejection — is
 * exercised end-to-end) and for seeding page/post content over REST.
 */

import { writeFileSync, mkdtempSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
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
	/** Brand Identity meta box fields (all optional). */
	identityTitle?: string;
	identityTagline?: string;
	/** Attachment ID for the Brand logo (set into the hidden picker input). */
	logoId?: number;
	/** Check the "Rewrite URLs to the domain being browsed" box. */
	urlRewrite?: boolean;
	/** Check the "Force https in rewritten URLs" box. */
	urlRewriteForceHttps?: boolean;
}

/**
 * Create a Brand through the classic-editor admin form and publish it.
 * Resolves once the published edit screen has loaded, with the new Brand's
 * post ID (parsed from the redirect URL).
 */
export async function createBrand(
	page: Page,
	options: CreateBrandOptions
): Promise< number > {
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

	// force on all three checkboxes below: same WP-admin postbox-instability
	// gotcha as the publish click at the end of this helper — the meta-box
	// layout never settles enough to pass Playwright's "stable" actionability
	// check, so an unforced .check() hangs until the test timeout. force
	// skips actionability only — check() still verifies the element ends up
	// checked.
	if ( options.isDefault ) {
		await page
			.locator( 'input[name="mbgs_is_default"]' )
			.check( { force: true } );
	}

	if ( options.identityTitle !== undefined ) {
		await page.locator( 'input[name="mbgs_title"]' ).fill( options.identityTitle );
	}

	if ( options.identityTagline !== undefined ) {
		await page
			.locator( 'input[name="mbgs_tagline"]' )
			.fill( options.identityTagline );
	}

	if ( options.logoId !== undefined ) {
		// The picker input is hidden; set it directly — the picker UI itself
		// is plain wp.media and not what these tests are exercising.
		await page
			.locator( 'input[name="mbgs_logo_id"]' )
			.evaluate( ( input, id ) => {
				( input as HTMLInputElement ).value = String( id );
			}, options.logoId );
	}

	if ( options.urlRewrite ) {
		await page
			.locator( 'input[name="mbgs_url_rewrite_enabled"]' )
			.check( { force: true } );
	}

	if ( options.urlRewriteForceHttps ) {
		await page
			.locator( 'input[name="mbgs_url_rewrite_force_https"]' )
			.check( { force: true } );
	}

	// force: confirmed empirically on native PHP (no wasm involved) — the
	// classic publish button is a plain form submit, but WP admin's postbox
	// layout never settles enough to pass Playwright's "stable"
	// actionability check, so the click hangs until the test's own timeout
	// closes the page. The postbox instability was never wasm-specific.
	//
	// :not(.disabled): core's post.js adds .disabled to every submit button
	// while a heartbeat autosave is in flight and preventDefaults clicks for
	// that window — a forced click there is silently swallowed (no POST to
	// post.php at all; hit on CI where the first autosave races this click).
	// force:true skips Playwright's actionability checks, not the page's own
	// guard, so the locator must wait out the autosave window itself. After
	// that first autosave the window can't reopen: nothing on the form
	// changes again, so later heartbeats have nothing to save.
	await page.locator( '#publish:not(.disabled)' ).click( { force: true } );

	// Classic editor redirects back to post.php with a success notice.
	await expect(
		page.locator( '#message.notice-success, #message.updated' )
	).toBeVisible();

	const url = new URL( page.url() );
	return Number( url.searchParams.get( 'post' ) );
}

const PNG_1X1 = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64'
);

/**
 * Upload a tiny PNG under the given filename; returns { id, source_url }.
 */
export async function uploadTestImage(
	requestUtils: RequestUtils,
	filename: string
): Promise< { id: number; source_url: string } > {
	const dir = mkdtempSync( join( tmpdir(), 'mbgs-e2e-' ) );
	const filePath = join( dir, filename );
	writeFileSync( filePath, PNG_1X1 );
	const media = await requestUtils.uploadMedia( filePath );
	return { id: media.id, source_url: media.source_url };
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
