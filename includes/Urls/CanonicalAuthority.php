<?php
/**
 * Canonical Authority
 *
 * @package MultiBrandGlobalStyles
 * @since 0.4.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Urls;

/**
 * Class CanonicalAuthority
 *
 * The install's own authorities — the hosts (plus explicit ports) of the
 * `home` and `siteurl` options, deduped. These are the authorities the
 * plugin is allowed to swap out for the browsed one; single source of truth
 * shared by HostRewriter (which hosts to look for in the HTML) and
 * RequestHomeUrl (whether a URL handed to the bridge filter is a home URL at
 * all).
 */
final class CanonicalAuthority {

	/**
	 * Get the canonical authorities.
	 *
	 * @return array<int, string> Lowercased host[:port] values, deduped.
	 */
	public static function all(): array {
		$authorities = array();

		foreach ( array( get_option( 'home' ), get_option( 'siteurl' ) ) as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$parts = wp_parse_url( $url );

			if ( empty( $parts['host'] ) ) {
				continue;
			}

			$authority = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

			$authorities[ $authority ] = true;
		}

		return array_keys( $authorities );
	}

	/**
	 * Get the canonical hosts, without ports.
	 *
	 * @return array<int, string> Lowercased hostnames, deduped.
	 */
	public static function hosts(): array {
		$hosts = array();

		foreach ( self::all() as $authority ) {
			list( $host )                 = explode( ':', $authority, 2 );
			$hosts[ strtolower( $host ) ] = true;
		}

		return array_keys( $hosts );
	}

	/**
	 * Whether a hostname is one of the canonical hosts.
	 *
	 * Port-insensitive, matching HostRewriter: a canonical host on an
	 * unusual port is still the canonical host.
	 *
	 * @param string $host Hostname to test (no port).
	 * @return bool True when the host is the home or siteurl host.
	 */
	public static function matches_host( string $host ): bool {
		return in_array( strtolower( $host ), self::hosts(), true );
	}
}
