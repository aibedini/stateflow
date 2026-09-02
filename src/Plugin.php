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
	 * SF-002.1 §1 invariant: product services may register ONLY when
	 * MigrationResult::is_success() is true. Two independent questions:
	 * "should product services run?" (gated below) and "should an admin
	 * error notice be shown?" (only on verified migration failure).
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

		if ( ! self::services_may_register( $result ) ) {
			// Any non-success outcome keeps product services OFF for this
			// request. The notice decision is separate:
			// - locked / db-unavailable: transient contention or harness
			// conditions; normal WooCommerce behavior wins, no scary
			// merchant error notice.
			// - verified migration failure: one concise capability-gated
			// error notice (SF-002 §16).
			if ( self::should_show_schema_error_notice( $result ) ) {
				$this->schema_error_notice->register();
			}

			return;
		}

		// Service registration point for SF-003 and later tickets. Reached
		// ONLY with is_success() === true (success covers the already-
		// current fast path), i.e. the schema is verified current.
	}

	/**
	 * SF-002.1 §1 invariant, made explicit and unit-testable: product
	 * services may register only when the migration outcome is a success
	 * (which covers the already-current fast path).
	 *
	 * @param MigrationResult $result Migration outcome.
	 * @return bool
	 */
	public static function services_may_register( MigrationResult $result ): bool {
		return $result->is_success();
	}

	/**
	 * Whether a schema error notice should be shown: only on a verified
	 * migration failure — never for transient lock contention or a missing
	 * database layer.
	 *
	 * @param MigrationResult $result Migration outcome.
	 * @return bool
	 */
	public static function should_show_schema_error_notice( MigrationResult $result ): bool {
		return ! $result->is_success()
			&& ! $result->was_locked()
			&& ! $result->was_db_unavailable();
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
