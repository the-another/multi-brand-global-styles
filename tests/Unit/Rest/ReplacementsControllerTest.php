<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Rest;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageMapBuilder;
use TheAnother\Plugin\MultiBrandGlobalStyles\Rest\ReplacementsController;
use WP_Post;
use WP_REST_Request;

#[CoversClass( ReplacementsController::class )]
class ReplacementsControllerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'rest_ensure_response' )->returnArg();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_controller( ?BrandRepository $repository = null, ?ImageMapBuilder $builder = null ): ReplacementsController {
		return new ReplacementsController(
			$repository ?? Mockery::mock( BrandRepository::class ),
			$builder ?? Mockery::mock( ImageMapBuilder::class )
		);
	}

	public function test_can_manage_requires_edit_theme_options(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'edit_theme_options' )->andReturn( false );

		$this->assertFalse( $this->make_controller()->can_manage() );
	}

	public function test_get_replacements_returns_row_per_published_brand(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'get_the_title' )->alias( static fn( int $id ) => "Brand {$id}" );
		Functions\when( 'wp_get_attachment_image_url' )->alias(
			static fn( int $id ) => "https://ex.com/up/thumb-{$id}.jpg"
		);

		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_published_brand_ids' )->andReturn( array( 1, 2 ) );
		$repository->shouldReceive( 'get_image_map' )->with( 1 )->andReturn( array( 10 => 20 ) );
		$repository->shouldReceive( 'get_image_map' )->with( 2 )->andReturn( array() );

		$rows = $this->make_controller( $repository )->get_replacements( new WP_REST_Request( array( 'original' => 10 ) ) );

		$this->assertSame(
			array(
				array(
					'brand_id'              => 1,
					'brand_name'            => 'Brand 1',
					'replacement_id'        => 20,
					'replacement_thumb_url' => 'https://ex.com/up/thumb-20.jpg',
				),
				array(
					'brand_id'              => 2,
					'brand_name'            => 'Brand 2',
					'replacement_id'        => null,
					'replacement_thumb_url' => null,
				),
			),
			$rows
		);
	}

	public function test_get_replacements_rejects_non_image_original(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );

		$result = $this->make_controller()->get_replacements( new WP_REST_Request( array( 'original' => 10 ) ) );

		$this->assertTrue( is_wp_error( $result ) );
	}

	public function test_set_replacement_sets_pair_and_returns_row(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'get_the_title' )->justReturn( 'Brand 1' );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://ex.com/up/thumb-20.jpg' );

		$brand              = new WP_Post( 1, 'mbgs_brand' );
		$brand->post_status = 'publish';
		Functions\when( 'get_post' )->justReturn( $brand );

		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_image_map' )->with( 1 )->andReturn( array( 30 => 40 ) );

		$builder = Mockery::mock( ImageMapBuilder::class );
		$builder->shouldReceive( 'persist' )->once()->with(
			1,
			array(
				30 => 40,
				10 => 20,
			)
		);

		$row = $this->make_controller( $repository, $builder )->set_replacement(
			new WP_REST_Request(
				array(
					'brand_id'       => 1,
					'original_id'    => 10,
					'replacement_id' => 20,
				)
			)
		);

		$this->assertSame( 20, $row['replacement_id'] );
	}

	public function test_set_replacement_null_removes_pair(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'get_the_title' )->justReturn( 'Brand 1' );

		$brand              = new WP_Post( 1, 'mbgs_brand' );
		$brand->post_status = 'publish';
		Functions\when( 'get_post' )->justReturn( $brand );

		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_image_map' )->with( 1 )->andReturn( array( 10 => 20 ) );

		$builder = Mockery::mock( ImageMapBuilder::class );
		$builder->shouldReceive( 'persist' )->once()->with( 1, array() );

		$row = $this->make_controller( $repository, $builder )->set_replacement(
			new WP_REST_Request(
				array(
					'brand_id'       => 1,
					'original_id'    => 10,
					'replacement_id' => null,
				)
			)
		);

		$this->assertNull( $row['replacement_id'] );
	}

	public function test_set_replacement_rejects_unpublished_brand(): void {
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );

		$brand              = new WP_Post( 1, 'mbgs_brand' );
		$brand->post_status = 'draft';
		Functions\when( 'get_post' )->justReturn( $brand );

		$result = $this->make_controller()->set_replacement(
			new WP_REST_Request(
				array(
					'brand_id'       => 1,
					'original_id'    => 10,
					'replacement_id' => 20,
				)
			)
		);

		$this->assertTrue( is_wp_error( $result ) );
	}
}
