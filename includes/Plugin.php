<?php
/**
 * Plugin Orchestrator Class
 *
 * @package MultiDomainGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiDomainGlobalStyles;

use TheAnother\Plugin\MultiDomainGlobalStyles\Brand\AdminNotices;
use TheAnother\Plugin\MultiDomainGlobalStyles\Brand\BrandPostType;
use TheAnother\Plugin\MultiDomainGlobalStyles\Brand\UrlRuleRegistry;
use TheAnother\Plugin\MultiDomainGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiDomainGlobalStyles\GlobalStyles\GlobalStylesOverride;
use TheAnother\Plugin\MultiDomainGlobalStyles\GlobalStyles\GlobalStylesPostService;
use TheAnother\Plugin\MultiDomainGlobalStyles\ContentVariables\VariableParser;
use TheAnother\Plugin\MultiDomainGlobalStyles\ContentVariables\VariableSubstitutionService;
use TheAnother\Plugin\MultiDomainGlobalStyles\Brand\BrandRepository;

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

		// The rule-map transient never expires, and the nonce-gated save()
		// handler above doesn't run on trash/untrash/delete — invalidate
		// unconditionally on any Brand status change so the map can't go
		// stale when a Brand is trashed or permanently deleted.
		$url_rule_registry = $this->container->get( 'url_rule_registry' );
		$hooks->register_action( 'save_post_' . BrandPostType::POST_TYPE, array( $url_rule_registry, 'invalidate_cache' ) );
		$hooks->register_action(
			'deleted_post',
			function ( $post_id, $post ) use ( $url_rule_registry ) {
				if ( $post && BrandPostType::POST_TYPE === $post->post_type ) {
					$url_rule_registry->invalidate_cache();
				}
			},
			10,
			2
		);

		$global_styles_override = $this->container->get( 'global_styles_override' );
		$hooks->register_filter( 'wp_theme_json_data_user', array( $global_styles_override, 'filter_theme_json' ) );

		$variable_substitution_service = $this->container->get( 'variable_substitution_service' );
		$hooks->register_action( 'template_redirect', array( $variable_substitution_service, 'start_buffer' ) );

		$admin_notices = $this->container->get( 'admin_notices' );
		$hooks->register_action( 'admin_notices', array( $admin_notices, 'render' ) );
	}

	/**
	 * Register all services in the container.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$this->container->register( 'url_rule_registry', fn() => new UrlRuleRegistry() );
		$this->container->register( 'variable_parser', fn() => new VariableParser() );
		$this->container->register( 'brand_repository', fn() => new BrandRepository() );
		$this->container->register( 'global_styles_post_service', fn() => new GlobalStylesPostService() );

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
			'brand_post_type',
			fn( Container $c ) => new BrandPostType(
				$c->get( 'url_rule_registry' ),
				$c->get( 'variable_parser' ),
				$c->get( 'global_styles_post_service' )
			)
		);

		$this->container->register( 'admin_notices', fn() => new AdminNotices() );
	}
}
