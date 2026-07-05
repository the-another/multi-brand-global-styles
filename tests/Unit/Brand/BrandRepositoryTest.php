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
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandSettings;

#[CoversClass( BrandRepository::class )]
#[UsesClass( BrandSettings::class )]
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

	public function test_get_settings_reads_meta_and_sets_transient_on_cold_cache(): void {
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_brand_settings_5' )->andReturn( false );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mbgs_settings', true )
			->andReturn( array( 'rules' => array( 'example.com' ) ) );
		Functions\expect( 'set_transient' )
			->once()
			->with( 'mbgs_brand_settings_5', array( 'rules' => array( 'example.com' ) ), 0 );

		$this->assertSame( array( 'example.com' ), $this->repository->get_settings( 5 )->rules() );
	}

	public function test_get_settings_prefers_transient_over_meta(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'mbgs_brand_settings_5' )
			->andReturn( array( 'rules' => array( 'cached.com' ) ) );
		Functions\expect( 'get_post_meta' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( array( 'cached.com' ), $this->repository->get_settings( 5 )->rules() );
	}

	public function test_get_settings_memoizes_per_instance(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( array( 'rules' => array( 'a.com' ) ) );

		$first  = $this->repository->get_settings( 5 );
		$second = $this->repository->get_settings( 5 );

		$this->assertSame( $first, $second );
	}

	public function test_get_settings_caches_empty_settings_for_missing_meta(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );
		Functions\expect( 'set_transient' )->once()->with( 'mbgs_brand_settings_5', array(), 0 );

		$this->assertSame( array(), $this->repository->get_settings( 5 )->rules() );
	}

	public function test_save_settings_writes_meta_and_flushes_caches(): void {
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 5, '_mbgs_settings', array( 'rules' => array( 'new.com' ) ) );
		Functions\expect( 'delete_transient' )->once()->with( 'mbgs_brand_settings_5' );
		Functions\expect( 'delete_transient' )->once()->with( 'mbgs_default_brand' );

		$this->repository->save_settings( 5, array( 'rules' => array( 'new.com' ) ) );
	}

	public function test_save_settings_clears_memo_so_next_read_is_fresh(): void {
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\expect( 'get_transient' )->twice()->andReturn( false );
		Functions\expect( 'set_transient' )->twice()->andReturn( true );
		Functions\expect( 'get_post_meta' )
			->twice()
			->andReturn( array( 'rules' => array( 'old.com' ) ), array( 'rules' => array( 'new.com' ) ) );

		$this->assertSame( array( 'old.com' ), $this->repository->get_settings( 5 )->rules() );
		$this->repository->save_settings( 5, array( 'rules' => array( 'new.com' ) ) );
		$this->assertSame( array( 'new.com' ), $this->repository->get_settings( 5 )->rules() );
	}

	public function test_update_settings_merges_partial_over_stored_raw(): void {
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mbgs_settings', true )
			->andReturn(
				array(
					'rules'                 => array( 'keep.com' ),
					'global_styles_post_id' => 42,
				)
			);
		Functions\expect( 'update_post_meta' )
			->once()
			->with(
				5,
				'_mbgs_settings',
				array(
					'rules'                 => array( 'keep.com' ),
					'global_styles_post_id' => 42,
					'image_map'             => array( 10 => 20 ),
				)
			);
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->repository->update_settings( 5, array( 'image_map' => array( 10 => 20 ) ) );
	}

	public function test_update_settings_treats_non_array_stored_meta_as_empty(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );
		Functions\expect( 'update_post_meta' )
			->once()
			->with( 5, '_mbgs_settings', array( 'is_default' => false ) );
		Functions\when( 'delete_transient' )->justReturn( true );

		$this->repository->update_settings( 5, array( 'is_default' => false ) );
	}

	public function test_flush_brand_caches_deletes_both_transients(): void {
		Functions\expect( 'delete_transient' )->once()->with( 'mbgs_brand_settings_9' );
		Functions\expect( 'delete_transient' )->once()->with( 'mbgs_default_brand' );

		$this->repository->flush_brand_caches( 9 );
	}

	public function test_get_default_brand_id_returns_cached_id(): void {
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_default_brand' )->andReturn( 12 );
		Functions\expect( 'get_posts' )->never();

		$this->assertSame( 12, $this->repository->get_default_brand_id() );
	}

	public function test_get_default_brand_id_returns_null_for_zero_sentinel(): void {
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_default_brand' )->andReturn( 0 );
		Functions\expect( 'get_posts' )->never();

		$this->assertNull( $this->repository->get_default_brand_id() );
	}

	public function test_get_default_brand_id_iterates_settings_and_caches_answer(): void {
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_default_brand' )->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 3, 12 ) );
		// Brand 3: not default; Brand 12: default. Settings come cold from meta.
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_brand_settings_3' )->andReturn( array() );
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_brand_settings_12' )->andReturn( array( 'is_default' => true ) );
		Functions\expect( 'set_transient' )->once()->with( 'mbgs_default_brand', 12, 0 );

		$this->assertSame( 12, $this->repository->get_default_brand_id() );
	}

	public function test_get_default_brand_id_caches_zero_sentinel_when_none_flagged(): void {
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_default_brand' )->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 3 ) );
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_brand_settings_3' )->andReturn( array() );
		Functions\expect( 'set_transient' )->once()->with( 'mbgs_default_brand', 0, 0 );

		$this->assertNull( $this->repository->get_default_brand_id() );
	}

	public function test_delegating_getters_read_from_settings(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'mbgs_brand_settings_5' )
			->andReturn(
				array(
					'rules'                 => array( 'example.com' ),
					'variables'             => array( 'name' => 'Acme' ),
					'identity'              => array( 'title' => 'Acme' ),
					'image_map'             => array( 10 => 20 ),
					'image_url_map'         => array( 'https://a' => 'https://b' ),
					'global_styles_post_id' => 42,
				)
			);

		$this->assertSame( array( 'example.com' ), $this->repository->get_rules( 5 ) );
		$this->assertSame( array( 'name' => 'Acme' ), $this->repository->get_variables( 5 ) );
		$this->assertSame( array( 'title' => 'Acme' ), $this->repository->get_identity( 5 ) );
		$this->assertSame( array( 10 => 20 ), $this->repository->get_image_map( 5 ) );
		$this->assertSame( array( 'https://a' => 'https://b' ), $this->repository->get_image_url_map( 5 ) );
		$this->assertSame( 42, $this->repository->get_global_styles_post_id( 5 ) );
	}

	public function test_get_brand_ids_queries_any_status(): void {
		Functions\expect( 'get_posts' )->once()->with(
			Mockery::on(
				static fn( array $args ): bool => 'mbgs_brand' === $args['post_type'] && 'any' === $args['post_status']
			)
		)->andReturn( array( '3', '7' ) );

		$this->assertSame( array( 3, 7 ), $this->repository->get_brand_ids() );
	}

	public function test_get_published_brand_ids_queries_publish_status(): void {
		Functions\expect( 'get_posts' )->once()->with(
			Mockery::on(
				static fn( array $args ): bool => 'publish' === $args['post_status']
			)
		)->andReturn( array( 3 ) );

		$this->assertSame( array( 3 ), $this->repository->get_published_brand_ids() );
	}
}
