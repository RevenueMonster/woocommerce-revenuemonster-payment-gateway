<?php
/**
 * Plugin Name: WooCommerce RevenueMonster Payment Gateway
 * Plugin URI: https://revenuemonster.my/
 * Description: Accept all major Malaysia e-wallet, such as TnG eWallet, Boost, Maybank QRPay & credit cards. Fast, seamless, and flexible.
 * Author: RevenueMonster
 * Author URI: https://revenuemonster.my/
 * Version: 1.0.10
 * WC requires at least: 3.0
 * WC tested up to: 8.2
 * Requires Plugins: woocommerce
 * Requires at least: 4.7
 * Requires PHP: 7.1
 * Text Domain: woocommerce-gateway-revenuemonster
 * Domain Path: /languages
 *
 * @package WC_Gateway_RevenueMonster
 */

defined('ABSPATH') || die('Missing global variable ABSPATH');

define('WC_REVENUEMONSTER_FILE', __FILE__);
define('WC_REVENUEMONSTER_PATH', plugin_dir_path(__FILE__));
define('WC_REVENUEMONSTER_URL', plugin_dir_url(__FILE__));

// Load the gateway bootstrap and main class.
require_once WC_REVENUEMONSTER_PATH . 'class-wc-gateway-revenuemonster.php';
