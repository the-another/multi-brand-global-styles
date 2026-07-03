<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Container;
use TheAnother\Plugin\MultiBrandGlobalStyles\HookManager;

#[CoversClass( Container::class )]
#[UsesClass( HookManager::class )]
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

	public function test_set_stores_a_direct_instance_retrieved_via_get(): void {
		$container = Container::get_instance();
		$instance  = new \stdClass();

		$container->set( 'direct', $instance );

		$this->assertSame( $instance, $container->get( 'direct' ) );
	}

	public function test_deregister_all_hooks_clears_hook_manager(): void {
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'remove_action' )->justReturn( true );

		$container = Container::get_instance();
		$container->get_hook_manager()->register_action( 'init', static function () {} );

		$container->deregister_all_hooks();

		$this->assertSame( array(), $container->get_hook_manager()->get_registered_hooks() );
	}

	public function test_clone_is_prevented(): void {
		$container = Container::get_instance();

		$this->expectException( \Error::class );

		clone $container;
	}

	public function test_wakeup_throws_to_prevent_unserialize(): void {
		$reflection = new \ReflectionClass( Container::class );
		$instance   = $reflection->newInstanceWithoutConstructor();

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Cannot unserialize singleton' );

		$instance->__wakeup();
	}
}
