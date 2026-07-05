<?php
/**
 * Brand Settings
 *
 * @package MultiBrandGlobalStyles
 * @since 1.2.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Brand;

/**
 * Class BrandSettings
 *
 * Readonly value object over the single `_mbgs_settings` meta entry. All
 * normalization/defaulting of stored Brand data lives HERE, in one place:
 * whatever shape the meta is in (missing, corrupt, wrong-typed), consumers
 * get typed, safe values. Hydrated and cached by BrandRepository.
 */
final class BrandSettings {

	/**
	 * Normalized URL rules.
	 *
	 * @var array<int, string>
	 */
	private readonly array $rules;

	/**
	 * Content variables.
	 *
	 * @var array<string, string>
	 */
	private readonly array $variables;

	/**
	 * Whether this Brand is the fallback for unmatched requests.
	 *
	 * @var bool
	 */
	private readonly bool $is_default;

	/**
	 * Identity overrides (subset of logo_id, icon_id, title, tagline).
	 *
	 * @var array<string, int|string>
	 */
	private readonly array $identity;

	/**
	 * Image replacement pairs (original attachment ID => replacement attachment ID).
	 *
	 * @var array<int, int>
	 */
	private readonly array $image_map;

	/**
	 * Derived image URL map (original URL => replacement URL, longest-key-first).
	 *
	 * @var array<string, string>
	 */
	private readonly array $image_url_map;

	/**
	 * Linked wp_global_styles post ID.
	 *
	 * @var int|null
	 */
	private readonly ?int $global_styles_post_id;

	/**
	 * Whether URL host rewriting is enabled.
	 *
	 * @var bool
	 */
	private readonly bool $url_rewrite_enabled;

	/**
	 * Whether rewritten URLs are forced to https.
	 *
	 * @var bool
	 */
	private readonly bool $url_rewrite_force_https;

	/**
	 * Canonical host form for URL rewriting: 'www', 'apex', or '' (off).
	 *
	 * @var string
	 */
	private readonly string $url_rewrite_host_form;

	/**
	 * Private constructor — hydrate via from_meta().
	 *
	 * @param array<int, string>        $rules                   Normalized URL rules.
	 * @param array<string, string>     $variables               Content variables.
	 * @param bool                      $is_default              Fallback-Brand flag.
	 * @param array<string, int|string> $identity                Identity overrides.
	 * @param array<int, int>           $image_map               Image replacement pairs.
	 * @param array<string, string>     $image_url_map           Derived image URL map.
	 * @param int|null                  $global_styles_post_id   Linked wp_global_styles post ID.
	 * @param bool                      $url_rewrite_enabled     URL rewrite opt-in.
	 * @param bool                      $url_rewrite_force_https Force-https flag.
	 */
	private function __construct(
		array $rules,
		array $variables,
		bool $is_default,
		array $identity,
		array $image_map,
		array $image_url_map,
		?int $global_styles_post_id,
		bool $url_rewrite_enabled,
		bool $url_rewrite_force_https,
		string $url_rewrite_host_form
	) {
		$this->rules                   = $rules;
		$this->variables               = $variables;
		$this->is_default              = $is_default;
		$this->identity                = $identity;
		$this->image_map               = $image_map;
		$this->image_url_map           = $image_url_map;
		$this->global_styles_post_id   = $global_styles_post_id;
		$this->url_rewrite_enabled     = $url_rewrite_enabled;
		$this->url_rewrite_force_https = $url_rewrite_force_https;
		$this->url_rewrite_host_form   = $url_rewrite_host_form;
	}

	/**
	 * Hydrate from whatever is stored in the `_mbgs_settings` meta entry.
	 *
	 * @param mixed $raw Raw meta value.
	 * @return self Normalized settings.
	 */
	public static function from_meta( mixed $raw ): self {
		$raw = is_array( $raw ) ? $raw : array();

		$url_rewrite = isset( $raw['url_rewrite'] ) && is_array( $raw['url_rewrite'] ) ? $raw['url_rewrite'] : array();

		return new self(
			self::normalize_string_list( $raw['rules'] ?? null ),
			self::normalize_string_map( $raw['variables'] ?? null ),
			! empty( $raw['is_default'] ),
			self::normalize_identity( $raw['identity'] ?? null ),
			self::normalize_id_map( $raw['image_map'] ?? null ),
			self::normalize_string_map( $raw['image_url_map'] ?? null ),
			self::normalize_post_id( $raw['global_styles_post_id'] ?? null ),
			! empty( $url_rewrite['enabled'] ),
			! empty( $url_rewrite['force_https'] ),
			self::normalize_host_form( $url_rewrite['canonical_host_form'] ?? null )
		);
	}

