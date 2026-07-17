<?php
/**
 * PHPUnit bootstrap file for Multi-Brand Global Styles plugin tests.
 *
 * @package TheAnother\Plugin\MultiBrandGlobalStyles\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/brain/monkey/inc/patchwork-loader.php';
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

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

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public $post_type;
		public $post_status = 'publish';

		public function __construct( int $id = 0, string $post_type = '' ) {
			$this->ID        = $id;
			$this->post_type = $post_type;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params;

		private string $method;

		public function __construct( array $params = array(), string $method = 'GET' ) {
			$this->params = $params;
			$this->method = $method;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function has_param( string $key ): bool {
			return array_key_exists( $key, $this->params );
		}

		public function get_method(): string {
			return $this->method;
		}
	}
}

if ( ! class_exists( 'WP_Theme_JSON' ) ) {
	/**
	 * Minimal stand-in for core's WP_Theme_JSON. The real class normalizes a
	 * theme.json structure (e.g. flat presets => origin-keyed) and validates it
	 * against the schema; that behavior is exercised against real WordPress in
	 * the e2e suite. Here it only needs to round-trip the data so the wiring —
	 * that GlobalStylesPostService routes stored styles through WP_Theme_JSON —
	 * can be asserted. Tests may override get_raw_data() output via the public
	 * $raw_data_override to simulate core's normalization, and
	 * remove_insecure_properties() output via $insecure_properties_override to
	 * simulate core's value-safety + custom-CSS stripping.
	 */
	class WP_Theme_JSON {
		public const LATEST_SCHEMA = 3;

		public static ?array $raw_data_override = null;

		public static ?array $insecure_properties_override = null;

		/** @var array<int, array{0: array<string, mixed>, 1: string}> */
		public static array $insecure_properties_calls = array();

		private array $data;

		public function __construct( array $data = array(), string $origin = 'theme' ) {
			$this->data = $data;
		}

		public function get_raw_data(): array {
			return self::$raw_data_override ?? $this->data;
		}

		/**
		 * @param array<string, mixed> $theme_json Input theme.json structure.
		 * @param string               $origin     Data origin.
		 * @return array<string, mixed>
		 */
		public static function remove_insecure_properties( array $theme_json, string $origin = 'theme' ): array {
			self::$insecure_properties_calls[] = array( $theme_json, $origin );

			return self::$insecure_properties_override ?? $theme_json;
		}
	}
}

// Do NOT define esc_html() (or other Brain Monkey-stubable WP functions) here:
// a real definition blocks Brain Monkey/Patchwork from redefining them per-test.
// Tests that need them stub via Functions\when() in their own setUp().
