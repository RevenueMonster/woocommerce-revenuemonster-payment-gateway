<?php
/**
 * Plugin Name: WooCommerce RevenueMonster Payment Gateway
 * Description: Accept all major Malaysia e-wallet, such as TnG eWallet, Boost, Maybank QRPay & credit cards. Fast, seamless, and flexible.
 * Author: RevenueMonster
 * Author URI: https://revenuemonster.my/
 * Version: 1.0.6
 * WC requires at least: 2.6
 * WC tested up to: 4.0.1
 *
 * @package WC_Gateway_RevenueMonster
 */

defined( 'ABSPATH' ) || die( 'Missing global variable ABSPATH' );

add_filter( 'cron_schedules', 'add_cron_minute_interval' );

/**
 * Function add_cron_minute_interval
 *
 * @param array $schedules wp schedules.
 */
function add_cron_minute_interval( $schedules ) {
	if ( ! isset( $schedules['minute'] ) ) {
		$schedules['minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minutes', 'woocommerce-gateway-revenuemonster' ),
		);
	}
	return $schedules;
}
if ( ! wp_next_scheduled( 'pending_orders_requery' ) ) {
	wp_schedule_event( time(), 'minute', 'pending_orders_requery' );
}
add_action( 'pending_orders_requery', array( WC_Gateway_RevenueMonster::class, 'pending_orders_requery_cron' ) );

add_filter( 'woocommerce_payment_gateways', 'wc_revenuemonster_add_to_gateways' );

/**
 *  Function wc_revenuemonster_add_to_gateways
 *
 * @param array $gateways gateways.
 */
function wc_revenuemonster_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_RevenueMonster';
	return $gateways;
}

add_action( 'plugins_loaded', 'wc_gateway_revenuemonster_init', 11 );

/**
 *  Function wc_gateway_revenuemonster_init
 *
 * @throws \Exception Invalid payment status.
 */
