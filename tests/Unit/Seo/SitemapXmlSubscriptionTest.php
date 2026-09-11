<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\HookManager;
use TheAnother\Plugin\MultiBrandGlobalStyles\Seo\SitemapXmlSubscription;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\HostRewriter;

#[CoversClass( SitemapXmlSubscription::class )]
#[UsesClass( HookManager::class )]
class SitemapXmlSubscriptionTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/** @var HostRewriter&Mockery\MockInterface */
	private $host_rewriter;

	private HookManager $hooks;

	private SitemapXmlSubscription $subscription;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_filter' )->justReturn( true );

		$this->host_rewriter = Mockery::mock( HostRewriter::class );
		$this->hooks         = new HookManager();
		$this->subscription  = new SitemapXmlSubscription( $this->hooks, $this->host_rewriter );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The taseo_sitemap_xml entries this subscription registered, if any.
	 *
	 * @return array<int, array<string, mixed>> Matching hook records.
	 */
	private function registered_sitemap_filters(): array {
		return array_values(
			array_filter(
				$this->hooks->get_registered_hooks(),
				static fn( array $hook ): bool => 'taseo_sitemap_xml' === $hook['hook']
			)
		);
	}

	public function test_subscribes_when_the_request_would_rewrite(): void {
		$this->host_rewriter->shouldReceive( 'would_rewrite' )->andReturn( true );

		$this->subscription->maybe_subscribe();

		$filters = $this->registered_sitemap_filters();

		$this->assertCount( 1, $filters );
		$this->assertSame( 'filter', $filters[0]['type'] );
		$this->assertSame(
			array( $this->host_rewriter, 'filter_taseo_sitemap_xml' ),
			$filters[0]['callback']
		);
	}

	public function test_does_not_subscribe_when_the_request_would_not_rewrite(): void {
		$this->host_rewriter->shouldReceive( 'would_rewrite' )->andReturn( false );

		$this->subscription->maybe_subscribe();

		$this->assertSame(
			array(),
			$this->registered_sitemap_filters(),
			'Subscribing with nothing to rewrite makes The Another SEO buffer every sitemap chunk instead of streaming it.'
		);
	}
}
