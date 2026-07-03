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
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;

// The registry mock below is a partial mock: only get_rule_map() is stubbed,
// normalize_host()/normalize_path() fall through to the real UrlRuleRegistry
// so tests like test_www_and_port_are_normalized_before_lookup exercise real
// normalization. #[UsesClass] tells PHPUnit's strict coverage metadata check
// this is intentional — without it, the "unintentionally covered code" risky
// flag makes PHPUnit discard the test's ENTIRE coverage contribution,
// including BrandResolver's own lines.
#[CoversClass( BrandResolver::class )]
#[UsesClass( UrlRuleRegistry::class )]
class BrandResolverTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	/**
	 * Build a resolver whose registry performs REAL normalization (pass-through
	 * to a concrete UrlRuleRegistry) against a fixed rule map.
	 */
	private function make_resolver( array $rule_map, ?int $default_brand_id = null ): BrandResolver {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$registry = Mockery::mock( UrlRuleRegistry::class )->makePartial();
		$registry->shouldReceive( 'get_rule_map' )->andReturn( $rule_map );

		$repository = Mockery::mock( BrandRepository::class );
		$repository->shouldReceive( 'get_default_brand_id' )->andReturn( $default_brand_id );

		return new BrandResolver( $registry, $repository );
	}

	private const MAP = array(
		'auctionbill.com'      => array( '' => 5 ),
		'beta.auctionbill.com' => array( '' => 5 ),
		'site.com'             => array(
			''      => 7,
			'/farm' => 9,
		),
		'site2.com'            => array( '/farm' => 9 ),
	);

	public function test_host_wide_rule_matches_any_path(): void {
		$resolver = $this->make_resolver( self::MAP );

		$this->assertSame( 5, $resolver->resolve( 'auctionbill.com', '/anything/here' ) );
	}

	public function test_www_and_port_are_normalized_before_lookup(): void {
		$resolver = $this->make_resolver( self::MAP );

		$this->assertSame( 5, $resolver->resolve( 'WWW.AuctionBill.com:8080', '/' ) );
	}

	public function test_path_rule_beats_host_wide_rule_on_same_host(): void {
		$resolver = $this->make_resolver( self::MAP );

		$this->assertSame( 9, $resolver->resolve( 'site.com', '/farm/tractors' ) );
	}

	public function test_path_rule_matches_its_exact_path(): void {
		$resolver = $this->make_resolver( self::MAP );

		$this->assertSame( 9, $resolver->resolve( 'site.com', '/farm' ) );
	}

	public function test_host_wide_rule_wins_when_path_rule_does_not_match(): void {
		$resolver = $this->make_resolver( self::MAP );

		$this->assertSame( 7, $resolver->resolve( 'site.com', '/shop' ) );
	}

	public function test_prefix_matching_respects_segment_boundaries(): void {
		$resolver = $this->make_resolver( self::MAP );

		// /farmhouse must NOT match the /farm rule.
		$this->assertSame( 7, $resolver->resolve( 'site.com', '/farmhouse' ) );
	}

	public function test_longer_prefix_beats_shorter_prefix(): void {
		$map = array(
			'site.com' => array(
				'/farm'          => 9,
				'/farm/tractors' => 12,
			),
		);
		$resolver = $this->make_resolver( $map );

		$this->assertSame( 12, $resolver->resolve( 'site.com', '/farm/tractors/deere' ) );
		$this->assertSame( 9, $resolver->resolve( 'site.com', '/farm/seeds' ) );
	}

	public function test_falls_back_to_default_when_host_unknown(): void {
		$resolver = $this->make_resolver( self::MAP, 20 );

		$this->assertSame( 20, $resolver->resolve( 'unknown.test', '/' ) );
	}

	public function test_falls_back_to_default_when_only_path_rules_exist_and_none_match(): void {
		$resolver = $this->make_resolver( self::MAP, 20 );

		// site2.com has ONLY the /farm rule; /shop matches nothing.
		$this->assertSame( 20, $resolver->resolve( 'site2.com', '/shop' ) );
	}

	public function test_returns_null_when_unmatched_and_no_default(): void {
		$resolver = $this->make_resolver( self::MAP, null );

		$this->assertNull( $resolver->resolve( 'unknown.test', '/' ) );
	}

	public function test_falls_back_to_default_for_empty_host(): void {
		$resolver = $this->make_resolver( self::MAP, 20 );

		$this->assertSame( 20, $resolver->resolve( '', '/farm' ) );
	}

	public function test_resolve_current_request_reads_host_and_request_uri(): void {
		$_SERVER['HTTP_HOST']   = 'site.com';
		$_SERVER['REQUEST_URI'] = '/farm/tractors?sort=new';

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$resolver = $this->make_resolver( self::MAP );

		$this->assertSame( 9, $resolver->resolve_current_request() );
	}
}
