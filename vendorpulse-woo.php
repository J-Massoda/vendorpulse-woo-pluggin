<?php
/**
 * Plugin Name: VendorPulse for WooCommerce
 * Description: An operations queue for WooCommerce orders that need attention.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * WC requires at least: 8.2
 * License: GPL-2.0-or-later
 * Author: Jean Emilien Massoda
 * Text Domain: vendorpulse-woo
 */

defined( 'ABSPATH' ) || exit;

define( 'VENDORPULSE_VERSION', '1.0.0' );
define( 'VENDORPULSE_PATH', plugin_dir_path( __FILE__ ) );
require_once VENDORPULSE_PATH . 'includes/class-vendorpulse-rules.php';
require_once VENDORPULSE_PATH . 'includes/class-vendorpulse-woo.php';

add_action( 'before_woocommerce_init', static function (): void {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );
register_activation_hook( __FILE__, array( 'VendorPulse_Woo', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VendorPulse_Woo', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'VendorPulse_Woo', 'init' ) );
