<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiDomainGlobalStyles\Tests\Brand;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiDomainGlobalStyles\Brand\BrandPostType;
use TheAnother\Plugin\MultiDomainGlobalStyles\Brand\UrlRuleRegistry;
use TheAnother\Plugin\MultiDomainGlobalStyles\GlobalStyles\GlobalStylesPostService;
use TheAnother\Plugin\MultiDomainGlobalStyles\ContentVariables\VariableParser;

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

		$_POST['mdgs_brand_nonce'] = 'valid';
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

	public function test_save_skips_when_nonce_missing(): void {
		unset( $_POST['mdgs_brand_nonce'] );

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldNotReceive( 'parse_rules_input' );

		$post_type = $this->make_post_type( $url_rule_registry );

		$post_type->save( 5 );

		$this->assertTrue( true ); // No exception, no calls made — assertions are the shouldNotReceive expectations above.
	}

	public function test_save_accepts_rules_without_conflicts(): void {
		$_POST['mdgs_rules']   = "example.com\nexample.org";
		$_POST['mdgs_variables'] = '';

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

		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_rules', array( 'example.com', 'example.org' ) )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_is_default', '' )->once();

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_drops_conflicting_rule_and_records_rejection(): void {
		$_POST['mdgs_rules']   = "example.com\ntaken.com";
		$_POST['mdgs_variables'] = '';

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )->andReturn( array( 'example.com', 'taken.com' ) );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'example.com', 5 )->andReturn( null );
		$url_rule_registry->shouldReceive( 'find_conflicting_brand' )->with( 'taken.com', 5 )->andReturn( 9 );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->andReturn( array() );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_rules', array( 'example.com' ) )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_is_default', '' )->once();
		Functions\expect( 'set_transient' )
			->once()
			->with( 'mdgs_rule_conflict_1', array( 'taken.com' ), 30 );

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_stores_parsed_variables(): void {
		$_POST['mdgs_rules']   = '';
		$_POST['mdgs_variables'] = 'name = Acme';

		$url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$url_rule_registry->shouldReceive( 'parse_rules_input' )->andReturn( array() );
		$url_rule_registry->shouldReceive( 'invalidate_cache' )->once();

		$variable_parser = Mockery::mock( VariableParser::class );
		$variable_parser->shouldReceive( 'parse' )->with( 'name = Acme' )->andReturn( array( 'name' => 'Acme' ) );

		$global_styles_post_service = Mockery::mock( GlobalStylesPostService::class );
		$global_styles_post_service->shouldReceive( 'ensure_global_styles_post' )->andReturn( 42 );

		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_rules', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_variables', array( 'name' => 'Acme' ) )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_is_default', '' )->once();

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}

	public function test_save_clears_other_defaults_when_marked_default(): void {
		$_POST['mdgs_rules']    = '';
		$_POST['mdgs_variables']  = '';
		$_POST['mdgs_is_default'] = '1';

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
					'post_type'      => 'mdgs_brand',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'post__not_in'   => array( 5 ),
					'meta_key'       => '_mdgs_is_default',
					'meta_value'     => '1',
				)
			)
			->andReturn( array( 7 ) );
		Functions\expect( 'delete_post_meta' )->once()->with( 7, '_mdgs_is_default' );

		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_rules', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_variables', array() )->once();
		Functions\expect( 'update_post_meta' )->with( 5, '_mdgs_is_default', '1' )->once();

		$post_type = $this->make_post_type( $url_rule_registry, $variable_parser, $global_styles_post_service );

		$post_type->save( 5 );
	}
}
