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

/**
 * Class HostRewriter
 *
 * For Brands that opt in, rewrites the canonical home/siteurl authority in
 * the final rendered HTML to the authority the visitor is actually browsing.
 * Host and port only — path, query, and fragment are never touched. Handles
 * absolute (https://host), protocol-relative (//host), and JSON-escaped
 * (https:\/\/host, \/\/host) forms. Runs LAST among PageBuffer transformers:
 * the image URL map's keys carry canonical-host URLs, so hosts must still be
 * canonical when the image pass runs.
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

		$current_authority = $this->current_authority();

		if ( '' === $current_authority ) {
			return $html;
		}

		$force_https = $settings->url_rewrite_force_https();
		$scheme      = $force_https || is_ssl() ? 'https' : 'http';

		foreach ( $this->canonical_authorities() as $authority ) {
			if ( ! $force_https && $authority === $current_authority ) {
				// Already browsing this canonical authority — nothing to move.
				continue;
			}

			$html = $this->rewrite_authority( $html, $authority, $current_authority, $scheme );
		}

		return $html;
	}

	/**
	 * Get the sanitized, validated authority (host[:port]) being browsed.
	 *
	 * @return string Lowercased authority, or empty string when unusable.
	 */
	private function current_authority(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

		if ( ! preg_match( '/^[a-z0-9.-]+(:\d+)?$/i', $host ) ) {
			return '';
		}

		return strtolower( $host );
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
	 * Swap one canonical authority for the browsed one, in every URL form.
	 *
	 * @param string $html                Subject HTML.
	 * @param string $canonical_authority Canonical authority (host[:port]).
	 * @param string $current_authority   Browsed authority (host[:port]).
	 * @param string $scheme              Target scheme for absolute URLs.
	 * @return string Rewritten HTML.
	 */
	private function rewrite_authority( string $html, string $canonical_authority, string $current_authority, string $scheme ): string {
		list( $host ) = explode( ':', $canonical_authority, 2 );

		// (\\?/\\?/) matches // and the JSON-escaped \/\/ (each slash optionally
		// preceded by a backslash), capturing the separator so the replacement
		// keeps the original form. (?::\d+)? swallows any port so it is replaced
		// together with the host — never appended twice. The lookarounds keep
		// host-boundary safety: canonical.com never matches inside
		// canonical.com.evil.net, sub.canonical.com, or not-canonical.com.
		$pattern = '#(?<![\w.-])(?:(https?):)?(\\\\?/\\\\?/)' . preg_quote( $host, '#' ) . '(?::\d+)?(?![\w.-])#i';

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
