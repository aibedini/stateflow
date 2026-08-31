<?php
/**
 * Admin requirements notice for unsupported environments.
 *
 * Shown only to administrators who can activate plugins, only in wp-admin,
 * only when the environment is unsupported. The frontend never renders it.
 *
 * @package StateFlow
 */

declare( strict_types = 1 );

namespace StateFlow\Admin;

use StateFlow\Infrastructure\Environment;

/**
 * Renders the "requirements not met" admin notice.
 */
final class RequirementsNotice {

	/**
	 * Register the admin_notices listener. Called only when the environment
	 * is unsupported.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render the notice.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: minimum PHP version, 2: minimum WordPress version, 3: minimum WooCommerce version. */
			__( 'StateFlow requires PHP %1$s or newer, WordPress %2$s or newer and WooCommerce %3$s or newer. Please update your stack or deactivate StateFlow.', 'stateflow' ),
			Environment::MIN_PHP,
			Environment::MIN_WORDPRESS,
			Environment::MIN_WOOCOMMERCE
		);

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
