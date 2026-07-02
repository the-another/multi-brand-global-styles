<?php
/**
 * Brand Repository Service
 *
 * @package MultiDomainGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiDomainGlobalStyles\Brand;

/**
 * Class BrandRepository
 *
 * Read-only helpers for `mdgs_brand` post data.
 */
class BrandRepository {

	/**
	 * Get a Brand's registered URL rules.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<int, string> Normalized hostnames.
	 */
	public function get_rules( int $brand_id ): array {
		$rules = get_post_meta( $brand_id, '_mdgs_rules', true );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Get a Brand's content variables.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<string, string> Variable key => value.
	 */
	public function get_variables( int $brand_id ): array {
		$variables = get_post_meta( $brand_id, '_mdgs_variables', true );

		return is_array( $variables ) ? $variables : array();
	}

	/**
	 * Get the Brand ID flagged as the fallback for unmatched requests.
	 *
	 * @return int|null Brand post ID, or null if none is flagged.
	 */
	public function get_default_brand_id(): ?int {
		$posts = get_posts(
			array(
				'post_type'      => 'mdgs_brand',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_mdgs_is_default',
				'meta_value'     => '1',
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Get the ID of a Brand's dedicated wp_global_styles post.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return int|null Global styles post ID, or null if not yet created.
	 */
	public function get_global_styles_post_id( int $brand_id ): ?int {
		$id = get_post_meta( $brand_id, '_mdgs_global_styles_post_id', true );

		return $id ? (int) $id : null;
	}
}
