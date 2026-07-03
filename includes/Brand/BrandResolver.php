<?php
/**
 * Brand Resolver Service
 *
 * @package MultiBrandGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Brand;

/**
 * Class BrandResolver
 *
 * Resolves the current request's host + path to a Brand ID using the rule
 * map. Most specific rule wins: host+path beats host-wide, longer path
 * prefix beats shorter, prefixes match on path segment boundaries.
 */
class BrandResolver {

	/**
	 * URL rule registry.
	 *
	 * @var UrlRuleRegistry
	 */
	private UrlRuleRegistry $url_rule_registry;

	/**
	 * Brand repository.
	 *
	 * @var BrandRepository
	 */
	private BrandRepository $brand_repository;

	/**
	 * Constructor.
	 *
	 * @param UrlRuleRegistry $url_rule_registry URL rule registry service.
	 * @param BrandRepository $brand_repository  Brand repository service.
	 */
	public function __construct( UrlRuleRegistry $url_rule_registry, BrandRepository $brand_repository ) {
		$this->url_rule_registry = $url_rule_registry;
		$this->brand_repository  = $brand_repository;
	}

	/**
	 * Resolve the current request (HTTP_HOST + REQUEST_URI) to a Brand ID.
	 *
	 * @return int|null Brand post ID, or null if unmatched with no default.
	 */
	public function resolve_current_request(): ?int {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return $this->resolve( $host, $path );
	}

	/**
	 * Resolve an arbitrary host + path to a Brand ID.
	 *
	 * @param string $host Raw hostname (e.g. from HTTP_HOST).
	 * @param string $path Raw request path (e.g. from REQUEST_URI; query string is ignored).
	 * @return int|null Brand post ID, or null if unmatched with no default.
	 */
	public function resolve( string $host, string $path ): ?int {
		$normalized_host = $this->url_rule_registry->normalize_host( $host );

		if ( '' === $normalized_host ) {
			return $this->brand_repository->get_default_brand_id();
		}

		$map = $this->url_rule_registry->get_rule_map();

		if ( ! isset( $map[ $normalized_host ] ) ) {
			return $this->brand_repository->get_default_brand_id();
		}

		$normalized_path = $this->url_rule_registry->normalize_path( $path );

		$best_prefix = null;

		foreach ( $map[ $normalized_host ] as $path_prefix => $brand_id ) {
			if ( '' !== $path_prefix
				&& $normalized_path !== $path_prefix
				&& ! str_starts_with( $normalized_path, $path_prefix . '/' )
			) {
				continue;
			}

			if ( null === $best_prefix || strlen( $path_prefix ) > strlen( $best_prefix ) ) {
				$best_prefix = $path_prefix;
			}
		}

		if ( null === $best_prefix ) {
			return $this->brand_repository->get_default_brand_id();
		}

		return $map[ $normalized_host ][ $best_prefix ];
	}
}
