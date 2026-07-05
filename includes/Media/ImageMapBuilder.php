<?php
/**
 * Image Map Builder
 *
 * @package MultiBrandGlobalStyles
 * @since 1.1.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Media;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;

/**
 * Class ImageMapBuilder
 *
 * Turns a Brand's `original attachment => replacement attachment` pairs into
 * a flat URL map (full-size + every registered size variant, matched by size
 * name) and persists both meta keys. The URL map is precomputed at save time
 * so render time needs zero attachment queries.
 */
class ImageMapBuilder {

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
	 * Build the flat URL map for a set of replacement pairs.
	 *
	 * @param array<int, int> $pairs Original attachment ID => replacement attachment ID.
	 * @return array<string, string> Original URL => replacement URL, keys sorted longest-first.
	 */
	public function build_url_map( array $pairs ): array {
		$map = array();

		foreach ( $pairs as $original_id => $replacement_id ) {
			$original_id    = (int) $original_id;
			$replacement_id = (int) $replacement_id;

			$original_url    = wp_get_attachment_url( $original_id );
			$replacement_url = wp_get_attachment_url( $replacement_id );

			if ( ! $original_url || ! $replacement_url ) {
				continue;
			}

			$map[ $original_url ] = $replacement_url;

			$original_sizes    = $this->get_size_files( $original_id );
			$replacement_sizes = $this->get_size_files( $replacement_id );
			$original_dir      = dirname( $original_url );
			$replacement_dir   = dirname( $replacement_url );

			foreach ( $original_sizes as $size_name => $file ) {
				$target = isset( $replacement_sizes[ $size_name ] )
					? $replacement_dir . '/' . $replacement_sizes[ $size_name ]
					: $replacement_url;

				$map[ $original_dir . '/' . $file ] = $target;
			}
		}

		// Longest keys first so no shorter URL that happens to be a prefix
		// of a longer one is ever substituted into it by str_replace().
		uksort( $map, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

		return $map;
	}

	/**
	 * Persist a Brand's pairs and the derived URL map into the settings entry.
	 *
	 * @param int             $brand_id Brand post ID.
	 * @param array<int, int> $pairs    Original attachment ID => replacement attachment ID.
	 * @return void
	 */
	public function persist( int $brand_id, array $pairs ): void {
		$this->brand_repository->update_settings(
			$brand_id,
			array(
				'image_map'     => $pairs,
				'image_url_map' => $this->build_url_map( $pairs ),
			)
		);
	}

	/**
	 * Get an attachment's registered size files keyed by size name.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, string> Size name => file name.
	 */
	private function get_size_files( int $attachment_id ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return array();
		}

		$files = array();
		foreach ( $metadata['sizes'] as $size_name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$files[ $size_name ] = $size['file'];
			}
		}

		return $files;
	}
}
