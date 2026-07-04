<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Editor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Editor\EditorAssets;

#[CoversClass( EditorAssets::class )]
class EditorAssetsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enqueue_skips_when_asset_file_missing(): void {
		Functions\expect( 'wp_enqueue_script' )->never();

		( new EditorAssets( '/nonexistent/dir/', 'https://ex.com/wp-content/plugins/x/' ) )->enqueue();
	}

	public function test_enqueue_reads_asset_file_and_enqueues(): void {
		$dir = sys_get_temp_dir() . '/mbgs-editor-assets-test/';
		mkdir( $dir . 'assets/build', 0777, true );
		file_put_contents(
			$dir . 'assets/build/index.asset.php',
			"<?php return array( 'dependencies' => array( 'wp-blocks' ), 'version' => 'abc123' );"
		);

		Functions\expect( 'wp_enqueue_script' )->once()->with(
			'mbgs-editor',
			'https://ex.com/wp-content/plugins/x/assets/build/index.js',
			array( 'wp-blocks' ),
			'abc123',
			true
		);
		Functions\expect( 'home_url' )->once()->with( '/' )->andReturn( 'https://ex.com/' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\expect( 'wp_add_inline_script' )->once()->with(
			'mbgs-editor',
			'window.mbgsEditor = {"homeUrl":"https:\/\/ex.com\/"};',
			'before'
		);

		( new EditorAssets( $dir, 'https://ex.com/wp-content/plugins/x/' ) )->enqueue();

		unlink( $dir . 'assets/build/index.asset.php' );
		rmdir( $dir . 'assets/build' );
		rmdir( $dir . 'assets' );
		rmdir( $dir );
	}
}
