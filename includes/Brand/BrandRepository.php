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
 * The single gateway to per-Brand data, all of which lives in one
 * `_mbgs_settings` meta entry hydrated into a BrandSettings value object.
 * Two cache layers: a per-request memo (this service is a container
 * singleton, so the resolver, identity filters, and every transformer share
 * it) plus a per-Brand `mbgs_brand_settings_<id>` transient created on first
 * load and dropped on every write. The default-Brand lookup — hit by every
 * request that matches no URL rule — is cached in its own `mbgs_default_brand`
 * transient (0 sentinel = "none flagged", so that answer is not re-queried
 * either).
 */
class BrandRepository {

	/**
	 * The single settings meta key.
	 *
	 * @var string
	 */
	private const SETTINGS_META_KEY = '_mbgs_settings';

	/**
	 * Per-Brand settings transient prefix.
	 *
	 * @var string
	 */
	private const SETTINGS_TRANSIENT_PREFIX = 'mbgs_brand_settings_';

	/**
	 * Default-Brand lookup transient key.
	 *
	 * @var string
	 */
	private const DEFAULT_BRAND_TRANSIENT = 'mbgs_default_brand';

	/**
	 * Per-request settings memo.
	 *
	 * @var array<int, BrandSettings>
	 */
	private array $settings_memo = array();

	/**
	 * Get a Brand's settings (memo → transient → meta).
	 *
	 * @param int $brand_id Brand post ID.
	 * @return BrandSettings Hydrated settings.
	 */
	public function get_settings( int $brand_id ): BrandSettings {
		if ( isset( $this->settings_memo[ $brand_id ] ) ) {
			return $this->settings_memo[ $brand_id ];
		}

		$raw = get_transient( self::SETTINGS_TRANSIENT_PREFIX . $brand_id );

		if ( ! is_array( $raw ) ) {
			$raw = get_post_meta( $brand_id, self::SETTINGS_META_KEY, true );
			$raw = is_array( $raw ) ? $raw : array();

			set_transient( self::SETTINGS_TRANSIENT_PREFIX . $brand_id, $raw, 0 );
		}

		$this->settings_memo[ $brand_id ] = BrandSettings::from_meta( $raw );

		return $this->settings_memo[ $brand_id ];
	}

	/**
	 * Write a Brand's full settings array and drop its caches.
	 *
	 * @param int                  $brand_id Brand post ID.
	 * @param array<string, mixed> $settings Full settings array.
	 * @return void
	 */
	public function save_settings( int $brand_id, array $settings ): void {
		update_post_meta( $brand_id, self::SETTINGS_META_KEY, $settings );

		$this->flush_brand_caches( $brand_id );
	}

	/**
	 * Merge a partial settings array over the stored one (top-level keys).
	 *
	 * @param int                  $brand_id Brand post ID.
	 * @param array<string, mixed> $partial  Keys to overwrite.
	 * @return void
	 */
	public function update_settings( int $brand_id, array $partial ): void {
		$raw = get_post_meta( $brand_id, self::SETTINGS_META_KEY, true );
		$raw = is_array( $raw ) ? $raw : array();

		$this->save_settings( $brand_id, array_merge( $raw, $partial ) );
	}

	/**
	 * Drop a Brand's settings caches (memo + transient) and the default-Brand
	 * cache. Also wired directly to save_post_mbgs_brand / deleted_post so
	 * trash/untrash/delete (which skip the nonce-gated save handler) cannot
	 * leave stale caches behind.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return void
	 */
	public function flush_brand_caches( int $brand_id ): void {
		unset( $this->settings_memo[ $brand_id ] );

		delete_transient( self::SETTINGS_TRANSIENT_PREFIX . $brand_id );
		delete_transient( self::DEFAULT_BRAND_TRANSIENT );
	}

	/**
	 * Get the Brand ID flagged as the fallback for unmatched requests.
	 *
	 * @return int|null Brand post ID, or null if none is flagged.
	 */
	public function get_default_brand_id(): ?int {
		$cached = get_transient( self::DEFAULT_BRAND_TRANSIENT );

		if ( false !== $cached ) {
			return (int) $cached > 0 ? (int) $cached : null;
		}

		$found = 0;
		foreach ( $this->get_published_brand_ids() as $brand_id ) {
			if ( $this->get_settings( $brand_id )->is_default() ) {
				$found = $brand_id;
				break;
			}
		}

		set_transient( self::DEFAULT_BRAND_TRANSIENT, $found, 0 );

		return $found > 0 ? $found : null;
	}

	/**
	 * Get a Brand's registered URL rules.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<int, string> Normalized rules.
	 */
	public function get_rules( int $brand_id ): array {
		return $this->get_settings( $brand_id )->rules();
	}

	/**
	 * Get a Brand's content variables.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<string, string> Variable key => value.
	 */
	public function get_variables( int $brand_id ): array {
		return $this->get_settings( $brand_id )->variables();
	}

	/**
	 * Get the ID of a Brand's dedicated wp_global_styles post.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return int|null Global styles post ID, or null if not yet created.
	 */
	public function get_global_styles_post_id( int $brand_id ): ?int {
		return $this->get_settings( $brand_id )->global_styles_post_id();
	}

	/**
	 * Get a Brand's identity overrides.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<string, int|string> Any of logo_id, icon_id, title, tagline.
	 */
	public function get_identity( int $brand_id ): array {
		return $this->get_settings( $brand_id )->identity();
	}

	/**
	 * Get a Brand's image replacement pairs.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<int, int> Original attachment ID => replacement attachment ID.
	 */
	public function get_image_map( int $brand_id ): array {
		return $this->get_settings( $brand_id )->image_map();
	}

	/**
	 * Get a Brand's precomputed image URL map.
	 *
	 * @param int $brand_id Brand post ID.
	 * @return array<string, string> Original URL => replacement URL.
	 */
	public function get_image_url_map( int $brand_id ): array {
		return $this->get_settings( $brand_id )->image_url_map();
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
