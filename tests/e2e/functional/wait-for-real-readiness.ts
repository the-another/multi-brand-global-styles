import type { APIRequestContext } from '@playwright/test';

/**
 * Playwright's webServer readiness check (webServer.port) only waits for
 * @wp-playground/cli's server to accept a TCP connection at all — the very
 * first spec's page navigation can still land in a startup window where
 * something in front of the real PHP runtime answers with a transient error
 * (observed as a bare "Bad Gateway" response body) before WordPress
 * installation has actually finished. Poll past the window ourselves before
 * doing anything that depends on a real WordPress response.
 *
 * This is the suite's only real readiness gate: webServer.port proves the
 * process bound the port, not that WordPress is actually installed and
 * answering. A URL-based poller would prove that too, but
 * @wp-playground/cli's Blueprint-driven login makes any cookie-less URL
 * poll loop forever — see playwright.config.ts's webServer comment. Plugin
 * activation is handled by functional-blueprint.json's own activatePlugin
 * step, not a runtime call here — see CLAUDE.md's gotchas for why.
 */
export async function waitForRealReadiness(
	request: APIRequestContext,
	baseURL: string
): Promise< void > {
	const deadline = Date.now() + 60_000;
	while ( Date.now() < deadline ) {
		try {
			// A per-request timeout matters here: without one, a single
			// request that never gets a response (observed in this
			// environment under heavy host load) hangs this function
			// forever, bypassing the deadline check below entirely.
			const response = await request.get( `${ baseURL }/wp-login.php`, { timeout: 5_000 } );
			const body = await response.text();
			if ( response.ok() && ! /bad gateway|service unavailable|database tables are unavailable|error establishing a database connection/i.test( body ) ) {
				return;
			}
		} catch {
			// Connection not accepted yet, or the per-request timeout above
			// fired — keep polling.
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1_000 ) );
	}
	throw new Error( 'WordPress never became ready (still erroring after 60s)' );
}
