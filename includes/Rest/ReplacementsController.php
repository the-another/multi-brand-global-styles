<?php
/**
 * Replacements REST Controller
 *
 * @package MultiBrandGlobalStyles
 * @since 1.1.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Rest;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandPostType;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageMapBuilder;
use WP_Error;
use WP_REST_Request;

/**
 * Class ReplacementsController
 *
 * The mbgs/v1 REST surface behind the editor UIs: per-image replacement rows
 * (Image-block inspector panel) and — via Task 11 — the Brand list and the
 * per-Brand preview payload (Brand preview sidebar). All routes are gated by
 * `edit_theme_options`, the same capability that gates the Brand CPT.
 */
class ReplacementsController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private const REST_NAMESPACE = 'mbgs/v1';

	/**
	 * Brand repository.
	 *
	 * @var BrandRepository
	 */
	private BrandRepository $brand_repository;

	/**
	 * Image map builder.
	 *
	 * @var ImageMapBuilder
	 */
	private ImageMapBuilder $image_map_builder;

	/**
	 * Constructor.
	 *
	 * @param BrandRepository $brand_repository  Brand repository service.
	 * @param ImageMapBuilder $image_map_builder Image map builder service.
	 */
	public function __construct( BrandRepository $brand_repository, ImageMapBuilder $image_map_builder ) {
		$this->brand_repository  = $brand_repository;
		$this->image_map_builder = $image_map_builder;
	}

	/**
	 * Register the mbgs/v1 routes. Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/replacements',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_replacements' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'original' => array(
							'required' => true,
							'type'     => 'integer',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'set_replacement' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'brand_id'       => array(
							'required' => true,
							'type'     => 'integer',
						),
						'original_id'    => array(
							'required' => true,
							'type'     => 'integer',
						),
						'replacement_id' => array(
							'required' => false,
							'type'     => array( 'integer', 'null' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/brands',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_brands' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/preview-map',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_preview_map' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'brand' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: same capability that gates the Brand CPT.
	 *
	 * @return bool Whether the current user may manage replacements.
	 */
	public function can_manage(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * GET /replacements — one row per published Brand for one original image.
	 *
	 * @param WP_REST_Request $request Request with an `original` param.
	 * @return array<int, array<string, mixed>>|WP_Error Rows, or an error.
	 */
	public function get_replacements( WP_REST_Request $request ) {
		$original_id = (int) $request->get_param( 'original' );

		if ( ! wp_attachment_is_image( $original_id ) ) {
			return new WP_Error(
				'mbgs_invalid_attachment',
				__( 'Not an image attachment.', 'the-another-multi-brand-global-styles' ),
				array( 'status' => 400 )
			);
		}

		$rows = array();
		foreach ( $this->brand_repository->get_published_brand_ids() as $brand_id ) {
			$rows[] = $this->build_row( $brand_id, $original_id );
		}

		return rest_ensure_response( $rows );
	}

	/**
	 * POST /replacements — set or (on null replacement_id) remove one pair.
	 *
	 * @param WP_REST_Request $request Request with brand_id, original_id, replacement_id|null.
	 * @return array<string, mixed>|WP_Error The updated row, or an error.
	 */
	public function set_replacement( WP_REST_Request $request ) {
		$brand_id       = (int) $request->get_param( 'brand_id' );
		$original_id    = (int) $request->get_param( 'original_id' );
		$replacement_id = $request->get_param( 'replacement_id' );
		$replacement_id = null === $replacement_id ? 0 : (int) $replacement_id;

		if ( ! wp_attachment_is_image( $original_id ) || ( $replacement_id && ! wp_attachment_is_image( $replacement_id ) ) ) {
			return new WP_Error(
				'mbgs_invalid_attachment',
				__( 'Not an image attachment.', 'the-another-multi-brand-global-styles' ),
				array( 'status' => 400 )
			);
		}

		$brand = get_post( $brand_id );

		if ( ! $brand || BrandPostType::POST_TYPE !== $brand->post_type || 'publish' !== $brand->post_status ) {
			return new WP_Error(
				'mbgs_invalid_brand',
				__( 'Not a published Brand.', 'the-another-multi-brand-global-styles' ),
				array( 'status' => 400 )
			);
		}

		$pairs = $this->brand_repository->get_image_map( $brand_id );

		if ( $replacement_id ) {
			$pairs[ $original_id ] = $replacement_id;
		} else {
			unset( $pairs[ $original_id ] );
		}

		$this->image_map_builder->persist( $brand_id, $pairs );

		return rest_ensure_response( $this->build_row( $brand_id, $original_id, $pairs ) );
	}

	/**
	 * Build one response row for a Brand + original image.
	 *
	 * @param int                  $brand_id    Brand post ID.
	 * @param int                  $original_id Original attachment ID.
	 * @param array<int, int>|null $pairs       Pre-fetched pairs, or null to fetch.
	 * @return array<string, mixed> Row payload.
	 */
	private function build_row( int $brand_id, int $original_id, ?array $pairs = null ): array {
		$pairs          = $pairs ?? $this->brand_repository->get_image_map( $brand_id );
		$replacement_id = isset( $pairs[ $original_id ] ) ? (int) $pairs[ $original_id ] : null;

		$thumb_url = null;
		if ( $replacement_id ) {
			$thumb_url = wp_get_attachment_image_url( $replacement_id, 'thumbnail' );
			$thumb_url = $thumb_url ? $thumb_url : null;
		}

		return array(
			'brand_id'              => $brand_id,
			'brand_name'            => get_the_title( $brand_id ),
			'replacement_id'        => $replacement_id,
			'replacement_thumb_url' => $thumb_url,
		);
	}

	/**
	 * GET /brands — id + name of every published Brand.
	 *
	 * @return mixed Response rows.
	 */
	public function get_brands() {
		$rows = array();
		foreach ( $this->brand_repository->get_published_brand_ids() as $brand_id ) {
			$rows[] = array(
				'brand_id'   => $brand_id,
				'brand_name' => get_the_title( $brand_id ),
			);
		}

		return rest_ensure_response( $rows );
	}

	/**
	 * GET /preview-map — one Brand's editor-preview payload.
	 *
	 * @param WP_REST_Request $request Request with a `brand` param.
	 * @return mixed Payload, or WP_Error.
	 */
	public function get_preview_map( WP_REST_Request $request ) {
		$brand_id = (int) $request->get_param( 'brand' );
		$brand    = get_post( $brand_id );

		if ( ! $brand || BrandPostType::POST_TYPE !== $brand->post_type || 'publish' !== $brand->post_status ) {
			return new WP_Error(
				'mbgs_invalid_brand',
				__( 'Not a published Brand.', 'the-another-multi-brand-global-styles' ),
				array( 'status' => 400 )
			);
		}

		$identity = $this->brand_repository->get_identity( $brand_id );

		$images = array();
		foreach ( $this->brand_repository->get_image_map( $brand_id ) as $original_id => $replacement_id ) {
			$url = wp_get_attachment_url( (int) $replacement_id );
			if ( $url ) {
				$images[ (int) $original_id ] = $url;
			}
		}

		$logo_url = null;
		if ( isset( $identity['logo_id'] ) ) {
			$logo_url = wp_get_attachment_image_url( (int) $identity['logo_id'], 'full' );
			$logo_url = $logo_url ? $logo_url : null;
		}

		$icon_url = null;
		if ( isset( $identity['icon_id'] ) ) {
			$icon_url = wp_get_attachment_image_url( (int) $identity['icon_id'], 'full' );
			$icon_url = $icon_url ? $icon_url : null;
		}

		return rest_ensure_response(
			array(
				'title'    => $identity['title'] ?? null,
				'tagline'  => $identity['tagline'] ?? null,
				'logo_url' => $logo_url,
				'icon_url' => $icon_url,
				'images'   => $images,
			)
		);
	}
}
