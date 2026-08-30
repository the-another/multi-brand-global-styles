<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\ContentVariables;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables\BlockAttributeSubstitutionService;

#[CoversClass( BlockAttributeSubstitutionService::class )]
class BlockAttributeSubstitutionServiceTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_service( ?int $brand_id, array $variables = array() ): BlockAttributeSubstitutionService {
		$resolver = Mockery::mock( BrandResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->andReturn( $brand_id );

		$repository = Mockery::mock( BrandRepository::class );
		if ( null !== $brand_id ) {
			$repository->shouldReceive( 'get_variables' )->with( $brand_id )->andReturn( $variables );
		}

		return new BlockAttributeSubstitutionService( $resolver, $repository );
	}

	/**
	 * The whole point of the class: core's navigation-link render callback runs
	 * esc_url() on this attribute, which would prepend http:// to a bare token,
	 * so the token has to be gone before the callback ever sees it.
	 */
	public function test_replaces_known_token_in_a_block_attribute(): void {
		$service = $this->make_service(
			5,
			array( 'auctioneer_login_url' => 'https://dashboard.farmauctionguide.com/login' )
		);

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array(
					'label' => 'Login',
					'url'   => '%%brand.auctioneer_login_url%%',
				),
			)
		);

		$this->assertSame( 'https://dashboard.farmauctionguide.com/login', $block['attrs']['url'] );
		$this->assertSame( 'Login', $block['attrs']['label'] );
	}

	public function test_replaces_tokens_nested_inside_attribute_arrays(): void {
		$service = $this->make_service(
			5,
			array( 'auctioneer_registration_url' => 'https://dashboard.auctionbill.com/registration' )
		);

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/buttons',
				'attrs'     => array(
					'links' => array(
						array( 'href' => '%%brand.auctioneer_registration_url%%' ),
					),
				),
			)
		);

		$this->assertSame(
			'https://dashboard.auctionbill.com/registration',
			$block['attrs']['links'][0]['href']
		);
	}

	public function test_leaves_tokens_alone_in_the_admin_so_authors_can_edit_them(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$service = $this->make_service(
			5,
			array( 'auctioneer_login_url' => 'https://dashboard.farmauctionguide.com/login' )
		);

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
			)
		);

		$this->assertSame( '%%brand.auctioneer_login_url%%', $block['attrs']['url'] );
	}

	public function test_leaves_tokens_alone_during_ajax(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );

		$service = $this->make_service(
			5,
			array( 'auctioneer_login_url' => 'https://dashboard.farmauctionguide.com/login' )
		);

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
			)
		);

		$this->assertSame( '%%brand.auctioneer_login_url%%', $block['attrs']['url'] );
	}

	public function test_leaves_tokens_alone_in_feeds(): void {
		Functions\when( 'is_feed' )->justReturn( true );

		$service = $this->make_service(
			5,
			array( 'auctioneer_login_url' => 'https://dashboard.farmauctionguide.com/login' )
		);

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
			)
		);

		$this->assertSame( '%%brand.auctioneer_login_url%%', $block['attrs']['url'] );
	}

	#[RunInSeparateProcess]
	public function test_leaves_tokens_alone_during_rest_requests(): void {
		define( 'REST_REQUEST', true );

		$service = $this->make_service(
			5,
			array( 'auctioneer_login_url' => 'https://dashboard.farmauctionguide.com/login' )
		);

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
			)
		);

		$this->assertSame( '%%brand.auctioneer_login_url%%', $block['attrs']['url'] );
	}

	/**
	 * Guards the one property that makes this class different from
	 * VariableSubstitutionService. The value is handed back to the block's own
	 * render callback, which escapes for its context; escaping here as well
	 * would turn a query string into `?a=1&amp;b=2` inside the href.
	 */
	public function test_inserts_the_value_unescaped_for_the_render_callback_to_escape(): void {
		Functions\when( 'esc_html' )->alias( static fn( $text ) => 'ESCAPED:' . $text );

		$service = $this->make_service(
			5,
			array( 'auctioneer_login_url' => 'https://dashboard.auctionbill.com/login?a=1&b=2' )
		);

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
			)
		);

		$this->assertSame( 'https://dashboard.auctionbill.com/login?a=1&b=2', $block['attrs']['url'] );
	}

	public function test_leaves_an_undefined_token_literal(): void {
		$service = $this->make_service( 5, array( 'name' => 'Acme' ) );

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
			)
		);

		$this->assertSame( '%%brand.auctioneer_login_url%%', $block['attrs']['url'] );
	}

	public function test_leaves_the_block_untouched_when_no_brand_resolves(): void {
		$service = $this->make_service( null );

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
			)
		);

		$this->assertSame( '%%brand.auctioneer_login_url%%', $block['attrs']['url'] );
	}

	public function test_preserves_non_string_attribute_types(): void {
		$service = $this->make_service( 5, array( 'name' => 'Acme' ) );

		$block = $service->filter_block_data(
			array(
				'blockName' => 'core/navigation',
				'attrs'     => array(
					'ref'         => 1,
					'overlayMenu' => 'never',
					'isResponsive' => true,
				),
			)
		);

		$this->assertSame( 1, $block['attrs']['ref'] );
		$this->assertTrue( $block['attrs']['isResponsive'] );
	}

	public function test_leaves_a_block_without_attributes_untouched(): void {
		$service = $this->make_service( 5, array( 'name' => 'Acme' ) );

		$block = $service->filter_block_data( array( 'blockName' => 'core/paragraph' ) );

		$this->assertSame( array( 'blockName' => 'core/paragraph' ), $block );
	}

	/**
	 * core/navigation renders its children through its own path
	 * (WP_Navigation_Block_Renderer::get_markup_for_inner_block calls
	 * WP_Block::render() directly), which never applies `render_block_data` —
	 * so the filter above cannot reach a navigation link. Core's dedicated
	 * inner-blocks filter is the only seam, and this is the case the whole
	 * feature exists for: the mobile menu's Login item.
	 */
	public function test_replaces_tokens_in_navigation_inner_blocks(): void {
		$service = $this->make_service(
			5,
			array( 'auctioneer_login_url' => 'https://dashboard.auctionbill.com/login' )
		);

		$list = new FakeBlockList(
			array(
				array(
					'blockName' => 'core/navigation-link',
					'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
				),
			)
		);

		$filtered = $service->filter_navigation_inner_blocks( $list );

		$this->assertSame(
			'https://dashboard.auctionbill.com/login',
			$filtered[0]['attrs']['url']
		);
	}

	public function test_replaces_tokens_in_nested_navigation_submenu_items(): void {
		$service = $this->make_service(
			5,
			array( 'auctioneer_login_url' => 'https://dashboard.auctionbill.com/login' )
		);

		$list = new FakeBlockList(
			array(
				array(
					'blockName'   => 'core/navigation-submenu',
					'attrs'       => array( 'label' => 'Auctioneers' ),
					'innerBlocks' => array(
						array(
							'blockName' => 'core/navigation-link',
							'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
						),
					),
				),
			)
		);

		$filtered = $service->filter_navigation_inner_blocks( $list );

		$this->assertSame(
			'https://dashboard.auctionbill.com/login',
			$filtered[0]['innerBlocks'][0]['attrs']['url']
		);
	}

	public function test_leaves_navigation_inner_blocks_alone_when_no_brand_resolves(): void {
		$service = $this->make_service( null );

		$list = new FakeBlockList(
			array(
				array(
					'blockName' => 'core/navigation-link',
					'attrs'     => array( 'url' => '%%brand.auctioneer_login_url%%' ),
				),
			)
		);

		$filtered = $service->filter_navigation_inner_blocks( $list );

		// Untouched: the list still holds the block objects core put there,
		// not the raw arrays a substituting pass writes back.
		$this->assertSame(
			'%%brand.auctioneer_login_url%%',
			$filtered[0]->parsed_block['attrs']['url']
		);
	}
}

/**
 * Stands in for WP_Block_List, which the unit suite has no WordPress to load.
 * Mirrors the two behaviours the service relies on: iteration yields objects
 * carrying a public `parsed_block`, and an offset can be overwritten with a raw
 * parsed array (core then re-instantiates it lazily on the next read).
 */
class FakeBlockList implements \ArrayAccess, \IteratorAggregate {

	/** @var array<int, mixed> */
	private array $blocks;

	public function __construct( array $parsed_blocks ) {
		$this->blocks = array_map(
			static function ( array $parsed ) {
				$block               = new \stdClass();
				$block->parsed_block = $parsed;

				return $block;
			},
			$parsed_blocks
		);
	}

	public function getIterator(): \Traversable {
		return new \ArrayIterator( $this->blocks );
	}

	public function offsetExists( mixed $offset ): bool {
		return isset( $this->blocks[ $offset ] );
	}

	public function offsetGet( mixed $offset ): mixed {
		return $this->blocks[ $offset ];
	}

	public function offsetSet( mixed $offset, mixed $value ): void {
		$this->blocks[ $offset ] = $value;
	}

	public function offsetUnset( mixed $offset ): void {
		unset( $this->blocks[ $offset ] );
	}
}
