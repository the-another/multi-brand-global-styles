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
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\RequestAuthority;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\RequestHomeUrl;

#[CoversClass( RequestHomeUrl::class )]
#[UsesClass( BrandSettings::class )]
#[UsesClass( RequestAuthority::class )]
class RequestHomeUrlTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * @var BrandResolver&Mockery\MockInterface
	 */
	private $brand_resolver;

	/**
	 * @var BrandRepository&Mockery\MockInterface
	 */
	private $brand_repository;

	private RequestHomeUrl $request_home_url;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->brand_resolver   = Mockery::mock( BrandResolver::class );
		$this->brand_repository = Mockery::mock( BrandRepository::class );
		$this->request_home_url = new RequestHomeUrl( $this->brand_resolver, $this->brand_repository );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Arrange: brand 5 resolves with the given url_rewrite settings while
	 * browsing $http_host over $ssl.
	 *
	 * @param array<string, bool> $url_rewrite url_rewrite settings subarray.
	 * @param string              $http_host   Current HTTP_HOST.
	 * @param bool                $ssl         is_ssl() answer.
	 */
	private function arrange( array $url_rewrite, string $http_host = 'brand.com', bool $ssl = true ): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request_rule_match' )->andReturn( 5 );
		$this->brand_repository->shouldReceive( 'get_settings' )
			->with( 5 )
			->andReturn( BrandSettings::from_meta( array( 'url_rewrite' => $url_rewrite ) ) );

		Functions\when( 'is_ssl' )->justReturn( $ssl );

		$_SERVER['HTTP_HOST'] = $http_host;
	}

	public function test_no_http_host_is_a_noop(): void {
		$this->assertSame( 'https://canonical.com', $this->request_home_url->filter( 'https://canonical.com' ) );
	}

	public function test_invalid_http_host_is_a_noop(): void {
		$_SERVER['HTTP_HOST'] = 'bad host!';

		$this->assertSame( 'https://canonical.com', $this->request_home_url->filter( 'https://canonical.com' ) );
	}

	public function test_no_brand_resolved_is_a_noop(): void {
		$_SERVER['HTTP_HOST'] = 'brand.com';
		$this->brand_resolver->shouldReceive( 'resolve_current_request_rule_match' )->andReturn( null );

		$this->assertSame( 'https://canonical.com', $this->request_home_url->filter( 'https://canonical.com' ) );
	}

	public function test_default_brand_fallback_is_a_noop_even_with_rewrite_enabled(): void {
		// BrandResolver returns null from resolve_current_request_rule_match()
		// whenever the request's Host+path matched no explicit Brand URL
		// rule — including when a default Brand exists and would otherwise
		// be returned by resolve_current_request(). RequestHomeUrl must
		// treat that the same as "no brand": no substitution, and it must
		// not even reach get_settings() to check the rewrite flag.
		$_SERVER['HTTP_HOST'] = 'attacker-supplied-host.example';
		$this->brand_resolver->shouldReceive( 'resolve_current_request_rule_match' )->andReturn( null );
		$this->brand_repository->shouldNotReceive( 'get_settings' );

		$this->assertSame( 'https://canonical.com', $this->request_home_url->filter( 'https://canonical.com' ) );
	}

	public function test_explicit_rule_match_still_substitutes(): void {
		// Sanity check that a genuine rule match (as opposed to a
		// default-Brand fallback) still triggers substitution — the gate
		// only excludes non-matches, it doesn't break the happy path.
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			'https://brand.com',
			$this->request_home_url->filter( 'https://canonical.com' )
		);
	}

	public function test_rewrite_disabled_is_a_noop(): void {
		$this->arrange( array() );

		$this->assertSame( 'https://canonical.com', $this->request_home_url->filter( 'https://canonical.com' ) );
	}

	public function test_relative_url_is_a_noop(): void {
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame( '/my-account/', $this->request_home_url->filter( '/my-account/' ) );
	}

	public function test_swaps_authority_keeping_path_query_and_fragment(): void {
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			'https://brand.com/my-account/?tab=orders#top',
			$this->request_home_url->filter( 'https://canonical.com/my-account/?tab=orders#top' )
		);
	}

	public function test_scheme_matches_current_request_when_not_forced(): void {
		$this->arrange( array( 'enabled' => true ), ssl: false );

		$this->assertSame( 'http://brand.com', $this->request_home_url->filter( 'https://canonical.com' ) );
	}

	public function test_force_https_upgrades_scheme(): void {
		$this->arrange(
			array(
				'enabled'     => true,
				'force_https' => true,
			),
			ssl: false
		);

		$this->assertSame( 'https://brand.com', $this->request_home_url->filter( 'http://canonical.com' ) );
	}

	public function test_browsed_port_is_carried_into_the_result(): void {
		$this->arrange( array( 'enabled' => true ), http_host: 'brand.com:8443' );

		$this->assertSame( 'https://brand.com:8443/x', $this->request_home_url->filter( 'https://canonical.com/x' ) );
	}
}
