<?php
/**
 * Request Authority
 *
 * @package MultiBrandGlobalStyles
 * @since 1.3.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Urls;

/**
 * Class RequestAuthority
 *
 * The sanitized, validated authority (host[:port]) of the current request.
 * Single source of truth shared by HostRewriter (rewrite target) and
 * HostCanonicalizer (redirect decision).
 */
final class RequestAuthority {

	/**
	 * Get the current request authority.
	 *
	 * @return string Lowercased host[:port], or '' when absent/invalid.
	 */
	public static function current(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

		if ( ! preg_match( '/^[a-z0-9.-]+(:\d+)?$/i', $host ) ) {
			return '';
		}

		return strtolower( $host );
	}
}