function wc_gateway_revenuemonster_init() {
	require_once dirname( __FILE__ ) . '/includes/class-revenuemonster.php';

	/**
	 * Class WC_Gateway_RevenueMonster
	 */
	class WC_Gateway_RevenueMonster extends WC_Payment_Gateway {

		/**
		 * Whether or not logging is enabled
		 *
		 * @var bool
		 */
		public static $log_enabled = false;

		/**
		 * Logger instance
		 *
		 * @var WC_Logger
		 */
		public static $log = false;

		/**
		 * Construct
		 */
		public function __construct() {
			$this->id                 = 'revenuemonster'; // payment gateway plugin ID.
			$this->icon               = $this->get_option( 'logo' ); // URL of the icon that will be displayed on checkout page near your gateway name.
			$this->has_fields         = true; // in case you need a custom credit card form.
			$this->method_title       = 'RevenueMonster Checkout';
			$this->method_description = 'Pay via RevenueMonster Payment Gateway'; // will be displayed on the options page.
			$this->supports           = array(
				'products',
			);

			$this->init_form_fields();
			$this->init_settings();

			// data will show on the checkout page (wordpress/index.php/checkout/).
			$this->title           = $this->get_option( 'title' );
			$this->description     = $this->get_option( 'description' );
			$this->enabled         = $this->get_option( 'enabled' );
			$this->payment_methods = $this->get_option( 'payment_methods' );

			if ( is_admin() ) {
				// This action hook saves the settings.
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			}

			// Register a webhook.
			add_action( 'woocommerce_api_wc_gateway_revenuemonster', array( $this, 'webhook' ) );
		}

		/**
		 * Logging method.
		 *
		 * @param string $message Log message.
		 * @param string $level Optional. Default 'info'. Possible values:
		 *                      emergency|alert|critical|error|warning|notice|info|debug.
		 */
		public static function log( $message, $level = 'info' ) {
			if ( self::$log_enabled ) {
				if ( empty( self::$log ) ) {
					self::$log = wc_get_logger();
				}
				self::$log->log( $level, $message, array( 'source' => 'revenuemonster' ) );
			}
		}

		/**
		 * Function cronjob
		 */
		public static function pending_orders_requery_cron() {

			$pending_orders = wc_get_orders(
				array(
					'limit'  => -1,
					// 'date_created' => '>' . ( time() - 2*24*60*60 ),
					'status' => array( 'on-hold' ),
				)
			);
			$settings       = get_option( 'woocommerce_revenuemonster_settings' );
			$sdk            = RevenueMonster::get_instance(
				array(
					'client_id'     => $settings['client_id'],
					'client_secret' => $settings['client_secret'],
					'private_key'   => $settings['private_key'],
					'public_key'    => $settings['public_key'],
					'version'      => 'stable',
					'is_sandbox'    => filter_var( $settings['sandbox'], FILTER_VALIDATE_BOOLEAN ),
				)
			);
			foreach ( $pending_orders as $order ) {
				if ( $order->get_payment_method() === 'revenuemonster' ) {
					$oid = $order->get_transaction_id();
					try {
						$response = $sdk->query_order( $oid );
						if ( $response && strtoupper( $response->status ) === 'SUCCESS' ) {
							$obj = json_decode( json_encode( $response ), true );
							$order->payment_complete( $obj['transactionId'] );
							$order->set_payment_method( $response->method );
							$order->save();
						} elseif ( $response && strtoupper( $response->status ) === 'FAILED' ) {
							$order->update_status( 'failed', __( 'Payment failed', 'woocommerce-gateway-revenuemonster' ) );
							$order->save();
						}
					} catch ( Exception $e ) {
						if ( strtoupper( $e->getMessage() ) === 'TRANSACTION_NOT_FOUND' && ( filter_var( $settings['auto_cancel'], FILTER_VALIDATE_BOOLEAN ) ) ) {
							$order_id = explode( '-', $oid );
							if ( count( $order_id ) === 2 ) {
								if ( ( time() - intval( $order_id[1] ) ) > 60 * 30 ) {
									$order->update_status( 'failed', __( 'Payment failed', 'woocommerce-gateway-revenuemonster' ) );
									$order->save();
								}
							}
						}
					}
				}
			}
		}

		/**
		 * Function woocommerce settings form fields
		 */
		public function init_form_fields() {
			$this->form_fields = apply_filters(
				'wc_gateway_revenuemonster_form_fields',
				array(
					'enabled'         => array(
						'title'   => __( 'Enable/Disable', 'woocommerce-gateway-revenuemonster' ),
						'type'    => 'checkbox',
						'label'   => __( 'Enable this payment gateway', 'woocommerce-gateway-revenuemonster' ),
						'default' => 'yes',
					),

					'sandbox'         => array(
						'title'   => __( 'Sandbox', 'woocommerce-gateway-revenuemonster' ),
						'type'    => 'checkbox',
						'default' => 'no',
					),
					'auto_cancel'     => array(
						'title'   => __( 'Auto cancel', 'woocommerce-gateway-revenuemonster' ),
						'type'    => 'checkbox',
						'label'   => __( 'Cancel on hold order if transaction not found', 'woocommerce-gateway-revenuemonster' ),
						'default' => 'no',
					),

					'title'           => array(
						'title'       => __( 'Title', 'woocommerce-gateway-revenuemonster' ),
						'type'        => 'text',
						'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'woocommerce-gateway-revenuemonster' ),
						'desc_tip'    => true,
					),

					'description'     => array(
						'title'       => __( 'Description', 'woocommerce-gateway-revenuemonster' ),
						'type'        => 'text',
						'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce-gateway-revenuemonster' ),
						'desc_tip'    => true,
					),

					'logo'            => array(
						'title'       => __( 'Logo', 'woocommerce-gateway-revenuemonster' ),
						'type'        => 'text',
						'description' => __( 'Logo URL', 'woocommerce-gateway-revenuemonster' ),
					),

					'store_id'        => array(
						'title'       => __( 'Store ID', 'woocommerce-gateway-revenuemonster' ),
						'type'        => 'text',
						'description' => __( 'Store ID setup at Revenuemonster', 'woocommerce-gateway-revenuemonster' ),
						'desc_tip'    => true,
					),

					'client_id'       => array(
						'title'       => __( 'Client ID', 'woocommerce-gateway-revenuemonster' ),
						'type'        => 'text',
						'description' => __( 'Client ID setup at Revenuemonster', 'woocommerce-gateway-revenuemonster' ),
						'desc_tip'    => true,
					),

					'client_secret'   => array(
						'title'       => __( 'Client Secret', 'woocommerce-gateway-revenuemonster' ),
						'type'        => 'password',
						'description' => __( 'Client Secret setup at Revenuemonster', 'woocommerce-gateway-revenuemonster' ),
						'desc_tip'    => true,
					),

					'private_key'     => array(
						'title'       => __( 'Client Private Key', 'woocommerce-gateway-revenuemonster' ),
						'type'        => 'textarea',
						'description' => __( 'Instructions that will be added to the thank you page and emails.', 'woocommerce-gateway-revenuemonster' ),
						'default'     => '',
						'desc_tip'    => true,
					),

					'public_key'      => array(
						'title'       => __( 'Server Public Key', 'woocommerce-gateway-revenuemonster' ),
						'type'        => 'textarea',
						'description' => __( 'Instructions that will be added to the thank you page and emails.', 'woocommerce-gateway-revenuemonster' ),
						'default'     => '',
						'desc_tip'    => true,
					),
				)
			);
		}

		/**
		 * Function get_sdk
		 */
		public function get_sdk() {
			return RevenueMonster::get_instance(
				array(
					'client_id'     => $this->get_option( 'client_id' ),
					'client_secret' => $this->get_option( 'client_secret' ),
					'private_key'   => $this->get_option( 'private_key' ),
					'public_key'    => $this->get_option( 'public_key' ),
					'version'      => 'stable',
					'is_sandbox'    => filter_var( $this->get_option( 'sandbox' ), FILTER_VALIDATE_BOOLEAN ),
				)
			);
		}

		/**
		 * Function woocommerce payment logic
		 *
		 * @param string $order_id Order ID.
		 */
		public function process_payment( $order_id ) {
			global $woocommerce;
			$order = new WC_Order( $order_id );

			$oid = $order_id . '-' . time();
			$order->set_transaction_id( $oid );
			$order->save();

			$sdk     = $this->get_sdk();
			$url     = WC()->api_request_url( 'WC_Gateway_RevenueMonster' );
			$payload = array(
				'order'         => array(
					'id'             => strval( $oid ),
					'title'          => 'WooCommerce Order ' . strval( $order_id ),
					'detail'         => strval( $order_id ),
					'additionalData' => '',
					'amount'         => (int) round( floatval( $order->get_total() ) * 100 ),
					'currencyType'   => 'MYR',
				),
				'method' => array(),
				// 'method'        => $this->get_option( 'payment_methods' ),
				'type'          => 'WEB_PAYMENT',
				'storeId'       => strval( $this->get_option( 'store_id' ) ),
				'redirectUrl'   => escape_url( $url ),
				'notifyUrl'     => escape_url( $url ),
				'layoutVersion' => 'v3',
			);

			$response = $sdk->create_order( $payload );

			$order->update_status( 'on-hold', __( 'Awaiting payment', 'woocommerce-gateway-revenuemonster' ) );
			// Reduce stock levels.
			$order->reduce_order_stock();

			// Remove cart.
			$woocommerce->cart->empty_cart();

			return array(
				'result'   => 'success',
				'redirect' => $response->url,
			);
		}

		/**
		 * Function webhook
		 *
		 * @throws \Exception Invalid webhook response.
		 */
		public function webhook() {
			if ( empty( $_GET['orderId'] ) ) {
				wp_die( 'RevenueMonster payment failed', 'RevenueMonster Payment', array( 'response' => 500 ) );
				return;
			}

			$oid      = sanitize_key( wp_unslash( $_GET['orderId'] ) );
			$order_id = explode( '-', $oid );
			if ( count( $order_id ) !== 2 ) {
				wp_die( 'RevenueMonster payment failed', 'RevenueMonster Payment', array( 'response' => 500 ) );
				return;
			}

			$order = new WC_Order( $order_id[0] );
			$sdk   = $this->get_sdk();

			try {
				$response = $sdk->query_order( $oid );

				$order->set_payment_method( $response->method );

				$order->save();

				switch ( strtoupper( $response->status ) ) {
					case 'SUCCESS':
						$obj = json_decode( json_encode( $response ), true );
						$order->payment_complete( $obj['transactionId'] );
						WC()->cart->empty_cart();
						wp_redirect( $order->get_checkout_order_received_url() );
						break;
					default:
						throw new Exception( 'invalid payment status' );
				}
			} catch ( Exception $e ) {
				// https://docs.woocommerce.com/document/managing-orders/.
				// mark as failed.
				$order->update_status( 'failed', __( 'Payment failed', 'woocommerce-gateway-revenuemonster' ) );
				$order->save();
				wp_die( 'RevenueMonster payment failed', 'RevenueMonster Payment', array( 'response' => 500 ) );
			}
		}
	}
}
