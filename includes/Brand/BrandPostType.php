<?php
/**
 * Brand Post Type
 *
 * @package MultiBrandGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Brand;

use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesPostService;
use TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables\VariableParser;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageMapBuilder;
use WP_Post;

/**
 * Class BrandPostType
 *
 * Registers the `mbgs_brand` CPT: URL rules, content variables, default
 * flag, and an interim raw-JSON global styles editor (Task 10 of the
 * foundation plan; replaced by a Site Editor redirect in the follow-up plan).
 */
class BrandPostType {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'mbgs_brand';

	/**
	 * URL rule registry.
	 *
	 * @var UrlRuleRegistry
	 */
	private UrlRuleRegistry $url_rule_registry;

	/**
	 * Variable parser.
	 *
	 * @var VariableParser
	 */
	private VariableParser $variable_parser;

	/**
	 * Global styles post service.
	 *
	 * @var GlobalStylesPostService
	 */
	private GlobalStylesPostService $global_styles_post_service;

	/**
	 * Image map builder.
	 *
	 * @var ImageMapBuilder
	 */
	private ImageMapBuilder $image_map_builder;

	/**
	 * Brand repository.
	 *
	 * @var BrandRepository
	 */
	private BrandRepository $brand_repository;

	/**
	 * Constructor.
	 *
	 * @param UrlRuleRegistry         $url_rule_registry             URL rule registry service.
	 * @param VariableParser          $variable_parser             Variable parser service.
	 * @param GlobalStylesPostService $global_styles_post_service  Global styles post service.
	 * @param ImageMapBuilder         $image_map_builder           Image map builder service.
	 * @param BrandRepository         $brand_repository            Brand repository service.
	 */
	public function __construct(
		UrlRuleRegistry $url_rule_registry,
		VariableParser $variable_parser,
		GlobalStylesPostService $global_styles_post_service,
		ImageMapBuilder $image_map_builder,
		BrandRepository $brand_repository
	) {
		$this->url_rule_registry          = $url_rule_registry;
		$this->variable_parser            = $variable_parser;
		$this->global_styles_post_service = $global_styles_post_service;
		$this->image_map_builder          = $image_map_builder;
		$this->brand_repository           = $brand_repository;
	}

