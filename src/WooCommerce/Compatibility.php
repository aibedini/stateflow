<?php
/**
 * WooCommerce feature-compatibility declarations (HPOS).
 *
 * Uses the official FeaturesUtil declaration mechanism; no direct queries
 * against WooCommerce order storage anywhere in StateFlow.
 *
 * @package StateFlow\WooCommerce
 */

declare( strict_types = 1 );

namespace StateFlow\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Declares WooCommerce feature compatibility.
 */
final class Compatibility {

	/**
	 * Register the declaration hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Declare HPOS (custom order tables) compatibility.
	 *
	 * The class_exists() guard keeps this safe on installations where
	 * WooCommerce exists but never loads the FeaturesUtil class.
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', STATEFLOW_PLUGIN_FILE, true );
	}
}
