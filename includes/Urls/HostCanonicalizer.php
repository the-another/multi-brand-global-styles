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

		// Loop guard. If WordPress's own Site Address (home/siteurl) is the same
		// registrable domain as the browsed host but sits in the OPPOSITE www/apex
		// form, redirecting here would fight core's redirect_canonical — and any
		// web-server redirect that follows the Site Address — bouncing the visitor
		// back and forth forever. Defer to the Site Address (the operator's declared
		// canonical) instead of looping. Brands on a different domain than the
		// install are unaffected (their Site Address form is irrelevant).
		if ( $this->site_address_opposes( $authority, $form ) ) {
			return null;
		}

		$scheme           = $settings->url_rewrite_force_https() || is_ssl() ? 'https' : 'http';
		$target_authority = HostForm::apply( $authority, $form );
		$request_uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return $scheme . '://' . $target_authority . $request_uri;
	}

	/**
	 * Whether WordPress's Site Address canonicalizes the browsed host's own domain
	 * to the OPPOSITE www/apex form. When true, issuing our redirect would fight
	 * core's canonical redirect (and a Site-Address-following web server), so the
	 * caller must not redirect.
	 *
	 * Only the install's own domain is considered: a Site Address host whose apex
	 * form differs from the browsed host's apex form is a different domain and has
	 * no bearing on this Brand's canonicalization.
	 *
	 * @param string $authority Browsed authority (host[:port]).
	 * @param string $form      Target form ('www' or 'apex').
	 * @return bool True when the Site Address opposes the target form on the same domain.
	 */
	private function site_address_opposes( string $authority, string $form ): bool {
		$browsed_host = strtolower( explode( ':', $authority, 2 )[0] );
		$browsed_apex = HostForm::to_apex( $browsed_host );

		foreach ( array( get_option( 'home' ), get_option( 'siteurl' ) ) as $url ) {
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}

			$parts = wp_parse_url( $url );

			if ( empty( $parts['host'] ) ) {
				continue;
			}

			$site_host = strtolower( $parts['host'] );

			// Different registrable domain — irrelevant to this Brand's form.
			if ( HostForm::to_apex( $site_host ) !== $browsed_apex ) {
				continue;
			}

			// Same domain: if the Site Address is NOT in the target form, it is in
			// the opposite form, so core/the server will bounce back — defer.
			if ( ! HostForm::matches( $site_host, $form ) ) {
				return true;
			}
		}

		return false;
	}
}
