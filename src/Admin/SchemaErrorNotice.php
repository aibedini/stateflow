<?php
/**
 * Admin notice for incomplete StateFlow database setup (SF-002 §16).
 *
 * One concise, capability-gated error notice; no raw SQL, no stack traces,
 * no merchant-facing jargon. Diagnostic detail stays out of the markup.
 *
 * @package StateFlow\Admin
 */

declare( strict_types = 1 );

namespace StateFlow\Admin;

/**
 * Renders the schema-setup error notice.
 */
final class SchemaErrorNotice {

	/**
	 * Register the admin_notices listener (called on verified migration
	 * failure during initialize()/activate()).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render the notice. Capability-gated, admin-only.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'StateFlow database setup is incomplete. Product state features are disabled until the setup succeeds — normal WooCommerce behavior is unaffected. Please contact support if this notice persists.', 'stateflow' )
		);
	}
}
