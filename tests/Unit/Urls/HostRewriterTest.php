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
use WP_REST_Request;

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
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the frontend-context signals filter_wp_redirect() guards on.
	 */
	private function stub_frontend_context( bool $admin = false, bool $ajax = false ): void {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
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

	public function test_allowed_redirect_hosts_gains_browsed_host_when_enabled(): void {
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			array( 'canonical.com', 'brand.com' ),
			$this->rewriter->filter_allowed_redirect_hosts( array( 'canonical.com' ) )
		);
	}

	public function test_allowed_redirect_hosts_strips_port_from_browsed_authority(): void {
		$this->arrange(
			array( 'enabled' => true ),
			home: 'http://localhost:8881',
			siteurl: 'http://localhost:8881',
			http_host: '127.0.0.1:8881',
			ssl: false
		);

		// wp_validate_redirect() compares parse_url() hosts, which never carry
		// a port — the allowlist entry must be the bare host.
		$this->assertSame(
			array( 'localhost', '127.0.0.1' ),
			$this->rewriter->filter_allowed_redirect_hosts( array( 'localhost' ) )
		);
	}

	public function test_allowed_redirect_hosts_does_not_duplicate_existing_host(): void {
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			array( 'canonical.com', 'brand.com' ),
			$this->rewriter->filter_allowed_redirect_hosts( array( 'canonical.com', 'brand.com' ) )
		);
	}

	public function test_allowed_redirect_hosts_untouched_when_feature_disabled(): void {
		$this->arrange( array() );

		$this->assertSame(
			array( 'canonical.com' ),
			$this->rewriter->filter_allowed_redirect_hosts( array( 'canonical.com' ) )
		);
	}

	public function test_allowed_redirect_hosts_untouched_when_no_brand(): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->andReturn( null );

		$this->assertSame(
			array( 'canonical.com' ),
			$this->rewriter->filter_allowed_redirect_hosts( array( 'canonical.com' ) )
		);
	}

	public function test_allowed_redirect_hosts_untouched_for_garbage_host(): void {
		$this->arrange( array( 'enabled' => true ), http_host: 'bad host/injection' );

		$this->assertSame(
			array( 'canonical.com' ),
			$this->rewriter->filter_allowed_redirect_hosts( array( 'canonical.com' ) )
		);
	}

	public function test_allowed_redirect_hosts_passes_through_non_array(): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->never();

		$this->assertSame( 'nope', $this->rewriter->filter_allowed_redirect_hosts( 'nope' ) );
	}

	public function test_wp_redirect_rewrites_canonical_location_after_login_post(): void {
		$this->stub_frontend_context();
		$this->arrange( array( 'enabled' => true ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/my-account/';

		// PRG: WooCommerce's login fallback redirects the POST back to the same
		// path on the canonical host — the rewrite must keep the visitor on the
		// brand host even though the path matches the current request.
		$this->assertSame(
			'https://brand.com/my-account/',
			$this->rewriter->filter_wp_redirect( 'https://canonical.com/my-account/' )
		);
	}

	public function test_wp_redirect_leaves_brand_host_location_alone(): void {
		$this->stub_frontend_context();
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			'https://brand.com/my-account/',
			$this->rewriter->filter_wp_redirect( 'https://brand.com/my-account/' )
		);
	}

	public function test_wp_redirect_untouched_when_feature_disabled(): void {
		$this->stub_frontend_context();
		$this->arrange( array() );

		$this->assertSame(
			'https://canonical.com/x',
			$this->rewriter->filter_wp_redirect( 'https://canonical.com/x' )
		);
	}

	public function test_wp_redirect_untouched_in_admin(): void {
		$this->stub_frontend_context( admin: true );
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->never();

		$this->assertSame(
			'https://canonical.com/wp-admin/',
			$this->rewriter->filter_wp_redirect( 'https://canonical.com/wp-admin/' )
		);
	}

	public function test_wp_redirect_untouched_during_ajax(): void {
		$this->stub_frontend_context( ajax: true );
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->never();

		$this->assertSame(
			'https://canonical.com/x',
			$this->rewriter->filter_wp_redirect( 'https://canonical.com/x' )
		);
	}

	public function test_wp_redirect_passes_through_non_string(): void {
		$this->assertFalse( $this->rewriter->filter_wp_redirect( false ) );
	}

	public function test_wp_redirect_preserves_host_canonicalizer_get_redirect(): void {
		$this->stub_frontend_context();
		// Install's own domain: the canonical host is the apex form of the
		// browsed host, so HostCanonicalizer's www→apex 301 target IS a
		// canonical-authority URL.
		$this->arrange(
			array( 'enabled' => true ),
			home: 'http://brand.com',
			siteurl: 'http://brand.com',
			http_host: 'www.brand.com',
			ssl: false
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/page/';

		// Rewriting that target back to the browsed host would redirect the GET
		// to itself — an infinite 301 loop. The original target must survive.
		$this->assertSame(
			'http://brand.com/page/',
			$this->rewriter->filter_wp_redirect( 'http://brand.com/page/' )
		);
	}

	public function test_wp_redirect_rewrites_get_redirect_to_a_different_path(): void {
		$this->stub_frontend_context();
		$this->arrange( array( 'enabled' => true ), ssl: false );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/came-from/';

		$this->assertSame(
			'http://brand.com/go-to/',
			$this->rewriter->filter_wp_redirect( 'https://canonical.com/go-to/' )
		);
	}

	public function test_wp_redirect_rewrites_https_upgrade_onto_the_browsed_host(): void {
		$this->stub_frontend_context();
		$this->arrange(
			array(
				'enabled'     => true,
				'force_https' => true,
			),
			ssl: false
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/x/';

		// An https-forcing redirect built from home_url() targets the canonical
		// host; the rewrite keeps the upgrade but moves it onto the browsed
		// host. Not a self-redirect: the scheme differs from the current request.
		$this->assertSame(
			'https://brand.com/x/',
			$this->rewriter->filter_wp_redirect( 'http://canonical.com/x/' )
		);
	}

	public function test_rest_response_strings_are_rewritten_recursively_for_get_requests(): void {
		$this->arrange( array( 'enabled' => true ) );

		$data = array(
			'html'       => '<a href="https://canonical.com/auction/a/">a</a>',
			'pagination' => '<a href="https://brand.com/page/2/">2</a>',
			'_links'     => array( 'self' => array( array( 'href' => 'https://canonical.com/wp-json/x' ) ) ),
			'meta'       => array( 'link' => 'https:\/\/canonical.com\/x' ),
			'page'       => 2,
			'has_more'   => true,
			'missing'    => null,
		);

		$this->assertSame(
			array(
				'html'       => '<a href="https://brand.com/auction/a/">a</a>',
				'pagination' => '<a href="https://brand.com/page/2/">2</a>',
				'_links'     => array( 'self' => array( array( 'href' => 'https://brand.com/wp-json/x' ) ) ),
				'meta'       => array( 'link' => 'https:\/\/brand.com\/x' ),
				'page'       => 2,
				'has_more'   => true,
				'missing'    => null,
			),
			$this->rewriter->filter_rest_pre_echo_response( $data, null, new WP_REST_Request() )
		);
	}

	public function test_rest_response_untouched_for_write_methods(): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->never();

		$data = array( 'html' => '<a href="https://canonical.com/x">x</a>' );

		$this->assertSame(
			$data,
			$this->rewriter->filter_rest_pre_echo_response( $data, null, new WP_REST_Request( array(), 'POST' ) )
		);
	}

	public function test_rest_response_untouched_for_edit_context(): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->never();

		// A block-editor read: rewriting content.raw here would let brand-host
		// URLs be saved back into post content.
		$data = array( 'content' => array( 'raw' => '<a href="https://canonical.com/x">x</a>' ) );

		$this->assertSame(
			$data,
			$this->rewriter->filter_rest_pre_echo_response( $data, null, new WP_REST_Request( array( 'context' => 'edit' ) ) )
		);
	}

	public function test_rest_response_skipped_with_single_resolution_when_no_brand(): void {
		// One early resolution decides the whole response — never one per string.
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->once()->andReturn( null );

		$data = array(
			'html'       => '<a href="https://canonical.com/x">x</a>',
			'pagination' => '<a href="https://canonical.com/page/2/">2</a>',
			'meta'       => array( 'link' => 'https://canonical.com/y' ),
		);

		$this->assertSame(
			$data,
			$this->rewriter->filter_rest_pre_echo_response( $data, null, new WP_REST_Request() )
		);
	}

	public function test_rest_response_untouched_when_feature_disabled(): void {
		$this->arrange( array() );

		$data = array( 'html' => '<a href="https://canonical.com/x">x</a>' );

		$this->assertSame(
			$data,
			$this->rewriter->filter_rest_pre_echo_response( $data, null, new WP_REST_Request() )
		);
	}

	public function test_rest_response_head_requests_are_rewritten(): void {
		$this->arrange( array( 'enabled' => true ) );

		$this->assertSame(
			array( 'html' => '<a href="https://brand.com/x">x</a>' ),
			$this->rewriter->filter_rest_pre_echo_response(
				array( 'html' => '<a href="https://canonical.com/x">x</a>' ),
				null,
				new WP_REST_Request( array(), 'HEAD' )
			)
		);
	}

	public function test_rest_response_untouched_without_a_request(): void {
		$this->brand_resolver->shouldReceive( 'resolve_current_request' )->never();

		$data = array( 'html' => '<a href="https://canonical.com/x">x</a>' );

		$this->assertSame( $data, $this->rewriter->filter_rest_pre_echo_response( $data, null, null ) );
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
