<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\HookManager;

#[CoversClass( HookManager::class )]
class HookManagerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private HookManager $hooks;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->hooks = new HookManager();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_registered_hooks_starts_empty(): void {
		$this->assertSame( array(), $this->hooks->get_registered_hooks() );
	}

	public function test_register_action_adds_and_tracks_new_hook(): void {
		Functions\expect( 'has_action' )->once()->with( 'init', 'strlen' )->andReturn( false );
		Functions\expect( 'add_action' )->once()->with( 'init', 'strlen', 20, 2 );

		$this->hooks->register_action( 'init', 'strlen', 20, 2 );

		$this->assertSame(
			array(
				array(
					'type'          => 'action',
					'hook'          => 'init',
					'callback'      => 'strlen',
					'priority'      => 20,
					'accepted_args' => 2,
				),
			),
			$this->hooks->get_registered_hooks()
		);
	}

	public function test_register_action_skips_when_already_registered(): void {
		Functions\expect( 'has_action' )->once()->with( 'init', 'strlen' )->andReturn( 10 );
		Functions\expect( 'add_action' )->never();

		$this->hooks->register_action( 'init', 'strlen' );

		$this->assertSame( array(), $this->hooks->get_registered_hooks() );
	}

	public function test_register_filter_adds_and_tracks_new_hook(): void {
		Functions\expect( 'has_filter' )->once()->with( 'the_content', 'strtolower' )->andReturn( false );
		Functions\expect( 'add_filter' )->once()->with( 'the_content', 'strtolower', 10, 1 );

		$this->hooks->register_filter( 'the_content', 'strtolower' );

		$this->assertSame(
			array(
				array(
					'type'          => 'filter',
					'hook'          => 'the_content',
					'callback'      => 'strtolower',
					'priority'      => 10,
					'accepted_args' => 1,
				),
			),
			$this->hooks->get_registered_hooks()
		);
	}

	public function test_register_filter_skips_when_already_registered(): void {
		Functions\expect( 'has_filter' )->once()->with( 'the_content', 'strtolower' )->andReturn( 5 );
		Functions\expect( 'add_filter' )->never();

		$this->hooks->register_filter( 'the_content', 'strtolower' );

		$this->assertSame( array(), $this->hooks->get_registered_hooks() );
	}

	public function test_deregister_all_removes_tracked_actions_and_filters_and_clears_list(): void {
		Functions\expect( 'has_action' )->once()->andReturn( false );
		Functions\expect( 'add_action' )->once();
		Functions\expect( 'has_filter' )->once()->andReturn( false );
		Functions\expect( 'add_filter' )->once();

		$this->hooks->register_action( 'init', 'strlen', 15, 1 );
		$this->hooks->register_filter( 'the_content', 'strtolower', 15, 1 );

		Functions\expect( 'remove_action' )->once()->with( 'init', 'strlen', 15 );
		Functions\expect( 'remove_filter' )->once()->with( 'the_content', 'strtolower', 15 );

		$this->hooks->deregister_all();

		$this->assertSame( array(), $this->hooks->get_registered_hooks() );
	}
}
