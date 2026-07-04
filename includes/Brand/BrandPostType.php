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
	 * Constructor.
	 *
	 * @param UrlRuleRegistry         $url_rule_registry             URL rule registry service.
	 * @param VariableParser          $variable_parser             Variable parser service.
	 * @param GlobalStylesPostService $global_styles_post_service  Global styles post service.
	 */
	public function __construct(
		UrlRuleRegistry $url_rule_registry,
		VariableParser $variable_parser,
		GlobalStylesPostService $global_styles_post_service
	) {
		$this->url_rule_registry          = $url_rule_registry;
		$this->variable_parser            = $variable_parser;
		$this->global_styles_post_service = $global_styles_post_service;
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
	}

	/**
	 * Render the URL rules meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_rules_meta_box( WP_Post $post ): void {
		$rules = get_post_meta( $post->ID, '_mbgs_rules', true );
		$rules = is_array( $rules ) ? $rules : array();

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
		$variables = get_post_meta( $post->ID, '_mbgs_variables', true );
		$variables = is_array( $variables ) ? $variables : array();

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
		$is_default = get_post_meta( $post->ID, '_mbgs_is_default', true );
		?>
		<label>
			<input type="checkbox" name="mbgs_is_default" value="1" <?php checked( $is_default, '1' ); ?> />
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
		$global_styles_post_id = get_post_meta( $post->ID, '_mbgs_global_styles_post_id', true );
		$data                  = $global_styles_post_id ? $this->global_styles_post_service->get_global_styles_data( (int) $global_styles_post_id ) : array();
		?>
		<p><?php esc_html_e( 'Raw theme.json-shaped settings/styles for this Brand. A richer visual editor is planned; this is the interim editing UI.', 'the-another-multi-brand-global-styles' ); ?></p>
		<textarea name="mbgs_styles_json" rows="12" style="width:100%;font-family:monospace;"><?php echo esc_textarea( wp_json_encode( $data, JSON_PRETTY_PRINT ) ); ?></textarea>
		<?php
	}

	/**
	 * Render the Brand Identity meta box (logo, site icon, title, tagline).
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_identity_meta_box( WP_Post $post ): void {
		$identity = get_post_meta( $post->ID, '_mbgs_identity', true );
		$identity = is_array( $identity ) ? $identity : array();
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
	 * Save handler. Hooked to `save_post_mbgs_brand`.
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

		$this->save_rules( $post_id );
		$this->save_variables( $post_id );
		$this->save_default_flag( $post_id );
		$this->save_styles( $post_id );
		$this->save_identity( $post_id );

		$this->url_rule_registry->invalidate_cache();
		$this->global_styles_post_service->ensure_global_styles_post( $post_id );
	}

	/**
	 * Parse, validate, and persist the URL rules field.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_rules( int $post_id ): void {
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

		update_post_meta( $post_id, '_mbgs_rules', $accepted );
	}

	/**
	 * Parse and persist the content variables field.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_variables( int $post_id ): void {
		$raw = isset( $_POST['mbgs_variables'] ) ? sanitize_textarea_field( wp_unslash( $_POST['mbgs_variables'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.

		update_post_meta( $post_id, '_mbgs_variables', $this->variable_parser->parse( $raw ) );
	}

	/**
	 * Persist the default-Brand flag, clearing it from any other Brand.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_default_flag( int $post_id ): void {
		$is_default = ! empty( $_POST['mbgs_is_default'] ) ? '1' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in save() before delegation.

		if ( '1' === $is_default ) {
			$others = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'post_status'    => 'any',
					'meta_key'       => '_mbgs_is_default', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Save-time flag lookup over the handful of Brands a site defines.
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Save-time flag lookup over the handful of Brands a site defines.
				)
			);
			foreach ( $others as $other_id ) {
				if ( $other_id === $post_id ) {
					continue;
				}
				delete_post_meta( $other_id, '_mbgs_is_default' );
			}
		}

		update_post_meta( $post_id, '_mbgs_is_default', $is_default );
	}

	/**
	 * Parse and persist the raw-JSON styles field into the linked wp_global_styles post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_styles( int $post_id ): void {
		$raw = isset( $_POST['mbgs_styles_json'] ) && is_string( $_POST['mbgs_styles_json'] ) ? wp_unslash( $_POST['mbgs_styles_json'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save() before delegation; raw JSON would be corrupted by sanitize_*(), so it is validated instead: json_decode() below discards anything that is not a JSON object, and only a wp_json_encode() re-encoding of the parsed settings/styles subtrees is ever persisted.

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return;
		}

		$global_styles_post_id = $this->global_styles_post_service->ensure_global_styles_post( $post_id );

		wp_update_post(
			wp_slash(
				array(
					'ID'           => $global_styles_post_id,
					'post_content' => wp_json_encode(
						array(
							'version'                     => 3,
							'isGlobalStylesUserThemeJSON' => true,
							'settings'                    => $decoded['settings'] ?? new \stdClass(),
							'styles'                      => $decoded['styles'] ?? new \stdClass(),
						)
					),
				)
			)
		);
	}

	/**
	 * Parse, validate, and persist the identity fields.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_identity( int $post_id ): void {
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

		update_post_meta( $post_id, '_mbgs_identity', $identity );
	}
}
