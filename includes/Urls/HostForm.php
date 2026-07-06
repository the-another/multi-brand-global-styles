<?php
/**
 * Host Form
 *
 * @package MultiBrandGlobalStyles
 * @since 1.3.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Urls;

/**
 * Class HostForm
 *
 * Pure www↔apex transforms over an authority (host[:port]). `to_www` and
 * `to_apex` are exact inverses on a leading `www.`, which is what makes the
 * canonicalization redirect loop-safe. Subdomains are handled literally
 * (`beta.brand.com` → `www.beta.brand.com`); that is intended and
 * operator-owned.
 */
final class HostForm {

	/**
	 * Ensure a single leading `www.` on the authority's host.
	 *
	 * @param string $authority Authority (host[:port]).
	 * @return string Authority in www form.
	 */
	public static function to_www( string $authority ): string {
		if ( str_starts_with( $authority, 'www.' ) ) {
			return $authority;
		}

		return 'www.' . $authority;
	}

	/**
	 * Strip a single leading `www.` from the authority's host.
	 *
	 * @param string $authority Authority (host[:port]).
	 * @return string Authority in apex form.
	 */
	public static function to_apex( string $authority ): string {
		if ( str_starts_with( $authority, 'www.' ) ) {
			return substr( $authority, 4 );
		}

		return $authority;
	}

	/**
	 * Whether the authority is already in the given form.
	 *
	 * @param string $authority Authority (host[:port]).
	 * @param string $form      'www' or 'apex'. Any other value ⇒ true.
	 * @return bool True when nothing needs to change.
	 */
	public static function matches( string $authority, string $form ): bool {
		if ( 'www' === $form ) {
			return str_starts_with( $authority, 'www.' );
		}

		if ( 'apex' === $form ) {
			return ! str_starts_with( $authority, 'www.' );
		}

		return true;
	}

	/**
	 * Transform the authority into the given form.
	 *
	 * @param string $authority Authority (host[:port]).
	 * @param string $form      'www', 'apex', or anything else (no-op).
	 * @return string Transformed authority.
	 */
	public static function apply( string $authority, string $form ): string {
		if ( 'www' === $form ) {
			return self::to_www( $authority );
		}

		if ( 'apex' === $form ) {
			return self::to_apex( $authority );
		}

		return $authority;
	}
}
