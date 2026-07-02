<?php
/**
 * PHPUnit bootstrap file for Multi-Domain Global Styles plugin tests.
 *
 * @package TheAnother\Plugin\MultiDomainGlobalStyles\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/patchwork-loader.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors     = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			$this->errors[ $code ][]   = $message;
			$this->error_data[ $code ] = $data;
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$codes = array_keys( $this->errors );
				$code  = $codes[0] ?? '';
			}
			return isset( $this->errors[ $code ] ) ? $this->errors[ $code ][0] : '';
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

// Do NOT define esc_html() (or other Brain Monkey-stubable WP functions) here:
// a real definition blocks Brain Monkey/Patchwork from redefining them per-test.
// Tests that need them stub via Functions\when() in their own setUp().
