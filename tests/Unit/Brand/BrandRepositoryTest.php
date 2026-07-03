<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Brand;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;

#[CoversClass( BrandRepository::class )]
class BrandRepositoryTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private BrandRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repository = new BrandRepository();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_rules_returns_array_meta(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mbgs_rules', true )
			->andReturn( array( 'example.com' ) );

		$this->assertSame( array( 'example.com' ), $this->repository->get_rules( 5 ) );
	}

	public function test_get_rules_returns_empty_array_when_meta_missing(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );

		$this->assertSame( array(), $this->repository->get_rules( 5 ) );
	}

	public function test_get_variables_returns_array_meta(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mbgs_variables', true )
			->andReturn( array( 'name' => 'Acme' ) );

		$this->assertSame( array( 'name' => 'Acme' ), $this->repository->get_variables( 5 ) );
	}

	public function test_get_variables_returns_empty_array_when_meta_missing(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );

		$this->assertSame( array(), $this->repository->get_variables( 5 ) );
	}

	public function test_get_default_brand_id_returns_id_when_found(): void {
		Functions\expect( 'get_posts' )
			->once()
			->with(
				array(
					'post_type'      => 'mbgs_brand',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_mbgs_is_default',
					'meta_value'     => '1',
				)
			)
			->andReturn( array( 12 ) );

		$this->assertSame( 12, $this->repository->get_default_brand_id() );
	}

	public function test_get_default_brand_id_returns_null_when_none_found(): void {
		Functions\expect( 'get_posts' )->once()->andReturn( array() );

		$this->assertNull( $this->repository->get_default_brand_id() );
	}

	public function test_get_global_styles_post_id_returns_int_when_set(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mbgs_global_styles_post_id', true )
			->andReturn( '42' );

		$this->assertSame( 42, $this->repository->get_global_styles_post_id( 5 ) );
	}

	public function test_get_global_styles_post_id_returns_null_when_unset(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );

		$this->assertNull( $this->repository->get_global_styles_post_id( 5 ) );
	}
}
