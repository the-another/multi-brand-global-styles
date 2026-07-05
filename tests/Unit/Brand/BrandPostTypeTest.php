<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Brand;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandPostType;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandSettings;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesPostService;
use TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables\VariableParser;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageMapBuilder;
use WP_Post;

#[CoversClass( BrandPostType::class )]
#[UsesClass( BrandSettings::class )]
class BrandPostTypeTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		$_POST['mbgs_brand_nonce'] = 'valid';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_POST );
		parent::tearDown();
	}

	private function make_post_type(
		?UrlRuleRegistry $url_rule_registry = null,
		?VariableParser $variable_parser = null,
		?GlobalStylesPostService $global_styles_post_service = null,
		?ImageMapBuilder $image_map_builder = null,
		?BrandRepository $brand_repository = null
	): BrandPostType {
		return new BrandPostType(
			$url_rule_registry ?? Mockery::mock( UrlRuleRegistry::class )->shouldIgnoreMissing(),
			$variable_parser ?? Mockery::mock( VariableParser::class )->shouldIgnoreMissing(),
			$global_styles_post_service ?? Mockery::mock( GlobalStylesPostService::class )->shouldIgnoreMissing(),
			$image_map_builder ?? Mockery::mock( ImageMapBuilder::class )->shouldIgnoreMissing(),
			$brand_repository ?? Mockery::mock( BrandRepository::class )->shouldIgnoreMissing()
		);
	}

	/**
	 * A repository mock whose get_settings() returns settings hydrated from $raw.
	 *
	 * @param array<string, mixed> $raw      Raw settings.
	 * @param int                  $brand_id Expected Brand ID.
	 * @return BrandRepository&Mockery\MockInterface Mock.
	 */
	private function repository_with_settings( array $raw, int $brand_id = 5 ) {
		$repository = Mockery::mock( BrandRepository::class )->shouldIgnoreMissing();
		$repository->shouldReceive( 'get_settings' )->with( $brand_id )->andReturn( BrandSettings::from_meta( $raw ) );

		return $repository;
	}

	/**
	 * The parts every successful save shares: an empty-form settings write.
	 *
	 * @return array{0: UrlRuleRegistry&Mockery\MockInterface, 1: VariableParser&Mockery\MockInterface, 2: GlobalStylesPostService&Mockery\MockInterface, 3: ImageMapBuilder&Mockery\MockInterface} Mocks.
	 */
	private function empty_form_mocks(): array {
		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )->andReturn( array() );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->andReturn( 42 );

		$image_map_builder = Mockery::mock( ImageMapBuilder::class );
		$image_map_builder->shouldReceive( 'build_url_map' )->with( array() )->andReturn( array() );

		return array( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder );
	}

	/**
	 * The settings array save() writes for an empty form, with overrides.
	 *
	 * @param array<string, mixed> $overrides Key overrides.
	 * @return array<string, mixed> Expected settings payload.
	 */
	private static function expected_settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'rules'         => array(),
				'variables'     => array(),
				'is_default'    => false,
				'identity'      => array(),
				'image_map'     => array(),
				'image_url_map' => array(),
				'url_rewrite'   => array(),
			),
			$overrides
		);
	}

	public function test_register_gates_cpt_behind_edit_theme_options(): void {
		Functions\when( '__' )->returnArg();

		Functions\expect( 'register_post_type' )
			->once()
			->with(
				'mbgs_brand',
				Mockery::on(
					function ( $args ) {
						return isset( $args['capabilities'] )
							&& 'edit_theme_options' === $args['capabilities']['create_posts']
							&& 'edit_theme_options' === $args['capabilities']['edit_posts'];
					}
				)
			);

		$this->make_post_type()->register();
	}

	public function test_register_meta_boxes_registers_all_seven_boxes(): void {
		Functions\when( '__' )->returnArg();

		$calls = array();
		Functions\when( 'add_meta_box' )->alias(
			function ( $id, $title, $callback, $post_type, $context, $priority ) use ( &$calls ) {
				$calls[] = array( $id, $callback[1], $post_type, $context, $priority );
			}
		);

		$this->make_post_type()->register_meta_boxes();

		$this->assertSame(
			array(
				array( 'mbgs_rules', 'render_rules_meta_box', 'mbgs_brand', 'normal', 'high' ),
				array( 'mbgs_variables', 'render_variables_meta_box', 'mbgs_brand', 'normal', 'default' ),
				array( 'mbgs_default', 'render_default_meta_box', 'mbgs_brand', 'side', 'default' ),
				array( 'mbgs_styles', 'render_styles_meta_box', 'mbgs_brand', 'normal', 'default' ),
				array( 'mbgs_identity', 'render_identity_meta_box', 'mbgs_brand', 'side', 'default' ),
				array( 'mbgs_image_map', 'render_image_map_meta_box', 'mbgs_brand', 'normal', 'default' ),
				array( 'mbgs_url_rewrite', 'render_url_rewrite_meta_box', 'mbgs_brand', 'side', 'default' ),
			),
			$calls
		);
	}

	public function test_render_rules_meta_box_outputs_existing_rules(): void {
		Functions\when( 'esc_html_e' )->alias(
			function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\expect( 'wp_nonce_field' )->once()->with( 'mbgs_save_brand', 'mbgs_brand_nonce' );

		$repository = $this->repository_with_settings( array( 'rules' => array( 'site.com', 'other.com/farm' ) ) );

		ob_start();
		$this->make_post_type( brand_repository: $repository )->render_rules_meta_box( new WP_Post( 5 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="mbgs_rules"', $output );
		$this->assertStringContainsString( "site.com\nother.com/farm", $output );
	}

	public function test_render_variables_meta_box_outputs_key_value_lines(): void {
		Functions\when( 'esc_html_e' )->justReturn( null );
		Functions\when( 'esc_textarea' )->returnArg();

		$repository = $this->repository_with_settings(
			array(
				'variables' => array(
					'name'    => 'Acme',
					'tagline' => 'Great deals',
				),
			)
		);

		ob_start();
		$this->make_post_type( brand_repository: $repository )->render_variables_meta_box( new WP_Post( 5 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( "name = Acme\ntagline = Great deals", $output );
	}

	public function test_render_default_meta_box_checks_box_when_default(): void {
		Functions\when( 'esc_html_e' )->justReturn( null );
		Functions\expect( 'checked' )->once()->with( true )->andReturnUsing(
			function () {
				echo 'checked="checked"';
			}
		);

		$repository = $this->repository_with_settings( array( 'is_default' => true ) );

		ob_start();
		$this->make_post_type( brand_repository: $repository )->render_default_meta_box( new WP_Post( 5 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="mbgs_is_default"', $output );
		$this->assertStringContainsString( 'checked="checked"', $output );
	}

	public function test_render_styles_meta_box_outputs_data_when_post_id_present(): void {
		Functions\when( 'esc_html_e' )->justReturn( null );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfour' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'abc123' );
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );

		$repository = $this->repository_with_settings( array( 'global_styles_post_id' => 42 ) );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'get_global_styles_data' )
			->once()
			->with( 42 )
			->andReturn( array( 'settings' => array() ) );

		ob_start();
		$this->make_post_type( null, null, $global_styles_post_service, null, $repository )->render_styles_meta_box( new WP_Post( 5 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="mbgs_styles_json"', $output );
		$this->assertStringContainsString( '"settings"', $output );
	}

	public function test_render_styles_meta_box_outputs_active_styles_link(): void {
		Functions\when( 'esc_html_e' )->justReturn( null );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		Functions\when( 'get_stylesheet' )->justReturn( 'twentytwentyfour' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'abc123' );
		Functions\when( 'rest_url' )->alias( fn( $path ) => 'https://example.com/wp-json/' . $path );
		Functions\when( 'add_query_arg' )->alias( fn( $key, $value, $url ) => $url . '?' . $key . '=' . $value );

		$repository = $this->repository_with_settings( array() );

		ob_start();
		$this->make_post_type( brand_repository: $repository )->render_styles_meta_box( new WP_Post( 5 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'href="https://example.com/wp-json/wp/v2/global-styles/themes/twentytwentyfour?_wpnonce=abc123"',
			$output
		);
		$this->assertStringContainsString( 'target="_blank"', $output );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $output );
	}

	public function test_render_url_rewrite_meta_box_reflects_settings(): void {
		Functions\when( 'esc_html_e' )->justReturn( null );

		$checked_calls = array();
		Functions\when( 'checked' )->alias(
			function ( $value ) use ( &$checked_calls ) {
				$checked_calls[] = $value;
				echo $value ? 'checked="checked"' : '';
			}
		);

		$repository = $this->repository_with_settings( array( 'url_rewrite' => array( 'enabled' => true ) ) );

		ob_start();
		$this->make_post_type( brand_repository: $repository )->render_url_rewrite_meta_box( new WP_Post( 5 ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="mbgs_url_rewrite_enabled"', $output );
		$this->assertStringContainsString( 'name="mbgs_url_rewrite_force_https"', $output );
		$this->assertSame( array( true, false ), $checked_calls );
	}

	public function test_save_skips_when_nonce_missing(): void {
		unset( $_POST['mbgs_brand_nonce'] );

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldNotReceive( 'update_settings' );

		$this->make_post_type( brand_repository: $brand_repository )->save( 5 );

		$this->assertTrue( true ); // Assertions are the shouldNotReceive expectations above.
	}

	public function test_save_skips_when_user_cannot_edit_post(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldNotReceive( 'update_settings' );

		$this->make_post_type( brand_repository: $brand_repository )->save( 5 );

		$this->assertTrue( true ); // Assertions are the shouldNotReceive expectations above.
	}

	public function test_save_writes_one_consolidated_settings_entry(): void {
		$_POST['mbgs_rules']     = "example.com\nexample.org";
		$_POST['mbgs_variables'] = 'name = Acme';

		// Do NOT use empty_form_mocks() here: it builds a UrlRuleRegistry mock
		// with a ->once() expectation, and a discarded mock with an unmet
		// count expectation fails at Mockery teardown. Build all mocks inline.
		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class )->shouldIgnoreMissing();

		$image_map_builder = Mockery::mock( ImageMapBuilder::class );
		$image_map_builder->shouldReceive( 'build_url_map' )->with( array() )->andReturn( array() );

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )
			->with( "example.com\nexample.org" )
			->andReturn( array( 'example.com', 'example.org' ) );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'example.com', 5 )->andReturn( null );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'example.org', 5 )->andReturn( null );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->with( 'name = Acme' )->andReturn( array( 'name' => 'Acme' ) );

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with(
				5,
				self::expected_settings(
					array(
						'rules'     => array( 'example.com', 'example.org' ),
						'variables' => array( 'name' => 'Acme' ),
					)
				)
			);

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 5 );
	}

	public function test_save_drops_conflicting_rule_and_records_rejection(): void {
		$_POST['mbgs_rules']     = "example.com\ntaken.com";
		$_POST['mbgs_variables'] = '';

		// Inline mocks for the same reason as test_save_writes_one_consolidated_settings_entry.
		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class )->shouldIgnoreMissing();

		$image_map_builder = Mockery::mock( ImageMapBuilder::class );
		$image_map_builder->shouldReceive( 'build_url_map' )->with( array() )->andReturn( array() );

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )->andReturn( array( 'example.com', 'taken.com' ) );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'example.com', 5 )->andReturn( null );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'taken.com', 5 )->andReturn( 9 );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		Functions\expect( 'set_transient' )
			->once()
			->with( 'mbgs_rule_conflict_1', array( 'taken.com' ), 30 );

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 5, self::expected_settings( array( 'rules' => array( 'example.com' ) ) ) );

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 5 );
	}

	public function test_save_clears_other_defaults_when_marked_default(): void {
		$_POST['mbgs_rules']      = '';
		$_POST['mbgs_variables']  = '';
		$_POST['mbgs_is_default'] = '1';

		list( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder ) = $this->empty_form_mocks();

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'get_brand_ids' )->once()->andReturn( array( 7, 5, 9 ) );
		$brand_repository->shouldReceive( 'get_settings' )->with( 7 )->andReturn( BrandSettings::from_meta( array( 'is_default' => true ) ) );
		$brand_repository->shouldReceive( 'get_settings' )->with( 9 )->andReturn( BrandSettings::from_meta( array() ) );
		$brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 7, array( 'is_default' => false ) );
		$brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 5, self::expected_settings( array( 'is_default' => true ) ) );

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 5 );
	}

	public function test_save_persists_identity_fields(): void {
		$_POST['mbgs_rules']     = '';
		$_POST['mbgs_variables'] = '';
		$_POST['mbgs_logo_id']   = '123';
		$_POST['mbgs_icon_id']   = '0';
		$_POST['mbgs_title']     = 'Second Brand Co';
		$_POST['mbgs_tagline']   = '';

		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );

		list( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder ) = $this->empty_form_mocks();

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with(
				77,
				self::expected_settings(
					array(
						'identity' => array(
							'logo_id' => 123,
							'title'   => 'Second Brand Co',
						),
					)
				)
			);

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 77 );
	}

	public function test_save_drops_non_image_identity_attachments(): void {
		$_POST['mbgs_rules']     = '';
		$_POST['mbgs_variables'] = '';
		$_POST['mbgs_logo_id']   = '123';

		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );

		list( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder ) = $this->empty_form_mocks();

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 77, self::expected_settings() );

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 77 );
	}

	public function test_save_builds_image_map_and_derived_url_map(): void {
		$_POST['mbgs_rules']                 = '';
		$_POST['mbgs_variables']             = '';
		$_POST['mbgs_image_map_original']    = array( '10', '0', '30' );
		$_POST['mbgs_image_map_replacement'] = array( '20', '5', '' );

		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );

		list( $url_rule_registry, $variable_parser, $global_styles_post_service ) = $this->empty_form_mocks();

		$image_map_builder = Mockery::mock( ImageMapBuilder::class );
		$image_map_builder->shouldReceive( 'build_url_map' )
			->once()
			->with( array( 10 => 20 ) )
			->andReturn( array( 'https://a' => 'https://b' ) );

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with(
				77,
				self::expected_settings(
					array(
						'image_map'     => array( 10 => 20 ),
						'image_url_map' => array( 'https://a' => 'https://b' ),
					)
				)
			);

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 77 );
	}

	public function test_save_drops_non_image_pairs(): void {
		$_POST['mbgs_rules']                 = '';
		$_POST['mbgs_variables']             = '';
		$_POST['mbgs_image_map_original']    = array( '10' );
		$_POST['mbgs_image_map_replacement'] = array( '20' );

		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );

		list( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder ) = $this->empty_form_mocks();

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 77, self::expected_settings() );

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 77 );
	}

	public function test_save_persists_url_rewrite_flags(): void {
		$_POST['mbgs_rules']                        = '';
		$_POST['mbgs_variables']                    = '';
		$_POST['mbgs_url_rewrite_enabled']          = '1';
		$_POST['mbgs_url_rewrite_force_https']      = '1';

		list( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder ) = $this->empty_form_mocks();

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with(
				5,
				self::expected_settings(
					array(
						'url_rewrite' => array(
							'enabled'     => true,
							'force_https' => true,
						),
					)
				)
			);

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 5 );
	}

	public function test_save_persists_valid_styles_json(): void {
		$_POST['mbgs_rules']       = '';
		$_POST['mbgs_variables']   = '';
		$_POST['mbgs_styles_json'] = '{"settings":{"color":{"palette":[]}},"styles":{}}';

		list( $url_rule_registry, $variable_parser, , $image_map_builder ) = $this->empty_form_mocks();

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )
			->twice()
			->with( 5 )
			->andReturn( 42 );

		Functions\expect( 'wp_slash' )->once()->andReturnUsing( fn( $v ) => $v );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );

		Functions\expect( 'wp_update_post' )
			->once()
			->with(
				Mockery::on(
					function ( $args ) {
						return 42 === $args['ID']
							&& is_string( $args['post_content'] )
							&& str_contains( $args['post_content'], '"isGlobalStylesUserThemeJSON":true' )
							&& str_contains( $args['post_content'], '"settings":{"color":{"palette":[]}}' );
					}
				)
			);

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'update_settings' )->once()->with( 5, self::expected_settings() );

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 5 );
	}

	public function test_save_skips_styles_write_when_json_invalid(): void {
		$_POST['mbgs_rules']       = '';
		$_POST['mbgs_variables']   = '';
		$_POST['mbgs_styles_json'] = '{not valid json';

		list( $url_rule_registry, $variable_parser, , $image_map_builder ) = $this->empty_form_mocks();

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )
			->once()
			->with( 5 )
			->andReturn( 42 );

		Functions\expect( 'wp_update_post' )->never();

		$brand_repository = Mockery::mock( BrandRepository::class );
		$brand_repository->shouldReceive( 'update_settings' )->once()->with( 5, self::expected_settings() );

		$this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service, $image_map_builder, $brand_repository )->save( 5 );
	}
}