	/**
	 * Register the mbgs_brand post type. Hooked to `init`.
	 *
	 * @return void
	 */
	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Brands', 'the-another-multi-brand-global-styles' ),
					'singular_name' => __( 'Brand', 'the-another-multi-brand-global-styles' ),
					'add_new_item'  => __( 'Add New Brand', 'the-another-multi-brand-global-styles' ),
					'edit_item'     => __( 'Edit Brand', 'the-another-multi-brand-global-styles' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'menu_icon'    => 'dashicons-admin-multisite',
				'capabilities' => array(
					'edit_post'          => 'edit_theme_options',
					'read_post'          => 'edit_theme_options',
					'delete_post'        => 'edit_theme_options',
					'edit_posts'         => 'edit_theme_options',
					'edit_others_posts'  => 'edit_theme_options',
					'publish_posts'      => 'edit_theme_options',
					'read_private_posts' => 'edit_theme_options',
					'create_posts'       => 'edit_theme_options',
				),
			)
		);
	}

	/**
	 * Register meta boxes. Hooked to `add_meta_boxes`.
	 *
	 * @return void
	 */
	public function register_meta_boxes(): void {
		add_meta_box( 'mbgs_rules', __( 'URL Rules', 'the-another-multi-brand-global-styles' ), array( $this, 'render_rules_meta_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'mbgs_variables', __( 'Content Variables', 'the-another-multi-brand-global-styles' ), array( $this, 'render_variables_meta_box' ), self::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'mbgs_default', __( 'Default Brand', 'the-another-multi-brand-global-styles' ), array( $this, 'render_default_meta_box' ), self::POST_TYPE, 'side', 'default' );
		add_meta_box( 'mbgs_styles', __( 'Global Styles (raw JSON)', 'the-another-multi-brand-global-styles' ), array( $this, 'render_styles_meta_box' ), self::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'mbgs_identity', __( 'Brand Identity', 'the-another-multi-brand-global-styles' ), array( $this, 'render_identity_meta_box' ), self::POST_TYPE, 'side', 'default' );
		add_meta_box( 'mbgs_image_map', __( 'Image Replacements', 'the-another-multi-brand-global-styles' ), array( $this, 'render_image_map_meta_box' ), self::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'mbgs_url_rewrite', __( 'URL Rewrite', 'the-another-multi-brand-global-styles' ), array( $this, 'render_url_rewrite_meta_box' ), self::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Render the URL rules meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_rules_meta_box( WP_Post $post ): void {
		$rules = $this->brand_repository->get_settings( $post->ID )->rules();

		wp_nonce_field( 'mbgs_save_brand', 'mbgs_brand_nonce' );
		?>
		<p><?php esc_html_e( 'One rule per line. A bare hostname matches the whole domain (auctionbill.com); add a path to match one section only (site.com/farm/*).', 'the-another-multi-brand-global-styles' ); ?></p>
		<textarea name="mbgs_rules" rows="5" style="width:100%;"><?php echo esc_textarea( implode( "\n", $rules ) ); ?></textarea>
		<?php
	}

	/**
	 * Render the content variables meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_variables_meta_box( WP_Post $post ): void {
		$variables = $this->brand_repository->get_settings( $post->ID )->variables();

		$lines = array();
		foreach ( $variables as $key => $value ) {
			$lines[] = "{$key} = {$value}";
		}
		?>
		<p><?php esc_html_e( 'One variable per line, e.g. name = Acme Auctions. Reference in content as %%brand.name%%.', 'the-another-multi-brand-global-styles' ); ?></p>
		<textarea name="mbgs_variables" rows="5" style="width:100%;"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea>
		<?php
	}

	/**
	 * Render the default-Brand meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_default_meta_box( WP_Post $post ): void {
		$is_default = $this->brand_repository->get_settings( $post->ID )->is_default();
		?>
		<label>
			<input type="checkbox" name="mbgs_is_default" value="1" <?php checked( $is_default ); ?> />
			<?php esc_html_e( 'Use as fallback for unmatched domains', 'the-another-multi-brand-global-styles' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the raw-JSON global styles meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_styles_meta_box( WP_Post $post ): void {
		$global_styles_post_id = $this->brand_repository->get_settings( $post->ID )->global_styles_post_id();
		$data                  = $global_styles_post_id ? $this->global_styles_post_service->get_global_styles_data( $global_styles_post_id ) : array();

		// Show only the settings/styles subtrees — the wrapper keys (version,
		// isGlobalStylesUserThemeJSON) are managed internally and would confuse
		// the admin.
		$display = array(
			'settings' => self::ensure_json_object( $data['settings'] ?? array() ),
			'styles'   => self::ensure_json_object( $data['styles'] ?? array() ),
		);

		$active_styles_url = add_query_arg(
			'_wpnonce',
			wp_create_nonce( 'wp_rest' ),
			rest_url( 'wp/v2/global-styles/themes/' . get_stylesheet() )
		);
		?>
		<p><?php esc_html_e( 'Raw theme.json-shaped settings/styles for this Brand. A richer visual editor is planned; this is the interim editing UI.', 'the-another-multi-brand-global-styles' ); ?></p>
		<textarea name="mbgs_styles_json" rows="12" style="width:100%;font-family:monospace;"><?php echo esc_textarea( wp_json_encode( $display, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
		<p>
			<a href="<?php echo esc_url( $active_styles_url ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'View current global styles (JSON)', 'the-another-multi-brand-global-styles' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render the Brand Identity meta box (logo, site icon, title, tagline).
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_identity_meta_box( WP_Post $post ): void {
		$identity = $this->brand_repository->get_settings( $post->ID )->identity();
		?>
		<p><?php esc_html_e( 'Each field is optional; empty fields fall back to the site default.', 'the-another-multi-brand-global-styles' ); ?></p>
		<?php
		$this->render_media_picker( 'mbgs_logo_id', __( 'Logo', 'the-another-multi-brand-global-styles' ), (int) ( $identity['logo_id'] ?? 0 ) );
		$this->render_media_picker( 'mbgs_icon_id', __( 'Site icon (favicon)', 'the-another-multi-brand-global-styles' ), (int) ( $identity['icon_id'] ?? 0 ) );
		?>
		<p>
			<label for="mbgs_title"><strong><?php esc_html_e( 'Site title', 'the-another-multi-brand-global-styles' ); ?></strong></label>
			<input type="text" id="mbgs_title" name="mbgs_title" style="width:100%;" value="<?php echo esc_attr( (string) ( $identity['title'] ?? '' ) ); ?>" />
		</p>
		<p>
			<label for="mbgs_tagline"><strong><?php esc_html_e( 'Tagline', 'the-another-multi-brand-global-styles' ); ?></strong></label>
			<input type="text" id="mbgs_tagline" name="mbgs_tagline" style="width:100%;" value="<?php echo esc_attr( (string) ( $identity['tagline'] ?? '' ) ); ?>" />
		</p>
		<?php
	}

	/**
	 * Render one hidden-input + preview + select/remove media picker.
	 *
	 * @param string $field_name    Hidden input name.
	 * @param string $label         Field label.
	 * @param int    $attachment_id Currently selected attachment, 0 for none.
	 * @return void
	 */
	private function render_media_picker( string $field_name, string $label, int $attachment_id ): void {
		$thumb_url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
		?>
		<div class="mbgs-media-picker" style="margin-bottom:12px;">
			<strong><?php echo esc_html( $label ); ?></strong><br />
			<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( (string) $attachment_id ); ?>" />
			<img src="<?php echo esc_url( (string) $thumb_url ); ?>" alt="" style="max-width:100%;height:auto;<?php echo $thumb_url ? '' : 'display:none;'; ?>" />
			<button type="button" class="button mbgs-media-select"><?php esc_html_e( 'Select image', 'the-another-multi-brand-global-styles' ); ?></button>
			<button type="button" class="button mbgs-media-remove" <?php echo $attachment_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'the-another-multi-brand-global-styles' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render the Image Replacements meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_image_map_meta_box( WP_Post $post ): void {
		$pairs = $this->brand_repository->get_settings( $post->ID )->image_map();
		?>
		<p><?php esc_html_e( 'Wherever this Brand matches, each original image is replaced by its counterpart on the frontend. Same-aspect-ratio replacements are recommended. Replacements can also be set in the block editor, on any selected Image block.', 'the-another-multi-brand-global-styles' ); ?></p>
		<div class="mbgs-image-map-rows">
			<?php
			foreach ( $pairs as $original_id => $replacement_id ) {
				$this->render_image_map_row( (int) $original_id, (int) $replacement_id );
			}
			?>
		</div>
		<template class="mbgs-image-map-template">
			<?php $this->render_image_map_row( 0, 0 ); ?>
		</template>
		<button type="button" class="button mbgs-image-map-add"><?php esc_html_e( 'Add replacement', 'the-another-multi-brand-global-styles' ); ?></button>
		<?php
	}

	/**
	 * Render one original -> replacement picker row.
	 *
	 * @param int $original_id    Original attachment ID.
	 * @param int $replacement_id Replacement attachment ID.
	 * @return void
	 */
	private function render_image_map_row( int $original_id, int $replacement_id ): void {
		$original_thumb    = $original_id ? wp_get_attachment_image_url( $original_id, 'thumbnail' ) : '';
		$replacement_thumb = $replacement_id ? wp_get_attachment_image_url( $replacement_id, 'thumbnail' ) : '';
		?>
		<div class="mbgs-image-map-row" style="display:flex;gap:16px;align-items:flex-start;margin-bottom:12px;">
			<div class="mbgs-media-picker">
				<strong><?php esc_html_e( 'Original', 'the-another-multi-brand-global-styles' ); ?></strong><br />
				<input type="hidden" name="mbgs_image_map_original[]" value="<?php echo esc_attr( (string) $original_id ); ?>" />
				<img src="<?php echo esc_url( (string) $original_thumb ); ?>" alt="" style="max-width:100px;height:auto;<?php echo $original_thumb ? '' : 'display:none;'; ?>" />
				<button type="button" class="button mbgs-media-select"><?php esc_html_e( 'Select image', 'the-another-multi-brand-global-styles' ); ?></button>
			</div>
			<div class="mbgs-media-picker">
				<strong><?php esc_html_e( 'Replacement', 'the-another-multi-brand-global-styles' ); ?></strong><br />
				<input type="hidden" name="mbgs_image_map_replacement[]" value="<?php echo esc_attr( (string) $replacement_id ); ?>" />
				<img src="<?php echo esc_url( (string) $replacement_thumb ); ?>" alt="" style="max-width:100px;height:auto;<?php echo $replacement_thumb ? '' : 'display:none;'; ?>" />
				<button type="button" class="button mbgs-media-select"><?php esc_html_e( 'Select image', 'the-another-multi-brand-global-styles' ); ?></button>
			</div>
			<button type="button" class="button mbgs-image-map-remove"><?php esc_html_e( 'Remove', 'the-another-multi-brand-global-styles' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render the URL Rewrite meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_url_rewrite_meta_box( WP_Post $post ): void {
		$settings = $this->brand_repository->get_settings( $post->ID );
		?>
		<p>
			<label>
				<input type="checkbox" name="mbgs_url_rewrite_enabled" value="1" <?php checked( $settings->url_rewrite_enabled() ); ?> />
				<?php esc_html_e( 'Rewrite URLs to the domain being browsed', 'the-another-multi-brand-global-styles' ); ?>
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" name="mbgs_url_rewrite_force_https" value="1" <?php checked( $settings->url_rewrite_force_https() ); ?> />
				<?php esc_html_e( 'Force https in rewritten URLs', 'the-another-multi-brand-global-styles' ); ?>
			</label>
		</p>
		<?php $host_form = $settings->url_rewrite_host_form(); ?>
		<p>
			<strong><?php esc_html_e( 'Canonical host form', 'the-another-multi-brand-global-styles' ); ?></strong>
		</p>
		<p>
			<label>
				<input type="radio" name="mbgs_url_rewrite_host_form" value="" <?php checked( '', $host_form ); ?> />
				<?php esc_html_e( 'Follow browsed host (no change)', 'the-another-multi-brand-global-styles' ); ?>
			</label><br />
			<label>
				<input type="radio" name="mbgs_url_rewrite_host_form" value="www" <?php checked( 'www', $host_form ); ?> />
				<?php esc_html_e( 'Force www', 'the-another-multi-brand-global-styles' ); ?>
			</label><br />
			<label>
				<input type="radio" name="mbgs_url_rewrite_host_form" value="apex" <?php checked( 'apex', $host_form ); ?> />
				<?php esc_html_e( 'Force apex (no www)', 'the-another-multi-brand-global-styles' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'Only applies when "Rewrite URLs" is enabled. Visitors on the other form are redirected to the chosen one. Applies to this Brand\'s own domain(s).', 'the-another-multi-brand-global-styles' ); ?></p>
		<p class="description"><?php esc_html_e( 'For this site\'s own domain, the chosen form must match your WordPress Address (Settings → General) and any web-server redirect. Choosing the opposite form while the server or WordPress redirects the other way causes an infinite redirect loop.', 'the-another-multi-brand-global-styles' ); ?></p>
		<p class="description"><?php esc_html_e( 'When enabled, links pointing at the canonical site address are rewritten to the domain the visitor is browsing. Only the domain is changed, never the path.', 'the-another-multi-brand-global-styles' ); ?></p>
		<?php
	}

	/**
	 * Save handler. Hooked to `save_post_mbgs_brand`.
	 *
	 * Builds the full form-derived settings array and performs ONE merge-write
	 * (update_settings), so keys owned by other writers — global_styles_post_id —
	 * survive the admin save.
	 *
	 * @param int $post_id Post ID being saved.
	 * @return void
	 */
	public function save( int $post_id ): void {
		if ( ! isset( $_POST['mbgs_brand_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mbgs_brand_nonce'] ) ), 'mbgs_save_brand' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$pairs = $this->collect_image_map_pairs();

		$this->brand_repository->update_settings(
			$post_id,
			array(
				'rules'         => $this->collect_rules( $post_id ),
				'variables'     => $this->collect_variables(),
				'is_default'    => $this->collect_default_flag( $post_id ),
				'identity'      => $this->collect_identity(),
				'image_map'     => $pairs,
				'image_url_map' => $this->image_map_builder->build_url_map( $pairs ),
				'url_rewrite'   => $this->collect_url_rewrite(),
			)
		);

		$global_styles_post_id = $this->global_styles_post_service->ensure_global_styles_post( $post_id );
		$this->save_styles( $post_id, $global_styles_post_id );

		$this->url_rule_registry->invalidate_cache();
	}

	/**
	 * Parse and validate the URL rules field, rejecting exact-rule conflicts.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, string> Accepted rules.
	 */
	private function collect_rules( int $post_id ): array {
		$raw   = isset( $_POST['mbgs_rules'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mbgs_rules'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.
		$rules = $this->url_rule_registry->parse_rules_input( $raw );

		$accepted = array();
		$rejected = array();

		foreach ( $rules as $rule ) {
			if ( null !== $this->url_rule_registry->find_conflicting_brand( $rule, $post_id ) ) {
				$rejected[] = $rule;
				continue;
			}
			$accepted[] = $rule;
		}

		if ( ! empty( $rejected ) ) {
			set_transient( 'mbgs_rule_conflict_' . get_current_user_id(), $rejected, 30 );
		}

		return $accepted;
	}

	/**
	 * Parse the content variables field.
	 *
	 * @return array<string, string> Variables.
	 */
	private function collect_variables(): array {
		$raw = isset( $_POST['mbgs_variables'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mbgs_variables'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.

		return $this->variable_parser->parse( $raw );
	}

	/**
	 * Read the default-Brand flag, clearing it from any other Brand when set.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether this Brand is now the default.
	 */
	private function collect_default_flag( int $post_id ): bool {
		$is_default = ! empty( $_POST['mbgs_is_default'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.

		if ( $is_default ) {
			foreach ( $this->brand_repository->get_brand_ids() as $other_id ) {
				if ( $other_id === $post_id ) {
					continue;
				}
				if ( $this->brand_repository->get_settings( $other_id )->is_default() ) {
					$this->brand_repository->update_settings( $other_id, array( 'is_default' => false ) );
				}
			}
		}

		return $is_default;
	}

	/**
	 * Parse and persist the raw-JSON styles field into the linked wp_global_styles post.
	 *
	 * @param int $post_id              Post ID.
	 * @param int $global_styles_post_id wp_global_styles post ID.
	 * @return void
	 */
	private function save_styles( int $post_id, int $global_styles_post_id ): void {
		$raw = isset( $_POST['mbgs_styles_json'] ) && is_string( $_POST['mbgs_styles_json'] ) ? wp_unslash( $_POST['mbgs_styles_json'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save() before delegation; raw JSON would be corrupted by sanitize_*(), so it is validated instead: json_decode() below discards anything that is not a JSON object, and only a wp_json_encode() re-encoding of the parsed settings/styles subtrees is ever persisted.

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return;
		}

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $global_styles_post_id,
					'post_content' => wp_json_encode(
						array(
							'version'                     => 3,
							'isGlobalStylesUserThemeJSON' => true,
							'settings'                    => self::ensure_json_object( $decoded['settings'] ?? array() ),
							'styles'                      => self::ensure_json_object( $decoded['styles'] ?? array() ),
						)
					),
				)
			)
		);
	}

	/**
	 * Cast an empty PHP array to stdClass so json_encode produces {} not [].
	 *
	 * Associative arrays encode to JSON objects, but an empty PHP array is
	 * ambiguous — json_encode([]) produces "[]". WordPress and the global-
	 * styles schema expect {}, so this helper forces the stdClass cast when
	 * the value is empty.
	 *
	 * @param array<string, mixed>|\stdClass $value Settings or styles subtree.
	 * @return array<string, mixed>|\stdClass The same value, or stdClass if empty.
	 */
	private static function ensure_json_object( array|\stdClass $value ): array|\stdClass {
		if ( is_array( $value ) && 0 === count( $value ) ) {
			return new \stdClass();
		}

		return $value;
	}

	/**
	 * Parse and validate the identity fields.
	 *
	 * @return array<string, int|string> Identity (keys present only when set).
	 */
	private function collect_identity(): array {
		$identity = array();

		foreach ( array(
			'logo_id' => 'mbgs_logo_id',
			'icon_id' => 'mbgs_icon_id',
		) as $key => $field ) {
			$attachment_id = isset( $_POST[ $field ] ) ? absint( wp_unslash( $_POST[ $field ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.
			if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
				$identity[ $key ] = $attachment_id;
			}
		}

		foreach ( array(
			'title'   => 'mbgs_title',
			'tagline' => 'mbgs_tagline',
		) as $key => $field ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.
			if ( '' !== $value ) {
				$identity[ $key ] = $value;
			}
		}

		return $identity;
	}

	/**
	 * Parse and validate the image replacement pairs.
	 *
	 * @return array<int, int> Original attachment ID => replacement attachment ID.
	 */
	private function collect_image_map_pairs(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.
		$originals = isset( $_POST['mbgs_image_map_original'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['mbgs_image_map_original'] ) ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.
		$replacements = isset( $_POST['mbgs_image_map_replacement'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['mbgs_image_map_replacement'] ) ) : array();

		$pairs = array();

		foreach ( array_values( $originals ) as $index => $original_id ) {
			$replacement_id = array_values( $replacements )[ $index ] ?? 0;

			if ( ! $original_id || ! $replacement_id ) {
				continue;
			}

			if ( ! wp_attachment_is_image( $original_id ) || ! wp_attachment_is_image( $replacement_id ) ) {
				continue;
			}

			$pairs[ $original_id ] = $replacement_id;
		}

		return $pairs;
	}

	/**
	 * Read the URL rewrite checkboxes (keys present only when checked).
	 *
	 * @return array<string, bool> Any of enabled, force_https.
	 */
	private function collect_url_rewrite(): array {
		$url_rewrite = array();

		if ( ! empty( $_POST['mbgs_url_rewrite_enabled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.
			$url_rewrite['enabled'] = true;
		}

		if ( ! empty( $_POST['mbgs_url_rewrite_force_https'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.
			$url_rewrite['force_https'] = true;
		}

		$host_form = isset( $_POST['mbgs_url_rewrite_host_form'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.
			? sanitize_text_field( wp_unslash( $_POST['mbgs_url_rewrite_host_form'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
			: '';

		if ( in_array( $host_form, array( 'www', 'apex' ), true ) ) {
			$url_rewrite['canonical_host_form'] = $host_form;
		}

		return $url_rewrite;
	}

	/**
	 * Enqueue the media-picker script on the Brand edit screen only.
	 * Hooked to `admin_enqueue_scripts`.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'mbgs-brand-media',
			THE_ANOTHER_MULTI_BRAND_GLOBAL_STYLES_PLUGIN_URL . 'assets/admin/brand-media.js',
			array( 'media-editor' ),
			THE_ANOTHER_MULTI_BRAND_GLOBAL_STYLES_VERSION,
			true
		);
	}
}
