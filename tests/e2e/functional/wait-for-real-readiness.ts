import type { APIRequestContext } from '@playwright/test';

/**
 * Playwright's webServer readiness check (webServer.port) only waits for
 * @wp-playground/cli's server to accept a TCP connection at all — the first
 * real requests after that (both global setup's activatePlugin() call and
 * the very first spec's page navigation) can still land in a startup window
 * where something in front of the real PHP runtime answers with a transient
 * error (observed as a bare "Bad Gateway" response body) before WordPress
 * installation has actually finished. global-setup.ts's activatePlugin()
 * call additionally swallows ALL errors on the assumption they mean
 * "already active" (true when Playwright reused an already-running server,
 * not true on a freshly spawned one), so a request fired into this window
 * fails silently there and the failure only surfaces later, in the first
 * spec test. Poll past the window ourselves before doing anything that
 * depends on a real WordPress response.
 *
 * This is now the suite's only real readiness gate: webServer.port proves
 * the process bound the port, not that WordPress is actually installed and
 * answering. A URL-based poller would prove that too, but
 * @wp-playground/cli's Blueprint-driven login makes any cookie-less URL
 * poll loop forever — see playwright.config.ts's webServer comment.
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
