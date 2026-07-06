<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\Urls;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\HostForm;

#[CoversClass( HostForm::class )]
class HostFormTest extends TestCase {

	public function test_to_www_prepends_when_absent(): void {
		$this->assertSame( 'www.brand.com', HostForm::to_www( 'brand.com' ) );
	}

	public function test_to_www_is_noop_when_present(): void {
		$this->assertSame( 'www.brand.com', HostForm::to_www( 'www.brand.com' ) );
	}

	public function test_to_www_preserves_port(): void {
		$this->assertSame( 'www.brand.com:8080', HostForm::to_www( 'brand.com:8080' ) );
	}

	public function test_to_apex_strips_leading_www(): void {
		$this->assertSame( 'brand.com', HostForm::to_apex( 'www.brand.com' ) );
	}

	public function test_to_apex_is_noop_when_absent(): void {
		$this->assertSame( 'brand.com', HostForm::to_apex( 'brand.com' ) );
	}

	public function test_to_apex_preserves_port(): void {
		$this->assertSame( 'brand.com:8080', HostForm::to_apex( 'www.brand.com:8080' ) );
	}

	public function test_subdomain_www_is_prepended_literally(): void {
		$this->assertSame( 'www.beta.brand.com', HostForm::to_www( 'beta.brand.com' ) );
		$this->assertSame( 'beta.brand.com', HostForm::to_apex( 'beta.brand.com' ) );
	}

	public function test_apply_and_matches_are_consistent_inverses(): void {
		// After apply(form), matches(form) is always true (loop-safety guarantee).
		$this->assertTrue( HostForm::matches( HostForm::apply( 'brand.com', 'www' ), 'www' ) );
		$this->assertTrue( HostForm::matches( HostForm::apply( 'www.brand.com', 'apex' ), 'apex' ) );
	}

	public function test_matches_detects_current_form(): void {
		$this->assertTrue( HostForm::matches( 'www.brand.com', 'www' ) );
		$this->assertFalse( HostForm::matches( 'brand.com', 'www' ) );
		$this->assertTrue( HostForm::matches( 'brand.com', 'apex' ) );
		$this->assertFalse( HostForm::matches( 'www.brand.com', 'apex' ) );
	}

	public function test_apply_with_unknown_form_returns_unchanged(): void {
		$this->assertSame( 'brand.com', HostForm::apply( 'brand.com', '' ) );
	}
}
