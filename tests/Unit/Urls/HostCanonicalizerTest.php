<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Urls;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandSettings;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\HostCanonicalizer;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\HostForm;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\RequestAuthority;

#[CoversClass( HostCanonicalizer::class )]
#[UsesClass( BrandSettings::class )]
#[UsesClass( HostForm::class )]
#[UsesClass( RequestAuthority::class )]
class HostCanonicalizerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/** @var BrandResolver&Mockery\MockInterface */
	private $resolver;
	/** @var BrandRepository&Mockery\MockInterface */
	private $repository;
	private HostCanonicalizer $canonicalizer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->resolver       = Mockery::mock( BrandResolver::class );
		$this->repository     = Mockery::mock( BrandRepository::class );
		$this->canonicalizer  = new HostCanonicalizer( $this->resolver, $this->repository );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $url_rewrite url_rewrite settings.
	 */
	private function arrange( array $url_rewrite, string $host, string $uri = '/page/?x=1' ): void {
		$this->resolver->shouldReceive( 'resolve_current_request' )->andReturn( 5 );
		$this->repository->shouldReceive( 'get_settings' )->with( 5 )
			->andReturn( BrandSettings::from_meta( array( 'url_rewrite' => $url_rewrite ) ) );
		$_SERVER['HTTP_HOST']   = $host;
		$_SERVER['REQUEST_URI'] = $uri;
	}

	public function test_redirects_apex_to_www(): void {
		$this->arrange( array( 'enabled' => true, 'canonical_host_form' => 'www' ), 'brand.com' );
		$this->assertSame( 'https://www.brand.com/page/?x=1', $this->canonicalizer->maybe_redirect() );
	}

	public function test_redirects_www_to_apex(): void {
		$this->arrange( array( 'enabled' => true, 'canonical_host_form' => 'apex' ), 'www.brand.com' );
		$this->assertSame( 'https://brand.com/page/?x=1', $this->canonicalizer->maybe_redirect() );
	}

	public function test_no_redirect_when_already_canonical(): void {
		$this->arrange( array( 'enabled' => true, 'canonical_host_form' => 'www' ), 'www.brand.com' );
		$this->assertNull( $this->canonicalizer->maybe_redirect() );
	}

	public function test_no_redirect_when_form_off(): void {
		$this->arrange( array( 'enabled' => true ), 'brand.com' );
		$this->assertNull( $this->canonicalizer->maybe_redirect() );
	}

	public function test_no_redirect_when_rewrite_disabled(): void {
		$this->arrange( array( 'canonical_host_form' => 'www' ), 'brand.com' );
		$this->assertNull( $this->canonicalizer->maybe_redirect() );
	}

	public function test_no_redirect_when_no_brand(): void {
		$this->resolver->shouldReceive( 'resolve_current_request' )->andReturn( null );
		$_SERVER['HTTP_HOST'] = 'brand.com';
		$this->assertNull( $this->canonicalizer->maybe_redirect() );
	}

	public function test_no_redirect_for_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->assertNull( $this->canonicalizer->maybe_redirect() );
	}

	public function test_no_redirect_for_invalid_host(): void {
		$this->arrange( array( 'enabled' => true, 'canonical_host_form' => 'www' ), 'bad host!' );
		$this->assertNull( $this->canonicalizer->maybe_redirect() );
	}

	public function test_scheme_is_http_when_not_ssl_and_not_forced(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		$this->arrange( array( 'enabled' => true, 'canonical_host_form' => 'www' ), 'brand.com' );
		$this->assertSame( 'http://www.brand.com/page/?x=1', $this->canonicalizer->maybe_redirect() );
	}

	public function test_force_https_overrides_non_ssl(): void {
		Functions\when( 'is_ssl' )->justReturn( false );
		$this->arrange( array( 'enabled' => true, 'force_https' => true, 'canonical_host_form' => 'www' ), 'brand.com' );
		$this->assertSame( 'https://www.brand.com/page/?x=1', $this->canonicalizer->maybe_redirect() );
	}

	public function test_port_is_preserved(): void {
		$this->arrange( array( 'enabled' => true, 'canonical_host_form' => 'www' ), 'brand.com:8080' );
		$this->assertSame( 'https://www.brand.com:8080/page/?x=1', $this->canonicalizer->maybe_redirect() );
	}

	public function test_handle_issues_301_and_exits(): void {
		$this->arrange( array( 'enabled' => true, 'canonical_host_form' => 'www' ), 'brand.com' );

		// Simulate exit by throwing from wp_redirect so the test can assert the call
		// without terminating the process.
		Functions\expect( 'wp_redirect' )->once()->with( 'https://www.brand.com/page/?x=1', 301 )
			->andThrow( new \RuntimeException( 'redirected' ) );

		$this->expectException( \RuntimeException::class );
		$this->canonicalizer->handle();
	}

	public function test_handle_is_noop_when_no_target(): void {
		$this->arrange( array( 'enabled' => true, 'canonical_host_form' => 'www' ), 'www.brand.com' );
		Functions\expect( 'wp_redirect' )->never();
		$this->canonicalizer->handle(); // returns without redirect
		$this->assertTrue( true );
	}
}
