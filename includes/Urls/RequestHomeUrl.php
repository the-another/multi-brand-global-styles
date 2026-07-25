<?php
/**
 * Request Home URL
 *
 * @package MultiBrandGlobalStyles
 * @since 0.4.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Urls;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;

/**
 * Class RequestHomeUrl
 *
 * Callback behind the public `mbgs_request_home_url` bridge filter: given a
 * URL built for the canonical home (typically home_url()), returns it with
 * scheme and authority swapped to the authority the visitor is actually
 * browsing. For server-side consumers — emails, API payloads — whose output
 * never passes through the PageBuffer / redirect / REST egresses that
 * HostRewriter covers. Gated exactly like HostRewriter: a Brand must resolve
 * for the current request and have its URL-rewrite option enabled; the scheme
 * honors the Brand's force-https setting. Fails open: any condition it cannot
 * interpret returns the input unchanged.
 */
class RequestHomeUrl {

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
	 * Swap the URL's scheme and authority to the browsed authority when the
	 * resolved Brand opted into URL rewriting.
	 *
	 * @param string $url URL built for the canonical home; path, query and
	 *                    fragment are preserved.
	 * @return string Brand-aware URL, or the input unchanged.
	 */
	public function filter( string $url ): string {
		$authority = RequestAuthority::current();
		if ( '' === $authority ) {
			return $url;
		}

		$brand_id = $this->brand_resolver->resolve_current_request();
		if ( null === $brand_id ) {
			return $url;
		}

		$settings = $this->brand_repository->get_settings( $brand_id );
		if ( ! $settings->url_rewrite_enabled() ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( false === $parts || ! isset( $parts['host'] ) ) {
			return $url;
		}

		$scheme = $settings->url_rewrite_force_https() || is_ssl() ? 'https' : 'http';

		$rebuilt = $scheme . '://' . $authority;
		if ( isset( $parts['path'] ) ) {
			$rebuilt .= $parts['path'];
		}
		if ( isset( $parts['query'] ) ) {
			$rebuilt .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) ) {
			$rebuilt .= '#' . $parts['fragment'];
		}

		return $rebuilt;
	}
}
