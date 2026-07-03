#!/usr/bin/env node
/**
 * Plugin Check (PCP) suite — runs WordPress.org's official Plugin Check
 * against the packaged release zip in a fresh @wp-playground/cli WordPress
 * installed FROM that zip, via Plugin Check's WP-CLI runner.
 *
 * Why WP-CLI and not the wp-admin AJAX flow: PCP's AJAX flow swaps the
 * whole table set (users, options included) to a freshly-installed pc_-
 * prefixed environment on runtime-check requests, and nothing carries the
 * requester's auth (user row, salts, roles) into it — so its 5 runtime
 * checks (enqueued_scripts_size, enqueued_styles_size,
 * enqueued_styles_scope, enqueued_scripts_scope, non_blocking_scripts)
 * always die unauthenticated ("0"). The WP-CLI runner is upstream's only
 * behat-tested path for runtime checks and needs no auth at all. See the
 * "Plugin Check runtime checks" gotcha in CLAUDE.md for the full story.
 *
 * About the blueprint (check-plugin-blueprint.json — JSON, so its
 * rationale lives here):
 * - plugin-check installs BEFORE our zip: the reverse order broke PCP's
 *   activation with a persistent "database tables are unavailable" error
 *   (verified empirically in the previous suite; root cause never pinned).
 * - The object-cache.php drop-in (PCP's early-init hack) is cp'd before
 *   EACH wp-cli step: runtime checks require it pre-existing, and each
 *   run's cleanup deletes it again.
 * - Both wp-cli steps load pcp-cli-shim.php via --require; the shim fixes
 *   playground's argv/stdout quirks and appends marker-delimited output to
 *   build/plugin-check-results.txt (host-mounted as /tests-artifacts).
 * - Run 1 = PCP's full default check set. Run 2 = the 5 runtime checks
 *   explicitly: if early-init ever regresses, PCP reports those slugs as
 *   nonexistent and this run errors — a loud canary against the silent
 *   under-coverage failure mode the previous suite had.
 *
 * Pass/fail: ERROR-type findings gate; WARNING-type findings are reported
 * but don't fail the suite (same policy as the lint gate and the previous
 * suite). Structural failures (missing runs, early_init=no, fatals,
 * unparseable report lines) always gate — run-blueprint's exit code is 0
 * even when a wp-cli step errors, so this script trusts only the captured
 * output.
 */

import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname( fileURLToPath( import.meta.url ) );
const ROOT = path.resolve( HERE, '../../..' );
const PLUGIN_SLUG = 'the-another-multi-brand-global-styles';
const BUILD_DIR = path.join( ROOT, 'build' );
const ZIP_PATH = path.join( BUILD_DIR, `${ PLUGIN_SLUG }-test.zip` );
const RESULTS_FILE = path.join( BUILD_DIR, 'plugin-check-results.txt' );
const BLUEPRINT = path.join( HERE, 'check-plugin-blueprint.json' );

const RUNTIME_CHECKS = [
	'enqueued_scripts_size',
	'enqueued_styles_size',
	'enqueued_styles_scope',
	'enqueued_scripts_scope',
	'non_blocking_scripts',
];

const failures = [];

function fail( message ) {
	failures.push( message );
	console.error( `✗ ${ message }` );
}

if ( ! existsSync( ZIP_PATH ) ) {
	console.error(
		`✗ Missing ${ path.relative( ROOT, ZIP_PATH ) } — run "npm run plugin-zip:check" first (run-e2e.sh does).`
	);
	process.exit( 1 );
}

/*
 * Boot playground and run the blueprint. Playground downloads WordPress,
 * plugin-check, and wp-cli.phar at run time; any of those can flake on the
 * network (observed: transient ResourceDownloadError on wp-cli.phar in
 * CI-like Docker runs) — that says nothing about the plugin, so retry.
 */
