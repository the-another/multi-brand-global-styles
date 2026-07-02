/**
 * Runs WordPress.org's official Plugin Check (PCP) against the packaged
 * release zip, driving the plugin's own admin-ajax flow exactly as its
 * admin UI does (same nonce/action names, read from the page's own
 * localized `PLUGIN_CHECK` object) rather than WP-CLI: `run-blueprint`'s
 * wp-cli step executes in-process and never surfaces PHP-side stdout back
 * to the host process (verified empirically — see commit message), so the
 * browser-driven AJAX API is the only reliable way to capture results.
 *
 * Runs against playwright.check.config.ts's dedicated @wp-playground/cli
 * server (see that file for why it's separate from the main e2e config).
 */

import { test, expect } from '@wordpress/e2e-test-utils-playwright';
import { waitForRealReadiness } from './wait-for-real-readiness';

const PLUGIN_BASENAME =
	'the-another-multi-domain-global-styles/the-another-multi-domain-global-styles.php';

interface PluginCheckResult {
	success: boolean;
	data: {
		message?: string;
		errors?: Record< string, unknown[] >;
		warnings?: Record< string, unknown[] >;
	};
}

function countIssues( grouped: Record< string, unknown[] > | undefined ): number {
	return Object.values( grouped ?? {} ).reduce(
		( total, items ) => total + items.length,
		0
	);
}

test( 'Plugin Check reports no errors on the packaged release zip', async ( {
	page,
	request,
	baseURL,
} ) => {
	test.setTimeout( 120_000 );

	await waitForRealReadiness( request, baseURL! );

	// Plugin Check registers via add_management_page(), i.e. under Tools
	// (tools.php), not the generic admin.php page router — navigating to
	// admin.php?page=plugin-check hits core's "no matching page" fallback,
	// which renders the same "Sorry, you are not allowed to access this
	// page." wp_die() used for real permission failures (verified: a
	// confirmed-valid, cookie-present admin session still got that error
	// via admin.php; tools.php resolved correctly).
	await page.goto(
		`/wp-admin/tools.php?page=plugin-check&plugin=${ encodeURIComponent(
			PLUGIN_BASENAME
		) }`
	);

	// Confirm the admin page actually localized its JS config before we
	// try to read it — a clearer failure than a null-property TypeError
	// inside page.evaluate() if Plugin Check didn't load correctly.
	await expect( page.locator( 'body' ) ).toContainText( 'Plugin Check' );

	const result = await page.evaluate< PluginCheckResult, string >(
		async ( plugin ) => {
			// Localized by Plugin Check's own Admin_Page::enqueue_scripts()
			// as `const PLUGIN_CHECK = {...}` via wp_add_inline_script().
			// Top-level `const` in a <script> tag does NOT attach to
			// `window` (verified empirically: window.PLUGIN_CHECK was
			// undefined here) — but it IS visible as a bare identifier,
			// since page.evaluate() runs in the page's main execution
			// context, sharing the same top-level lexical scope as the
			// page's own inline scripts.
			declare const PLUGIN_CHECK: Record< string, string >;
			const pc = PLUGIN_CHECK;

			async function post(
				action: string,
				extra: Record< string, string | string[] > = {}
			): Promise< PluginCheckResult > {
				const body = new URLSearchParams();
				body.set( 'nonce', pc.nonce );
				body.set( 'action', action );
				body.set( 'plugin', plugin );
				for ( const [ key, value ] of Object.entries( extra ) ) {
					if ( Array.isArray( value ) ) {
						value.forEach( ( v ) => body.append( `${ key }[]`, v ) );
					} else {
						body.set( key, value );
					}
				}
				const response = await fetch( '/wp-admin/admin-ajax.php', {
					method: 'POST',
					credentials: 'same-origin',
					body,
				} );
				return response.json();
			}

			// Mirrors assets/js/plugin-check-admin.js's real flow exactly:
			// resolve the default check set, prepare the runtime sandbox
			// (needed for runtime checks like enqueued-script detection),
			// run, then tear the sandbox down.
			const checksResponse = await post( pc.actionGetChecksToRun, {
				'include-experimental': '0',
				'use-ai': '0',
			} );
			const checks: string[] = checksResponse.data.checks as unknown as string[];

			await post( pc.actionSetUpRuntimeEnvironment, {
				checks,
				'include-experimental': '0',
				'use-ai': '0',
			} );

			const runResponse = await post( pc.actionRunChecks, {
				checks,
				'include-experimental': '0',
				'use-ai': '0',
				types: [ 'error', 'warning' ],
			} );

			await post( pc.actionCleanUpRuntimeEnvironment );

			return runResponse;
		},
		PLUGIN_BASENAME
	);

	expect( result.success, JSON.stringify( result ) ).toBe( true );

	const errorCount = countIssues( result.data.errors );
	const warningCount = countIssues( result.data.warnings );

	// eslint-disable-next-line no-console
	console.log(
		`Plugin Check: ${ errorCount } error(s), ${ warningCount } warning(s)`
	);
	if ( errorCount > 0 || warningCount > 0 ) {
		// eslint-disable-next-line no-console
		console.log(
			JSON.stringify(
				{ errors: result.data.errors, warnings: result.data.warnings },
				null,
				2
			)
		);
	}

	expect(
		errorCount,
		'Plugin Check found ERROR-level issues — see the JSON report logged above'
	).toBe( 0 );
} );
