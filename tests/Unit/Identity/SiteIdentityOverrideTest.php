<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Identity;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\Identity\SiteIdentityOverride;

#[CoversClass( SiteIdentityOverride::class )]
class SiteIdentityOverrideTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_override( ?int $brand_id, array $identity = array() ): SiteIdentityOverride {
		$resolver = Mockery::mock( BrandResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->andReturn( $brand_id );

		$repository = Mockery::mock( BrandRepository::class );
		if ( null !== $brand_id ) {
			$repository->shouldReceive( 'get_identity' )->with( $brand_id )->andReturn( $identity );
		}

		return new SiteIdentityOverride( $resolver, $repository );
	}

	private function stub_frontend(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
	}

	public function test_blogname_overridden_when_brand_sets_title(): void {
		$this->stub_frontend();

		$override = $this->make_override( 5, array( 'title' => 'Second Brand Co' ) );

		$this->assertSame( 'Second Brand Co', $override->filter_blogname( false ) );
	}

	public function test_blogname_untouched_when_field_unset(): void {
		$this->stub_frontend();

		$override = $this->make_override( 5, array( 'tagline' => 'x' ) );

		$this->assertFalse( $override->filter_blogname( false ) );
	}

	public function test_untouched_when_no_brand_resolves(): void {
		$this->stub_frontend();

		$this->assertFalse( $this->make_override( null )->filter_blogname( false ) );
	}

	// Note: the REST_REQUEST branch of identity_value()'s guard is intentionally
	// not covered here — defining the REST_REQUEST constant would leak
	// process-wide and poison other tests in the suite.

	public function test_untouched_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$resolver = Mockery::mock( BrandResolver::class );
		$resolver->shouldNotReceive( 'resolve_current_request' );

		$override = new SiteIdentityOverride( $resolver, Mockery::mock( BrandRepository::class ) );

		$this->assertFalse( $override->filter_blogname( false ) );
	}

	public function test_untouched_during_ajax(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );

		$override = $this->make_override( 5, array( 'title' => 'X' ) );

		$this->assertFalse( $override->filter_blogname( false ) );
	}

	public function test_logo_filters_return_attachment_id(): void {
		$this->stub_frontend();

		$override = $this->make_override( 5, array( 'logo_id' => 123 ) );

		$this->assertSame( 123, $override->filter_logo_option( false ) );
		$this->assertSame( 123, $override->filter_logo_theme_mod( false ) );
	}

	public function test_site_icon_and_tagline_overridden(): void {
		$this->stub_frontend();

		$override = $this->make_override(
			5,
			array(
				'icon_id' => 77,
				'tagline' => 'Farm fresh',
			)
		);

		$this->assertSame( 77, $override->filter_site_icon( false ) );
		$this->assertSame( 'Farm fresh', $override->filter_blogdescription( false ) );
	}
}
