<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\GlobalStyles;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandSettings;
use TheAnother\Plugin\MultiBrandGlobalStyles\GlobalStyles\GlobalStylesPostService;

#[CoversClass( GlobalStylesPostService::class )]
#[UsesClass( BrandSettings::class )]
class GlobalStylesPostServiceTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private GlobalStylesPostService $service;

	/**
	 * @var BrandRepository&Mockery\MockInterface
	 */
	private $brand_repository;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();

		$this->brand_repository = Mockery::mock( BrandRepository::class );
		$this->service          = new GlobalStylesPostService( $this->brand_repository );
	}

	private function stub_stored_post_id( ?int $post_id ): void {
		$this->brand_repository->shouldReceive( 'get_settings' )
			->once()
			->with( 5 )
			->andReturn( BrandSettings::from_meta( null === $post_id ? array() : array( 'global_styles_post_id' => $post_id ) ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ensure_global_styles_post_returns_existing_published_post(): void {
		$this->stub_stored_post_id( 42 );
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'publish' );
		Functions\expect( 'wp_insert_post' )->never();
		$this->brand_repository->shouldNotReceive( 'update_settings' );

		$this->assertSame( 42, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_creates_when_missing(): void {
		$this->stub_stored_post_id( null );
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
		$this->brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 5, array( 'global_styles_post_id' => 99 ) );

		$this->assertSame( 99, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_recreates_when_existing_post_gone(): void {
		$this->stub_stored_post_id( 42 );
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( false );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 100 );
		$this->brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 5, array( 'global_styles_post_id' => 100 ) );

		$this->assertSame( 100, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_recreates_when_existing_post_trashed(): void {
		$this->stub_stored_post_id( 42 );
		Functions\expect( 'get_post_status' )->once()->with( 42 )->andReturn( 'trash' );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( 101 );
		$this->brand_repository->shouldReceive( 'update_settings' )
			->once()
			->with( 5, array( 'global_styles_post_id' => 101 ) );

		$this->assertSame( 101, $this->service->ensure_global_styles_post( 5 ) );
	}

	public function test_ensure_global_styles_post_throws_when_insert_fails(): void {
		$this->stub_stored_post_id( null );
		Functions\expect( 'wp_json_encode' )->andReturnUsing( 'json_encode' );

		$error = new \WP_Error( 'db_insert_error', 'Could not insert post into the database.' );
		Functions\expect( 'wp_insert_post' )->once()->andReturn( $error );
		$this->brand_repository->shouldNotReceive( 'update_settings' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Could not insert post into the database.' );

		$this->service->ensure_global_styles_post( 5 );
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
