import { defineConfig } from '@playwright/test';
import * as path from 'node:path';

// This config lives WITH the functional suite it drives; everything that
// must resolve against the repo root does so explicitly via ROOT below.
const ROOT = path.resolve( __dirname, '../../..' );

const PORT = Number( process.env.WP_NOW_PORT ) || 8881;
const BASE_URL = `http://localhost:${ PORT }`;

// RequestUtils from @wordpress/e2e-test-utils-playwright reads WP_BASE_URL
// (defaults to localhost:8889). Override it so it matches our wp-now port.
process.env.WP_BASE_URL = BASE_URL;

export default defineConfig( {
	testDir: '.',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	timeout: process.env.CI ? 60_000 : 30_000,
	// 2, not 1: the PHP-wasm engine's request-concurrency ceiling (a hardcoded
	// 5-instance pool with a 5s acquire timeout in @php-wasm/universal's
	// PHPProcessManager — not configurable via any CLI flag, blueprint step,
	// or public API, verified by reading the installed package source) can
	// transiently 502 a navigation on an asset-heavy admin/editor screen.
	// That clears within one retry (empirically: under half a second), but 1
	// retry leaves no margin if it happens twice in the same run.
	retries: 2,
	workers: 1,
	reporter: 'list',
	// Keep failure artifacts at the repo root (same location as before the
	// config moved here): .gitignore's /test-results/ and CI's
	// upload-artifact path both point there.
	outputDir: path.join( ROOT, 'test-results' ),
	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on',
		launchOptions: process.env.CHROMIUM_EXECUTABLE_PATH
			? {
					executablePath: process.env.CHROMIUM_EXECUTABLE_PATH,
					args: [ '--no-sandbox' ],
			  }
			: {},
	},
	projects: [
		{
			name: 'setup',
			testMatch: '*.setup.ts',
			retries: 0,
		},
		{
			name: 'default',
			testMatch: '*.spec.ts',
			dependencies: [ 'setup' ],
		},
	],
	globalSetup: './global-setup.ts',
	globalTeardown: './global-teardown.ts',
	webServer: {
		command: `npx wp-now start --port=${ PORT } --php=8.3 --reset --skip-browser --blueprint=tests/e2e/functional/functional-blueprint.json`,
		// wp-now mode-detects from its cwd and mounts it as the plugin;
		// Playwright defaults webServer.cwd to this config file's directory,
		// which would mount tests/e2e/functional instead of the plugin. Pin
		// the repo root (also keeps the --blueprint path above stable).
		cwd: ROOT,
		url: BASE_URL,
		reuseExistingServer: ! process.env.CI,
		timeout: 120_000,
	},
} );
