import type { FullConfig } from '@playwright/test';
import { RequestUtils } from '@wordpress/e2e-test-utils-playwright';
import * as fs from 'node:fs';
import * as path from 'node:path';
import * as os from 'node:os';

/**
 * Path where wp-now loads shared mu-plugins from.
 */
const WP_NOW_MU_PLUGINS_DIR = path.join( os.homedir(), '.wp-now', 'mu-plugins' );

/**
 * Mu-plugins to copy into wp-now's shared directory.
 */
const MU_PLUGINS = [ 'e2e-environment.php' ];

/**
 * Copy E2E mu-plugins into wp-now's shared mu-plugins directory
 * so WordPress loads them without any coupling to the main plugin.
 */
function installMuPlugins(): void {
	if ( ! fs.existsSync( WP_NOW_MU_PLUGINS_DIR ) ) {
		fs.mkdirSync( WP_NOW_MU_PLUGINS_DIR, { recursive: true } );
	}

	for ( const filename of MU_PLUGINS ) {
		fs.copyFileSync(
			path.resolve( __dirname, filename ),
			path.join( WP_NOW_MU_PLUGINS_DIR, filename )
		);
	}
}

export default async function globalSetup( config: FullConfig ) {
	const { baseURL } = config.projects[ 0 ].use as { baseURL: string };
	const storageStatePath = 'artifacts/storage-states/admin.json';

	// Install mu-plugins before any requests that depend on them.
	installMuPlugins();

	const requestUtils = await RequestUtils.setup( {
		baseURL,
		storageStatePath,
	} );

	// wp-now auto-activates the mounted plugin in plugin mode; this is the
	// explicit safety net (and the activation assertion for a --reset run).
	try {
		await requestUtils.activatePlugin(
			'the-another-multi-domain-global-styles'
		);
	} catch {
		// Already active.
	}
}
