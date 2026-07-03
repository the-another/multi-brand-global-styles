import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import { waitForRealReadiness } from './wait-for-real-readiness';

/**
 * Logs in and stores the resulting auth cookie, exactly like the main
 * suite's global-setup.ts — but to a separate storage state file
 * (STORAGE_STATE_PATH, set in playwright.check.config.ts) so this
 * completely separate WordPress instance never shares session state with
 * the main e2e suite's wp-now instance.
 *
 * A real form POST to wp-login.php (username admin / password password,
 * per the Blueprint schema's documented `login: true` default) does work
 * against @wp-playground/cli server — earlier failures traced entirely to
 * firing that POST before the server was actually ready (see
 * wait-for-real-readiness.ts), not to any limitation of the login flow
 * itself.
 *
 * Deliberately NOT using RequestUtils.login(): after the login POST
 * succeeds (real auth cookies set, confirmed via raw curl), it makes a
 * *second* call to admin-ajax.php?action=rest-nonce with
 * failOnStatusCode: true — which 400s against this environment and
 * throws, discarding the perfectly good session the POST already
 * established. That nonce is for WP's generic REST API, unrelated to
 * Plugin Check's own AJAX nonce (read fresh from the page in
 * plugin-check.spec.ts), so we don't need it — do the login POST
 * directly instead.
 */
export default async function globalSetup( config: FullConfig ) {
	const { baseURL } = config.projects[ 0 ].use as { baseURL: string };
	const storageStatePath = process.env.STORAGE_STATE_PATH;

	if ( ! storageStatePath ) {
		throw new Error( 'STORAGE_STATE_PATH must be set before running global setup.' );
	}

	// RequestUtils.setup() only READS an existing storage state file — it
	// never logs in or writes one on its own (verified from source: no
	// call to .login() inside .setup()).
	const requestUtils = await RequestUtils.setup( { baseURL, storageStatePath } );
	await waitForRealReadiness( requestUtils.request, baseURL );

	const response = await requestUtils.request.post( 'wp-login.php', {
		form: { log: 'admin', pwd: 'password' },
	} );
	if ( ! response.ok() && response.status() !== 302 ) {
		throw new Error( `Login failed: ${ response.status() } ${ await response.text() }` );
	}

	await requestUtils.request.storageState( { path: storageStatePath } );
}
