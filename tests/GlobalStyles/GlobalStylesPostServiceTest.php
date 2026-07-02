<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiDomainGlobalStyles\Tests\GlobalStyles;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiDomainGlobalStyles\GlobalStyles\GlobalStylesPostService;

#[CoversClass( GlobalStylesPostService::class )]
class GlobalStylesPostServiceTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private GlobalStylesPostService $service;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();

		$this->service = new GlobalStylesPostService();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ensure_global_styles_post_returns_existing_published_post(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mdgs_global_styles_post_id', true )
			->andReturn( '42' );
		Functions\expect( 'get_post_status' )->once()->with( '42' )->andReturn( 'publish' );
		Functions\expect( 'wp_insert_post' )->never();

		$this->assertSame( 42, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_creates_when_missing(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'wp_insert_post' )
			->once()
			->with(
				Mockery::on(
					function ( $args ) {
						return 'wp_global_styles' === $args['post_type']
							&& 'publish' === $args['post_status']
							&& str_contains( $args['post_content'], '"isGlobalStylesUserThemeJSON":true' );
					}
				),
				true
			)
			->andReturn( 99 );
		// No is_wp_error mock: tests/bootstrap.php already defines the real stub
		// (Brain Monkey cannot redefine an already-defined function), and 99 is
		// an int, so it naturally returns false.
		Functions\expect( 'update_post_meta' )->once()->with( 5, '_mdgs_global_styles_post_id', 99 );

		$this->assertSame( 99, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_recreates_when_existing_post_gone(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '42' );
		Functions\expect( 'get_post_status' )->once()->with( '42' )->andReturn( false );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 100 );
		Functions\expect( 'update_post_meta' )->once()->with( 5, '_mdgs_global_styles_post_id', 100 );

		$this->assertSame( 100, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_recreates_when_existing_post_trashed(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '42' );
		Functions\expect( 'get_post_status' )->once()->with( '42' )->andReturn( 'trash' );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 101 );
		Functions\expect( 'update_post_meta' )->once()->with( 5, '_mdgs_global_styles_post_id', 101 );

		$this->assertSame( 101, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_get_global_styles_data_decodes_post_content(): void {
		$post               = new \stdClass();
		$post->post_content = '{"version":3,"settings":{"color":{"palette":[]}},"styles":{}}';

		Functions\expect( 'get_post' )->once()->with( 42 )->andReturn( $post );

		$this->assertSame(
			array(
				'version'  => 3,
				'settings' => array( 'color' => array( 'palette' => array() ) ),
				'styles'   => array(),
			),
			$this->service->get_global_styles_data( 42 )
		);
	}

	public function test_get_global_styles_data_returns_empty_array_when_post_missing(): void {
		Functions\expect( 'get_post' )->once()->with( 42 )->andReturn( null );

		$this->assertSame( array(), $this->service->get_global_styles_data( 42 ) );
	}
}
