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
		// --auto-mount mode-detects this plugin from cwd (see cwd: ROOT
		// below), matching wp-now's old plugin-mode detection. --mount adds
		// the permalink mu-plugin directly into the running instance's
		// mu-plugins directory, replacing wp-now's old approach of copying
		// it into a shared, machine-global ~/.wp-now/mu-plugins/ directory.
		// No --reset: unlike wp-now, `server` mode uses ephemeral temp
		// storage per spawn already, so every fresh spawn starts from a
		// clean site with no flag needed. No --login: the Blueprint already
		// supplies "login": true, and combining it with a CLI --login flag
		// causes a cookie-path conflict (see the check-plugin suite's own
		// history in CLAUDE.md). --workers=6, not the CLI's own default
		// (min(6, cpus-1)): each worker is an independent thread with its
		// own PHP runtime, so total concurrent request capacity literally
		// equals the worker count, and the CLI's own boot-time warning
		// treats 6 as a hard safe floor, not a ratio to available cores
		// ("Running fewer than 6 workers may increase the likelihood of
		// deadlock due to workers blocking on file locks"). This is a
		// defensible floor regardless of host: on a 6-CPU Docker host the
		// default computes to 5 -- one below that floor -- while this
		// suite was hanging past both webServer.timeout and
		// waitForRealReadiness's own 60s deadline (see the "unexplained
		// hang" gotcha history in CLAUDE.md). NOT independently confirmed
		// to fix that hang, though: a retry with --workers=6 explicit
		// still hung identically on the same heavily-oversubscribed local
		// Docker host (~20 unrelated containers, load average 15-21+),
		// which points at least as strongly at host contention as at
		// worker count. Keep this pinned at 6 on its own merits; treat a
		// clean CI run (a dedicated, uncontended runner) as the real
		// verification this fix needed but didn't get locally.
		command: `npx @wp-playground/cli server --auto-mount --blueprint=tests/e2e/functional/functional-blueprint.json --mount=tests/e2e/functional/e2e-environment.php:/wordpress/wp-content/mu-plugins/e2e-environment.php --port=${ PORT } --php=8.3 --workers=6`,
		// @wp-playground/cli's --auto-mount detects from its cwd like
		// wp-now did; Playwright defaults webServer.cwd to this config
		// file's directory, which would mount tests/e2e/functional instead
		// of the plugin. Pin the repo root (also keeps the
		// --blueprint/--mount paths above stable).
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
