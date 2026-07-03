import type { APIRequestContext } from '@playwright/test';

/**
 * Playwright's webServer readiness check only waits for wp-now's server to
 * accept a connection at all — the first real requests after that (both
 * global setup's activatePlugin() call and the very first spec's page
 * navigation) can still land in a startup window where something in front
 * of the real PHP runtime answers with a transient error (observed as a
 * bare "Bad Gateway" response body) before WordPress installation has
 * actually finished. global-setup.ts's activatePlugin() call additionally
 * swallows ALL errors on the assumption they mean "already active" (true
 * on a reused server, not true on a fresh --reset one), so a request fired
 * into this window fails silently there and the failure only surfaces
 * later, in the first spec test. Poll past the window ourselves before
 * doing anything that depends on a real WordPress response. Same root
 * cause/fix shape as the (now-removed, @wp-playground/cli-specific)
 * check-plugin suite's wait-for-real-readiness.ts.
 */
export async function waitForRealReadiness(
	request: APIRequestContext,
	baseURL: string
): Promise< void > {
	const deadline = Date.now() + 60_000;
	while ( Date.now() < deadline ) {
		try {
			const response = await request.get( `${ baseURL }/wp-login.php` );
			const body = await response.text();
			if ( response.ok() && ! /bad gateway|service unavailable|database tables are unavailable|error establishing a database connection/i.test( body ) ) {
				return;
			}
		} catch {
			// Connection not accepted yet — keep polling.
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1_000 ) );
	}
	throw new Error( 'WordPress never became ready (still erroring after 60s)' );
}
