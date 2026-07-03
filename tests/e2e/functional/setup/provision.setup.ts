/**
 * Provisioning: creates the two Brands through the real admin form and
 * seeds frontend content with %%brand.*%% tokens. Runs once in the
 * "setup" Playwright project, before every spec.
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import {
	createBrand,
	createPage,
	getPageIdBySlug,
	setPageContent,
	setPostContent,
	ROOT_BRAND,
	SECTION_BRAND,
} from '../support/helpers';

test( 'provision: brands and token content', async ( {
	page,
	requestUtils,
} ) => {
	test.setTimeout( 120_000 );

	// Idempotency guard: with reuseExistingServer a re-run hits an already
	// provisioned database, where re-creating the brands would self-conflict
	// (their rules are already owned by the first run's posts).
	await page.goto( '/wp-admin/edit.php?post_type=mbgs_brand' );
	const alreadyProvisioned = await page
		.getByRole( 'link', { name: ROOT_BRAND.title, exact: true } )
		.isVisible()
		.catch( () => false );

	if ( ! alreadyProvisioned ) {
		// Two Brands via the real admin form: a host-wide brand for
		// localhost and a path-scoped brand for /sample-page/* —
		// deliberately overlapping, which the plugin must accept (only
		// exact-duplicate rules conflict).
		await createBrand( page, ROOT_BRAND );
		await createBrand( page, SECTION_BRAND );
	}

	// Front page (blog index) shows the default "Hello world!" post —
	// give it brand tokens, including one that no Brand defines.
	const posts = await requestUtils.rest< Array< { id: number } > >( {
		method: 'GET',
		path: '/wp/v2/posts',
		params: { per_page: 1, orderby: 'id', order: 'asc' },
	} );
	expect( posts.length ).toBeGreaterThan( 0 );
	await setPostContent(
		requestUtils,
		posts[ 0 ].id,
		'<p>Welcome to %%brand.name%% — %%brand.tagline%%.' +
			' Undefined token %%brand.missing%% stays literal.</p>'
	);

	// The stock Sample Page (slug: sample-page) sits inside the section
	// brand's path scope.
	const samplePageId = await getPageIdBySlug( requestUtils, 'sample-page' );
	await setPageContent(
		requestUtils,
		samplePageId,
		'<p>You are browsing %%brand.name%%.</p>'
	);

	// A child page under /sample-page/ — must inherit the section brand.
	await createPage( requestUtils, {
		title: 'Tractors',
		slug: 'tractors',
		parent: samplePageId,
		content: '<p>Section child sees %%brand.name%%.</p>',
	} );

	// A sibling page whose slug merely STARTS with "sample-page" — the
	// segment-boundary rule means it must NOT match /sample-page/*.
	await createPage( requestUtils, {
		title: 'Sample Pagex',
		slug: 'sample-pagex',
		content: '<p>Boundary page sees %%brand.name%%.</p>',
	} );
} );
