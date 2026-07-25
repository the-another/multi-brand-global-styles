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
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\CanonicalAuthority;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\RequestAuthority;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\RequestHomeUrl;

#[CoversClass( RequestHomeUrl::class )]
#[UsesClass( BrandSettings::class )]
#[UsesClass( CanonicalAuthority::class )]
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
	 * @param string              $siteurl     The siteurl option (defaults to the home option).
	 */
	private function arrange( array $url_rewrite, string $http_host = 'brand.com', bool $ssl = true, string $siteurl = 'https://canonical.com' ): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request_rule_match' )->andReturn( 5 );
		$this->brand_repository->shouldReceive( 'get_settings' )
			->with( 5 )
			->andReturn( BrandSettings::from_meta( array( 'url_rewrite' => $url_rewrite ) ) );

		Functions\when( 'is_ssl' )->justReturn( $ssl );
		Functions\when( 'get_option' )->alias(
			static fn( string $name ): string => 'siteurl' === $name ? $siteurl : 'https://canonical.com'
		);

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

		$this->assertSame( 'http://brand.com', $this->request_home_url->filter( 'http://canonical.com' ) );
	}

	public function test_https_input_is_never_downgraded_to_http(): void {
		// The canonical home is https but is_ssl() reads false — the usual
		// TLS-terminating-proxy case. Emitting http:// here would downgrade a
		// password-reset link that was correct before the filter ran, so the
		// input scheme is a floor: force_https/is_ssl can only upgrade it.
		$this->arrange( array( 'enabled' => true ), ssl: false );

		$this->assertSame( 'https://brand.com/x', $this->request_home_url->filter( 'https://canonical.com/x' ) );
	}

	public function test_non_canonical_host_is_a_noop(): void {
		// Only the canonical home/siteurl authority is ever swapped — the
		// filter is a home-URL bridge, not a blanket URL rewriter. A consumer
		// passing anything else (a CDN asset, an external callback) must get
		// it back untouched rather than silently repointed at the Brand host.
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			'https://cdn.example.com/logo.png',
			$this->request_home_url->filter( 'https://cdn.example.com/logo.png' )
		);
	}

	public function test_siteurl_host_is_substituted_too(): void {
		$this->arrange( array( 'enabled' => true ), siteurl: 'https://wp.canonical.com' );

		$this->assertSame( 'https://brand.com/wp-login.php', $this->request_home_url->filter( 'https://wp.canonical.com/wp-login.php' ) );
	}

	public function test_protocol_relative_canonical_url_becomes_absolute(): void {
		// Deliberate divergence from HostRewriter, which preserves the
		// protocol-relative form: this filter's consumers put the result in
		// emails and API payloads, where a schemeless URL is unusable.
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame( 'https://brand.com/x', $this->request_home_url->filter( '//canonical.com/x' ) );
	}

	public function test_null_is_passed_through_unchanged(): void {
		// Public filter: consumers control the value. A non-string must not
		// fatal — apply_filters( 'mbgs_request_home_url', null ) is a TypeError
		// against a `string` parameter.
		$this->arrange( array( 'enabled' => true ) );

		$this->assertNull( $this->request_home_url->filter( null ) );
	}

	public function test_false_is_not_coerced_to_an_empty_string(): void {
		// apply_filters() is called from core, which has no strict_types, so a
		// `string` parameter would silently coerce false to '' and hand the
		// next filter in the chain a different type than it was given.
		$this->arrange( array( 'enabled' => true ) );

		$this->assertFalse( $this->request_home_url->filter( false ) );
	}

	public function test_array_is_passed_through_unchanged(): void {
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame( array( 'x' ), $this->request_home_url->filter( array( 'x' ) ) );
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
