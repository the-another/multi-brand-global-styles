<?php
declare(strict_types=1);

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Tests\ContentVariables;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables\VariableParser;

#[CoversClass( VariableParser::class )]
class VariableParserTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private VariableParser $parser;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->parser = new VariableParser();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_parses_key_value_lines(): void {
		$raw = "name = Acme Auctions\nphone = 555-0100";

		$this->assertSame(
			array(
				'name'  => 'Acme Auctions',
				'phone' => '555-0100',
			),
			$this->parser->parse( $raw )
		);
	}

	public function test_lowercases_and_strips_invalid_characters_from_keys(): void {
		$raw = "Brand Name = Acme";

		$this->assertSame( array( 'brandname' => 'Acme' ), $this->parser->parse( $raw ) );
	}

	public function test_ignores_lines_without_equals_sign(): void {
		$raw = "not a variable line\nname = Acme";

		$this->assertSame( array( 'name' => 'Acme' ), $this->parser->parse( $raw ) );
	}

	public function test_ignores_lines_with_empty_key(): void {
		$raw = " = orphan value\nname = Acme";

		$this->assertSame( array( 'name' => 'Acme' ), $this->parser->parse( $raw ) );
	}

	public function test_value_may_contain_equals_signs(): void {
		$raw = 'formula = a=b+c';

		$this->assertSame( array( 'formula' => 'a=b+c' ), $this->parser->parse( $raw ) );
	}

	public function test_returns_empty_array_for_blank_input(): void {
		$this->assertSame( array(), $this->parser->parse( "\n \n" ) );
	}
}
