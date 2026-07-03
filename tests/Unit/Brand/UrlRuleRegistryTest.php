<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Brand;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;

#[CoversClass( UrlRuleRegistry::class )]
class UrlRuleRegistryTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private UrlRuleRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$this->registry = new UrlRuleRegistry();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public static function normalize_host_cases(): array {
		return array(
			'plain host'               => array( 'example.com', 'example.com' ),
			'uppercase'                => array( 'EXAMPLE.com', 'example.com' ),
			'leading www'              => array( 'www.example.com', 'example.com' ),
			'with port'                => array( 'example.com:8080', 'example.com' ),
			'www and port'             => array( 'WWW.Example.com:443', 'example.com' ),
			'full https url'           => array( 'https://example.com/path', 'example.com' ),
			'full http url with www'   => array( 'http://www.example.com', 'example.com' ),
			'surrounding whitespace'   => array( '  example.com  ', 'example.com' ),
			'empty string'             => array( '', '' ),
			'unicode host rejected'    => array( 'münchen.de', '' ),
			'host with space rejected' => array( 'exam ple.com', '' ),
			'punycode host accepted'   => array( 'xn--mnchen-3ya.de', 'xn--mnchen-3ya.de' ),
		);
	}

	#[DataProvider( 'normalize_host_cases' )]
	public function test_normalize_host( string $input, string $expected ): void {
		$this->assertSame( $expected, $this->registry->normalize_host( $input ) );
	}

	public static function normalize_path_cases(): array {
		return array(
			'empty'                 => array( '', '' ),
			'root slash only'       => array( '/', '' ),
			'simple section'        => array( '/farm', '/farm' ),
			'no leading slash'      => array( 'farm', '/farm' ),
			'trailing slash'        => array( '/farm/', '/farm' ),
			'trailing wildcard'     => array( '/farm/*', '/farm' ),
			'uppercase'             => array( '/Farm/Sub', '/farm/sub' ),
			'query string stripped' => array( '/farm?x=1', '/farm' ),
			'fragment stripped'     => array( '/farm#top', '/farm' ),
			'nested with wildcard'  => array( '/farm/tractors/*', '/farm/tractors' ),
		);
	}

	#[DataProvider( 'normalize_path_cases' )]
	public function test_normalize_path( string $input, string $expected ): void {
		$this->assertSame( $expected, $this->registry->normalize_path( $input ) );
	}

	public static function normalize_rule_cases(): array {
		return array(
			'bare host'                 => array( 'auctionbill.com', 'auctionbill.com' ),
			'host with www and port'    => array( 'WWW.AuctionBill.com:8080', 'auctionbill.com' ),
			'host with trailing slash'  => array( 'site.com/', 'site.com' ),
			'host and section'          => array( 'site.com/farm', 'site.com/farm' ),
			'section with wildcard'     => array( 'site.com/farm/*', 'site.com/farm' ),
			'full url with scheme'      => array( 'https://site.com/farm/', 'site.com/farm' ),
			'mixed case path'           => array( 'site.com/Farm/Sub/', 'site.com/farm/sub' ),
			'query string stripped'     => array( 'site.com/farm?x=1', 'site.com/farm' ),
			'path without host is junk' => array( '/farm', '' ),
			'empty string'              => array( '', '' ),
			'unicode host rejected'     => array( 'münchen.de/farm', '' ),
		);
	}

	#[DataProvider( 'normalize_rule_cases' )]
	public function test_normalize_rule( string $input, string $expected ): void {
		$this->assertSame( $expected, $this->registry->normalize_rule( $input ) );
	}

	public function test_split_rule_host_only(): void {
		$this->assertSame( array( 'auctionbill.com', '' ), $this->registry->split_rule( 'auctionbill.com' ) );
	}

	public function test_split_rule_host_and_path(): void {
		$this->assertSame( array( 'site.com', '/farm' ), $this->registry->split_rule( 'site.com/farm' ) );
	}

	public function test_parse_rules_input_splits_dedupes_and_normalizes(): void {
		$raw = "auctionbill.com\nWWW.AuctionBill.com\n\nsite.com/farm/*\nsite.com/farm";

		$this->assertSame(
			array( 'auctionbill.com', 'site.com/farm' ),
			$this->registry->parse_rules_input( $raw )
		);
	}

	public function test_parse_rules_input_ignores_blank_and_junk_lines(): void {
		$this->assertSame( array(), $this->registry->parse_rules_input( "\n \n/path-without-host\n" ) );
	}

	public function test_get_rule_map_returns_cached_value_when_present(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'mbgs_rule_map' )
			->andReturn( array( 'auctionbill.com' => array( '' => 5 ) ) );

		$this->assertSame( array( 'auctionbill.com' => array( '' => 5 ) ), $this->registry->get_rule_map() );
	}

	public function test_get_rule_map_builds_and_caches_when_absent(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'get_posts' )
			->once()
			->andReturn( array( 5, 9 ) );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 5, '_mbgs_rules', true )
			->andReturn( array( 'auctionbill.com', 'beta.auctionbill.com' ) );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 9, '_mbgs_rules', true )
			->andReturn( array( 'site.com/farm', 'site2.com/farm' ) );
		Functions\expect( 'set_transient' )
			->once()
			->with(
				'mbgs_rule_map',
				array(
					'auctionbill.com'      => array( '' => 5 ),
					'beta.auctionbill.com' => array( '' => 5 ),
					'site.com'             => array( '/farm' => 9 ),
					'site2.com'            => array( '/farm' => 9 ),
				),
				0
			);

		$map = $this->registry->get_rule_map();

		$this->assertSame(
			array(
				'auctionbill.com'      => array( '' => 5 ),
				'beta.auctionbill.com' => array( '' => 5 ),
				'site.com'             => array( '/farm' => 9 ),
				'site2.com'            => array( '/farm' => 9 ),
			),
			$map
		);
	}

	public function test_get_rule_map_merges_host_wide_and_path_rules_for_same_host(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 7, 9 ) );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 7, '_mbgs_rules', true )
			->andReturn( array( 'site.com' ) );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 9, '_mbgs_rules', true )
			->andReturn( array( 'site.com/farm' ) );
		Functions\expect( 'set_transient' )->once();

		$this->assertSame(
			array(
				'site.com' => array(
					''      => 7,
					'/farm' => 9,
				),
			),
			$this->registry->get_rule_map()
		);
	}

	public function test_get_rule_map_skips_posts_with_no_rules_meta(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'get_posts' )->once()->andReturn( array( 11 ) );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 11, '_mbgs_rules', true )
			->andReturn( '' );
		Functions\expect( 'set_transient' )->once()->with( 'mbgs_rule_map', array(), 0 );

		$this->assertSame( array(), $this->registry->get_rule_map() );
	}

	public function test_find_conflicting_brand_returns_null_when_rule_unused(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( array( 'site.com' => array( '' => 7 ) ) );

		$this->assertNull( $this->registry->find_conflicting_brand( 'other.test' ) );
	}

	public function test_find_conflicting_brand_allows_overlapping_but_different_rules(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( array( 'site.com' => array( '' => 7 ) ) );

		// site.com is taken by Brand 7, but site.com/farm is a DIFFERENT rule — no conflict.
		$this->assertNull( $this->registry->find_conflicting_brand( 'site.com/farm', 9 ) );
	}

	public function test_find_conflicting_brand_returns_null_for_self(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( array( 'site.com' => array( '/farm' => 9 ) ) );

		$this->assertNull( $this->registry->find_conflicting_brand( 'site.com/farm', 9 ) );
	}

	public function test_find_conflicting_brand_returns_owning_id_for_other_post(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( array( 'site.com' => array( '/farm' => 9 ) ) );

		$this->assertSame( 9, $this->registry->find_conflicting_brand( 'site.com/farm', 5 ) );
	}

	public function test_invalidate_cache_deletes_transient(): void {
		Functions\expect( 'delete_transient' )->once()->with( 'mbgs_rule_map' );

		$this->registry->invalidate_cache();
	}
}