	/**
	 * Get the normalized URL rules.
	 *
	 * @return array<int, string> Rules.
	 */
	public function rules(): array {
		return $this->rules;
	}

	/**
	 * Get the content variables.
	 *
	 * @return array<string, string> Variable key => value.
	 */
	public function variables(): array {
		return $this->variables;
	}

	/**
	 * Whether this Brand is the fallback for unmatched requests.
	 *
	 * @return bool True when default.
	 */
	public function is_default(): bool {
		return $this->is_default;
	}

	/**
	 * Get the identity overrides.
	 *
	 * @return array<string, int|string> Any of logo_id, icon_id, title, tagline.
	 */
	public function identity(): array {
		return $this->identity;
	}

	/**
	 * Get the image replacement pairs.
	 *
	 * @return array<int, int> Original attachment ID => replacement attachment ID.
	 */
	public function image_map(): array {
		return $this->image_map;
	}

	/**
	 * Get the derived image URL map.
	 *
	 * @return array<string, string> Original URL => replacement URL.
	 */
	public function image_url_map(): array {
		return $this->image_url_map;
	}

	/**
	 * Get the linked wp_global_styles post ID.
	 *
	 * @return int|null Post ID, or null when not yet linked.
	 */
	public function global_styles_post_id(): ?int {
		return $this->global_styles_post_id;
	}

	/**
	 * Whether URL host rewriting is enabled.
	 *
	 * @return bool True when enabled.
	 */
	public function url_rewrite_enabled(): bool {
		return $this->url_rewrite_enabled;
	}

	/**
	 * Whether rewritten URLs are forced to https.
	 *
	 * @return bool True when forced.
	 */
	public function url_rewrite_force_https(): bool {
		return $this->url_rewrite_force_https;
	}

	/**
	 * Get the canonical host form for URL rewriting.
	 *
	 * @return string 'www', 'apex', or '' when off.
	 */
	public function url_rewrite_host_form(): string {
		return $this->url_rewrite_host_form;
	}

	/**
	 * Keep only non-empty string values, reindexed.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, string> Normalized list.
	 */
	private static function normalize_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$list = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$list[] = $item;
			}
		}

		return $list;
	}

	/**
	 * Keep only non-empty-string-keyed scalar values, cast to string.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, string> Normalized map.
	 */
	private static function normalize_string_map( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$map = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && '' !== $key && is_scalar( $item ) ) {
				$map[ $key ] = (string) $item;
			}
		}

		return $map;
	}

	/**
	 * Keep only positive-int => positive-int pairs.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int, int> Normalized map.
	 */
	private static function normalize_id_map( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$map = array();
		foreach ( $value as $key => $item ) {
			$original    = (int) $key;
			$replacement = is_scalar( $item ) ? (int) $item : 0;

			if ( $original > 0 && $replacement > 0 ) {
				$map[ $original ] = $replacement;
			}
		}

		return $map;
	}

	/**
	 * Keep only the known identity keys with valid types.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, int|string> Normalized identity.
	 */
	private static function normalize_identity( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$identity = array();

		foreach ( array( 'logo_id', 'icon_id' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) && (int) $value[ $key ] > 0 ) {
				$identity[ $key ] = (int) $value[ $key ];
			}
		}

		foreach ( array( 'title', 'tagline' ) as $key ) {
			if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && '' !== $value[ $key ] ) {
				$identity[ $key ] = $value[ $key ];
			}
		}

		return $identity;
	}

	/**
	 * Normalize a stored post ID: positive int or null.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null Post ID, or null.
	 */
	private static function normalize_post_id( mixed $value ): ?int {
		$id = is_scalar( $value ) ? (int) $value : 0;

		return $id > 0 ? $id : null;
	}

	/**
	 * Normalize the canonical host form: only 'www' or 'apex' survive.
	 *
	 * @param mixed $value Raw value.
	 * @return string 'www', 'apex', or ''.
	 */
	private static function normalize_host_form( mixed $value ): string {
		return in_array( $value, array( 'www', 'apex' ), true ) ? $value : '';
	}
}
