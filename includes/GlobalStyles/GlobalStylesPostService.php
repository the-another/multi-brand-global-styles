<?php
/**
 * Global Styles Post Service
 *
 * @package MultiBrandGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles;

use RuntimeException;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;

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
	 * Brand repository.
	 *
	 * @var BrandRepository
	 */
	private BrandRepository $brand_repository;

	/**
	 * Constructor.
	 *
	 * @param BrandRepository $brand_repository Brand repository service.
	 */
	public function __construct( BrandRepository $brand_repository ) {
		$this->brand_repository = $brand_repository;
	}

	/**
	 * Ensure a Brand has a wp_global_styles post, creating one if missing, trashed, or otherwise not published.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return int The wp_global_styles post ID.
	 *
	 * @throws RuntimeException If post creation fails.
	 */
	public function ensure_global_styles_post( int $brand_id ): int {
		$existing_id = $this->brand_repository->get_settings( $brand_id )->global_styles_post_id();

		if ( $existing_id && 'publish' === get_post_status( $existing_id ) ) {
			return $existing_id;
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

		$this->brand_repository->update_settings( $brand_id, array( 'global_styles_post_id' => (int) $post_id ) );

		return (int) $post_id;
	}

	/**
	 * Write decoded theme.json-shaped data into a Brand's wp_global_styles post.
	 *
	 * The decoded settings/styles are first run through WP_Theme_JSON (origin
	 * `custom`) and read back via get_raw_data(). This is load-bearing, not
	 * cosmetic: core registers wp_filter_global_styles_post() on
	 * `content_save_pre` for any user WITHOUT the `unfiltered_html` capability
	 * (every multisite site admin, and any site where a security plugin drops
	 * that cap). That filter re-runs WP_Theme_JSON::remove_insecure_properties()
	 * over the post_content on save, and remove_insecure_settings() only
	 * preserves presets stored in their origin-keyed form
	 * (settings.color.palette.custom => [...]) — a flat theme.json preset list
	 * (settings.color.palette => [...], exactly what an admin pastes) is
	 * silently dropped, leaving only {version, isGlobalStylesUserThemeJSON}.
	 * WP_Theme_JSON normalizes the flat list into the keyed form that survives
	 * that filter (and renders identically), using core's own API so no preset
	 * path list has to be hard-coded here. kses still runs on the write, so
	 * unsafe styles are sanitized exactly as core intends — we are not
	 * bypassing it, only handing it data in the shape it keeps.
	 *
	 * @param int                  $global_styles_post_id wp_global_styles post ID.
	 * @param array<string, mixed> $decoded               Decoded theme.json-shaped data (settings/styles).
	 * @return void
	 */
	public function update_global_styles( int $global_styles_post_id, array $decoded ): void {
		$theme_json = new \WP_Theme_JSON(
			array(
				'version'  => 3,
				'settings' => isset( $decoded['settings'] ) && is_array( $decoded['settings'] ) ? $decoded['settings'] : array(),
				'styles'   => isset( $decoded['styles'] ) && is_array( $decoded['styles'] ) ? $decoded['styles'] : array(),
			),
			'custom'
		);

		$raw = $theme_json->get_raw_data();

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $global_styles_post_id,
					'post_content' => wp_json_encode(
						array(
							'version'                     => 3,
							'isGlobalStylesUserThemeJSON' => true,
							'settings'                    => $raw['settings'] ?? new \stdClass(),
							'styles'                      => $raw['styles'] ?? new \stdClass(),
						)
					),
				)
			)
		);
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
