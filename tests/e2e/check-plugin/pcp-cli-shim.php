<?php
/**
 * wp-cli --require shim for running Plugin Check inside @wp-playground/cli.
 *
 * Loaded by wp-cli BEFORE WordPress boots (see the wp-cli steps in
 * check-plugin-blueprint.json). It papers over two @wp-playground/cli
 * quirks that otherwise break Plugin Check's CLI runner, and records the
 * evidence run-plugin-check.mjs needs to prove the runtime checks really
 * executed (the previous, AJAX-based suite failed silently on exactly
 * that — see the "Plugin Check runtime checks" gotcha in CLAUDE.md).
 *
 * Quirk 1 — argv: Plugin Check's CLI_Runner::is_plugin_check() parses
 * $_SERVER['argv'] positionally (argv[1] === 'plugin', argv[2] === 'check')
 * and reads its own parameters from argv slices. Under @wp-playground/cli,
 * $_SERVER['argv'] is an empty string and the real argv (in
 * $GLOBALS['argv']) has wp-cli's phar path at [0] and '--path=/wordpress'
 * injected at [1], so Plugin Check never early-initializes and reports
 * runtime checks as nonexistent. Rebuild a canonical argv here — this runs
 * before the object-cache.php drop-in (Plugin Check's early-init hook)
 * inspects it.
 *
 * Quirk 2 — stdout: @wp-playground/cli's `wp-cli` blueprint step does not
 * expose the command's stdout anywhere, so the JSON report would be lost.
 * Buffer all echoed output and append it to the host-mounted results file
 * at shutdown, under a marker header the runner script can parse.
 *
 * @package MultiBrandGlobalStyles
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Quirk 1: rebuild a canonical argv from the real one.
if ( isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ) {
	$pcp_shim_args = array_values(
		array_filter(
			array_slice( $GLOBALS['argv'], 1 ),
			static function ( $arg ) {
				return 0 !== strpos( $arg, '--path=' ) && 0 !== strpos( $arg, '--require=' );
			}
		)
	);
	array_unshift( $pcp_shim_args, 'wp' );
	$_SERVER['argv'] = $pcp_shim_args;
}

/*
 * Record whether Plugin Check actually early-initialized its runner. Only
 * an early-initialized (object-cache.php drop-in + argv match) CLI runner
 * allows runtime checks at all — CLI_Runner::allow_runtime_checks()
 * returns false otherwise. Checking this AFTER WordPress is loaded, from
 * wp-cli's own hook, is what lets the runner script distinguish "runtime
 * checks ran and found nothing" from "runtime checks silently never ran".
 */
WP_CLI::add_hook(
	'after_wp_load',
	static function () {
		$GLOBALS['pcp_shim_early_init'] =
			class_exists( 'WordPress\\Plugin_Check\\Utilities\\Plugin_Request_Utility' )
			&& null !== \WordPress\Plugin_Check\Utilities\Plugin_Request_Utility::get_runner();
	}
);

// Quirk 2: capture all echoed output (incl. the --format=json report).
ob_start();
register_shutdown_function(
	static function () {
		// Never let this handler fatal: if it dies, the marker AND the
		// captured report are both lost and the runner script is left
		// with far poorer diagnostics (only the missing-file failure).
		$argv = isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] )
			? implode( ' ', $_SERVER['argv'] )
			: var_export( $_SERVER['argv'] ?? null, true );

		file_put_contents(
			'/tests-artifacts/plugin-check-results.txt',
			'===RUN=== early_init=' . ( empty( $GLOBALS['pcp_shim_early_init'] ) ? 'no' : 'yes' )
				. ' cmd=' . $argv . "\n"
				. ob_get_contents()
				. "\n===END===\n",
			FILE_APPEND
		);
	}
);
