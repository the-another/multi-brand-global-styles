<?php
/**
 * SEO Sitemap XML Subscription
 *
 * @package MultiBrandGlobalStyles
 * @since 0.6.1
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Seo;

use TheAnother\Plugin\MultiBrandGlobalStyles\HookManager;
use TheAnother\Plugin\MultiBrandGlobalStyles\Urls\HostRewriter;

/**
 * Class SitemapXmlSubscription
 *
 * Subscribes HostRewriter to The Another SEO's `taseo_sitemap_xml` egress
 * filter, but only on requests where the rewrite would actually change a byte.
 *
 * The subscription is not free on the other side: that plugin serves a sitemap
 * chunk by streaming the pre-built file, and abandons streaming for a whole
 * read-into-memory whenever `has_filter( 'taseo_sitemap_xml' )` is true. That
 * check runs BEFORE the callback, so an early return inside the callback
 * cannot recover the streaming path — subscribing unconditionally buys the
 * expensive mode on every sitemap request, including the ones with nothing to
 * rewrite (no Brand opted in, or the crawler is on the canonical host).
 *
 * Hence the deferral: registration happens on `template_redirect` at priority
 * -1, the last moment before that plugin serves at priority 0, when the
 * browsed host is known and HostRewriter can answer for THIS request.
 *
 * Inert when The Another SEO is absent: nothing applies the filter, so a
 * subscription simply never fires.
 *
 * @since 0.6.1
 */
class SitemapXmlSubscription {

	/**
	 * Hook manager.
	 *
	 * @var HookManager
	 */
	private HookManager $hooks;

	/**
	 * Host rewriter.
	 *
	 * @var HostRewriter
	 */
	private HostRewriter $host_rewriter;

	/**
	 * Constructor.
	 *
	 * @param HookManager  $hooks         Hook manager.
	 * @param HostRewriter $host_rewriter Host rewriter service.
	 */
	public function __construct( HookManager $hooks, HostRewriter $host_rewriter ) {
		$this->hooks         = $hooks;
		$this->host_rewriter = $host_rewriter;
	}

	/**
	 * Subscribe to the sitemap XML egress filter, when this request has
	 * something to rewrite. Hooked to `template_redirect` at priority -1.
	 *
	 * @since 0.6.1
	 *
	 * @return void
	 */
	public function maybe_subscribe(): void {
		if ( ! $this->host_rewriter->would_rewrite() ) {
			return;
		}

		$this->hooks->register_filter(
			'taseo_sitemap_xml',
			array( $this->host_rewriter, 'filter_taseo_sitemap_xml' )
		);
	}
}
