<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Rendering;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Rendering\PageBuffer;

#[CoversClass( PageBuffer::class )]
class PageBufferTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_apply_runs_transformers_in_order(): void {
		$buffer = new PageBuffer(
			array(
				static fn( string $html ): string => $html . 'A',
				static fn( string $html ): string => $html . 'B',
			)
		);

		$this->assertSame( 'xAB', $buffer->apply( 'x' ) );
	}

	// Note: the REST_REQUEST branch of start_buffer()'s guard is intentionally not
	// covered here — defining the REST_REQUEST constant would leak process-wide
	// and poison other tests in the suite.

	public function test_start_buffer_skips_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$buffer = new PageBuffer( array() );

		$level = ob_get_level();
		$buffer->start_buffer();

		$this->assertSame( $level, ob_get_level() );
	}

	public function test_start_buffer_skips_feeds(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( true );

		$buffer = new PageBuffer( array() );

		$level = ob_get_level();
		$buffer->start_buffer();

		$this->assertSame( $level, ob_get_level() );
	}

	public function test_start_buffer_applies_transformers_on_flush(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );

		$buffer = new PageBuffer(
			array( static fn( string $html ): string => str_replace( 'World', 'Brand', $html ) )
		);

		ob_start(); // outer capture buffer
		$level_before = ob_get_level();

		$buffer->start_buffer();
		$this->assertSame( $level_before + 1, ob_get_level() );

		echo 'Hello World';
		ob_end_flush();

		$this->assertSame( 'Hello Brand', ob_get_clean() );
	}
}
