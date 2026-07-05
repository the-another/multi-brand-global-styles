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
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\HostRewriter;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\RequestAuthority;

#[CoversClass( HostRewriter::class )]
#[UsesClass( BrandSettings::class )]
#[UsesClass( RequestAuthority::class )]
class HostRewriterTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/**
	 * @var BrandResolver&Mockery\MockInterface
	 */
	private $brand_resolver;

	/**
	 * @var BrandRepository&Mockery\MockInterface
	 */
	private $brand_repository;

	private HostRewriter $rewriter;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->brand_resolver   = Mockery::mock( BrandResolver::class );
		$this->brand_repository = Mockery::mock( BrandRepository::class );
		$this->rewriter         = new HostRewriter( $this->brand_resolver, $this->brand_repository );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Arrange: brand 5 resolves with the given url_rewrite settings, canonical
	 * options and current host as given.
	 *
	 * @param array<string, bool> $url_rewrite url_rewrite settings subarray.
	 * @param string              $home        home option.
	 * @param string              $siteurl     siteurl option.
	 * @param string              $http_host   Current HTTP_HOST.
	 * @param bool                $ssl         is_ssl() answer.
	 */
	private function arrange(
		array $url_rewrite,
		string $home = 'https://canonical.com',
		string $siteurl = 'https://canonical.com',
		string $http_host = 'brand.com',
		bool $ssl = true
	): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->andReturn( 5 );
		$this->brand_repository->shouldReceive( 'get_settings' )
			->with( 5 )
			->andReturn( BrandSettings::from_meta( array( 'url_rewrite' => $url_rewrite ) ) );

		Functions\when( 'get_option' )->alias(
			static fn( string $name ) => 'home' === $name ? $home : $siteurl
		);
		Functions\when( 'is_ssl' )->justReturn( $ssl );

		$_SERVER['HTTP_HOST'] = $http_host;
	}

	public function test_no_brand_resolved_is_a_noop(): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->andReturn( null );

		$html = '<a href="https://canonical.com/x">x</a>';
		$this->assertSame( $html, $this->rewriter->replace( $html ) );
	}

	public function test_disabled_option_is_a_noop(): void {
		$this->arrange( array() );

		$html = '<a href="https://canonical.com/x">x</a>';
		$this->assertSame( $html, $this->rewriter->replace( $html ) );
	}

	public function test_rewrites_absolute_urls_keeping_path_and_query(): void {
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			'<a href="https://brand.com/x?y=1#f">x</a>',
			$this->rewriter->replace( '<a href="https://canonical.com/x?y=1#f">x</a>' )
		);
	}

	public function test_scheme_matches_current_request_when_not_forced(): void {
		$this->arrange( array( 'enabled' => true ), ssl: false );

		$this->assertSame(
			'<a href="http://brand.com/x">x</a>',
			$this->rewriter->replace( '<a href="https://canonical.com/x">x</a>' )
		);
	}

	public function test_force_https_upgrades_scheme(): void {
		$this->arrange(
			array(
				'enabled'     => true,
				'force_https' => true,
			),
			ssl: false
		);

		$this->assertSame(
			'<a href="https://brand.com/x">x</a>',
			$this->rewriter->replace( '<a href="http://canonical.com/x">x</a>' )
		);
	}

	public function test_rewrites_protocol_relative_urls_without_adding_scheme(): void {
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			'<img src="//brand.com/i.png" />',
			$this->rewriter->replace( '<img src="//canonical.com/i.png" />' )
		);
	}

	public function test_rewrites_json_escaped_urls(): void {
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			'{"url":"https:\/\/brand.com\/x","rel":"\/\/brand.com\/y"}',
			$this->rewriter->replace( '{"url":"https:\/\/canonical.com\/x","rel":"\/\/canonical.com\/y"}' )
		);
	}

	public function test_leaves_third_party_and_lookalike_hosts_alone(): void {
		$this->arrange( array( 'enabled' => true ) );

		$html = '<a href="https://other.com/x">1</a>'
			. '<a href="https://canonical.com.evil.net/x">2</a>'
			. '<a href="https://not-canonical.com/x">3</a>'
			. '<a href="https://sub.canonical.com/x">4</a>';

		$this->assertSame( $html, $this->rewriter->replace( $html ) );
	}

	public function test_replaces_canonical_port_with_current_authority(): void {
		$this->arrange(
			array( 'enabled' => true ),
			home: 'http://localhost:8881',
			siteurl: 'http://localhost:8881',
			http_host: '127.0.0.1:8881',
			ssl: false
		);

		$this->assertSame(
			'<a href="http://127.0.0.1:8881/x">x</a>',
			$this->rewriter->replace( '<a href="http://localhost:8881/x">x</a>' )
		);
	}

	public function test_rewrites_both_home_and_siteurl_hosts_when_they_differ(): void {
		$this->arrange(
			array( 'enabled' => true ),
			home: 'https://canonical.com',
			siteurl: 'https://wp.canonical-core.com'
		);

		$this->assertSame(
			'<a href="https://brand.com/x">x</a><script src="https://brand.com/wp-includes/j.js"></script>',
			$this->rewriter->replace(
				'<a href="https://canonical.com/x">x</a><script src="https://wp.canonical-core.com/wp-includes/j.js"></script>'
			)
		);
	}

	public function test_visiting_canonical_host_without_force_https_is_a_noop(): void {
		$this->arrange( array( 'enabled' => true ), http_host: 'canonical.com', ssl: false );

		$html = '<a href="https://canonical.com/x">x</a>';
		$this->assertSame( $html, $this->rewriter->replace( $html ) );
	}

	public function test_visiting_canonical_host_with_force_https_normalizes_scheme(): void {
		$this->arrange(
			array(
				'enabled'     => true,
				'force_https' => true,
			),
			http_host: 'canonical.com',
			ssl: false
		);

		$this->assertSame(
			'<a href="https://canonical.com/x">x</a>',
			$this->rewriter->replace( '<a href="http://canonical.com/x">x</a>' )
		);
	}

	public function test_garbage_http_host_is_a_noop(): void {
		$this->arrange( array( 'enabled' => true ), http_host: 'bad host/injection' );

		$html = '<a href="https://canonical.com/x">x</a>';
		$this->assertSame( $html, $this->rewriter->replace( $html ) );
	}

	public function test_missing_http_host_is_a_noop(): void {
		$this->arrange( array( 'enabled' => true ) );
		unset( $_SERVER['HTTP_HOST'] );

		$html = '<a href="https://canonical.com/x">x</a>';
		$this->assertSame( $html, $this->rewriter->replace( $html ) );
	}

	public function test_redirect_canonical_is_cancelled_when_only_the_host_differs(): void {
		$this->arrange( array( 'enabled' => true ), ssl: false );

		$this->assertFalse(
			$this->rewriter->filter_redirect_canonical(
				'https://canonical.com/sample-page/',
				'http://brand.com/sample-page/'
			)
		);
	}

	public function test_redirect_canonical_keeps_path_fixes_on_the_browsed_host(): void {
		$this->arrange( array( 'enabled' => true ), ssl: false );

		$this->assertSame(
			'http://brand.com/sample-page/',
			$this->rewriter->filter_redirect_canonical(
				'https://canonical.com/sample-page/',
				'http://brand.com/sample-page' // trailing-slash canonicalization stays, host does not.
			)
		);
	}

	public function test_redirect_canonical_untouched_when_feature_disabled(): void {
		$this->arrange( array() );

		$this->assertSame(
			'https://canonical.com/x/',
			$this->rewriter->filter_redirect_canonical( 'https://canonical.com/x/', 'https://brand.com/x' )
		);
	}

	public function test_redirect_canonical_passes_through_non_string_redirects(): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->never();

		$this->assertFalse( $this->rewriter->filter_redirect_canonical( false, 'https://brand.com/x' ) );
	}

	public function test_redirect_canonical_forces_https_upgrade_redirect(): void {
		$this->arrange(
			array(
				'enabled'     => true,
				'force_https' => true,
			),
			http_host: 'brand.com',
			ssl: false
		);

		$this->assertSame(
			'https://brand.com/sample-page/',
			$this->rewriter->filter_redirect_canonical(
				'https://canonical.com/sample-page/',
				'http://brand.com/sample-page/'
			)
		);
	}
}
