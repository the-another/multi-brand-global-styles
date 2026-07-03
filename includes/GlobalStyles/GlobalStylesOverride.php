<?php
/**
 * Global Styles Override Service
 *
 * @package MultiBrandGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;

/**
 * Class GlobalStylesOverride
 *
 * Hooked to `wp_theme_json_data_user` on the frontend: merges the resolved
 * Brand's stored global-styles data over whatever the site's own user
 * global styles would otherwise be. Partial merge (update_with), not full
 * replacement — a Brand only needs to define what differs from the theme.
 */
class GlobalStylesOverride {

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
	 * Global styles post service.
	 *
	 * @var GlobalStylesPostService
	 */
	private GlobalStylesPostService $global_styles_post_service;

	/**
	 * Constructor.
	 *
	 * @param BrandResolver           $brand_resolver             Brand resolver service.
	 * @param BrandRepository         $brand_repository          Brand repository service.
	 * @param GlobalStylesPostService $global_styles_post_service  Global styles post service.
	 */
	public function __construct(
		BrandResolver $brand_resolver,
		BrandRepository $brand_repository,
		GlobalStylesPostService $global_styles_post_service
	) {
		$this->brand_resolver             = $brand_resolver;
		$this->brand_repository           = $brand_repository;
		$this->global_styles_post_service = $global_styles_post_service;
	}

	/**
	 * Filter callback for `wp_theme_json_data_user`.
	 *
	 * @param mixed $theme_json WP_Theme_JSON_Data instance.
	 * @return mixed WP_Theme_JSON_Data instance, possibly merged with Brand overrides.
	 */
	public function filter_theme_json( mixed $theme_json ): mixed {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $theme_json;
		}

		$brand_id = $this->brand_resolver->resolve_current_request();

		if ( null === $brand_id ) {
			return $theme_json;
		}

		$global_styles_post_id = $this->brand_repository->get_global_styles_post_id( $brand_id );

		if ( null === $global_styles_post_id ) {
			return $theme_json;
		}

		$data = $this->global_styles_post_service->get_global_styles_data( $global_styles_post_id );

		if ( empty( $data['settings'] ) && empty( $data['styles'] ) ) {
			return $theme_json;
		}

		return $theme_json->update_with(
			array(
				'version'                     => 3,
				'isGlobalStylesUserThemeJSON' => true,
				'settings'                    => $data['settings'] ?? array(),
				'styles'                      => $data['styles'] ?? array(),
			)
		);
	}
}
