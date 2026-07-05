<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Urls;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\RequestAuthority;

#[CoversClass( RequestAuthority::class )]
class RequestAuthorityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_HOST'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_lowercased_authority(): void {
		$_SERVER['HTTP_HOST'] = 'Brand.COM';
		$this->assertSame( 'brand.com', RequestAuthority::current() );
	}

	public function test_preserves_port(): void {
		$_SERVER['HTTP_HOST'] = 'brand.com:8080';
		$this->assertSame( 'brand.com:8080', RequestAuthority::current() );
	}

	public function test_rejects_invalid_host(): void {
		$_SERVER['HTTP_HOST'] = 'bad host!';
		$this->assertSame( '', RequestAuthority::current() );
	}

	public function test_missing_host_returns_empty(): void {
		$this->assertSame( '', RequestAuthority::current() );
	}
}
