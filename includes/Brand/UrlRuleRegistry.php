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
	 * A host is only ever normalized in full or rejected — never truncated.
	 * If the remaining token contains any character outside `[a-z0-9.-]`,
	 * the whole thing is treated as junk and an empty string is returned.
	 * Internationalized domains must be entered as punycode (e.g.
	 * `xn--mnchen-3ya.de`), which is plain ASCII and passes validation.
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
			// Bare hostname (optionally with a trailing port or path) — drop
			// the port here; the path (if any) was already split off by the
			// caller, so what's left should be host-shaped or nothing at all.
			$raw = preg_replace( '/:\d+$/', '', $raw );
		}

		if ( '' === $raw ) {
			return '';
		}

		$raw = strtolower( $raw );
		$raw = preg_replace( '/:\d+$/', '', $raw );
		$raw = preg_replace( '/^www\./', '', $raw );

		if ( ! preg_match( '/^[a-z0-9.-]+$/', $raw ) ) {
			return '';
		}

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

	/**
	 * Get the cached rule map, rebuilding it if not cached.
	 *
	 * @return array<string, array<string, int>> Host => (path prefix => Brand ID). '' prefix = host-wide.
	 */
	public function get_rule_map(): array {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map       = array();
		$brand_ids = get_posts(
			array(
				'post_type'      => 'mdgs_brand',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $brand_ids as $brand_id ) {
			$rules = get_post_meta( $brand_id, '_mdgs_rules', true );

			if ( ! is_array( $rules ) ) {
				continue;
			}

			foreach ( $rules as $rule ) {
				list( $host, $path_prefix ) = $this->split_rule( $rule );

				$map[ $host ][ $path_prefix ] = $brand_id;
			}
		}

		set_transient( self::CACHE_KEY, $map, 0 );

		return $map;
	}

	/**
	 * Find the Brand that already owns an exact rule, if any other than $exclude_post_id.
	 *
	 * Overlapping-but-different rules (site.com vs site.com/farm) never conflict.
	 *
	 * @param string $normalized_rule Normalized rule.
	 * @param int    $exclude_post_id Brand post ID to treat as "self" (never reported as a conflict).
	 * @return int|null Conflicting Brand ID, or null if the exact rule is free (or owned by $exclude_post_id).
	 */
	public function find_conflicting_brand( string $normalized_rule, int $exclude_post_id = 0 ): ?int {
		list( $host, $path_prefix ) = $this->split_rule( $normalized_rule );

		$map = $this->get_rule_map();

		if ( ! isset( $map[ $host ][ $path_prefix ] ) ) {
			return null;
		}

		if ( $map[ $host ][ $path_prefix ] === $exclude_post_id ) {
			return null;
		}

		return $map[ $host ][ $path_prefix ];
	}

	/**
	 * Invalidate the cached rule map. Call after any Brand save/trash/delete.
	 *
	 * @return void
	 */
	public function invalidate_cache(): void {
		delete_transient( self::CACHE_KEY );
	}
}
