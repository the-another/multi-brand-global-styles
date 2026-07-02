<?php
/**
 * Plugin Name: The Another Multi-Domain Global Styles
 * Plugin URI: https://theanother.org/plugin/multi-domain-global-styles/
 * Description: Define Brands — URL match rules (whole domains or path sections) with per-Brand global style overrides and content variables — on a single WordPress install.
 * Version: 0.1.0
 * Author: The Another
 * Author URI: https://theanother.org
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Text Domain: the-another-multi-domain-global-styles
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package MultiDomainGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiDomainGlobalStyles;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'THE_ANOTHER_MULTI_DOMAIN_GLOBAL_STYLES_VERSION', '0.1.0' );
define( 'THE_ANOTHER_MULTI_DOMAIN_GLOBAL_STYLES_PLUGIN_FILE', __FILE__ );
define( 'THE_ANOTHER_MULTI_DOMAIN_GLOBAL_STYLES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'THE_ANOTHER_MULTI_DOMAIN_GLOBAL_STYLES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'THE_ANOTHER_MULTI_DOMAIN_GLOBAL_STYLES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'The Another Multi-Domain Global Styles requires PHP 8.3 or higher. Please upgrade your PHP version.', 'the-another-multi-domain-global-styles' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'The Another Multi-Domain Global Styles requires WordPress 6.9 or higher. Please upgrade WordPress.', 'the-another-multi-domain-global-styles' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

if ( file_exists( THE_ANOTHER_MULTI_DOMAIN_GLOBAL_STYLES_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once THE_ANOTHER_MULTI_DOMAIN_GLOBAL_STYLES_PLUGIN_DIR . 'vendor/autoload.php';
}

add_action(
	'plugins_loaded',
	function () {
		try {
			Plugin::get_instance()->start();
		} catch ( Exception $e ) {
			wp_die(
				esc_html( $e->getMessage() ),
				'Multi-Domain Global Styles Error',
				array( 'response' => 500 )
			);
		}
	}
);
