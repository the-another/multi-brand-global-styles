<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Media;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\AttachmentLifecycle;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageMapBuilder;

#[CoversClass( AttachmentLifecycle::class )]
class AttachmentLifecycleTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_metadata_save_rebuilds_only_referencing_brands(): void {
		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_brand_ids' )->andReturn( array( 1, 2, 3 ) );
		$repository->shouldReceive( 'get_image_map' )->with( 1 )->andReturn( array( 10 => 20 ) ); // references 10 as original
		$repository->shouldReceive( 'get_image_map' )->with( 2 )->andReturn( array( 30 => 10 ) ); // references 10 as replacement
		$repository->shouldReceive( 'get_image_map' )->with( 3 )->andReturn( array( 40 => 50 ) ); // unrelated

		$builder = Mockery::mock( ImageMapBuilder::class );
		$builder->shouldReceive( 'persist' )->once()->with( 1, array( 10 => 20 ) );
		$builder->shouldReceive( 'persist' )->once()->with( 2, array( 30 => 10 ) );

		( new AttachmentLifecycle( $repository, $builder ) )->on_attachment_meta_saved( 99, 10, '_wp_attachment_metadata' );
	}

	public function test_metadata_save_ignores_other_meta_keys(): void {
		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldNotReceive( 'get_brand_ids' );

		$builder = Mockery::mock( ImageMapBuilder::class );

		( new AttachmentLifecycle( $repository, $builder ) )->on_attachment_meta_saved( 99, 10, 'some_other_key' );
	}

	public function test_delete_prunes_pairs_on_both_sides(): void {
		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_brand_ids' )->andReturn( array( 1, 2 ) );
		$repository->shouldReceive( 'get_image_map' )->with( 1 )->andReturn(
			array(
				10 => 20,
				30 => 40,
			)
		);
		$repository->shouldReceive( 'get_image_map' )->with( 2 )->andReturn( array( 50 => 10 ) );

		$builder = Mockery::mock( ImageMapBuilder::class );
		$builder->shouldReceive( 'persist' )->once()->with( 1, array( 30 => 40 ) );
		$builder->shouldReceive( 'persist' )->once()->with( 2, array() );

		( new AttachmentLifecycle( $repository, $builder ) )->on_delete_attachment( 10 );
	}

	public function test_delete_skips_brands_not_referencing_the_attachment(): void {
		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_brand_ids' )->andReturn( array( 1 ) );
		$repository->shouldReceive( 'get_image_map' )->with( 1 )->andReturn( array( 30 => 40 ) );

		$builder = Mockery::mock( ImageMapBuilder::class );
		$builder->shouldNotReceive( 'persist' );

		( new AttachmentLifecycle( $repository, $builder ) )->on_delete_attachment( 10 );
	}
}
