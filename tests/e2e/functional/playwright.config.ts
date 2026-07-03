import { defineConfig } from '@playwright/test';
import * as path from 'node:path';

// This config lives WITH the functional suite it drives; everything that
// must resolve against the repo root does so explicitly via ROOT below.
const ROOT = path.resolve( __dirname, '../../..' );

const PORT = Number( process.env.WP_E2E_PORT ) || 8881;
const BASE_URL = `http://localhost:${ PORT }`;

// RequestUtils from @wordpress/e2e-test-utils-playwright reads WP_BASE_URL
// (defaults to localhost:8889). Override it so it matches our e2e port.
process.env.WP_BASE_URL = BASE_URL;

export default defineConfig( {
	testDir: '.',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	timeout: process.env.CI ? 60_000 : 30_000,
	// A safety net for ordinary transient flakiness, not a load-bearing
	// workaround: @wp-playground/cli server's request concurrency is sized
	// by --workers (one worker THREAD per in-flight request — a different
	// "workers" than Playwright's own test-runner `workers: 1` below), and
	// webServer.command pins it to 6 rather than trusting the CLI's own
	// default — see that comment.
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
	webServer: {
		// Explicit --mount flags for exactly the plugin's own runtime files
		// (main file, includes/, vendor/, readme.txt), not --auto-mount:
		// --auto-mount names the plugin's wp-content/plugins/ folder after
		// the mounted host directory's basename, which inside this repo's
		// Docker image is /app (the container's WORKDIR), not the plugin's
		// real slug — confirmed empirically, this broke the Blueprint's own
		// activatePlugin step below ("Plugin /wordpress/wp-content/plugins
		// /app could not be activated"). Explicit mounts also keep dev-only
		// directories (node_modules/, .git/, tests/, docs/) out of the
		// mounted plugin folder entirely, rather than relying on WordPress's
		// plugin-file scanner to skip over them.
		//
		// --site-url matches BASE_URL (http://localhost:<port>): without
		// it, @wp-playground/cli defaults WordPress's own site URL to
		// http://127.0.0.1:<port>. Both resolve to the same server, but a
		// browser treats them as different origins — client-side REST API
		// fetches from any admin page (loaded at the localhost origin) were
		// blocked by CORS against the 127.0.0.1-rooted API root WordPress
		// advertised, confirmed empirically via the browser's own console
		// error ("blocked by CORS policy... Redirect is not allowed for a
		// preflight request").
		//
		// No --reset: unlike wp-now, `server` mode uses ephemeral temp
		// storage per spawn already, so every fresh spawn starts from a
		// clean site with no flag needed. No --login: the Blueprint already
		// supplies "login": true, and combining it with a CLI --login flag
		// causes a cookie-path conflict (see the check-plugin suite's own
		// history in CLAUDE.md). --workers=6, not the CLI's own default
		// (min(6, cpus-1), which computes to only 5 on a 6-CPU host): the
		// CLI's own boot-time warning treats 6 as a hard safe floor, not a
		// ratio to available cores ("Running fewer than 6 workers may
		// increase the likelihood of deadlock due to workers blocking on
		// file locks"). Kept on those documented merits, though it wasn't
		// the fix for this suite's specific historical "unexplained hang"
		// (see CLAUDE.md) — that traced to two other bugs (the mount-path
		// issue above, and functional-blueprint.json's activatePlugin step
		// replacing a runtime REST call that had no request timeout and
		// could hang indefinitely).
		command: `npx @wp-playground/cli server --site-url=${ BASE_URL } --mount=the-another-multi-brand-global-styles.php:/wordpress/wp-content/plugins/the-another-multi-brand-global-styles/the-another-multi-brand-global-styles.php --mount=includes:/wordpress/wp-content/plugins/the-another-multi-brand-global-styles/includes --mount=vendor:/wordpress/wp-content/plugins/the-another-multi-brand-global-styles/vendor --mount=readme.txt:/wordpress/wp-content/plugins/the-another-multi-brand-global-styles/readme.txt --mount=tests/e2e/functional/e2e-environment.php:/wordpress/wp-content/mu-plugins/e2e-environment.php --blueprint=tests/e2e/functional/functional-blueprint.json --port=${ PORT } --php=8.3 --workers=6`,
		// Playwright defaults webServer.cwd to this config file's
		// directory; pin the repo root instead so the relative --mount and
		// --blueprint paths above resolve correctly.
		cwd: ROOT,
		// Not `url: BASE_URL`: @wp-playground/cli's Blueprint "login": true
		// step 302-redirects-to-self on every request from a client that
		// doesn't already carry the cookie it sets on that first hit —
		// confirmed empirically (a cookie-less client loops forever; a
		// cookie-jar client resolves in exactly 2 hops: 302 then 200).
		// Playwright's built-in `url` readiness poller (playwright-core's
		// httpRequest/isURLAvailable) follows redirects but carries no
		// cookies, so it would loop forever against that URL until
		// webServer.timeout kills the run — this, not anything Docker- or
		// musl-specific, is what a prior migration attempt's "hang right
		// after Ready!" (see CLAUDE.md) actually was. `port` is a pure
		// TCP-accept check with no HTTP semantics, so it's immune to the
		// loop. Real readiness (WordPress actually installed and
		// answering, not just the process having bound the port) is still
		// gated by globalSetup's waitForRealReadiness(), which uses a real
		// Playwright request context (a cookie jar, like a browser) and
		// already resolves the same redirect in 2 hops.
		port: PORT,
		reuseExistingServer: ! process.env.CI,
		timeout: 120_000,
	},
} );
