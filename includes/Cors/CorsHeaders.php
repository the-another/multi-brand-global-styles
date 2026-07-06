<?php
/**
 * CORS Headers Service
 *
 * @package MultiBrandGlobalStyles
 * @since 1.3.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Cors;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;

/**
 * Class CorsHeaders
 *
 * Sets Access-Control-Allow-Origin on every frontend response so that assets
 * (CSS, JS, fonts, images) served from the canonical host are consumable by
 * pages rendered on any Brand host — and vice-versa. The allowed-origin list
 * is built dynamically from the canonical home/siteurl hosts plus every host
 * that appears in a published Brand's URL rules.
 *
 * Only one Origin can be reflected per response (the spec forbids a
 * space-separated list), so the incoming Origin header is validated against
 * the allowed set and echoed back when it matches, with `Vary: Origin` so
 * shared caches key on the Origin header.
 */
class CorsHeaders {

	/**
	 * URL rule registry.
	 *
	 * @var UrlRuleRegistry
	 */
	private UrlRuleRegistry $url_rule_registry;

	/**
	 * Constructor.
	 *
	 * @param UrlRuleRegistry $url_rule_registry URL rule registry service.
	 */
	public function __construct( UrlRuleRegistry $url_rule_registry ) {
		$this->url_rule_registry = $url_rule_registry;
	}

	/**
	 * Send CORS headers if the request's Origin matches a known Brand host.
	 *
	 * Hooked to `send_headers` (fired by wp() after the main query and
	 * before template loading). Skipped for admin, AJAX, and REST requests
	 * (WordPress core already handles CORS for the REST API).
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( is_admin() || wp_doing_ajax() || defined( 'REST_REQUEST' ) ) {
			return;
		}

		$origin = $this->get_allowed_origin();

		if ( null === $origin ) {
			return;
		}

		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Vary: Origin', false );
	}

	/**
	 * Determine the allowed origin for the current request.
	 *
	 * Returns the validated, lowercased origin if the request's Origin
	 * header matches one of the known Brand hosts or the canonical site
	 * hosts; null otherwise.
	 *
	 * @return string|null The matched origin, or null if no match.
	 */
	public function get_allowed_origin(): ?string {
		$origin = $this->get_request_origin();

		if ( '' === $origin ) {
			return null;
		}

		$allowed = $this->get_allowed_origins();

		if ( in_array( $origin, $allowed, true ) ) {
			return $origin;
		}

		return null;
	}

	/**
	 * Get the sanitized Origin header from the current request.
	 *
	 * @return string The origin URL (e.g. `https://brand.example.com`), or
	 *                empty string if absent or malformed.
	 */
	private function get_request_origin(): string {
		if ( ! isset( $_SERVER['HTTP_ORIGIN'] ) ) {
			return '';
		}

		$origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );

		// Origins are scheme + host (+ optional port), never bare hostnames.
		if ( ! preg_match( '#^https?://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d+)?$#i', $origin ) ) {
			return '';
		}

		return strtolower( $origin );
	}

	/**
	 * Build the list of allowed origins from the canonical site URLs and
	 * every host that appears in a published Brand's URL rules.
	 *
	 * Each host is expanded into both `https://` and `http://` origins so
	 * cross-scheme requests are not silently blocked.
	 *
	 * @return array<int, string> Allowed origin URLs (lowercased, deduped).
	 */
	private function get_allowed_origins(): array {
		$hosts = array();

		// Canonical hosts from home / siteurl.
		foreach ( array( get_option( 'home' ), get_option( 'siteurl' ) ) as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$parts = wp_parse_url( $url );

			if ( ! empty( $parts['host'] ) ) {
				$authority           = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
				$hosts[ $authority ] = true;
			}
		}

		// Every host from the rule map (already normalized, www-stripped).
		foreach ( array_keys( $this->url_rule_registry->get_rule_map() ) as $host ) {
			$hosts[ $host ] = true;
		}

		$origins = array();

		foreach ( array_keys( $hosts ) as $authority ) {
			$origins[] = 'https://' . $authority;
			$origins[] = 'http://' . $authority;
		}

		return $origins;
	}
}
