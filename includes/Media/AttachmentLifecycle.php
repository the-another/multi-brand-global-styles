<?php
/**
 * Attachment Lifecycle
 *
 * @package MultiBrandGlobalStyles
 * @since 1.1.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Media;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;

/**
 * Class AttachmentLifecycle
 *
 * Keeps the precomputed per-Brand URL maps truthful when attachments change:
 * a metadata rewrite (image edit, thumbnail regeneration) rebuilds the maps
 * of every Brand referencing that attachment, and deleting an attachment
 * prunes every pair that references it (either side). Brands are few and
 * both events are rare, so a full sweep is cheap.
 */
class AttachmentLifecycle {

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
	 * Rebuild URL maps when an attachment's metadata is written.
	 * Hooked to `added_post_meta` and `updated_post_meta` (fires after the
	 * DB write, unlike the wp_update_attachment_metadata filter).
	 *
	 * @param int    $meta_id   Meta row ID (unused).
	 * @param int    $object_id Post the meta belongs to.
	 * @param string $meta_key  Meta key being written.
	 * @return void
	 */
	public function on_attachment_meta_saved( int $meta_id, int $object_id, string $meta_key ): void {
		if ( '_wp_attachment_metadata' !== $meta_key ) {
			return;
		}

		foreach ( $this->brand_repository->get_brand_ids() as $brand_id ) {
			$pairs = $this->brand_repository->get_image_map( $brand_id );

			if ( $this->references_attachment( $pairs, $object_id ) ) {
				$this->image_map_builder->persist( $brand_id, $pairs );
			}
		}
	}

	/**
	 * Prune pairs referencing a deleted attachment. Hooked to `delete_attachment`.
	 *
	 * @param int $attachment_id Attachment being deleted.
	 * @return void
	 */
	public function on_delete_attachment( int $attachment_id ): void {
		foreach ( $this->brand_repository->get_brand_ids() as $brand_id ) {
			$pairs = $this->brand_repository->get_image_map( $brand_id );

			if ( ! $this->references_attachment( $pairs, $attachment_id ) ) {
				continue;
			}

			$pruned = array();
			foreach ( $pairs as $original_id => $replacement_id ) {
				if ( (int) $original_id === $attachment_id || (int) $replacement_id === $attachment_id ) {
					continue;
				}
				$pruned[ $original_id ] = $replacement_id;
			}

			$this->image_map_builder->persist( $brand_id, $pruned );
		}
	}

	/**
	 * Whether a pair set references an attachment on either side.
	 *
	 * @param array<int, int> $pairs         Original => replacement pairs.
	 * @param int             $attachment_id Attachment ID.
	 * @return bool True when referenced.
	 */
	private function references_attachment( array $pairs, int $attachment_id ): bool {
		foreach ( $pairs as $original_id => $replacement_id ) {
			if ( (int) $original_id === $attachment_id || (int) $replacement_id === $attachment_id ) {
				return true;
			}
		}

		return false;
	}
}
