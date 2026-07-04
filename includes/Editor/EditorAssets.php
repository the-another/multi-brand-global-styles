<?php
/**
 * Editor Assets
 *
 * @package MultiBrandGlobalStyles
 * @since 1.1.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Editor;

/**
 * Class EditorAssets
 *
 * Enqueues the built editor bundle (Image-block replacements panel, Brand
 * preview sidebar, canvas swap) from assets/build/, using the
 * wp-scripts-generated *.asset.php for dependencies and cache-busting.
 * Hooked to `enqueue_block_editor_assets`.
 */
class EditorAssets {

	/**
	 * Plugin directory path (trailing slash).
	 *
	 * @var string
	 */
	private string $plugin_dir;

	/**
	 * Plugin directory URL (trailing slash).
	 *
	 * @var string
	 */
	private string $plugin_url;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_dir Plugin directory path.
	 * @param string $plugin_url Plugin directory URL.
	 */
	public function __construct( string $plugin_dir, string $plugin_url ) {
		$this->plugin_dir = $plugin_dir;
		$this->plugin_url = $plugin_url;
	}

	/**
	 * Enqueue the editor bundle. Hooked to `enqueue_block_editor_assets`.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$asset_file = $this->plugin_dir . 'assets/build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'mbgs-editor',
			$this->plugin_url . 'assets/build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_add_inline_script(
			'mbgs-editor',
			'window.mbgsEditor = ' . wp_json_encode( array( 'homeUrl' => home_url( '/' ) ) ) . ';',
			'before'
		);
	}
}
