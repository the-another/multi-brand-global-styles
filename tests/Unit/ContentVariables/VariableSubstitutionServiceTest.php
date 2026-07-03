<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\ContentVariables;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables\VariableSubstitutionService;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;

#[CoversClass( VariableSubstitutionService::class )]
class VariableSubstitutionServiceTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_service( ?int $brand_id, array $variables = array() ): VariableSubstitutionService {
		$resolver = Mockery::mock( BrandResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->andReturn( $brand_id );

		$repository = Mockery::mock( BrandRepository::class );
		if ( null !== $brand_id ) {
			$repository->shouldReceive( 'get_variables' )->with( $brand_id )->andReturn( $variables );
		}

		return new VariableSubstitutionService( $resolver, $repository );
	}

	public function test_replaces_known_brand_token(): void {
		Functions\when( 'esc_html' )->returnArg();

		$service = $this->make_service( 5, array( 'name' => 'Acme Auctions' ) );

		$this->assertSame(
			'Welcome to Acme Auctions today.',
			$service->replace( 'Welcome to %%brand.name%% today.' )
		);
	}

	public function test_leaves_undefined_token_literal(): void {
		$service = $this->make_service( 5, array( 'name' => 'Acme Auctions' ) );

		$this->assertSame(
			'Call us at %%brand.phone%%.',
			$service->replace( 'Call us at %%brand.phone%%.' )
		);
	}

	public function test_ignores_tokens_outside_brand_namespace(): void {
		$service = $this->make_service( 5, array( 'name' => 'Acme Auctions' ) );

		$this->assertSame(
			'%%other.name%% stays untouched.',
			$service->replace( '%%other.name%% stays untouched.' )
		);
	}

	public function test_replaces_multiple_adjacent_tokens(): void {
		Functions\when( 'esc_html' )->returnArg();

		$service = $this->make_service(
			5,
			array(
				'name'  => 'Acme',
				'phone' => '555-0100',
			) 
		);

		$this->assertSame(
			'AcmeCall 555-0100',
			$service->replace( '%%brand.name%%Call %%brand.phone%%' )
		);
	}

	public function test_escapes_variable_value(): void {
		Functions\when( 'esc_html' )->alias(
			static fn( $text ) => str_replace( '<', '&lt;', $text )
		);

		$service = $this->make_service( 5, array( 'name' => '<script>Acme</script>' ) );

		$this->assertSame(
			'&lt;script>Acme&lt;/script>',
			$service->replace( '%%brand.name%%' )
		);
	}

	public function test_returns_html_unchanged_when_no_brand_resolved(): void {
		$service = $this->make_service( null );

		$this->assertSame( '%%brand.name%%', $service->replace( '%%brand.name%%' ) );
	}

	public function test_returns_html_unchanged_when_no_variables_defined(): void {
		$service = $this->make_service( 5, array() );

		$this->assertSame( '%%brand.name%%', $service->replace( '%%brand.name%%' ) );
	}

	// Note: the REST_REQUEST branch of start_buffer()'s guard is intentionally not
	// covered here — defining the REST_REQUEST constant would leak process-wide
	// and poison other tests in the suite.

	public function test_start_buffer_skips_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$service = $this->make_service( null );

		$level = ob_get_level();
		$service->start_buffer();

		$this->assertSame( $level, ob_get_level() );
	}

	public function test_start_buffer_skips_feeds(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( true );

		$service = $this->make_service( null );

		$level = ob_get_level();
		$service->start_buffer();

		$this->assertSame( $level, ob_get_level() );
	}

	public function test_start_buffer_starts_buffer_and_applies_replace_on_flush(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();

		$service = $this->make_service( 5, array( 'name' => 'Acme Auctions' ) );

		ob_start(); // outer capture buffer
		$level_before = ob_get_level();

		$service->start_buffer();

		$this->assertSame( $level_before + 1, ob_get_level() );

		echo 'Hello %%brand.name%%';
		ob_end_flush(); // flush inner buffer through replace(), into the outer buffer

		$this->assertSame( 'Hello Acme Auctions', ob_get_clean() );
	}
}
