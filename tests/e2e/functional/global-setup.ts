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
}
