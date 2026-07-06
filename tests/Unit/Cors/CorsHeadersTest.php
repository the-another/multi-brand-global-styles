<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Cors;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;
use TheAnother\Plugin\MultiBrandGlobalStyles\Cors\CorsHeaders;

#[CoversClass( CorsHeaders::class )]
class CorsHeadersTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/** @var UrlRuleRegistry&Mockery\MockInterface */
	private $url_rule_registry;

	private CorsHeaders $cors;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$this->cors              = new CorsHeaders( $this->url_rule_registry );
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_ORIGIN'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Arrange the common context with the given rule-map hosts and canonical options.
	 *
	 * @param array<string, array<string, int>> $rule_map Rule map (host => paths).
	 * @param string                            $home     home option URL.
	 * @param string                            $siteurl  siteurl option URL.
	 */
	private function arrange(
		array $rule_map = array(),
		string $home = 'https://canonical.example',
		string $siteurl = 'https://canonical.example'
	): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name ) => 'home' === $name ? $home : $siteurl
		);

		$this->url_rule_registry->shouldReceive( 'get_rule_map' )->andReturn( $rule_map );
	}

	public function test_returns_matching_brand_origin(): void {
		$this->arrange( array( 'brand.example' => array( '' => 1 ) ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://brand.example';

		$this->assertSame( 'https://brand.example', $this->cors->get_allowed_origin() );
	}

	public function test_returns_matching_canonical_origin(): void {
		$this->arrange( array( 'brand.example' => array( '' => 1 ) ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://canonical.example';

		$this->assertSame( 'https://canonical.example', $this->cors->get_allowed_origin() );
	}

	public function test_returns_null_for_unknown_origin(): void {
		$this->arrange( array( 'brand.example' => array( '' => 1 ) ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

		$this->assertNull( $this->cors->get_allowed_origin() );
	}

	public function test_returns_null_when_origin_absent(): void {
		$this->arrange( array( 'brand.example' => array( '' => 1 ) ) );
		// No HTTP_ORIGIN set.

		$this->assertNull( $this->cors->get_allowed_origin() );
	}

	public function test_allows_http_origin_for_brand_host(): void {
		$this->arrange( array( 'brand.example' => array( '' => 1 ) ) );
		$_SERVER['HTTP_ORIGIN'] = 'http://brand.example';

		$this->assertSame( 'http://brand.example', $this->cors->get_allowed_origin() );
	}

	public function test_allows_multiple_brand_hosts(): void {
		$this->arrange(
			array(
				'brand-a.example' => array( '' => 1 ),
				'brand-b.example' => array( '' => 2 ),
			)
		);
		$_SERVER['HTTP_ORIGIN'] = 'https://brand-b.example';

		$this->assertSame( 'https://brand-b.example', $this->cors->get_allowed_origin() );
	}

	public function test_rejects_malformed_origin(): void {
		$this->arrange( array( 'brand.example' => array( '' => 1 ) ) );
		$_SERVER['HTTP_ORIGIN'] = 'not-a-url';

		$this->assertNull( $this->cors->get_allowed_origin() );
	}

	public function test_origin_matching_is_case_insensitive(): void {
		$this->arrange( array( 'brand.example' => array( '' => 1 ) ) );
		$_SERVER['HTTP_ORIGIN'] = 'HTTPS://BRAND.EXAMPLE';

		$this->assertSame( 'https://brand.example', $this->cors->get_allowed_origin() );
	}

	public function test_includes_port_in_canonical_authority(): void {
		$this->arrange(
			array(),
			'https://canonical.example:8443',
			'https://canonical.example:8443'
		);
		$_SERVER['HTTP_ORIGIN'] = 'https://canonical.example:8443';

		$this->assertSame( 'https://canonical.example:8443', $this->cors->get_allowed_origin() );
	}

	public function test_rejects_origin_with_path(): void {
		$this->arrange( array( 'brand.example' => array( '' => 1 ) ) );
		$_SERVER['HTTP_ORIGIN'] = 'https://brand.example/some-path';

		$this->assertNull( $this->cors->get_allowed_origin() );
	}

	public function test_rejects_ftp_scheme(): void {
		$this->arrange( array( 'brand.example' => array( '' => 1 ) ) );
		$_SERVER['HTTP_ORIGIN'] = 'ftp://brand.example';

		$this->assertNull( $this->cors->get_allowed_origin() );
	}

	public function test_handle_skips_admin_requests(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		$_SERVER['HTTP_ORIGIN'] = 'https://brand.example';

		// handle() should return early — no rule map call.
		$this->url_rule_registry->shouldReceive( 'get_rule_map' )->never();

		$this->cors->handle();
	}

	public function test_handle_skips_ajax_requests(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$_SERVER['HTTP_ORIGIN'] = 'https://brand.example';

		$this->url_rule_registry->shouldReceive( 'get_rule_map' )->never();

		$this->cors->handle();
	}

	public function test_includes_both_home_and_siteurl_hosts(): void {
		$this->arrange(
			array(),
			'https://home.example',
			'https://siteurl.example'
		);
		$_SERVER['HTTP_ORIGIN'] = 'https://siteurl.example';

		$this->assertSame( 'https://siteurl.example', $this->cors->get_allowed_origin() );
	}

	public function test_dedupes_canonical_and_brand_hosts(): void {
		// Same host in both canonical and rule map — should still match once.
		$this->arrange(
			array( 'canonical.example' => array( '' => 1 ) ),
			'https://canonical.example',
			'https://canonical.example'
		);
		$_SERVER['HTTP_ORIGIN'] = 'https://canonical.example';

		$this->assertSame( 'https://canonical.example', $this->cors->get_allowed_origin() );
	}
}