const MAX_ATTEMPTS = 3;
let bootLog = '';
for ( let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++ ) {
	rmSync( RESULTS_FILE, { force: true } );

	console.log(
		`Booting @wp-playground/cli and running Plugin Check (WP-CLI runner)… (attempt ${ attempt }/${ MAX_ATTEMPTS })`
	);
	const run = spawnSync(
		'npx',
		[
			'@wp-playground/cli',
			'run-blueprint',
			'--php=8.3',
			'--wp=latest',
			`--blueprint=${ BLUEPRINT }`,
			`--mount=${ BUILD_DIR }:/tests-artifacts`,
			`--mount=${ HERE }:/pcp-harness`,
		],
		{
			cwd: ROOT,
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			timeout: 15 * 60_000,
			// Node's default maxBuffer is 1 MiB; an erroring playground run
			// (stack traces per worker) can exceed it, which would surface
			// as a bogus ENOBUFS "launch failure" instead of the real error.
			maxBuffer: 64 * 1024 * 1024,
		}
	);

	bootLog = `${ run.stdout ?? '' }${ run.stderr ?? '' }`;

	// Spawn-level failures (timeout, ENOBUFS, missing npx) and playground
	// download flakes are environmental, not plugin problems — retry both.
	const spawnFailure = run.error
		? `Failed to run @wp-playground/cli: ${ run.error.message }`
		: null;
	const downloadFlake = /ResourceDownloadError|Could not download/.test( bootLog )
		? 'Transient playground download failure'
		: null;

	if ( ! spawnFailure && ! downloadFlake ) {
		break;
	}
	if ( attempt < MAX_ATTEMPTS ) {
		console.warn( `⚠ ${ spawnFailure ?? downloadFlake } — retrying…` );
		continue;
	}
	if ( spawnFailure ) {
		console.error( bootLog );
		console.error( `✗ ${ spawnFailure } (after ${ MAX_ATTEMPTS } attempts)` );
		process.exit( 1 );
	}
	// Final-attempt download flake: fall through — the bootLog error scan
	// and missing-results handling below will fail the run with context.
}

// run-blueprint exits 0 even when a wp-cli step errors — scan its log for
// step-level errors ourselves. PHP deprecation notices from wp-cli's own
// phar under PHP 8.3 are expected noise, not failures.
const bootLogProblems = bootLog
	.split( '\n' )
	.filter( ( line ) => /Fatal error|(?<!PHP )Error:/.test( line ) )
	.filter( ( line ) => ! /Deprecated/.test( line ) );
if ( bootLogProblems.length > 0 ) {
	console.error( bootLog );
	fail( `wp-cli reported errors: ${ bootLogProblems.join( ' | ' ) }` );
}

if ( ! existsSync( RESULTS_FILE ) ) {
	console.error( bootLog );
	fail(
		'No plugin-check-results.txt was produced — the wp-cli steps never ran or the shim did not load.'
	);
	report( [], [] );
}

/**
 * Parses one shim-captured run section into findings.
 *
 * Body format (from `wp plugin check --format=json`, interleaved with PHP
 * notice HTML noise):
 *   FILE: includes/foo.js
 *   [{"line":0,...,"type":"WARNING","code":"...","message":"..."}]
 *
 * @param {string} body Section body text.
 * @param {string} cmd  The wp-cli command (for failure messages).
 * @return {Array<Object>} Findings with a `file` property added.
 */
