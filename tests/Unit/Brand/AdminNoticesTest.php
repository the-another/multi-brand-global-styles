<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Brand;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\AdminNotices;

#[CoversClass( AdminNotices::class )]
class AdminNoticesTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_outputs_nothing_when_no_rejection_recorded(): void {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_rule_conflict_1' )->andReturn( false );

		$notices = new AdminNotices();

		ob_start();
		$notices->render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_render_outputs_notice_and_clears_transient_when_rejection_recorded(): void {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_transient' )->once()->with( 'mbgs_rule_conflict_1' )->andReturn( array( 'taken.com' ) );
		Functions\expect( 'delete_transient' )->once()->with( 'mbgs_rule_conflict_1' );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();

		$notices = new AdminNotices();

		ob_start();
		$notices->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'taken.com', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
	}
}
