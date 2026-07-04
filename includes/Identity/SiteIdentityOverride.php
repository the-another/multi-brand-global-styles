<?php
/**
 * Site Identity Override
 *
 * @package MultiBrandGlobalStyles
 * @since 1.1.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Identity;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;

/**
 * Class SiteIdentityOverride
 *
 * Overrides site identity (logo, title, tagline, site icon) at the
 * option/theme-mod level for the resolved Brand, so core builds all markup
 * (srcset, alt, link wrapping, icon sizes). Frontend only: admin, AJAX, and
 * REST reads are untouched. Feeds are deliberately NOT excluded — is_feed()
 * is unsafe before the main query, and a feed on a Brand's host should carry
 * that Brand's title/tagline anyway.
 */
class SiteIdentityOverride {

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
	 * Filter `pre_option_site_logo` (block themes / REST-facing option).
	 *
	 * @param mixed $value Incoming pre-option value (false = not short-circuited).
	 * @return mixed Brand logo attachment ID, or the incoming value.
	 */
	public function filter_logo_option( mixed $value ): mixed {
		return $this->identity_value( 'logo_id' ) ?? $value;
	}

	/**
	 * Filter `theme_mod_custom_logo` (get_custom_logo() / classic themes).
	 *
	 * @param mixed $value Incoming theme-mod value.
	 * @return mixed Brand logo attachment ID, or the incoming value.
	 */
	public function filter_logo_theme_mod( mixed $value ): mixed {
		return $this->identity_value( 'logo_id' ) ?? $value;
	}

	/**
	 * Filter `pre_option_blogname`.
	 *
	 * @param mixed $value Incoming pre-option value.
	 * @return mixed Brand title, or the incoming value.
	 */
	public function filter_blogname( mixed $value ): mixed {
		return $this->identity_value( 'title' ) ?? $value;
	}

	/**
	 * Filter `pre_option_blogdescription`.
	 *
	 * @param mixed $value Incoming pre-option value.
	 * @return mixed Brand tagline, or the incoming value.
	 */
	public function filter_blogdescription( mixed $value ): mixed {
		return $this->identity_value( 'tagline' ) ?? $value;
	}

	/**
	 * Filter `pre_option_site_icon`.
	 *
	 * @param mixed $value Incoming pre-option value.
	 * @return mixed Brand icon attachment ID, or the incoming value.
	 */
	public function filter_site_icon( mixed $value ): mixed {
		return $this->identity_value( 'icon_id' ) ?? $value;
	}

	/**
	 * Get one identity field for the resolved Brand, or null to fall through.
	 *
	 * @param string $key Identity key: logo_id, icon_id, title, tagline.
	 * @return int|string|null Value, or null when the override does not apply.
	 */
	private function identity_value( string $key ): int|string|null {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return null;
		}

		$brand_id = $this->brand_resolver->resolve_current_request();

		if ( null === $brand_id ) {
			return null;
		}

		$identity = $this->brand_repository->get_identity( $brand_id );

		return $identity[ $key ] ?? null;
	}
}
