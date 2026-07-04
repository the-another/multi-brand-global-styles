<?php
/**
 * Page Buffer
 *
 * @package MultiBrandGlobalStyles
 * @since 1.1.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Rendering;

/**
 * Class PageBuffer
 *
 * The single whole-page output buffer for frontend HTML requests. Applies an
 * ordered list of transformers (variable substitution, then image URL
 * replacement) to the final HTML in one ob_start() — one buffer, N passes,
 * no nesting. Hooked to `template_redirect`.
 */
class PageBuffer {

	/**
	 * Ordered HTML transformers.
	 *
	 * @var array<int, callable(string): string>
	 */
	private array $transformers;

	/**
	 * Constructor.
	 *
	 * @param array<int, callable(string): string> $transformers Ordered transformers.
	 */
	public function __construct( array $transformers ) {
		$this->transformers = $transformers;
	}

	/**
	 * Start the output buffer on frontend HTML requests. Hooked to `template_redirect`.
	 *
	 * @return void
	 */
	public function start_buffer(): void {
		if ( is_admin() || wp_doing_ajax() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		ob_start( array( $this, 'apply' ) );
	}

	/**
	 * Apply every transformer, in order, to the rendered HTML.
	 *
	 * @param string $html Rendered page HTML.
	 * @return string Transformed HTML.
	 */
	public function apply( string $html ): string {
		foreach ( $this->transformers as $transformer ) {
			$html = (string) call_user_func( $transformer, $html );
		}

		return $html;
	}
}
