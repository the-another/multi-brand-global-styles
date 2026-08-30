<?php
/**
 * Block Attribute Substitution Service
 *
 * @package MultiBrandGlobalStyles
 * @since 0.6.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\ContentVariables;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;

/**
 * Class BlockAttributeSubstitutionService
 *
 * Replaces %%brand.*%% tokens in a parsed block's ATTRIBUTES, on the
 * `render_block_data` filter, before the block's render callback runs.
 *
 * VariableSubstitutionService already covers rendered HTML, which is enough for
 * static blocks that carry their markup inline. It is NOT enough for a dynamic
 * block whose callback escapes an attribute on the way out: core's
 * navigation-link callback runs esc_url() on `url`, and esc_url() prepends
 * `http://` to a bare `%%brand.…%%` token because it contains no colon. The
 * buffer pass then substitutes inside that mangled URL, yielding
 * `http://https://dashboard.example.com/login`. Substituting here — before the
 * callback — is the only point at which the attribute is still raw.
 */
class BlockAttributeSubstitutionService {

	/**
	 * Brand resolver.
	 *
	 * @var BrandResolver
	 */
	private BrandResolver $brand_resolver;

	/**
	 * Brand repository.
	 *
	 * @var BrandRepository
	 */
	private BrandRepository $brand_repository;

	/**
	 * Constructor.
	 *
	 * @param BrandResolver   $brand_resolver   Brand resolver service.
	 * @param BrandRepository $brand_repository Brand repository service.
	 */
	public function __construct( BrandResolver $brand_resolver, BrandRepository $brand_repository ) {
		$this->brand_resolver   = $brand_resolver;
		$this->brand_repository = $brand_repository;
	}

	/**
	 * Substitute %%brand.*%% tokens in a parsed block's attributes.
	 *
	 * @param array<string,mixed> $parsed_block Parsed block, as passed to `render_block_data`.
	 * @return array<string,mixed> The block, with known tokens replaced in its attributes.
	 */
	public function filter_block_data( array $parsed_block ): array {
		if ( empty( $parsed_block['attrs'] ) || ! is_array( $parsed_block['attrs'] ) ) {
			return $parsed_block;
		}

		if ( $this->is_authoring_context() ) {
			return $parsed_block;
		}

		$brand_id = $this->brand_resolver->resolve_current_request();

		if ( null === $brand_id ) {
			return $parsed_block;
		}

		$variables = $this->brand_repository->get_variables( $brand_id );

		if ( empty( $variables ) ) {
			return $parsed_block;
		}

		$parsed_block['attrs'] = $this->substitute( $parsed_block['attrs'], $variables );

		return $parsed_block;
	}

	/**
	 * Substitute %%brand.*%% tokens in a navigation block's inner blocks.
	 *
	 * The core/navigation block does not render its children the ordinary way:
	 * WP_Navigation_Block_Renderer::get_markup_for_inner_block() calls
	 * WP_Block::render() on each one directly, bypassing both render_block()
	 * and the parent's inner-content loop — the two places core applies
	 * `render_block_data`. filter_block_data() above therefore never sees a
	 * navigation link, and `block_core_navigation_render_inner_blocks` (core
	 * 6.1+) is the only seam left before those children render.
	 *
	 * Entries are written back as raw parsed arrays rather than mutated in
	 * place: WP_Block::$attributes is lazily derived from parsed_block['attrs']
	 * on first read, so editing an already-instantiated block risks depending on
	 * whether that read has happened yet. WP_Block_List::offsetSet() accepts an
	 * array and re-instantiates it on the next read, which has no such ordering.
	 *
	 * @param mixed $inner_blocks WP_Block_List of the navigation's children.
	 * @return mixed The same list, with known tokens replaced in child attributes.
	 */
	public function filter_navigation_inner_blocks( $inner_blocks ) {
		if ( ! is_iterable( $inner_blocks ) || ! $inner_blocks instanceof \ArrayAccess ) {
			return $inner_blocks;
		}

		if ( $this->is_authoring_context() ) {
			return $inner_blocks;
		}

		$brand_id = $this->brand_resolver->resolve_current_request();

		if ( null === $brand_id ) {
			return $inner_blocks;
		}

		$variables = $this->brand_repository->get_variables( $brand_id );

		if ( empty( $variables ) ) {
			return $inner_blocks;
		}

		foreach ( $inner_blocks as $index => $block ) {
			$parsed = $block->parsed_block ?? null;

			if ( ! is_array( $parsed ) ) {
				continue;
			}

			$inner_blocks[ $index ] = $this->substitute_parsed_block( $parsed, $variables );
		}

		return $inner_blocks;
	}

