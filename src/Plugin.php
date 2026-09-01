<?php
/**
 * Plugin orchestrator: lifecycle, service registration point.
 *
 * SF-001 registers no services yet; later tickets add them in initialize()
 * behind the environment guard.
 *
 * @package StateFlow
 */

declare( strict_types = 1 );

namespace StateFlow;

use StateFlow\Admin\RequirementsNotice;
use StateFlow\Infrastructure\Environment;
use StateFlow\WooCommerce\Compatibility;

/**
 * Main plugin instance.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already registered the lifecycle hooks.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Requirements notice renderer (admin only).
	 *
	 * @var RequirementsNotice
	 */
	private RequirementsNotice $requirements_notice;

	/**
	 * WooCommerce compatibility declarations.
	 *
	 * @var Compatibility
	 */
	private Compatibility $compatibility;

	/**
	 * Private constructor: use instance().
	 */
	private function __construct() {
		$this->requirements_notice = new RequirementsNotice();
		$this->compatibility       = new Compatibility();
	}

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register lifecycle hooks. Idempotent: repeated calls never stack
	 * duplicate listeners.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'plugins_loaded', array( $this, 'initialize' ), 20 );
		$this->compatibility->register();

		register_activation_hook( STATEFLOW_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( STATEFLOW_PLUGIN_FILE, array( $this, 'deactivate' ) );
	}

	/**
	 * Runs once WordPress and all plugins are loaded; decides between full
	 * initialization and the degraded mode.
	 *
	 * @return void
	 */
	public function initialize(): void {
		if ( ! Environment::is_supported() ) {
			// Degraded mode: no business logic, admin notice only. The
			// frontend is unaffected (no hooks are registered at all).
			$this->requirements_notice->register();

			return;
		}

		// Service registration point for SF-002 and later tickets.
	}

	/**
	 * Activation callback. Intentionally a no-op at SF-001: there is no
	 * schema or state to create yet. When SF-002 introduces real
	 * activation/migration effects, those effects become the observable
	 * assertions for the lifecycle tests.
	 *
	 * @return void
	 */
	public function activate(): void {
	}

	/**
	 * Deactivation callback. Intentionally a no-op at SF-001.
	 *
	 * @return void
	 */
	public function deactivate(): void {
	}

	/**
	 * Render the requirements notice (public for direct test invocation).
	 *
	 * @return void
	 */
	public function render_requirements_notice(): void {
		$this->requirements_notice->render();
	}
}
