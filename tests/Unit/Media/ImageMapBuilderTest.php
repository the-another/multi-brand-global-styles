<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Media;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageMapBuilder;

#[CoversClass( ImageMapBuilder::class )]
class ImageMapBuilderTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_attachments( array $urls, array $metadata ): void {
		Functions\when( 'wp_get_attachment_url' )->alias(
			static fn( int $id ) => $urls[ $id ] ?? false
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			static fn( int $id ) => $metadata[ $id ] ?? false
		);
	}

	public function test_maps_full_and_matching_size_urls(): void {
		$this->stub_attachments(
			array(
				10 => 'https://ex.com/up/orig.jpg',
				20 => 'https://ex.com/up/repl.jpg',
			),
			array(
				10 => array( 'sizes' => array( 'thumbnail' => array( 'file' => 'orig-150x150.jpg' ) ) ),
				20 => array( 'sizes' => array( 'thumbnail' => array( 'file' => 'repl-150x150.jpg' ) ) ),
			)
		);

		$map = ( new ImageMapBuilder() )->build_url_map( array( 10 => 20 ) );

		$this->assertSame( 'https://ex.com/up/repl.jpg', $map['https://ex.com/up/orig.jpg'] );
		$this->assertSame( 'https://ex.com/up/repl-150x150.jpg', $map['https://ex.com/up/orig-150x150.jpg'] );
	}

	public function test_missing_replacement_size_falls_back_to_full_url(): void {
		$this->stub_attachments(
			array(
				10 => 'https://ex.com/up/orig.jpg',
				20 => 'https://ex.com/up/repl.jpg',
			),
			array(
				10 => array( 'sizes' => array( 'large' => array( 'file' => 'orig-1024x768.jpg' ) ) ),
				20 => array( 'sizes' => array() ),
			)
		);

		$map = ( new ImageMapBuilder() )->build_url_map( array( 10 => 20 ) );

		$this->assertSame( 'https://ex.com/up/repl.jpg', $map['https://ex.com/up/orig-1024x768.jpg'] );
	}

	public function test_skips_pairs_whose_attachments_do_not_resolve(): void {
		$this->stub_attachments( array( 10 => 'https://ex.com/up/orig.jpg' ), array() );

		$this->assertSame( array(), ( new ImageMapBuilder() )->build_url_map( array( 10 => 99 ) ) );
	}

	public function test_orders_keys_longest_first(): void {
		$this->stub_attachments(
			array(
				10 => 'https://ex.com/up/orig.jpg',
				20 => 'https://ex.com/up/repl.jpg',
			),
			array(
				10 => array( 'sizes' => array( 'thumbnail' => array( 'file' => 'orig-150x150.jpg' ) ) ),
				20 => array(),
			)
		);

		$keys = array_keys( ( new ImageMapBuilder() )->build_url_map( array( 10 => 20 ) ) );

		$this->assertSame( 'https://ex.com/up/orig-150x150.jpg', $keys[0] );
	}

	public function test_persist_writes_both_meta_keys(): void {
		$this->stub_attachments(
			array(
				10 => 'https://ex.com/up/orig.jpg',
				20 => 'https://ex.com/up/repl.jpg',
			),
			array()
		);

		Functions\expect( 'update_post_meta' )->once()->with( 5, '_mbgs_image_map', array( 10 => 20 ) );
		Functions\expect( 'update_post_meta' )->once()->with(
			5,
			'_mbgs_image_url_map',
			array( 'https://ex.com/up/orig.jpg' => 'https://ex.com/up/repl.jpg' )
		);

		( new ImageMapBuilder() )->persist( 5, array( 10 => 20 ) );
	}
}
