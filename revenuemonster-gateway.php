<?php
/**
 * Plugin Name: WooCommerce RevenueMonster Payment Gateway
 * Plugin URI: https://revenuemonster.my/
 * Description: Accept all major Malaysia e-wallet, such as TnG eWallet, Boost, Maybank QRPay & credit cards. Fast, seamless, and flexible.
 * Author: RevenueMonster
 * Author URI: https://revenuemonster.my/
 * Version: 1.0.8
 * WC requires at least: 3.0
 * WC tested up to: 8.2
 * Requires Plugins: woocommerce
 * Requires at least: 4.7
 * Tested up to: 6.3
 * Requires PHP: 7.1
 * Text Domain: woocommerce-gateway-revenuemonster
 * Domain Path: /languages
 *
 * @package WC_Gateway_RevenueMonster
 */

defined('ABSPATH') || die('Missing global variable ABSPATH');

// Declare compatibility with WooCommerce High Performance Order Storage (HPOS)
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Include the main gateway class
require_once plugin_dir_path(__FILE__) . 'class-wc-gateway-revenuemonster.php';