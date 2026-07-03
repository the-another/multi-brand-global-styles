<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\GlobalStyles;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesOverride;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesPostService;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;

/**
 * Minimal stand-in for WP_Theme_JSON_Data, which isn't available outside a
 * full WordPress load. Only implements what GlobalStylesOverride calls.
 */
class FakeThemeJsonData {
	public array $received_update = array();

	public function update_with( array $data ): self {
		$this->received_update = $data;
		return $this;
	}
}

#[CoversClass( GlobalStylesOverride::class )]
class GlobalStylesOverrideTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_input_unchanged_in_admin(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( true );

		$resolver   = Mockery::mock( BrandResolver::class );
		$repository = Mockery::mock( BrandRepository::class );
		$posts      = Mockery::mock( GlobalStylesPostService::class );

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$this->assertSame( $theme_json, $override->filter_theme_json( $theme_json ) );
	}

	public function test_returns_input_unchanged_when_doing_ajax(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );
		Functions\expect( 'wp_doing_ajax' )->once()->andReturn( true );

		$resolver   = Mockery::mock( BrandResolver::class );
		$repository = Mockery::mock( BrandRepository::class );
		$posts      = Mockery::mock( GlobalStylesPostService::class );

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$this->assertSame( $theme_json, $override->filter_theme_json( $theme_json ) );
	}

	public function test_returns_input_unchanged_when_no_brand_resolved(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );
		Functions\expect( 'wp_doing_ajax' )->once()->andReturn( false );

		$resolver = Mockery::mock( BrandResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->once()->andReturn( null );

		$repository = Mockery::mock( BrandRepository::class );
		$posts      = Mockery::mock( GlobalStylesPostService::class );

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$this->assertSame( $theme_json, $override->filter_theme_json( $theme_json ) );
	}

	public function test_returns_input_unchanged_when_brand_has_no_styles_post(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );
		Functions\expect( 'wp_doing_ajax' )->once()->andReturn( false );

		$resolver = Mockery::mock( BrandResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->once()->andReturn( 5 );

		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_global_styles_post_id' )->once()->with( 5 )->andReturn( null );

		$posts = Mockery::mock( GlobalStylesPostService::class );

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$this->assertSame( $theme_json, $override->filter_theme_json( $theme_json ) );
	}

	public function test_returns_input_unchanged_when_brand_styles_are_empty(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );
		Functions\expect( 'wp_doing_ajax' )->once()->andReturn( false );

		$resolver = Mockery::mock( BrandResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->once()->andReturn( 5 );

		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_global_styles_post_id' )->once()->with( 5 )->andReturn( 42 );

		$posts = Mockery::mock( GlobalStylesPostService::class );
		$posts->shouldReceive( 'get_global_styles_data' )->once()->with( 42 )->andReturn(
			array(
				'settings' => array(),
				'styles'   => array(),
			)
		);

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$this->assertSame( $theme_json, $override->filter_theme_json( $theme_json ) );
	}

	public function test_merges_brand_styles_over_input_when_present(): void {
		Functions\expect( 'is_admin' )->once()->andReturn( false );
		Functions\expect( 'wp_doing_ajax' )->once()->andReturn( false );

		$resolver = Mockery::mock( BrandResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->once()->andReturn( 5 );

		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_global_styles_post_id' )->once()->with( 5 )->andReturn( 42 );

		$settings = array(
			'color' => array(
				'palette' => array(
					array(
						'slug'  => 'brand-primary',
						'color' => '#123456',
					),
				),
			),
		);

		$posts = Mockery::mock( GlobalStylesPostService::class );
		$posts->shouldReceive( 'get_global_styles_data' )->once()->with( 42 )->andReturn(
			array(
				'settings' => $settings,
				'styles'   => array(),
			)
		);

		$override   = new GlobalStylesOverride( $resolver, $repository, $posts );
		$theme_json = new FakeThemeJsonData();

		$result = $override->filter_theme_json( $theme_json );

		$this->assertSame( $theme_json, $result );
		$this->assertSame( 3, $theme_json->received_update['version'] );
		$this->assertTrue( $theme_json->received_update['isGlobalStylesUserThemeJSON'] );
		$this->assertSame( $settings, $theme_json->received_update['settings'] );
	}
}
