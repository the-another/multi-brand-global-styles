<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Media;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;
use TheAnother\Plugin\MultiBrandGlobalStyles\Media\ImageUrlReplacer;

#[CoversClass( ImageUrlReplacer::class )]
class ImageUrlReplacerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_replacer( ?int $brand_id, array $url_map = array() ): ImageUrlReplacer {
		$resolver = Mockery::mock( BrandResolver::class );
		$resolver->shouldReceive( 'resolve_current_request' )->andReturn( $brand_id );

		$repository = Mockery::mock( BrandRepository::class );
		if ( null !== $brand_id ) {
			$repository->shouldReceive( 'get_image_url_map' )->with( $brand_id )->andReturn( $url_map );
		}

		return new ImageUrlReplacer( $resolver, $repository );
	}

	public function test_swaps_src_and_srcset_urls_in_one_pass(): void {
		$replacer = $this->make_replacer(
			5,
			array(
				'https://ex.com/up/orig-150x150.jpg' => 'https://ex.com/up/repl-150x150.jpg',
				'https://ex.com/up/orig.jpg'         => 'https://ex.com/up/repl.jpg',
			)
		);

		$html = '<img src="https://ex.com/up/orig.jpg" srcset="https://ex.com/up/orig-150x150.jpg 150w, https://ex.com/up/orig.jpg 800w">';

		$this->assertSame(
			'<img src="https://ex.com/up/repl.jpg" srcset="https://ex.com/up/repl-150x150.jpg 150w, https://ex.com/up/repl.jpg 800w">',
			$replacer->replace( $html )
		);
	}

	public function test_returns_html_unchanged_when_no_brand(): void {
		$html = '<img src="https://ex.com/up/orig.jpg">';

		$this->assertSame( $html, $this->make_replacer( null )->replace( $html ) );
	}

	public function test_returns_html_unchanged_when_map_empty(): void {
		$html = '<img src="https://ex.com/up/orig.jpg">';

		$this->assertSame( $html, $this->make_replacer( 5, array() )->replace( $html ) );
	}

	public function test_longer_overlapping_url_keys_are_replaced_before_their_prefixes(): void {
		$replacer = $this->make_replacer(
			5,
			array(
				'https://ex.com/up/orig.jpg?v=2' => 'https://ex.com/up/repl-v2.jpg',
				'https://ex.com/up/orig.jpg'     => 'https://ex.com/up/repl.jpg',
			)
		);

		$html = '<img src="https://ex.com/up/orig.jpg?v=2"><img src="https://ex.com/up/orig.jpg">';

		$this->assertSame(
			'<img src="https://ex.com/up/repl-v2.jpg"><img src="https://ex.com/up/repl.jpg">',
			$replacer->replace( $html )
		);
	}
}
