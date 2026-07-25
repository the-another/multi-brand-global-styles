<?php
/**
 * Public API functions
 *
 * @package MultiBrandGlobalStyles
 * @since 0.4.0
 */

use TheAnother\Plugin\MultiBrandGlobalStyles\Container;

if ( ! function_exists( 'mbgs_request_home_url' ) ) {
	/**
	 * The home URL as seen from the domain the visitor is actually browsing.
	 *
	 * Server-side consumers (emails, API payloads — output that never passes
	 * through the rendered-page egresses) call this instead of home_url() to
	 * get a Brand-aware value: `mbgs_request_home_url( home_url() )`. Guard
	 * with `function_exists()` — with this plugin inactive the function does
	 * not exist and home_url() is the correct fallback.
	 *
	 * The scheme and host are swapped to the browsed domain only when the
	 * request's Host+path explicitly matched a configured Brand URL rule
	 * (never the default-Brand fallback or the admin preview override), that
	 * Brand has URL rewrite enabled, and the passed URL's host is the site's
	 * own home/siteurl host; the input scheme is a floor, never downgraded.
	 * See Urls\RequestHomeUrl for the full contract. In every other case the
	 * input comes back unchanged.
	 *
	 * Trust note: the substituted authority derives from the client-supplied
	 * Host header. It is only ever a host that explicitly matched a
	 * configured Brand URL rule — treat the result as untrusted for anything
	 * beyond link/branding selection.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $url URL built for the canonical home, typically
	 *                   home_url(); non-string values pass through unchanged.
	 * @return mixed Brand-aware URL, or the input unchanged.
	 */
	function mbgs_request_home_url( mixed $url ): mixed {
		$container = Container::get_instance();

		$brand_aware = $container->has( 'request_home_url' )
			? $container->get( 'request_home_url' )->filter( $url )
			: $url;

		/**
		 * Filters the Brand-aware home URL before it is returned to the caller.
		 *
		 * Extension point for overriding the computed value; the substitution
		 * itself has already happened (or been declined) at this point.
		 *
		 * @since 0.4.0
		 *
		 * @param mixed $brand_aware The computed Brand-aware URL.
		 * @param mixed $url         The original value passed by the caller.
		 */
		return apply_filters( 'mbgs_request_home_url', $brand_aware, $url );
	}
}
