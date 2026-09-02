<?php
/**
 * Service-gate decision tests (SF-002.1 §1).
 *
 * The invariant: product services may register ONLY when
 * MigrationResult::is_success() === true; the admin error notice is a
 * separate decision shown only on verified migration failure. Tested
 * table-driven over every MigrationResult outcome — no fake production
 * services are needed to prove the gate.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StateFlow\Infrastructure\Database\MigrationResult;
use StateFlow\Plugin;

/**
 * Migration-result → service-gate decisions.
 */
final class ServiceGateTest extends TestCase {

	/**
	 * The complete gate truth table over every outcome.
	 *
	 * @return void
	 */
	public function test_gate_truth_table(): void {
		$cases = array(
			'success (migrated)'                  => array(
				'result'  => MigrationResult::migrated(),
				'service' => true,
				'notice'  => false,
			),
			'success (already current fast path)' => array(
				'result'  => MigrationResult::already_current(),
				'service' => true,
				'notice'  => false,
			),
			'locked'                              => array(
				'result'  => MigrationResult::locked(),
				'service' => false,
				'notice'  => false,
			),
			'db unavailable'                      => array(
				'result'  => MigrationResult::db_unavailable(),
				'service' => false,
				'notice'  => false,
			),
			'verified migration failure'          => array(
				'result'  => MigrationResult::failed( array( 'structural problem' ) ),
				'service' => false,
				'notice'  => true,
			),
		);

		foreach ( $cases as $label => $case ) {
			$this->assertSame(
				$case['service'],
				Plugin::services_may_register( $case['result'] ),
				"Service gate is wrong for outcome: {$label}."
			);

			$this->assertSame(
				$case['notice'],
				Plugin::should_show_schema_error_notice( $case['result'] ),
				"Notice decision is wrong for outcome: {$label}."
			);
		}
	}

	/**
	 * Gate and notice decisions are independent: a locked request must
	 * never reach the service gate AND must never show the error notice.
	 *
	 * @return void
	 */
	public function test_locked_request_never_reaches_service_gate_or_notice(): void {
		$result = MigrationResult::locked();

		$this->assertFalse( Plugin::services_may_register( $result ) );
		$this->assertFalse( Plugin::should_show_schema_error_notice( $result ) );
	}

	/**
	 * A database-unavailable outcome never reaches the service gate.
	 *
	 * @return void
	 */
	public function test_db_unavailable_never_reaches_service_gate(): void {
		$result = MigrationResult::db_unavailable();

		$this->assertFalse( Plugin::services_may_register( $result ) );
		$this->assertFalse( Plugin::should_show_schema_error_notice( $result ) );
	}

	/**
	 * A failed migration never reaches the service gate but shows the
	 * notice.
	 *
	 * @return void
	 */
	public function test_failed_migration_never_reaches_service_gate(): void {
		$result = MigrationResult::failed( array( 'missing index' ) );

		$this->assertFalse( Plugin::services_may_register( $result ) );
		$this->assertTrue( Plugin::should_show_schema_error_notice( $result ) );
	}

	/**
	 * A successful migration reaches the service gate without a notice.
	 *
	 * @return void
	 */
	public function test_successful_migration_reaches_service_gate(): void {
		$this->assertTrue( Plugin::services_may_register( MigrationResult::migrated() ) );
		$this->assertFalse( Plugin::should_show_schema_error_notice( MigrationResult::migrated() ) );
	}
}
