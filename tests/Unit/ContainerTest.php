<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Container;

#[CoversClass( Container::class )]
class ContainerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();

		$reflection = new \ReflectionClass( Container::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_instance_returns_same_instance(): void {
		$first  = Container::get_instance();
		$second = Container::get_instance();

		$this->assertSame( $first, $second );
	}

	public function test_register_and_get_resolves_factory(): void {
		$container = Container::get_instance();

		$container->register( 'greeting', fn() => 'hello' );

		$this->assertSame( 'hello', $container->get( 'greeting' ) );
	}

	public function test_singleton_factory_returns_same_instance_on_repeat_calls(): void {
		$container = Container::get_instance();

		$container->register( 'object_service', fn() => new \stdClass() );

		$this->assertSame( $container->get( 'object_service' ), $container->get( 'object_service' ) );
	}

	public function test_non_singleton_factory_returns_new_instance_each_call(): void {
		$container = Container::get_instance();

		$container->register( 'object_service', fn() => new \stdClass(), false );

		$this->assertNotSame( $container->get( 'object_service' ), $container->get( 'object_service' ) );
	}

	public function test_has_reflects_registered_services(): void {
		$container = Container::get_instance();

		$this->assertFalse( $container->has( 'missing' ) );

		$container->register( 'present', fn() => true );

		$this->assertTrue( $container->has( 'present' ) );
	}

	public function test_get_throws_for_unknown_service(): void {
		$container = Container::get_instance();

		$this->expectException( \Exception::class );

		$container->get( 'does_not_exist' );
	}

	public function test_get_hook_manager_returns_hook_manager_instance(): void {
		$container = Container::get_instance();

		$this->assertInstanceOf(
			\TheAnother\Plugin\MultiBrandGlobalStyles\HookManager::class,
			$container->get_hook_manager()
		);
	}
}
