<?php
/**
 * Admin Notices
 *
 * @package MultiDomainGlobalStyles
 * @since 1.0.0
 */

namespace TheAnother\Plugin\MultiDomainGlobalStyles\Brand;

/**
 * Class AdminNotices
 *
 * Surfaces the rule-conflict rejection recorded by BrandPostType::save().
 */
class AdminNotices {

	/**
	 * Render pending admin notices. Hooked to `admin_notices`.
	 *
	 * @return void
	 */
	public function render(): void {
		$user_id       = get_current_user_id();
		$transient_key = 'mdgs_rule_conflict_' . $user_id;
		$rejected      = get_transient( $transient_key );

		if ( empty( $rejected ) ) {
			return;
		}

		delete_transient( $transient_key );

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of rejected URL rules */
					__( 'The following URL rules were not saved because they are already assigned to another Brand: %s', 'the-another-multi-domain-global-styles' ),
					implode( ', ', (array) $rejected )
				)
			)
		);
	}
}
