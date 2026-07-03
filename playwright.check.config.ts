import { defineConfig } from '@playwright/test';
import * as path from 'node:path';

// Separate from playwright.config.ts on purpose: this boots a FRESH
// WordPress instance from the packaged release zip (build/*-test.zip) via
// @wp-playground/cli, not wp-now's auto-mounted dev source — the whole
// point is to catch packaging bugs (missing files, wrong autoloader) that
// a source-directory mount would never surface. Requires
// `npm run plugin-zip:check` to have built that zip first.

const PORT = 8883;
// @wp-playground/cli server's own default --site-url is http://127.0.0.1:{port}
// (confirmed via --help), which becomes WordPress's real home/siteurl. Using
// "localhost" here instead would make the browser treat page origin and
// WP's own generated absolute URLs (ajaxurl, nonce refresh, etc.) as two
// different origins and block them via CORS — verified empirically.
const BASE_URL = `http://127.0.0.1:${ PORT }`;
const BUILD_DIR = path.resolve( __dirname, 'build' );

// A separate file from the main e2e suite's, since this is a completely
// separate WordPress instance on a different port. check-plugin-global-setup.ts
// logs in and writes this file; `use.storageState` below (Playwright's own
// context-creation mechanism) is what actually makes `page` navigation
// authenticated — @wordpress/e2e-test-utils-playwright's `test` fixture
// does NOT propagate its own requestUtils session to the browser context,
// so both need to point at the same file.
const STORAGE_STATE_PATH = path.resolve(
	__dirname,
	'artifacts/storage-states/plugin-check-admin.json'
);
process.env.STORAGE_STATE_PATH = STORAGE_STATE_PATH;
// check-plugin-blueprint.json installs plugin-check BEFORE our own zip —
// verified empirically that the reverse order breaks plugin-check's own
// activation with a persistent "database tables are unavailable" error
// (root cause not pinned down further; the fix is simply the install
// order). Do not reorder those two installPlugin steps.
const BLUEPRINT = path.resolve( __dirname, 'tests/e2e/check-plugin/check-plugin-blueprint.json' );

export default defineConfig( {
	testDir: './tests/e2e/check-plugin',
	testMatch: 'plugin-check.spec.ts',
	fullyParallel: false,
	retries: 0,
	workers: 1,
	timeout: 60_000,
	reporter: 'list',
	use: {
		baseURL: BASE_URL,
		storageState: STORAGE_STATE_PATH,
		launchOptions: process.env.CHROMIUM_EXECUTABLE_PATH
			? {
					executablePath: process.env.CHROMIUM_EXECUTABLE_PATH,
					args: [ '--no-sandbox' ],
			  }
			: {},
	},
	globalSetup: './tests/e2e/check-plugin/check-plugin-global-setup.ts',
	webServer: {
		command: [
			'npx @wp-playground/cli server',
			`--port=${ PORT }`,
			'--php=8.3',
			'--wp=latest',
			'--login',
			// Pinned to a single worker: with the default multi-worker pool,
			// the login POST (in global setup) and the later page navigation
			// can land on different worker threads. If each worker's runtime
			// isn't sharing identical wp-config.php auth salts, a cookie
			// issued by one worker fails auth-cookie hash validation on
			// another — the suspected cause of a real-cookie-present,
			// still-"not allowed" failure seen during development.
			'--workers=1',
			`--blueprint=${ BLUEPRINT }`,
			`--mount=${ BUILD_DIR }:/tests-artifacts`,
		].join( ' ' ),
		url: BASE_URL,
		reuseExistingServer: false,
		timeout: 180_000,
	},
} );
