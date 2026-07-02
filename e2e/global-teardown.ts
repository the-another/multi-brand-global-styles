import * as fs from 'node:fs';
import * as path from 'node:path';
import * as os from 'node:os';

const WP_NOW_MU_PLUGINS_DIR = path.join( os.homedir(), '.wp-now', 'mu-plugins' );
const MU_PLUGINS = [ 'e2e-environment.php' ];

/**
 * Remove E2E mu-plugins so they don't linger in the shared wp-now
 * directory after tests finish.
 */
export default async function globalTeardown() {
	for ( const filename of MU_PLUGINS ) {
		const target = path.join( WP_NOW_MU_PLUGINS_DIR, filename );
		if ( fs.existsSync( target ) ) {
			fs.unlinkSync( target );
		}
	}
}
