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
use StateFlow\Admin\SchemaErrorNotice;
use StateFlow\Infrastructure\Database\MigrationResult;
use StateFlow\Infrastructure\Database\MigrationRunner;
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
	 * Schema-setup error notice (admin only).
	 *
	 * @var SchemaErrorNotice
	 */
	private SchemaErrorNotice $schema_error_notice;

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
		$this->schema_error_notice = new SchemaErrorNotice();
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
	 * SF-002 §13: WordPress does not run activation hooks when updating an
	 * already-active plugin, so initialize() re-checks the schema and
	 * safely ensures it is current before any product service registers.
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

		$result = $this->ensure_schema();

		if ( ! $result->is_success() && ! $result->was_locked() && ! $result->was_db_unavailable() ) {
			// Verified migration failure: one concise capability-gated
			// admin notice; product services stay disabled (SF-002 §16).
			$this->schema_error_notice->register();

			return;
		}

		// Service registration point for SF-003 and later tickets. Reached
		// only when the schema is current (or a concurrent migration holds
		// the lock; that request retries on its next initialize()).
	}

	/**
	 * Ensure the StateFlow schema is current. The already-current path is
	 * a cached option read plus an integer comparison — no upgrade.php, no
	 * dbDelta, no table inspection (SF-002 §10/§28).
	 *
	 * @return MigrationResult
	 */
	private function ensure_schema(): MigrationResult {
		$runner = $this->migration_runner();

		if ( null === $runner ) {
			// No usable database layer (never reached in real WordPress;
			// possible in stub-based test harnesses): degrade silently, no
			// user-facing notice, product services stay disabled.
			return MigrationResult::db_unavailable();
		}

		return $runner->ensure_current();
	}

	/**
	 * Migration runner factory. Guards the wpdb global so a broken or
	 * absent database layer can never fatal the storefront (SF-002 §16).
	 *
	 * @return MigrationRunner|null Null when wpdb is unavailable; the
	 *                              caller keeps product services disabled.
	 */
	private function migration_runner(): ?MigrationRunner {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}

		return new MigrationRunner( $wpdb );
	}

	/**
	 * Activation callback. First real production effect (SF-002 §13): on a
	 * supported environment, ensure the StateFlow schema is installed and
	 * current. Re-runnable: the fast path is a version equality check.
	 *
	 * On an unsupported environment nothing schema-related runs (the
	 * degraded-mode behavior from SF-001 remains); when the environment
	 * later becomes supported, initialize() performs the migration.
	 *
	 * @return void
	 */
	public function activate(): void {
		if ( ! Environment::is_supported() ) {
			return;
		}

		$this->ensure_schema();
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
