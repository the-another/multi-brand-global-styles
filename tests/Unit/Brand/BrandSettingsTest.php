<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Brand;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandSettings;

#[CoversClass( BrandSettings::class )]
class BrandSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_hydrates_full_settings_array(): void {
		$settings = BrandSettings::from_meta(
			array(
				'rules'                 => array( 'auctionbill.com', 'site.com/farm' ),
				'variables'             => array( 'name' => 'Acme' ),
				'is_default'            => true,
				'identity'              => array(
					'logo_id' => 12,
					'icon_id' => 13,
					'title'   => 'Acme Co',
					'tagline' => 'Deals',
				),
				'image_map'             => array( 10 => 20 ),
				'image_url_map'         => array( 'https://ex.com/a.jpg' => 'https://ex.com/b.jpg' ),
				'global_styles_post_id' => 42,
				'url_rewrite'           => array(
					'enabled'     => true,
					'force_https' => true,
				),
			)
		);

		$this->assertSame( array( 'auctionbill.com', 'site.com/farm' ), $settings->rules() );
		$this->assertSame( array( 'name' => 'Acme' ), $settings->variables() );
		$this->assertTrue( $settings->is_default() );
		$this->assertSame(
			array(
				'logo_id' => 12,
				'icon_id' => 13,
				'title'   => 'Acme Co',
				'tagline' => 'Deals',
			),
			$settings->identity()
		);
		$this->assertSame( array( 10 => 20 ), $settings->image_map() );
		$this->assertSame( array( 'https://ex.com/a.jpg' => 'https://ex.com/b.jpg' ), $settings->image_url_map() );
		$this->assertSame( 42, $settings->global_styles_post_id() );
		$this->assertTrue( $settings->url_rewrite_enabled() );
		$this->assertTrue( $settings->url_rewrite_force_https() );
	}

	public function test_hydrates_safe_defaults_from_non_array_meta(): void {
		foreach ( array( '', false, null, 'corrupt', 42 ) as $raw ) {
			$settings = BrandSettings::from_meta( $raw );

			$this->assertSame( array(), $settings->rules() );
			$this->assertSame( array(), $settings->variables() );
			$this->assertFalse( $settings->is_default() );
			$this->assertSame( array(), $settings->identity() );
			$this->assertSame( array(), $settings->image_map() );
			$this->assertSame( array(), $settings->image_url_map() );
			$this->assertNull( $settings->global_styles_post_id() );
			$this->assertFalse( $settings->url_rewrite_enabled() );
			$this->assertFalse( $settings->url_rewrite_force_https() );
		}
	}

	public function test_hydrates_safe_defaults_from_wrong_typed_keys(): void {
		$settings = BrandSettings::from_meta(
			array(
				'rules'                 => 'not-a-list',
				'variables'             => 42,
				'is_default'            => '',
				'identity'              => 'junk',
				'image_map'             => null,
				'image_url_map'         => false,
				'global_styles_post_id' => array( 'nope' ),
				'url_rewrite'           => 'on',
			)
		);

		$this->assertSame( array(), $settings->rules() );
		$this->assertSame( array(), $settings->variables() );
		$this->assertFalse( $settings->is_default() );
		$this->assertSame( array(), $settings->identity() );
		$this->assertSame( array(), $settings->image_map() );
		$this->assertSame( array(), $settings->image_url_map() );
		$this->assertNull( $settings->global_styles_post_id() );
		$this->assertFalse( $settings->url_rewrite_enabled() );
		$this->assertFalse( $settings->url_rewrite_force_https() );
	}

	public function test_filters_junk_entries_inside_collections(): void {
		$settings = BrandSettings::from_meta(
			array(
				'rules'         => array( 'site.com', 42, '', array( 'x' ) ),
				'variables'     => array( 'name' => 'Acme', 5 => 'numeric-key', 'obj' => array( 'x' ), '' => 'empty-key' ),
				'identity'      => array(
					'logo_id' => '12',
					'icon_id' => 0,
					'title'   => '',
					'tagline' => 'Deals',
					'rogue'   => 'x',
				),
				'image_map'     => array( 10 => 20, 0 => 30, 40 => 0, '11' => '22' ),
				'image_url_map' => array( 'https://a' => 'https://b', 7 => 'https://c' ),
			)
		);

		$this->assertSame( array( 'site.com' ), $settings->rules() );
		$this->assertSame( array( 'name' => 'Acme' ), $settings->variables() );
		$this->assertSame(
			array(
				'logo_id' => 12,
				'tagline' => 'Deals',
			),
			$settings->identity()
		);
		$this->assertSame( array( 10 => 20, 11 => 22 ), $settings->image_map() );
		$this->assertSame( array( 'https://a' => 'https://b' ), $settings->image_url_map() );
	}

	public function test_global_styles_post_id_zero_is_null(): void {
		$this->assertNull( BrandSettings::from_meta( array( 'global_styles_post_id' => 0 ) )->global_styles_post_id() );
		$this->assertNull( BrandSettings::from_meta( array( 'global_styles_post_id' => '' ) )->global_styles_post_id() );
		$this->assertSame( 9, BrandSettings::from_meta( array( 'global_styles_post_id' => '9' ) )->global_styles_post_id() );
	}

	public function test_url_rewrite_flags_default_false_when_keys_absent(): void {
		$settings = BrandSettings::from_meta( array( 'url_rewrite' => array() ) );

		$this->assertFalse( $settings->url_rewrite_enabled() );
		$this->assertFalse( $settings->url_rewrite_force_https() );
	}
}
