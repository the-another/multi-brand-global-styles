<?php
/**
 * Variable Parser Service
 *
 * @package MultiBrandGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables;

/**
 * Class VariableParser
 *
 * Parses "key = value" textarea input into a variable map.
 */
class VariableParser {

	/**
	 * Parse "key = value" lines into an associative array.
	 *
	 * @param string $raw Raw textarea contents.
	 * @return array<string, string> Lowercased, alphanumeric+underscore key => trimmed value.
	 */
	public function parse( string $raw ): array {
		$variables = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}

			[ $key, $value ] = array_map( 'trim', explode( '=', $line, 2 ) );
			$key             = strtolower( preg_replace( '/[^a-z0-9_]/i', '', $key ) );

			if ( '' === $key ) {
				continue;
			}

			$variables[ $key ] = $value;
		}

		return $variables;
	}
}
