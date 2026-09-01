<?php
/**
 * Product identity consistency tests (SF-001.1 item 1).
 *
 * StateFlow manages WooCommerce PRODUCT / VARIATION SALES STATES — not
 * "order states". These tests keep the canonical definition consistent and
 * keep misleading phrases out of the identity-bearing surfaces.
 *
 * @package StateFlow\Tests\Unit
 */

declare( strict_types = 1 );

namespace StateFlow\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Canonical product definition enforcement.
 */
final class ProductIdentityTest extends TestCase {

	/**
	 * The single canonical product definition.
	 *
	 * @return string
	 */
	private function canonical(): string {
		return 'StateFlow adds an explainable sales-state layer to WooCommerce products and variations without mutating their canonical price or inventory data.';
	}

	/**
	 * Identity-bearing files and the canonical marker each must carry.
	 *
	 * @return array<string, string>
	 */
	private function surfaces(): array {
		$root = dirname( __DIR__, 2 );

		return array(
			'stateful header'   => $root . '/stateflow.php',
			'composer metadata' => $root . '/composer.json',
			'README'            => $root . '/README.md',
			'AGENTS'            => $root . '/AGENTS.md',
			'architecture doc'  => $root . '/docs/ARCHITECTURE.md',
		);
	}

	/**
	 * Phrases that must never describe the product again.
	 *
	 * @return array<int, string>
	 */
	private function forbidden_phrases(): array {
		return array(
			'order state automation',
			'order-state automation',
			'Order state automation',
			'Order State Automation',
		);
	}

	/**
	 * Every identity-bearing surface exists (guards accidental moves).
	 *
	 * @return void
	 */
	public function test_identity_surfaces_exist(): void {
		foreach ( $this->surfaces() as $label => $path ) {
			$this->assertFileExists( $path, $label );
		}
	}

	/**
	 * The canonical definition appears verbatim in the README, the
	 * architecture doc and the composer description source of truth.
	 *
	 * @return void
	 */
	public function test_canonical_definition_is_present(): void {
		$canonical = $this->canonical();

		$this->assertStringContainsString( $canonical, (string) file_get_contents( dirname( __DIR__, 2 ) . '/README.md' ), 'README' );
		$this->assertStringContainsString( $canonical, (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/ARCHITECTURE.md' ), 'docs/ARCHITECTURE.md' );
	}

	/**
	 * The plugin header description carries the canonical definition
	 * (case-insensitive, wrapped for the header).
	 *
	 * @return void
	 */
	public function test_plugin_header_uses_canonical_definition(): void {
		$header = (string) file_get_contents( dirname( __DIR__, 2 ) . '/stateflow.php' );

		$this->assertSame( 1, preg_match( '/^\s*\*\s*Description:\s*(.+)$/m', $header, $matches ), 'Header must declare a Description.' );

		$raw = $matches[1] ?? '';

		$description = trim( preg_replace( '/\s+/', ' ', $raw ) ?? '' );

		$this->assertSame( $this->canonical(), $description, 'Header Description must be the canonical definition.' );
	}

	/**
	 * Composer metadata must not describe an order-state product.
	 *
	 * @return void
	 */
	public function test_composer_description_is_canonical(): void {
		$composer = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), true );

		$this->assertIsArray( $composer );
		$this->assertSame( $this->canonical(), $composer['description'] ?? '', 'composer.json description must be the canonical definition.' );
	}

	/**
	 * No forbidden phrase remains in any identity surface.
	 *
	 * @return void
	 */
	public function test_forbidden_phrases_are_gone(): void {
		foreach ( $this->surfaces() as $label => $path ) {
			$contents = (string) file_get_contents( $path );

			foreach ( $this->forbidden_phrases() as $phrase ) {
				$this->assertStringNotContainsString( $phrase, $contents, sprintf( '%s must not use "%s".', $label, $phrase ) );
			}
		}
	}
}
