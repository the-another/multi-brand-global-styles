<?php
/**
 * Brand Repository Service
 *
 * @package MultiBrandGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Brand;

/**
 * Class BrandRepository
 *
 * Read-only helpers for `mbgs_brand` post data.
 */
class BrandRepository {

	/**
	 * Get a Brand's registered URL rules.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<int, string> Normalized hostnames.
	 */
	public function get_rules( int $brand_id ): array {
		$rules = get_post_meta( $brand_id, '_mbgs_rules', true );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Get a Brand's content variables.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<string, string> Variable key => value.
	 */
	public function get_variables( int $brand_id ): array {
		$variables = get_post_meta( $brand_id, '_mbgs_variables', true );

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
				'post_type'      => 'mbgs_brand',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_mbgs_is_default', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Single-row flag lookup over the handful of Brands a site defines.
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Single-row flag lookup over the handful of Brands a site defines.
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
		$id = get_post_meta( $brand_id, '_mbgs_global_styles_post_id', true );

		return $id ? (int) $id : null;
	}

	/**
	 * Get a Brand's identity overrides.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<string, int|string> Any of logo_id, icon_id, title, tagline.
	 */
	public function get_identity( int $brand_id ): array {
		$identity = get_post_meta( $brand_id, '_mbgs_identity', true );

		return is_array( $identity ) ? $identity : array();
	}

	/**
	 * Get a Brand's image replacement pairs.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<int, int> Original attachment ID => replacement attachment ID.
	 */
	public function get_image_map( int $brand_id ): array {
		$map = get_post_meta( $brand_id, '_mbgs_image_map', true );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Get a Brand's precomputed image URL map.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<string, string> Original URL => replacement URL.
	 */
	public function get_image_url_map( int $brand_id ): array {
		$map = get_post_meta( $brand_id, '_mbgs_image_url_map', true );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Get all Brand post IDs regardless of status.
	 *
	 * @return array<int, int> Brand post IDs.
	 */
	public function get_brand_ids(): array {
		return $this->query_brand_ids( 'any' );
	}

	/**
	 * Get all published Brand post IDs.
	 *
	 * @return array<int, int> Brand post IDs.
	 */
	public function get_published_brand_ids(): array {
		return $this->query_brand_ids( 'publish' );
	}

	/**
	 * Query Brand post IDs by status.
	 *
	 * @param string $status Post status.
	 * @return array<int, int> Brand post IDs.
	 */
	private function query_brand_ids( string $status ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'mbgs_brand',
				'post_status'    => $status,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		return array_map( 'intval', $ids );
	}
}
