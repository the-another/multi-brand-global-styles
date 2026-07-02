<?php
/**
 * Variable Substitution Service
 *
 * @package MultiDomainGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiDomainGlobalStyles\ContentVariables;

use TheAnother\Plugin\MultiDomainGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiDomainGlobalStyles\Brand\BrandRepository;

/**
 * Class VariableSubstitutionService
 *
 * Replaces %%brand.*%% tokens in the final rendered HTML with the resolved
 * Brand's variable values. Runs as a whole-page output buffer so it covers
 * post content, template parts, patterns, widgets, and menus in one pass,
 * rather than hooking a dozen individual WP content filters.
 */
class VariableSubstitutionService {

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
	 * @param BrandResolver   $brand_resolver    Brand resolver service.
	 * @param BrandRepository $brand_repository Brand repository service.
	 */
	public function __construct( BrandResolver $brand_resolver, BrandRepository $brand_repository ) {
		$this->brand_resolver   = $brand_resolver;
		$this->brand_repository = $brand_repository;
	}

	/**
	 * Start the output buffer on frontend HTML requests. Hooked to `template_redirect`.
	 *
	 * @return void
	 */
	public function start_buffer(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		ob_start( array( $this, 'replace' ) );
	}

	/**
	 * Replace %%brand.*%% tokens in HTML with the resolved Brand's variable values.
	 *
	 * @param string $html Rendered page HTML.
	 * @return string HTML with known tokens replaced; unknown tokens are left literal.
	 */
	public function replace( string $html ): string {
		$brand_id = $this->brand_resolver->resolve_current_request();

		if ( null === $brand_id ) {
			return $html;
		}

		$variables = $this->brand_repository->get_variables( $brand_id );

		if ( empty( $variables ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/%%brand\.([a-z0-9_]+)%%/i',
			static function ( array $matches ) use ( $variables ) {
				$key = strtolower( $matches[1] );

				if ( ! isset( $variables[ $key ] ) ) {
					return $matches[0];
				}

				return esc_html( $variables[ $key ] );
			},
			$html
		);
	}
}