	/**
	 * Substitute a parsed block's attributes, and those of its children.
	 *
	 * Recursion is on `innerBlocks` so a navigation submenu's items are covered;
	 * they render through the same bypassing path as their parent.
	 *
	 * @param array<string,mixed>   $parsed    Parsed block.
	 * @param array<string, string> $variables Resolved Brand variables.
	 * @return array<string,mixed> The parsed block, with known tokens replaced.
	 */
	private function substitute_parsed_block( array $parsed, array $variables ): array {
		if ( ! empty( $parsed['attrs'] ) && is_array( $parsed['attrs'] ) ) {
			$parsed['attrs'] = $this->substitute( $parsed['attrs'], $variables );
		}

		if ( ! empty( $parsed['innerBlocks'] ) && is_array( $parsed['innerBlocks'] ) ) {
			foreach ( $parsed['innerBlocks'] as $i => $child ) {
				if ( is_array( $child ) ) {
					$parsed['innerBlocks'][ $i ] = $this->substitute_parsed_block( $child, $variables );
				}
			}
		}

		return $parsed;
	}

	/**
	 * Whether this request authors content rather than serving it.
	 *
	 * Mirrors PageBuffer::start_buffer()'s gate, for the same reason: an author
	 * editing the header has to see `%%brand.auctioneer_login_url%%` in the URL
	 * field, not one brand's resolved link silently baked in. The editor renders
	 * dynamic blocks over REST, so that check covers block previews too.
	 *
	 * @return bool True when tokens must be left literal.
	 */
	private function is_authoring_context(): bool {
		return is_admin()
			|| wp_doing_ajax()
			|| is_feed()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Replace tokens in every string within an attribute array.
	 *
	 * Values are inserted RAW, deliberately: unlike the rendered-HTML pass, the
	 * result here is handed back to the block's own render callback, which
	 * escapes it for its context (esc_url, esc_attr, …). Escaping now would
	 * double-encode whatever that callback escapes next.
	 *
	 * Recurses into nested arrays; non-string scalars are left as they are, so a
	 * block's numeric and boolean attributes keep their types.
	 *
	 * @param array<mixed>          $attributes Attribute values.
	 * @param array<string, string> $variables  Resolved Brand variables.
	 * @return array<mixed> Attributes with known tokens replaced.
	 */
	private function substitute( array $attributes, array $variables ): array {
		foreach ( $attributes as $key => $value ) {
			if ( is_string( $value ) ) {
				$attributes[ $key ] = $this->replace_tokens( $value, $variables );
			} elseif ( is_array( $value ) ) {
				$attributes[ $key ] = $this->substitute( $value, $variables );
			}
		}

		return $attributes;
	}

	/**
	 * Replace every known %%brand.*%% token in one string.
	 *
	 * @param string                $value     Attribute value.
	 * @param array<string, string> $variables Resolved Brand variables.
	 * @return string Value with known tokens replaced; unknown tokens left literal.
	 */
	private function replace_tokens( string $value, array $variables ): string {
		return (string) preg_replace_callback(
			'/%%brand\.([a-z0-9_]+)%%/i',
			static function ( array $matches ) use ( $variables ) {
				$key = strtolower( $matches[1] );

				return $variables[ $key ] ?? $matches[0];
			},
			$value
		);
	}
}
