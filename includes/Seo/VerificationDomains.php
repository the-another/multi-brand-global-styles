<?php
/**
 * SEO Verification Domains Bridge
 *
 * @package MultiBrandGlobalStyles
 * @since 0.5.0
 */

namespace TheAnother\Plugin\MultiBrandGlobalStyles\Seo;

use TheAnother\Plugin\MultiBrandGlobalStyles\Brand\UrlRuleRegistry;

/**
 * Class VerificationDomains
 *
 * Tells The Another SEO which domains this site answers on, so each Brand
 * domain can carry its own webmaster verification codes and tracking IDs
 * instead of sharing the canonical host's.
 *
 * The rule map is already keyed by normalized host and already cached in a
 * transient invalidated on Brand save and trash, so this is a read of work
 * done elsewhere. Normalization, de-duplication and default-first ordering
 * belong to the SEO plugin's own registry — this class only appends.
 *
 * Inert when that plugin is absent: nothing applies the filter, so the
 * callback never runs.
 *
 * @since 0.5.0
 */
class VerificationDomains {

	/**
	 * URL rule registry.
	 *
	 * @var UrlRuleRegistry
	 */
	private UrlRuleRegistry $url_rule_registry;

	/**
	 * Constructor.
	 *
	 * @param UrlRuleRegistry $url_rule_registry URL rule registry service.
	 */
	public function __construct( UrlRuleRegistry $url_rule_registry ) {
		$this->url_rule_registry = $url_rule_registry;
	}

	/**
	 * Append every Brand host to the SEO plugin's verification domain list.
	 *
	 * @since 0.5.0
	 *
	 * @param mixed $domains Hosts collected so far; non-arrays pass through.
	 * @return mixed Hosts with every Brand host appended.
	 */
	public function filter_domains( mixed $domains ): mixed {
		if ( ! is_array( $domains ) ) {
			return $domains;
		}

		return array_merge( $domains, array_keys( $this->url_rule_registry->get_rule_map() ) );
	}
}
