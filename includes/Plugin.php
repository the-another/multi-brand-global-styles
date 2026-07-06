<?php
/**
 * Plugin Orchestrator Class
 *
 * @package MultiBrandGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\AdminNotices;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandPostType;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesOverride;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesPostService;
use TheAnother\Plugin\MultiBrandGlobalStyles\Identity\SiteIdentityOverride;
use TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables\VariableParser;
use TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables\VariableSubstitutionService;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Editor\EditorAssets;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\AttachmentLifecycle;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageUrlReplacer;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageMapBuilder;
use TheAnother\Plugin\MultiBrandGlobalStyles\Rendering\PageBuffer;
use TheAnother\Plugin\MultiBrandGlobalStyles\Rest\ReplacementsController;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\HostCanonicalizer;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\HostRewriter;

/**
 * Class Plugin
 *
 * Registers services and wires hooks.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->container = Container::get_instance();
	}

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin Plugin instance.
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Start the plugin: register services and hooks.
	 *
	 * @return void
	 */
	public function start(): void {
		$this->register_services();

		$hooks = $this->container->get_hook_manager();

		$brand_post_type = $this->container->get( 'brand_post_type' );
		$hooks->register_action( 'init', array( $brand_post_type, 'register' ) );
		$hooks->register_action( 'add_meta_boxes', array( $brand_post_type, 'register_meta_boxes' ) );
		$hooks->register_action( 'save_post_' . BrandPostType::POST_TYPE, array( $brand_post_type, 'save' ) );
		$hooks->register_action( 'admin_enqueue_scripts', array( $brand_post_type, 'enqueue_admin_assets' ) );

		// The rule-map transient never expires, and the nonce-gated save()
		// handler above doesn't run on trash/untrash/delete — invalidate
		// unconditionally on any Brand status change so the map can't go
		// stale when a Brand is trashed or permanently deleted. The same
		// applies to the per-Brand settings + default-Brand transients.
		$url_rule_registry = $this->container->get( 'url_rule_registry' );
		$brand_repository  = $this->container->get( 'brand_repository' );
		$hooks->register_action( 'save_post_' . BrandPostType::POST_TYPE, array( $url_rule_registry, 'invalidate_cache' ) );
		$hooks->register_action(
			'deleted_post',
			function ( $post_id, $post ) use ( $url_rule_registry, $brand_repository ) {
				if ( $post && BrandPostType::POST_TYPE === $post->post_type ) {
					$url_rule_registry->invalidate_cache();
					$brand_repository->flush_brand_caches( (int) $post_id );
				}
			},
			10,
			2
		);
		$hooks->register_action( 'save_post_' . BrandPostType::POST_TYPE, array( $brand_repository, 'flush_brand_caches' ) );

		$global_styles_override = $this->container->get( 'global_styles_override' );
		$hooks->register_filter( 'wp_theme_json_data_user', array( $global_styles_override, 'filter_theme_json' ) );

		$site_identity_override = $this->container->get( 'site_identity_override' );
		$hooks->register_filter( 'pre_option_site_logo', array( $site_identity_override, 'filter_logo_option' ) );
		$hooks->register_filter( 'theme_mod_custom_logo', array( $site_identity_override, 'filter_logo_theme_mod' ) );
		$hooks->register_filter( 'pre_option_blogname', array( $site_identity_override, 'filter_blogname' ) );
		$hooks->register_filter( 'pre_option_blogdescription', array( $site_identity_override, 'filter_blogdescription' ) );
		$hooks->register_filter( 'pre_option_site_icon', array( $site_identity_override, 'filter_site_icon' ) );

		$host_canonicalizer = $this->container->get( 'host_canonicalizer' );
		$hooks->register_action( 'template_redirect', array( $host_canonicalizer, 'handle' ), 1 );

		$page_buffer = $this->container->get( 'page_buffer' );
		$hooks->register_action( 'template_redirect', array( $page_buffer, 'start_buffer' ) );

		$host_rewriter = $this->container->get( 'host_rewriter' );
		$hooks->register_filter( 'redirect_canonical', array( $host_rewriter, 'filter_redirect_canonical' ), 10, 2 );

		$admin_notices = $this->container->get( 'admin_notices' );
		$hooks->register_action( 'admin_notices', array( $admin_notices, 'render' ) );

		$attachment_lifecycle = $this->container->get( 'attachment_lifecycle' );
		$hooks->register_action( 'added_post_meta', array( $attachment_lifecycle, 'on_attachment_meta_saved' ), 10, 3 );
		$hooks->register_action( 'updated_post_meta', array( $attachment_lifecycle, 'on_attachment_meta_saved' ), 10, 3 );
		$hooks->register_action( 'delete_attachment', array( $attachment_lifecycle, 'on_delete_attachment' ) );

		$replacements_controller = $this->container->get( 'replacements_controller' );
		$hooks->register_action( 'rest_api_init', array( $replacements_controller, 'register_routes' ) );

		$editor_assets = $this->container->get( 'editor_assets' );
		$hooks->register_action( 'enqueue_block_editor_assets', array( $editor_assets, 'enqueue' ) );
	}

	/**
	 * Register all services in the container.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$this->container->register(
			'url_rule_registry',
			fn( Container $c ) => new UrlRuleRegistry( $c->get( 'brand_repository' ) )
		);
		$this->container->register( 'variable_parser', fn() => new VariableParser() );
		$this->container->register( 'brand_repository', fn() => new BrandRepository() );
		$this->container->register(
			'global_styles_post_service',
			fn( Container $c ) => new GlobalStylesPostService( $c->get( 'brand_repository' ) )
		);

		$this->container->register(
			'brand_resolver',
			fn( Container $c ) => new BrandResolver( $c->get( 'url_rule_registry' ), $c->get( 'brand_repository' ) )
		);

		$this->container->register(
			'global_styles_override',
			fn( Container $c ) => new GlobalStylesOverride(
				$c->get( 'brand_resolver' ),
				$c->get( 'brand_repository' ),
				$c->get( 'global_styles_post_service' )
			)
		);

		$this->container->register(
			'variable_substitution_service',
			fn( Container $c ) => new VariableSubstitutionService( $c->get( 'brand_resolver' ), $c->get( 'brand_repository' ) )
		);

		$this->container->register(
			'site_identity_override',
			fn( Container $c ) => new SiteIdentityOverride( $c->get( 'brand_resolver' ), $c->get( 'brand_repository' ) )
		);

		$this->container->register(
			'image_url_replacer',
			fn( Container $c ) => new ImageUrlReplacer( $c->get( 'brand_resolver' ), $c->get( 'brand_repository' ) )
		);

		$this->container->register(
			'host_rewriter',
			fn( Container $c ) => new HostRewriter( $c->get( 'brand_resolver' ), $c->get( 'brand_repository' ) )
		);

		$this->container->register(
			'host_canonicalizer',
			fn( Container $c ) => new HostCanonicalizer( $c->get( 'brand_resolver' ), $c->get( 'brand_repository' ) )
		);

		$this->container->register(
			'image_map_builder',
			fn( Container $c ) => new ImageMapBuilder( $c->get( 'brand_repository' ) )
		);

		$this->container->register(
			'attachment_lifecycle',
			fn( Container $c ) => new AttachmentLifecycle( $c->get( 'brand_repository' ), $c->get( 'image_map_builder' ) )
		);

		$this->container->register(
			'page_buffer',
			fn( Container $c ) => new PageBuffer(
				array(
					array( $c->get( 'variable_substitution_service' ), 'replace' ),
					array( $c->get( 'image_url_replacer' ), 'replace' ),
					// LAST: the image URL map's keys carry canonical-host URLs,
					// so hosts must still be canonical when the image pass runs.
					array( $c->get( 'host_rewriter' ), 'replace' ),
				)
			)
		);

		$this->container->register(
			'brand_post_type',
			fn( Container $c ) => new BrandPostType(
				$c->get( 'url_rule_registry' ),
				$c->get( 'variable_parser' ),
				$c->get( 'global_styles_post_service' ),
				$c->get( 'image_map_builder' ),
				$c->get( 'brand_repository' )
			)
		);

		$this->container->register( 'admin_notices', fn() => new AdminNotices() );

		$this->container->register(
			'replacements_controller',
			fn( Container $c ) => new ReplacementsController( $c->get( 'brand_repository' ), $c->get( 'image_map_builder' ) )
		);

		$this->container->register(
			'editor_assets',
			fn() => new EditorAssets(
				THE_ANOTHER_MULTI_BRAND_GLOBAL_STYLES_PLUGIN_DIR,
				THE_ANOTHER_MULTI_BRAND_GLOBAL_STYLES_PLUGIN_URL
			)
		);
	}
}