function parseFindings( body, cmd ) {
	const findings = [];
	let currentFile = '(unknown file)';

	for ( const rawLine of body.split( '\n' ) ) {
		const line = rawLine.replace( /<br\s*\/?>/g, '' ).trim();

		const fileMatch = line.match( /^FILE: (.+)$/ );
		if ( fileMatch ) {
			currentFile = fileMatch[ 1 ].trim();
			continue;
		}

		if ( line.startsWith( '[' ) ) {
			try {
				for ( const finding of JSON.parse( line ) ) {
					findings.push( { file: currentFile, ...finding } );
				}
			} catch {
				fail(
					`Unparseable report line from "${ cmd }" (Plugin Check output format drift?): ${ line.slice( 0, 200 ) }`
				);
			}
			continue;
		}

		// A fatal anywhere means the run was cut short — always gate.
		if ( /Fatal error/.test( line ) ) {
			fail( `PHP fatal error during "${ cmd }": ${ line }` );
			continue;
		}

		/*
		 * PHP problems raised by THIS PLUGIN's code while Plugin Check
		 * exercised it (the URL-aware runtime checks actually render
		 * pages) are real defects that never appear as PCP findings —
		 * gate on them. Notices from other code (wp-cli's phar
		 * deprecations, PCP itself, core) are upstream noise we can't
		 * act on, so they're deliberately not matched.
		 */
		if (
			/(Warning|Notice|Deprecated)(<\/b>)?:/.test( line ) &&
			line.includes( `plugins/${ PLUGIN_SLUG }/` )
		) {
			fail( `PHP problem in plugin code during "${ cmd }": ${ line.slice( 0, 300 ) }` );
		}
	}

	return findings;
}

const sections = readFileSync( RESULTS_FILE, 'utf8' )
	.split( /^===RUN=== /m )
	.filter( ( s ) => s.trim().length > 0 )
	.map( ( s ) => {
		const [ header, ...bodyLines ] = s.split( '\n' );
		const cmd = header.match( /cmd=(.*)$/ )?.[ 1 ] ?? '(unknown command)';
		return {
			earlyInit: /early_init=yes/.test( header ),
			cmd,
			body: bodyLines.join( '\n' ).replace( /\n===END===\n?$/, '' ),
		};
	} );

if ( sections.length !== 2 ) {
	fail(
		`Expected 2 Plugin Check runs (full set + runtime canary), found ${ sections.length }.`
	);
}

const canary = sections.find( ( s ) => s.cmd.includes( '--checks=' ) );
if ( ! canary ) {
	fail( 'The runtime-checks canary run (--checks=…) is missing.' );
} else {
	for ( const slug of RUNTIME_CHECKS ) {
		if ( ! canary.cmd.includes( slug ) ) {
			fail( `Runtime canary run does not include the "${ slug }" check.` );
		}
	}
}

for ( const section of sections ) {
	if ( ! section.earlyInit ) {
		// Without early init, PCP silently omits all runtime checks from
		// the full run (and errors on the canary) — exactly the silent
		// under-coverage this suite exists to prevent.
		fail(
			`Plugin Check did NOT early-initialize for "${ section.cmd }" — runtime checks cannot have run.`
		);
	}
}

const allFindings = sections.flatMap( ( s ) => parseFindings( s.body, s.cmd ) );

// The canary's findings are a subset of the full run's — dedupe.
const seen = new Set();
const findings = allFindings.filter( ( f ) => {
	const key = `${ f.file }|${ f.code }|${ f.line }|${ f.column }|${ f.message }`;
	if ( seen.has( key ) ) {
		return false;
	}
	seen.add( key );
	return true;
} );

report(
	findings.filter( ( f ) => f.type === 'ERROR' ),
	findings.filter( ( f ) => f.type !== 'ERROR' )
);

/**
 * Prints the summary and exits with the suite's pass/fail status.
 *
 * @param {Array<Object>} errors   ERROR-type findings (gate).
 * @param {Array<Object>} warnings Everything else (reported only).
 */
function report( errors, warnings ) {
	console.log(
		`\nPlugin Check: ${ errors.length } error(s), ${ warnings.length } warning(s)`
	);
	for ( const f of [ ...errors, ...warnings ] ) {
		console.log(
			`  [${ f.type }] ${ f.file }:${ f.line } ${ f.code } — ${ f.message }`
		);
	}

	if ( errors.length > 0 ) {
		fail( 'Plugin Check found ERROR-level issues (see above).' );
	}

	if ( failures.length > 0 ) {
		console.error( `\n✗ Plugin Check suite FAILED (${ failures.length } failure(s)).` );
		process.exit( 1 );
	}
	console.log( '\n✓ Plugin Check suite passed.' );
	process.exit( 0 );
}
