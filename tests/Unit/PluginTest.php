<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandPostType;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandSettings;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;
use TheAnother\Plugin\MultiBrandGlobalStyles\Container;
use TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables\VariableSubstitutionService;
use TheAnother\Plugin\MultiBrandGlobalStyles\Editor\EditorAssets;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesOverride;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesPostService;
use TheAnother\Plugin\MultiBrandGlobalStyles\HookManager;
use TheAnother\Plugin\MultiBrandGlobalStyles\Identity\SiteIdentityOverride;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\AttachmentLifecycle;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageMapBuilder;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageUrlReplacer;
use TheAnother\Plugin\MultiBrandGlobalStyles\Plugin;
use TheAnother\Plugin\MultiBrandGlobalStyles\Rendering\PageBuffer;
use TheAnother\Plugin\MultiBrandGlobalStyles\Rest\ReplacementsController;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\HostRewriter;
use WP_Post;

// Plugin::start() wires and constructs every real service in the container
// (that IS what Plugin.php's job is — see CLAUDE.md: "the single wiring
// map"), so every one of them is a legitimate #[UsesClass], not an accident.
// Without declaring them, PHPUnit's strict coverage metadata check discards
// this test's ENTIRE coverage contribution, including Plugin's own lines.
#[CoversClass( Plugin::class )]
#[UsesClass( Container::class )]
#[UsesClass( HookManager::class )]
#[UsesClass( BrandPostType::class )]
#[UsesClass( BrandRepository::class )]
#[UsesClass( BrandResolver::class )]
#[UsesClass( BrandSettings::class )]
#[UsesClass( UrlRuleRegistry::class )]
#[UsesClass( GlobalStylesOverride::class )]
#[UsesClass( GlobalStylesPostService::class )]
#[UsesClass( SiteIdentityOverride::class )]
#[UsesClass( VariableSubstitutionService::class )]
#[UsesClass( ImageUrlReplacer::class )]
#[UsesClass( ImageMapBuilder::class )]
#[UsesClass( AttachmentLifecycle::class )]
#[UsesClass( PageBuffer::class )]
#[UsesClass( ReplacementsController::class )]
#[UsesClass( EditorAssets::class )]
#[UsesClass( HostRewriter::class )]
class PluginTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'THE_ANOTHER_MULTI_BRAND_GLOBAL_STYLES_PLUGIN_DIR' ) ) {
			define( 'THE_ANOTHER_MULTI_BRAND_GLOBAL_STYLES_PLUGIN_DIR', '/tmp/' );
		}
		if ( ! defined( 'THE_ANOTHER_MULTI_BRAND_GLOBAL_STYLES_PLUGIN_URL' ) ) {
			define( 'THE_ANOTHER_MULTI_BRAND_GLOBAL_STYLES_PLUGIN_URL', 'https://example.com/wp-content/plugins/x/' );
		}

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->reset_singleton( Container::class );
		$this->reset_singleton( Plugin::class );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_singleton( string $class ): void {
		$reflection = new \ReflectionClass( $class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	public function test_get_instance_returns_same_instance(): void {
		$first  = Plugin::get_instance();
		$second = Plugin::get_instance();

		$this->assertSame( $first, $second );
	}

	public function test_start_registers_all_expected_hooks(): void {
		Plugin::get_instance()->start();

		$hooks = Container::get_instance()->get_hook_manager()->get_registered_hooks();

		$this->assertCount( 21, $hooks );

		$actions = array_column( array_filter( $hooks, fn( $h ) => 'action' === $h['type'] ), 'hook' );
		$filters = array_column( array_filter( $hooks, fn( $h ) => 'filter' === $h['type'] ), 'hook' );

		$this->assertSame(
			array( 'init', 'add_meta_boxes', 'save_post_mbgs_brand', 'admin_enqueue_scripts', 'save_post_mbgs_brand', 'deleted_post', 'save_post_mbgs_brand', 'template_redirect', 'admin_notices', 'added_post_meta', 'updated_post_meta', 'delete_attachment', 'rest_api_init', 'enqueue_block_editor_assets' ),
			$actions
		);
		$this->assertSame(
			array(
				'wp_theme_json_data_user',
				'pre_option_site_logo',
				'theme_mod_custom_logo',
				'pre_option_blogname',
				'pre_option_blogdescription',
				'pre_option_site_icon',
				'redirect_canonical',
			),
			$filters
		);
	}

	public function test_start_registers_all_services_resolvable_via_container(): void {
		Plugin::get_instance()->start();

		$container = Container::get_instance();

		foreach ( array(
			'url_rule_registry',
			'variable_parser',
			'brand_repository',
			'global_styles_post_service',
			'brand_resolver',
			'global_styles_override',
			'site_identity_override',
			'variable_substitution_service',
			'image_url_replacer',
			'image_map_builder',
			'attachment_lifecycle',
			'page_buffer',
			'host_rewriter',
			'brand_post_type',
			'admin_notices',
			'replacements_controller',
			'editor_assets',
		) as $service ) {
			$this->assertTrue( $container->has( $service ), "Expected service '{$service}' to be registered" );
		}

		$this->assertInstanceOf( BrandPostType::class, $container->get( 'brand_post_type' ) );
	}

	public static function deleted_post_cases(): array {
		return array(
			'matching post type triggers invalidation'     => array( new WP_Post( 5, BrandPostType::POST_TYPE ), true ),
			'non-matching post type is ignored'             => array( new WP_Post( 5, 'post' ), false ),
			'null post is ignored'                          => array( null, false ),
		);
	}

	#[DataProvider( 'deleted_post_cases' )]
	public function test_deleted_post_hook_invalidates_cache_only_for_brand_post_type( ?WP_Post $post, bool $expect_invalidation ): void {
		Plugin::get_instance()->start();

		$hooks   = Container::get_instance()->get_hook_manager()->get_registered_hooks();
		$matches = array_values( array_filter( $hooks, fn( $h ) => 'deleted_post' === $h['hook'] ) );
		$this->assertCount( 1, $matches );

		if ( $expect_invalidation ) {
			Functions\expect( 'delete_transient' )->once()->with( 'mbgs_rule_map' );
			Functions\expect( 'delete_transient' )->once()->with( 'mbgs_brand_settings_5' );
			Functions\expect( 'delete_transient' )->once()->with( 'mbgs_default_brand' );
		} else {
			Functions\expect( 'delete_transient' )->never();
		}

		( $matches[0]['callback'] )( 5, $post );
	}
}
