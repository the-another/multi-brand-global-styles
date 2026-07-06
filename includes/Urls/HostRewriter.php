<?php
/**
 * Host Rewriter
 *
 * @package MultiBrandGlobalStyles
 * @since 1.2.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Urls;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\RequestAuthority;

/**
 * Class HostRewriter
 *
 * For Brands that opt in, rewrites the canonical home/siteurl authority in
 * the final rendered HTML to the authority the visitor is actually browsing.
 * Host and port only — path, query, and fragment are never touched. Handles
 * absolute (https://host), protocol-relative (//host), and JSON-escaped
 * (https:\/\/host, \/\/host) forms. Runs LAST among PageBuffer transformers:
 * the image URL map's keys carry canonical-host URLs, so hosts must still be
 * canonical when the image pass runs. Also guards redirect_canonical so core
 * cannot bounce opted-in Brands' visitors back to the canonical host.
 */
class HostRewriter {

	/**
	 * Brand resolver.
	 *
	 * @var BrandResolver
	 */
	private BrandResolver $brand_resolver;

	/**
	 * Brand repository.
	 *
	 * @var BrandRepository
	 */
	private BrandRepository $brand_repository;

	/**
	 * Constructor.
	 *
	 * @param BrandResolver   $brand_resolver   Brand resolver service.
	 * @param BrandRepository $brand_repository Brand repository service.
	 */
	public function __construct( BrandResolver $brand_resolver, BrandRepository $brand_repository ) {
		$this->brand_resolver   = $brand_resolver;
		$this->brand_repository = $brand_repository;
	}

	/**
	 * Rewrite canonical-authority URLs in the rendered HTML to the browsed
	 * authority. Fails open: any condition it cannot interpret returns the
	 * HTML unchanged.
	 *
	 * @param string $html Rendered page HTML.
	 * @return string HTML with canonical authorities rewritten.
	 */
	public function replace( string $html ): string {
		$brand_id = $this->brand_resolver->resolve_current_request();

		if ( null === $brand_id ) {
			return $html;
		}

		$settings = $this->brand_repository->get_settings( $brand_id );

		if ( ! $settings->url_rewrite_enabled() ) {
			return $html;
		}

		$current_authority = RequestAuthority::current();

		if ( '' === $current_authority ) {
			return $html;
		}

		$force_https = $settings->url_rewrite_force_https();
		$scheme      = $force_https || is_ssl() ? 'https' : 'http';

		$hosts = array();

		foreach ( $this->canonical_authorities() as $authority ) {
			if ( ! $force_https && $authority === $current_authority ) {
				// Already browsing this canonical authority — nothing to move.
				continue;
			}

			list( $host )                 = explode( ':', $authority, 2 );
			$hosts[ strtolower( $host ) ] = true;
		}

		if ( empty( $hosts ) ) {
			return $html;
		}

		return $this->rewrite_hosts( $html, array_keys( $hosts ), $current_authority, $scheme );
	}

	/**
	 * Guard WordPress's canonical redirect. Hooked to `redirect_canonical`.
	 *
	 * Core rebuilds canonical URLs from home_url()/get_permalink(), so on a
	 * non-canonical Brand host every request would 301 back to the canonical
	 * domain before the HTML pass ever ran. Apply the same authority rewrite
	 * to the proposed redirect: if nothing but the host differed, cancel the
	 * redirect entirely; otherwise keep the path/query canonicalization on
	 * the browsed host.
	 *
	 * @param mixed $redirect_url  Proposed canonical redirect URL (string|false).
	 * @param mixed $requested_url Originally requested URL.
	 * @return mixed False to cancel, or the (possibly rewritten) redirect URL.
	 */
	public function filter_redirect_canonical( mixed $redirect_url, mixed $requested_url ): mixed {
		if ( ! is_string( $redirect_url ) || '' === $redirect_url ) {
			return $redirect_url;
		}

		$rewritten = $this->replace( $redirect_url );

		if ( $rewritten === $redirect_url ) {
			return $redirect_url;
		}

		if ( is_string( $requested_url ) && $rewritten === $requested_url ) {
			return false;
		}

		return $rewritten;
	}

	/**
	 * Get the canonical authorities: the hosts (plus explicit ports) of the
	 * home and siteurl options, deduped.
	 *
	 * @return array<int, string> Lowercased authorities.
	 */
	private function canonical_authorities(): array {
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
	 * Swap every canonical host for the browsed authority, in every URL form,
	 * in ONE pass.
	 *
	 * All canonical hosts are combined into a single alternation so the HTML is
	 * scanned once no matter how many canonical hosts there are (home + siteurl
	 * when they differ) — instead of one full pass per host.
	 *
	 * @param string             $html              Subject HTML.
	 * @param array<int, string> $hosts             Canonical hosts (no port), deduped.
	 * @param string             $current_authority Browsed authority (host[:port]).
	 * @param string             $scheme            Target scheme for absolute URLs.
	 * @return string Rewritten HTML.
	 */
	private function rewrite_hosts( string $html, array $hosts, string $current_authority, string $scheme ): string {
		$alternation = implode(
			'|',
			array_map(
				static fn( string $host ): string => preg_quote( $host, '#' ),
				$hosts
			)
		);

		// (\\?/\\?/) matches // and the JSON-escaped \/\/ (each slash optionally
		// preceded by a backslash), capturing the separator so the replacement
		// keeps the original form. (?::\d+)? swallows any port so it is replaced
		// together with the host — never appended twice. The lookarounds keep
		// host-boundary safety: canonical.com never matches inside
		// canonical.com.evil.net, sub.canonical.com, or not-canonical.com — and
		// the host alternation is anchored right after the slashes, so a
		// canonical host embedded in a longer non-canonical host is never hit.
		$pattern = '#(?<![\w.-])(?:(https?):)?(\\\\?/\\\\?/)(?:' . $alternation . ')(?::\d+)?(?![\w.-])#i';

		$result = preg_replace_callback(
			$pattern,
			static function ( array $matches ) use ( $current_authority, $scheme ) {
				$scheme_prefix = '' !== $matches[1] ? $scheme . ':' : '';

				return $scheme_prefix . $matches[2] . $current_authority;
			},
			$html
		);

		return is_string( $result ) ? $result : $html;
	}
}
