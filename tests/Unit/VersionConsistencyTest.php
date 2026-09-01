<?php
/**
 * Version consistency: runtime constant vs plugin header (SF-001.1 item 2).
 *
 * The runtime bootstrap must define STATEFLOW_VERSION as a cheap constant,
 * never via a per-request filesystem read. These tests pin:
 *
 * 1. the runtime constant equals the Version header, and
 * 2. the production bootstrap contains no header-reading call at all.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use StateFlow\Tests\PluginLoader;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Version SSOT consistency tests.
 */
final class VersionConsistencyTest extends TestCase {

	/**
	 * Load the real bootstrap exactly once per process.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		PluginLoader::load();
	}

	/**
	 * Extract the plugin header Version without WordPress helpers.
	 *
	 * @return string
	 */
	private function header_version(): string {
		$source = (string) file_get_contents( STATEFLOW_PLUGIN_FILE );

		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $source, $matches ),
			'Plugin header must declare a Version.'
		);

		$version = $matches[1] ?? '';

		return $version;
	}

	/**
	 * The runtime constant and the plugin header stay in lockstep.
	 *
	 * @return void
	 */
	public function test_runtime_version_matches_plugin_header(): void {
		$this->assertSame( $this->header_version(), STATEFLOW_VERSION );
	}

	/**
	 * The production bootstrap must not read the plugin file at runtime.
	 *
	 * @return void
	 */
	public function test_bootstrap_defines_version_without_filesystem_read(): void {
		$source = (string) file_get_contents( STATEFLOW_PLUGIN_FILE );

		$this->assertStringNotContainsString( 'get_file_data', $source, 'Runtime bootstrap must not call get_file_data().' );
		$this->assertStringNotContainsString( 'file_get_contents', $source, 'Runtime bootstrap must not read the plugin file at runtime.' );
	}
}
