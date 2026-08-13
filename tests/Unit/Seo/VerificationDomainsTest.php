<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Seo;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;
use TheAnother\Plugin\MultiBrandGlobalStyles\Seo\VerificationDomains;

#[CoversClass( VerificationDomains::class )]
class VerificationDomainsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/** @var UrlRuleRegistry&Mockery\MockInterface */
	private $url_rule_registry;

	private VerificationDomains $domains;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->url_rule_registry = Mockery::mock( UrlRuleRegistry::class );
		$this->domains           = new VerificationDomains( $this->url_rule_registry );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_appends_every_host_in_the_rule_map(): void {
		$this->url_rule_registry->shouldReceive( 'get_rule_map' )->andReturn(
			array(
				'brandtwo.com'  => array( '' => 11 ),
				'brandthree.co' => array( '/shop' => 12 ),
			)
		);

		$this->assertSame(
			array( 'example.com', 'brandtwo.com', 'brandthree.co' ),
			$this->domains->filter_domains( array( 'example.com' ) )
		);
	}

	public function test_returns_the_input_untouched_when_it_is_not_an_array(): void {
		$this->url_rule_registry->shouldNotReceive( 'get_rule_map' );

		$this->assertSame( 'not an array', $this->domains->filter_domains( 'not an array' ) );
	}

	public function test_appends_nothing_when_no_brand_has_a_url_rule(): void {
		$this->url_rule_registry->shouldReceive( 'get_rule_map' )->andReturn( array() );

		$this->assertSame( array( 'example.com' ), $this->domains->filter_domains( array( 'example.com' ) ) );
	}
}
