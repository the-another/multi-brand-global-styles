import { defineConfig } from '@playwright/test';

const PORT = Number( process.env.WP_NOW_PORT ) || 8881;
const BASE_URL = `http://localhost:${ PORT }`;

// RequestUtils from @wordpress/e2e-test-utils-playwright reads WP_BASE_URL
// (defaults to localhost:8889). Override it so it matches our wp-now port.
process.env.WP_BASE_URL = BASE_URL;

export default defineConfig( {
	testDir: './e2e',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	timeout: process.env.CI ? 60_000 : 30_000,
	retries: 1,
	workers: 1,
	reporter: 'list',
	use: {
		baseURL: BASE_URL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on',
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
	globalSetup: './e2e/global-setup.ts',
	globalTeardown: './e2e/global-teardown.ts',
	webServer: {
		command: `npx wp-now start --port=${ PORT } --php=8.3 --reset --skip-browser`,
		url: BASE_URL,
		reuseExistingServer: ! process.env.CI,
		timeout: 120_000,
	},
} );
