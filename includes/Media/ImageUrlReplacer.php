<?php
/**
 * Image URL Replacer
 *
 * @package MultiBrandGlobalStyles
 * @since 1.1.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Media;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;

/**
 * Class ImageUrlReplacer
 *
 * Rewrites mapped attachment URLs in the final HTML to the resolved Brand's
 * replacements. Consumes the URL map precomputed at Brand-save time, so this
 * costs one meta fetch and one linear strtr() pass — no attachment queries at
 * render. URLs appear literally in src, srcset, and inline style attributes,
 * so a single string pass covers all of them.
 */
class ImageUrlReplacer {

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
	 * Swap mapped image URLs in the rendered HTML.
	 *
	 * @param string $html Rendered page HTML.
	 * @return string HTML with mapped URLs replaced.
	 */
	public function replace( string $html ): string {
		$brand_id = $this->brand_resolver->resolve_current_request();

		if ( null === $brand_id ) {
			return $html;
		}

		$url_map = $this->brand_repository->get_image_url_map( $brand_id );

		if ( empty( $url_map ) ) {
			return $html;
		}

		// strtr() scans the HTML ONCE and, at each position, substitutes the
		// LONGEST matching key — so cost is O(html) regardless of how many
		// mappings the Brand has, and a key that is a substring of another can
		// never pre-empt the longer key (making the map's longest-first ordering
		// moot here). It also never re-scans replaced text, so a replacement URL
		// can't cascade into a later mapping — unlike sequential str_replace().
		return strtr( $html, $url_map );
	}
}
