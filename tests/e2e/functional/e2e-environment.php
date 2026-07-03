<?php
/**
 * E2E test environment fixes for wp-now.
 *
 * Installed as a wp-now mu-plugin by the Playwright global setup.
 * Never loaded by the main plugin; not shipped in production releases.
 *
 * @package MultiDomainGlobalStyles
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Pretty permalinks ------------------------------------------------------

// Tell WordPress the server supports URL rewriting.
add_filter( 'got_url_rewrite', '__return_true' );

// wp-now starts with "Plain" permalinks. The plugin's path-scoped Brand
// rules (host/path-prefix) need real path URLs like /sample-page/, so set
// a proper structure on first load.
add_action(
	'init',
	function () {
		if ( get_option( 'permalink_structure' ) === '' ) {
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			flush_rewrite_rules();
		}
	},
	999
);
