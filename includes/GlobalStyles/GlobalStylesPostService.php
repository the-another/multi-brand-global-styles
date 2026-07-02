<?php
/**
 * Global Styles Post Service
 *
 * @package MultiDomainGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiDomainGlobalStyles\GlobalStyles;

use RuntimeException;

/**
 * Class GlobalStylesPostService
 *
 * Creates and reads the dedicated wp_global_styles post for each Brand.
 *
 * Deliberately does NOT tag created posts with the wp_theme taxonomy — doing
 * so would make WP_Theme_JSON_Resolver's "find the canonical global styles
 * post for the active theme" query (ordered by date desc) pick up whichever
 * Brand was saved most recently, corrupting the real site-wide default.
 */
class GlobalStylesPostService {

	/**
	 * Postmeta key storing the linked wp_global_styles post ID.
	 *
	 * @var string
	 */
	private const META_KEY = '_mdgs_global_styles_post_id';

	/**
	 * Ensure a Brand has a wp_global_styles post, creating one if missing.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return int The wp_global_styles post ID.
	 *
	 * @throws RuntimeException If post creation fails.
	 */
	public function ensure_global_styles_post( int $brand_id ): int {
		$existing_id = get_post_meta( $brand_id, self::META_KEY, true );

		if ( $existing_id && get_post_status( $existing_id ) ) {
			return (int) $existing_id;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_title'   => 'Custom Styles',
				'post_content' => wp_json_encode(
					array(
						'version'                     => 3,
						'isGlobalStylesUserThemeJSON' => true,
						'settings'                    => new \stdClass(),
						'styles'                      => new \stdClass(),
					)
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( $brand_id, self::META_KEY, $post_id );

		return (int) $post_id;
	}

	/**
	 * Get the decoded settings/styles data for a wp_global_styles post.
	 *
	 * @param int $global_styles_post_id wp_global_styles post ID.
	 * @return array<string, mixed> Decoded content, or empty array if the post is missing/invalid.
	 */
	public function get_global_styles_data( int $global_styles_post_id ): array {
		$post = get_post( $global_styles_post_id );

		if ( ! $post ) {
			return array();
		}

		$decoded = json_decode( $post->post_content, true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
