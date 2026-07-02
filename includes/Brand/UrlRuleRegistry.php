<?php
/**
 * URL Rule Registry Service
 *
 * @package MultiDomainGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiDomainGlobalStyles\Brand;

/**
 * Class UrlRuleRegistry
 *
 * Normalizes URL match rules (host or host/path-prefix) and maintains the
 * cached rule map used to resolve requests to Brands.
 */
class UrlRuleRegistry {

	/**
	 * Transient key for the cached rule map.
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'mdgs_rule_map';

	/**
	 * Normalize a hostname: lowercase, strip scheme/path, strip port, strip leading www.
	 *
	 * @param string $raw Raw hostname or URL.
	 * @return string Normalized hostname, or empty string if nothing usable was found.
	 */
	public function normalize_host( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) || str_starts_with( $raw, '//' ) ) {
			$parsed = wp_parse_url( $raw );
			$raw    = $parsed['host'] ?? '';
		} else {
			// Bare hostname (optionally with a port or trailing path) — take
			// just the leading host-shaped portion.
			preg_match( '#^[a-z0-9.-]+#i', $raw, $matches );
			$raw = $matches[0] ?? '';
		}

		if ( '' === $raw ) {
			return '';
		}

		$raw = strtolower( $raw );
		$raw = preg_replace( '/:\d+$/', '', $raw );
		$raw = preg_replace( '/^www\./', '', $raw );

		return $raw;
	}

	/**
	 * Normalize a path prefix: lowercase, strip query/fragment, strip trailing
	 * wildcard and slashes, ensure a leading slash. Root ('/') collapses to ''.
	 *
	 * @param string $raw Raw path.
	 * @return string Normalized path prefix, or empty string for host-wide.
	 */
	public function normalize_path( string $raw ): string {
		$raw = strtolower( trim( $raw ) );

		$raw = preg_split( '/[?#]/', $raw )[0];
		$raw = preg_replace( '~/?\*$~', '', $raw );
		$raw = trim( $raw, '/' );

		if ( '' === $raw ) {
			return '';
		}

		return '/' . $raw;
	}

	/**
	 * Normalize a full rule: `host` or `host/path/prefix`.
	 *
	 * @param string $raw Raw rule line as entered by an admin.
	 * @return string Normalized rule, or empty string if no usable host was found.
	 */
	public function normalize_rule( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		// Strip scheme so the host/path split below is uniform.
		$raw = preg_replace( '#^[a-z][a-z0-9+.-]*://|^//#i', '', $raw );

		$slash_pos = strpos( $raw, '/' );
		$host_part = false === $slash_pos ? $raw : substr( $raw, 0, $slash_pos );
		$path_part = false === $slash_pos ? '' : substr( $raw, $slash_pos );

		$host = $this->normalize_host( $host_part );

		if ( '' === $host ) {
			return '';
		}

		return $host . $this->normalize_path( $path_part );
	}

	/**
	 * Split a normalized rule back into host and path prefix.
	 *
	 * @param string $rule Normalized rule.
	 * @return array{0: string, 1: string} Host and path prefix ('' for host-wide).
	 */
	public function split_rule( string $rule ): array {
		$slash_pos = strpos( $rule, '/' );

		if ( false === $slash_pos ) {
			return array( $rule, '' );
		}

		return array( substr( $rule, 0, $slash_pos ), substr( $rule, $slash_pos ) );
	}

	/**
	 * Parse a textarea of one-rule-per-line input into a deduped, normalized list.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return array<int, string> Normalized rules, in first-seen order.
	 */
	public function parse_rules_input( string $raw ): array {
		$rules = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$normalized = $this->normalize_rule( $line );
			if ( '' !== $normalized ) {
				$rules[ $normalized ] = true;
			}
		}

		return array_keys( $rules );
	}
}
