import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import { waitForRealReadiness } from './wait-for-real-readiness';

export default async function globalSetup( config: FullConfig ) {
	const { baseURL } = config.projects[ 0 ].use as { baseURL: string };
	const storageStatePath = 'artifacts/storage-states/admin.json';

	const requestUtils = await RequestUtils.setup( {
		baseURL,
		storageStatePath,
	} );

	await waitForRealReadiness( requestUtils.request, baseURL );

	// @wp-playground/cli's --auto-mount auto-activates the mounted plugin in
	// plugin mode (confirmed empirically); this is the explicit safety net
	// (and the activation assertion for a freshly spawned server).
	try {
		await requestUtils.activatePlugin(
			'the-another-multi-brand-global-styles'
		);
	} catch {
		// Already active.
	}
}
