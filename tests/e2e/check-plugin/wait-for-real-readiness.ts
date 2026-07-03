import type { APIRequestContext } from '@playwright/test';

/**
 * Playwright's webServer readiness check (and any request fired the moment
 * a test run starts) can hit @wp-playground/cli server before it's actually
 * ready: verified empirically that early requests are answered by a
 * placeholder Node/Express layer (`x-powered-by: Express`, redirects
 * wp-login.php to itself, no real cookies set) rather than the actual PHP
 * runtime (`x-powered-by: PHP/...`) — a "database tables are unavailable"
 * error page is another symptom of the same window. This bit both page
 * navigation AND the login POST in global setup, since both fire before
 * the framework's own readiness signal can be trusted. Poll past it
 * ourselves before doing anything that depends on a real WordPress
 * response.
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
			const poweredBy = response.headers()[ 'x-powered-by' ] ?? '';
			if (
				response.ok() &&
				poweredBy.startsWith( 'PHP/' ) &&
				! body.includes( 'database tables are unavailable' )
			) {
				return;
			}
		} catch {
			// Connection not accepted yet — keep polling.
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 1_000 ) );
	}
	throw new Error( 'WordPress never became ready (still erroring after 60s)' );
}
