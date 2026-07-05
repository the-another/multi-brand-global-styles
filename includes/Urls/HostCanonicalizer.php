<?php
/**
 * Host Canonicalizer
 *
 * @package MultiBrandGlobalStyles
 * @since 1.3.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Urls;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandRepository;
use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\BrandResolver;

/**
 * Class HostCanonicalizer
 *
 * For Brands that opt in (URL rewrite enabled + a canonical host form set),
 * 301-redirects visitors whose browsed host is the non-preferred www/apex
 * form to the preferred one. Hooked to `template_redirect` at priority 1 so
 * it runs before PageBuffer opens its buffer and before core's
 * redirect_canonical. Fails open: any unusable condition returns null (no
 * redirect). After the redirect the browsed host IS the preferred form, so
 * HostRewriter's canonical→browsed rewrite follows for free — this class does
 * not touch HTML.
 */
class HostCanonicalizer {

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
	 * Issue the canonical-form redirect when warranted. Hooked to
	 * `template_redirect` @ priority 1.
	 *
	 * @return void
	 */
	public function handle(): void {
		$target = $this->maybe_redirect();

		if ( null === $target ) {
			return;
		}

		wp_redirect( $target, 301 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Cross-host www/apex transform of the already-validated current host; wp_safe_redirect's allowlist would reject it. Target derived solely from the current request, regex-validated — no open-redirect surface.
		exit;
	}

	/**
	 * Decide the 301 target for the current request, or null when no redirect
	 * is warranted. Pure — no side effects.
	 *
	 * @return string|null Absolute redirect URL, or null.
	 */
	public function maybe_redirect(): ?string {
		if ( is_admin() || wp_doing_ajax() || is_feed()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		) {
			return null;
		}

		$brand_id = $this->brand_resolver->resolve_current_request();

		if ( null === $brand_id ) {
			return null;
		}

		$settings = $this->brand_repository->get_settings( $brand_id );

		if ( ! $settings->url_rewrite_enabled() ) {
			return null;
		}

		$form = $settings->url_rewrite_host_form();

		if ( 'www' !== $form && 'apex' !== $form ) {
			return null;
		}

		// No CLI guard needed: template_redirect never fires under wp-cli, and an absent HTTP_HOST yields '' here anyway.
		$authority = RequestAuthority::current();

		if ( '' === $authority || HostForm::matches( $authority, $form ) ) {
			return null;
		}

		$scheme           = $settings->url_rewrite_force_https() || is_ssl() ? 'https' : 'http';
		$target_authority = HostForm::apply( $authority, $form );
		$request_uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return $scheme . '://' . $target_authority . $request_uri;
	}
}
