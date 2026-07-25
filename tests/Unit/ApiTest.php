<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Container;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\RequestHomeUrl;

require_once dirname( __DIR__, 2 ) . '/includes/api.php';

#[CoversFunction( 'mbgs_request_home_url' )]
#[UsesClass( Container::class )]
class ApiTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_singleton( Container::class );
	}

	protected function tearDown(): void {
		$this->reset_singleton( Container::class );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function reset_singleton( string $class ): void {
		$reflection = new \ReflectionClass( $class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	/**
	 * Register a RequestHomeUrl mock in the container that expects one
	 * filter() call with $input and answers $output.
	 */
	private function register_service_expecting( string $input, string $output ): void {
		$service = Mockery::mock( RequestHomeUrl::class );
		$service->shouldReceive( 'filter' )->once()->with( $input )->andReturn( $output );
		Container::get_instance()->set( 'request_home_url', $service );
	}

	public function test_returns_the_service_result(): void {
		$this->register_service_expecting( 'https://canonical.com', 'https://brand.com' );

		$this->assertSame( 'https://brand.com', mbgs_request_home_url( 'https://canonical.com' ) );
	}

	public function test_applies_the_extension_filter_to_the_service_result(): void {
		$this->register_service_expecting( 'https://canonical.com', 'https://brand.com' );

		Filters\expectApplied( 'mbgs_request_home_url' )
			->once()
			->with( 'https://brand.com', 'https://canonical.com' )
			->andReturn( 'https://override.example' );

		$this->assertSame( 'https://override.example', mbgs_request_home_url( 'https://canonical.com' ) );
	}

	public function test_fails_open_when_the_service_is_not_registered(): void {
		// Fresh container, Plugin::start() never ran (e.g. the function is
		// called before plugins_loaded): the input passes through untouched.
		$this->assertSame( 'https://canonical.com', mbgs_request_home_url( 'https://canonical.com' ) );
	}

	public function test_extension_filter_still_runs_when_the_service_is_not_registered(): void {
		Filters\expectApplied( 'mbgs_request_home_url' )
			->once()
			->with( 'https://canonical.com', 'https://canonical.com' )
			->andReturn( 'https://override.example' );

		$this->assertSame( 'https://override.example', mbgs_request_home_url( 'https://canonical.com' ) );
	}

	public function test_non_string_input_passes_through_to_the_service(): void {
		// The mixed contract belongs to RequestHomeUrl::filter(); the function
		// forwards whatever it was given instead of fataling on its own.
		$service = Mockery::mock( RequestHomeUrl::class );
		$service->shouldReceive( 'filter' )->once()->with( null )->andReturn( null );
		Container::get_instance()->set( 'request_home_url', $service );

		$this->assertNull( mbgs_request_home_url( null ) );
	}
}
