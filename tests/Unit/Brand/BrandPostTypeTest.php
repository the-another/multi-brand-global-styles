<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Brand;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandPostType;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesPostService;
use TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables\VariableParser;

#[CoversClass( BrandPostType::class )]
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
		UrlRuleRegistry $url_rule_registry = null,
		VariableParser $variable_parser = null,
		GlobalStylesPostService $global_styles_post_service = null
	): BrandPostType {
		return new BrandPostType(
			$url_rule_registry ?? Mockery::mock( UrlRuleRegistry::class ),
			$variable_parser ?? Mockery::mock( VariableParser::class ),
			$global_styles_post_service ?? Mockery::mock( GlobalStylesPostService::class )
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

		$post_type = $this->make_post_type();

		$post_type->register();
	}

	public function test_save_skips_when_nonce_missing(): void {
		unset( $_POST['mbgs_brand_nonce'] );

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldNotReceive( 'parse_rules_input' );

		$post_type = $this->make_post_type( $url_rule_registry );

		$post_type->save( 5 );

		$this->assertTrue( true ); // No exception, no calls made — assertions are the shouldNotReceive expectations above.
	}

	public function test_save_accepts_rules_without_conflicts(): void {
		$_POST['mbgs_rules']   = "example.com\nexample.org";
		$_POST['mbgs_variables'] = '';

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )
			->with( "example.com\nexample.org" )
			->andReturn( array( 'example.com', 'example.org' ) );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'example.com', 5 )->andReturn( null );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'example.org', 5 )->andReturn( null );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->with( '' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->once()->with( 5 )->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_rules', array( 'example.com', 'example.org' ) )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_is_default', '' )->once();

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_drops_conflicting_rule_and_records_rejection(): void {
		$_POST['mbgs_rules']   = "example.com\ntaken.com";
		$_POST['mbgs_variables'] = '';

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )->andReturn( array( 'example.com', 'taken.com' ) );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'example.com', 5 )->andReturn( null );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'taken.com', 5 )->andReturn( 9 );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_rules', array( 'example.com' ) )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_is_default', '' )->once();
		Functions\expect( 'set_transient' )
			->once()
			->with( 'mbgs_rule_conflict_1', array( 'taken.com' ), 30 );

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_stores_parsed_variables(): void {
		$_POST['mbgs_rules']   = '';
		$_POST['mbgs_variables'] = 'name = Acme';

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )->andReturn( array() );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->with( 'name = Acme' )->andReturn( array( 'name' => 'Acme' ) );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_rules', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_variables', array( 'name' => 'Acme' ) )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_is_default', '' )->once();

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_clears_other_defaults_when_marked_default(): void {
		$_POST['mbgs_rules']    = '';
		$_POST['mbgs_variables']  = '';
		$_POST['mbgs_is_default'] = '1';

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )->andReturn( array() );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->andReturn( 42 );

		Functions\expect( 'get_posts' )
			->once()
			->with(
				array(
					'post_type'      => 'mbgs_brand',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'post__not_in'   => array( 5 ),
					'post_status'    => 'any',
					'meta_key'       => '_mbgs_is_default',
					'meta_value'     => '1',
				)
			)
			->andReturn( array( 7 ) );
		Functions\expect( 'delete_post_meta' )->once()->with( 7, '_mbgs_is_default' );

		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_rules', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_is_default', '1' )->once();

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_persists_valid_styles_json(): void {
		$_POST['mbgs_rules']       = '';
		$_POST['mbgs_variables']   = '';
		$_POST['mbgs_styles_json'] = '{"settings":{"color":{"palette":[]}},"styles":{}}';

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )->andReturn( array() );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )
			->twice()
			->with( 5 )
			->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_rules', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_is_default', '' )->once();

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

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_skips_styles_write_when_json_invalid(): void {
		$_POST['mbgs_rules']       = '';
		$_POST['mbgs_variables']   = '';
		$_POST['mbgs_styles_json'] = '{not valid json';

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )->andReturn( array() );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )
			->once()
			->with( 5 )
			->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_rules', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mbgs_is_default', '' )->once();

		Functions\expect( 'wp_update_post' )->never();

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}
}
