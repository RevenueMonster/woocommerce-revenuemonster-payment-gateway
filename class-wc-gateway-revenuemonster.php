<?php

/**
 * Gateway bootstrap and main class.
 *
 * The plugin header lives in revenuemonster-gateway.php, which defines the
 * WC_REVENUEMONSTER_* constants and then loads this file.
 *
 * @package WC_Gateway_RevenueMonster
 */

defined('ABSPATH') || die('Missing global variable ABSPATH');

defined('WC_REVENUEMONSTER_FILE') || define('WC_REVENUEMONSTER_FILE', __FILE__);
defined('WC_REVENUEMONSTER_PATH') || define('WC_REVENUEMONSTER_PATH', plugin_dir_path(WC_REVENUEMONSTER_FILE));
defined('WC_REVENUEMONSTER_URL') || define('WC_REVENUEMONSTER_URL', plugin_dir_url(WC_REVENUEMONSTER_FILE));

// Declare compatibility with HPOS and the Cart & Checkout Blocks.
add_action('before_woocommerce_init', function () {
	if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WC_REVENUEMONSTER_FILE, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', WC_REVENUEMONSTER_FILE, true);
	}
});

// Register the Cart & Checkout Blocks payment method integration.
add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
	if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
		return;
	}
	require_once WC_REVENUEMONSTER_PATH . 'includes/class-wc-revenuemonster-blocks.php';
	$registry->register(new WC_RevenueMonster_Blocks_Support());
});

add_filter('cron_schedules', 'add_cron_minute_interval');

/**
 * Function add_cron_minute_interval
 *
 * @param array $schedules wp schedules.
 */
function add_cron_minute_interval($schedules)
{
	if (!isset($schedules['minute'])) {
		$schedules['minute'] = array(
			'interval' => 60,
			'display'  => __('Every Minutes', 'woocommerce-gateway-revenuemonster'),
		);
	}
	return $schedules;
}
if (!wp_next_scheduled('pending_orders_requery')) {
	wp_schedule_event(time(), 'minute', 'pending_orders_requery');
}
add_action('pending_orders_requery', array(WC_Gateway_RevenueMonster::class, 'pending_orders_requery_cron'));

add_filter('woocommerce_payment_gateways', 'wc_revenuemonster_add_to_gateways');

/**
 *  Function wc_revenuemonster_add_to_gateways
 *
 * @param array $gateways gateways.
 */
function wc_revenuemonster_add_to_gateways($gateways)
{
	$gateways[] = 'WC_Gateway_RevenueMonster';
	return $gateways;
}

add_action('plugins_loaded', 'wc_gateway_revenuemonster_init', 11);

/**
 *  Function wc_gateway_revenuemonster_init
 *
 * @throws \Exception Invalid payment status.
 */
function wc_gateway_revenuemonster_init()
{
	require_once dirname(__FILE__) . '/includes/class-revenuemonster.php';

	if (!function_exists('is_plugin_active')) {
		require_once ABSPATH . '/wp-admin/includes/plugin.php';
	}

	if (!is_plugin_active('woocommerce/woocommerce.php')) {
		add_action(
			'admin_notices',
			function () {
				/* translators: 1. URL link. */
				echo '<div class="error"><p><strong>' . sprintf(esc_html__('Revenue Monster Payment Gateway requires WooCommerce to be installed and active. You can download %s here.', 'woocommerce-gateway-revenuemonster'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
			}
		);
		return;
	}

	/**
	 * Class WC_Gateway_RevenueMonster
	 */
	class WC_Gateway_RevenueMonster extends WC_Payment_Gateway
	{

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
		 * Per-request memo for the live Direct Card capability check.
		 *
		 * @var bool|null
		 */
		protected $card_capability_live = null;

		/**
		 * Construct
		 */
		public function __construct()
		{
			$this->id                 = 'revenuemonster'; // payment gateway plugin ID.
			$this->icon               = $this->get_option('logo'); // URL of the icon that will be displayed on checkout page near your gateway name.
			$this->has_fields         = true; // in case you need a custom credit card form.
			$this->method_title       = 'RevenueMonster Checkout';
			$this->method_description = 'Pay via RevenueMonster Payment Gateway'; // will be displayed on the options page.
			$this->supports           = array(
				'products',
			);

			$this->init_form_fields();
			$this->init_settings();

			// data will show on the checkout page (wordpress/index.php/checkout/).
			$this->title           = $this->get_option('title');
			$this->description     = $this->get_option('description');
			$this->enabled         = $this->get_option('enabled');
			$this->payment_methods = $this->get_option('payment_methods');

			if (is_admin()) {
				// This action hook saves the settings.
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
				// Direct Card capability warning, rendered outside #mainform so it
				// does not interfere with WooCommerce's unsaved-changes detection.
				add_action('admin_notices', array($this, 'render_card_capability_notice'));
			}

			// Register a webhook.
			add_action('woocommerce_api_wc_gateway_revenuemonster', array($this, 'webhook'));

			// Direct Card Checkout assets (self-gating)
			add_action('wp_enqueue_scripts', array($this, 'enqueue_direct_card_assets'));
		}

		/**
		 * Whether the Card Checkout Mode setting is "Direct Card Checkout".
		 *
		 * @return bool
		 */
		public function is_direct_card()
		{
			return 'direct' === $this->get_option('card_checkout_mode', 'hosted');
		}

		/**
		 * Check the Direct Card form should actually be offered
		 *
		 * @return bool
		 */
		public function direct_card_available()
		{
			$available = $this->is_direct_card() && $this->card_capability_active();

			return (bool) apply_filters('wc_revenuemonster_direct_card_available', $available, $this);
		}

		/**
		 * Raw stored Direct Card capability flag (no API call).
		 *
		 * @return bool
		 */
		protected function stored_card_capability()
		{
			return 'yes' === $this->get_option('card_online_active');
		}

		/**
		 * Whether Direct Card Checkout is usable right now.
		 *
		 * Verifies against the Revenue Monster account live (once per request)
		 * so that toggling Direct Card Payment on the RM dashboard takes effect
		 * on the next checkout without re-saving the gateway settings. Falls
		 * back to the stored flag when the API call fails.
		 *
		 * @return bool
		 */
		protected function card_capability_active()
		{
			if (null === $this->card_capability_live) {
				$this->card_capability_live = $this->refresh_card_capability();
			}

			return $this->card_capability_live;
		}

		/**
		 * Check the RM account and persist whether Direct Card Checkout is
		 * usable. Runs on settings save and on the first capability check of
		 * each request.
		 * Requires BOTH: Card (Online) active in the subscription status, and an
		 * active merchant with directCardPayment enabled.
		 *
		 * @return bool
		 */
		protected function refresh_card_capability()
		{
			if (!$this->is_direct_card() || !$this->get_option('client_id')) {
				return $this->stored_card_capability();
			}

			try {
				$sdk       = $this->get_sdk();
				$available = $this->subscription_has_active_card($sdk->get_subscription_status())
					&& $this->merchant_allows_direct_card($sdk->get_merchant());
			} catch (Exception $e) {
				self::log('Direct Card capability check failed: ' . $e->getMessage(), 'warning');

				return $this->stored_card_capability();
			}

			if ($available !== $this->stored_card_capability()) {
				$this->update_option('card_online_active', $available ? 'yes' : 'no');
			}

			return $available;
		}

		/**
		 * Merchant is active AND has Direct Card Payment enabled.
		 *
		 * @param object|null $item Merchant profile from get_merchant().
		 * @return bool
		 */
		protected function merchant_allows_direct_card($item)
		{
			return is_object($item)
				&& !empty($item->isActive)
				&& isset($item->subscription)
				&& !empty($item->subscription->directCardPayment);
		}

		/**
		 * Look for item.online["MASTERCARD.MALAYSIA"] === "ACTIVE" (case-insensitive).
		 *
		 * @param object|null $item Subscription status item.
		 * @return bool
		 */
		protected function subscription_has_active_card($item)
		{
			$method = apply_filters('wc_revenuemonster_card_capability_method', 'MASTERCARD.MALAYSIA');

			if (!is_object($item) || !isset($item->online)) {
				return false;
			}

			foreach ((array) $item->online as $name => $status) {
				if (0 === strcasecmp($name, $method) && 0 === strcasecmp((string) $status, 'ACTIVE')) {
					return true;
				}
			}

			return false;
		}

		/**
		 * The pay option chosen on checkout when Direct Card mode is on.
		 *
		 * @return string 'card' or 'hosted'.
		 */
		protected function get_posted_pay_mode()
		{
			$mode = isset($_POST['rm_pay_mode']) ? sanitize_key(wp_unslash($_POST['rm_pay_mode'])) : '';

			return in_array($mode, array('card', 'hosted'), true) ? $mode : 'hosted';
		}

		/**
		 * Enqueue the Direct Card Checkout script/style on the checkout page only.
		 */
		public function enqueue_direct_card_assets()
		{
			if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
				return;
			}
			if ('yes' !== $this->enabled || !$this->direct_card_available()) {
				return;
			}

			$base = plugins_url('assets/', __FILE__);
			$ver  = WC_REVENUEMONSTER_VERSION;

			wp_enqueue_style('rm-direct-card', $base . 'css/rm-direct-card.css', array(), $ver);
			wp_enqueue_script('rm-direct-card', $base . 'js/rm-direct-card.js', array('jquery', 'wc-checkout'), $ver, true);
			wp_localize_script(
				'rm-direct-card',
				'rmDirectCard',
				array(
					'ajaxUrl' => admin_url('admin-ajax.php'),
					'nonce'   => wp_create_nonce('rm_direct_card'),
					'i18n'    => array(
						'invalidNumber' => __('Please enter a valid card number.', 'woocommerce-gateway-revenuemonster'),
						'invalidName'   => __('Please enter the cardholder name.', 'woocommerce-gateway-revenuemonster'),
						'invalidExpiry' => __('Please enter a valid expiry date.', 'woocommerce-gateway-revenuemonster'),
						'invalidCvv'    => __('Please enter a valid security code.', 'woocommerce-gateway-revenuemonster'),
						'generic'       => __('We could not process your card. Please try again.', 'woocommerce-gateway-revenuemonster'),
						'authInProgress' => __('Completing bank authentication…', 'woocommerce-gateway-revenuemonster'),
						'cancel'        => __('Cancel payment', 'woocommerce-gateway-revenuemonster'),
						'cancelled'     => __('Payment cancelled. You have not been charged.', 'woocommerce-gateway-revenuemonster'),
					),
				)
			);
		}

		/**
		 * Render the payment method fields on checkout.
		 *
		 * Direct Card mode adds a "Card" / "E-wallet / Online Banking" choice; the
		 * card inputs carry no `name` attribute so card data never enters the
		 * WooCommerce checkout POST. Hosted mode shows the description only.
		 */
		public function payment_fields()
		{
			if ($this->description) {
				echo wpautop(wptexturize(wp_kses_post($this->description)));
			}

			if (!$this->direct_card_available()) {
				return;
			}

			?>
			<div class="rm-pay-modes" id="rm-pay-modes">
				<p class="form-row form-row-wide rm-pay-mode">
					<label>
						<input type="radio" name="rm_pay_mode" value="card" checked="checked" />
						<span><?php esc_html_e('Card', 'woocommerce-gateway-revenuemonster'); ?></span>
					</label>
				</p>

				<div class="rm-direct-card" id="rm-direct-card-fields">
					<p class="form-row form-row-wide rm-dc-row">
						<label for="rm-card-name"><?php esc_html_e('Cardholder Name', 'woocommerce-gateway-revenuemonster'); ?> <span class="required">*</span></label>
						<input id="rm-card-name" type="text" autocomplete="cc-name" class="input-text" placeholder="<?php esc_attr_e('Name as printed on card', 'woocommerce-gateway-revenuemonster'); ?>" />
					</p>
					<p class="form-row form-row-wide rm-dc-row">
						<label for="rm-card-number"><?php esc_html_e('Card Number', 'woocommerce-gateway-revenuemonster'); ?> <span class="required">*</span></label>
						<input id="rm-card-number" type="text" inputmode="numeric" autocomplete="cc-number" maxlength="23" class="input-text" placeholder="1234 5678 9012 3456" />
					</p>
					<p class="form-row form-row-wide rm-dc-row">
						<label for="rm-card-expiry"><?php esc_html_e('Expiry (MM / YY)', 'woocommerce-gateway-revenuemonster'); ?> <span class="required">*</span></label>
						<input id="rm-card-expiry" type="text" inputmode="numeric" autocomplete="cc-exp" placeholder="MM / YY" maxlength="7" class="input-text" />
					</p>
					<p class="form-row form-row-wide rm-dc-row">
						<label for="rm-card-cvv"><?php esc_html_e('Security Code / CVV', 'woocommerce-gateway-revenuemonster'); ?> <span class="required">*</span></label>
						<input id="rm-card-cvv" type="text" inputmode="numeric" autocomplete="cc-csc" maxlength="4" class="input-text" placeholder="CVV" />
					</p>
					<div class="rm-direct-card__notice" style="display:none"></div>
				</div>

				<p class="form-row form-row-wide rm-pay-mode">
					<label>
						<input type="radio" name="rm_pay_mode" value="hosted" />
						<span><?php esc_html_e('E-wallet / Online Banking', 'woocommerce-gateway-revenuemonster'); ?></span>
					</label>
				</p>
				<p class="rm-pay-mode__hint"><?php esc_html_e('You will be redirected to Revenue Monster to complete payment.', 'woocommerce-gateway-revenuemonster'); ?></p>
			</div>
			<?php
		}

		/**
		 * Logging method.
		 *
		 * @param string $message Log message.
		 * @param string $level Optional. Default 'info'. Possible values:
		 *                      emergency|alert|critical|error|warning|notice|info|debug.
		 */
		public static function log($message, $level = 'info')
		{
			if (self::$log_enabled) {
				if (empty(self::$log)) {
					self::$log = wc_get_logger();
				}
				self::$log->log($level, $message, array('source' => 'revenuemonster'));
			}
		}

		/**
		 * Function cronjob
		 */
		public static function pending_orders_requery_cron()
		{

			$pending_orders = wc_get_orders(
				array(
					'limit'  => -1,
					// 'date_created' => '>' . ( time() - 2*24*60*60 ),
					'status' => array('on-hold'),
				)
			);
			$settings       = get_option('woocommerce_revenuemonster_settings');
			$sdk            = RevenueMonster::get_instance(
				array(
					'client_id'     => $settings['client_id'],
					'client_secret' => $settings['client_secret'],
					'private_key'   => $settings['private_key'],
					'public_key'    => $settings['public_key'],
					'version'      => 'stable',
					'is_sandbox'    => filter_var($settings['sandbox'], FILTER_VALIDATE_BOOLEAN),
				)
			);
			foreach ($pending_orders as $order) {
				if ($order->get_payment_method() === 'revenuemonster') {
					$oid = $order->get_transaction_id();
					try {
						$response = $sdk->query_order($oid);
						if ($response && strtoupper($response->status) === 'SUCCESS') {
							$obj = json_decode(json_encode($response), true);
							$order->payment_complete($obj['transactionId']);
							$order->set_payment_method($response->method);
							$order->save();
						} elseif ($response && strtoupper($response->status) === 'FAILED') {
							$order->update_status('failed', __('Payment failed', 'woocommerce-gateway-revenuemonster'));
							$order->save();
						}
					} catch (Exception $e) {
						if (strtoupper($e->getMessage()) === 'TRANSACTION_NOT_FOUND' && (filter_var($settings['auto_cancel'], FILTER_VALIDATE_BOOLEAN))) {
							$order_id = explode('-', $oid);
							if (count($order_id) === 2) {
								if ((time() - intval($order_id[1])) > 60 * 30) {
									$order->update_status('failed', __('Payment failed', 'woocommerce-gateway-revenuemonster'));
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
		public function init_form_fields()
		{
			$this->form_fields = apply_filters(
				'wc_gateway_revenuemonster_form_fields',
				array(
					'enabled'         => array(
						'title'   => __('Enable/Disable', 'woocommerce-gateway-revenuemonster'),
						'type'    => 'checkbox',
						'label'   => __('Enable this payment gateway', 'woocommerce-gateway-revenuemonster'),
						'default' => 'yes',
					),

					'sandbox'         => array(
						'title'   => __('Sandbox', 'woocommerce-gateway-revenuemonster'),
						'type'    => 'checkbox',
						'default' => 'no',
					),
					'auto_cancel'     => array(
						'title'   => __('Auto cancel', 'woocommerce-gateway-revenuemonster'),
						'type'    => 'checkbox',
						'label'   => __('Cancel on hold order if transaction not found', 'woocommerce-gateway-revenuemonster'),
						'default' => 'yes',
					),

					'card_checkout_mode' => array(
						'title'       => __('Card Checkout Mode', 'woocommerce-gateway-revenuemonster'),
						'type'        => 'select',
						'description' => __('Hosted Checkout redirects the customer to the Revenue Monster payment page (default). Direct Card Checkout shows a card form on the WooCommerce checkout (submitted straight to Revenue Monster), plus a "E-wallet / Online Banking" option that uses the hosted redirect.', 'woocommerce-gateway-revenuemonster'),
						'desc_tip'    => true,
						'default'     => 'hosted',
						'options'     => array(
							'hosted' => __('Hosted Checkout', 'woocommerce-gateway-revenuemonster'),
							'direct' => __('Direct Card Checkout', 'woocommerce-gateway-revenuemonster'),
						),
					),

					'title'           => array(
						'title'       => __('Title', 'woocommerce-gateway-revenuemonster'),
						'type'        => 'text',
						'description' => __('This controls the title for the payment method the customer sees during checkout.', 'woocommerce-gateway-revenuemonster'),
						'desc_tip'    => true,
					),

					'description'     => array(
						'title'       => __('Description', 'woocommerce-gateway-revenuemonster'),
						'type'        => 'text',
						'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce-gateway-revenuemonster'),
						'desc_tip'    => true,
					),

					'logo'            => array(
						'title'       => __('Logo', 'woocommerce-gateway-revenuemonster'),
						'type'        => 'text',
						'description' => __('Logo URL', 'woocommerce-gateway-revenuemonster'),
					),

					'store_id'        => array(
						'title'       => __('Store ID', 'woocommerce-gateway-revenuemonster'),
						'type'        => 'text',
						'description' => __('Store ID setup at Revenuemonster', 'woocommerce-gateway-revenuemonster'),
						'desc_tip'    => true,
					),

					'client_id'       => array(
						'title'       => __('Client ID', 'woocommerce-gateway-revenuemonster'),
						'type'        => 'text',
						'description' => __('Client ID setup at Revenuemonster', 'woocommerce-gateway-revenuemonster'),
						'desc_tip'    => true,
					),

					'client_secret'   => array(
						'title'       => __('Client Secret', 'woocommerce-gateway-revenuemonster'),
						'type'        => 'password',
						'description' => __('Client Secret setup at Revenuemonster', 'woocommerce-gateway-revenuemonster'),
						'desc_tip'    => true,
					),

					'private_key'     => array(
						'title'       => __('Client Private Key', 'woocommerce-gateway-revenuemonster'),
						'type'        => 'textarea',
						'description' => __('Instructions that will be added to the thank you page and emails.', 'woocommerce-gateway-revenuemonster'),
						'default'     => '',
						'desc_tip'    => true,
					),

					'public_key'      => array(
						'title'       => __('Server Public Key', 'woocommerce-gateway-revenuemonster'),
						'type'        => 'textarea',
						'description' => __('Instructions that will be added to the thank you page and emails.', 'woocommerce-gateway-revenuemonster'),
						'default'     => '',
						'desc_tip'    => true,
					),
				)
			);
		}

		/**
		 * Settings screen: while "Direct Card Checkout" is selected, warn if the
		 * account does not support it.
		 *
		 * Hooked to admin_notices (not echoed from admin_options) so the notice
		 * sits in WordPress's notice area above the form. Echoing it inside
		 * #mainform let WordPress relocate the node on load, which broke
		 * WooCommerce's "Save changes" enable-on-change detection for the
		 * Card Checkout Mode dropdown.
		 */
		public function render_card_capability_notice()
		{
			// The gateway is instantiated more than once per request, so this
			// callback can be hooked several times; only ever print it once.
			static $printed = false;

			if ($printed) {
				return;
			}

			if (!function_exists('get_current_screen')) {
				return;
			}

			$screen = get_current_screen();

			if (!$screen || 'woocommerce_page_wc-settings' !== $screen->id) {
				return;
			}

			$tab     = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
			$section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';

			if ('checkout' !== $tab || $this->id !== $section) {
				return;
			}

			if (!$this->is_direct_card() || !$this->get_option('client_id') || $this->card_capability_active()) {
				return;
			}

			$printed = true;

			echo '<div class="notice notice-warning"><p>';
			echo wp_kses_post(__('<strong>Direct Card Checkout</strong> is not enabled on this Revenue Monster account, so selecting it will fall back to the hosted Revenue Monster page. Ask Revenue Monster to enable Direct Card Payment, then re-save these settings.', 'woocommerce-gateway-revenuemonster'));
			echo '</p></div>';
		}

		/**
		 * Refresh the stored Direct Card capability flag on every settings save.
		 */
		public function process_admin_options()
		{
			$saved = parent::process_admin_options();
			$this->refresh_card_capability();

			return $saved;
		}

		/**
		 * Function get_sdk
		 */
		public function get_sdk()
		{
			return RevenueMonster::get_instance(
				array(
					'client_id'     => $this->get_option('client_id'),
					'client_secret' => $this->get_option('client_secret'),
					'private_key'   => $this->get_option('private_key'),
					'public_key'    => $this->get_option('public_key'),
					'version'      => 'stable',
					'is_sandbox'    => filter_var($this->get_option('sandbox'), FILTER_VALIDATE_BOOLEAN),
				)
			);
		}

		/**
		 * Function woocommerce payment logic
		 *
		 * @param string $order_id Order ID.
		 */
		public function process_payment($order_id)
		{
			global $woocommerce;
			$order = wc_get_order($order_id);
			if (!$order) {
				return array('result' => 'failure');
			}

			$oid = $order_id . '-' . time();
			$order->set_transaction_id($oid);
			$order->save();

			$sdk     = $this->get_sdk();
			$url     = WC()->api_request_url('WC_Gateway_RevenueMonster');
			$payload = array(
				'order'         => array(
					'id'             => strval($oid),
					'title'          => 'WooCommerce Order ' . strval($order_id),
					'detail'         => strval($order_id),
					'additionalData' => '',
					'amount'         => (int) round(floatval($order->get_total()) * 100),
					'currencyType'   => 'MYR',
				),
				'method' => array(),
				// 'method'        => $this->get_option( 'payment_methods' ),
				'type'          => 'WEB_PAYMENT',
				'storeId'       => strval($this->get_option('store_id')),
				'redirectUrl'   => escape_url($url),
				'notifyUrl'     => escape_url($url),
				'layoutVersion' => 'v4',
			);

			// Direct Card mode + "Card" chosen: run the client-side card flow.
			if ($this->direct_card_available() && 'hosted' !== $this->get_posted_pay_mode()) {
				return $this->process_direct_card_payment($order, $oid, $sdk, $payload);
			}

			$response = $sdk->create_order($payload);

			$order->update_status('on-hold', __('Awaiting payment', 'woocommerce-gateway-revenuemonster'));
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
		 * Direct Card Checkout server side: create the order and the card checkout,
		 * then return a handle for the browser to submit the card details + 3DS.
		 * Card data never reaches this server; the final status comes from the
		 * webhook / query_order flow.
		 *
		 * @param WC_Order       $order   Order.
		 * @param string         $oid     Revenue Monster order id (transaction id).
		 * @param RevenueMonster $sdk     SDK instance.
		 * @param array          $payload create_order payload.
		 * @return array
		 */
		protected function process_direct_card_payment($order, $oid, $sdk, $payload)
		{
			// RM routes through MASTERCARD_MY;
			$method = apply_filters('wc_revenuemonster_card_method', 'MASTERCARD_MY', $order);

			try {
				$checkout = $sdk->create_order($payload);
				if (!isset($checkout->checkoutId)) {
					throw new Exception('missing checkoutId');
				}

				$card = $sdk->create_card_checkout($checkout->checkoutId, $method);
				// RM returns the id as `checkoutId`; older docs/builds call it `id`. Accept either.
				$card_code = !empty($card->checkoutId) ? $card->checkoutId : (!empty($card->id) ? $card->id : '');
				if (empty($card_code) || empty($card->url)) {
					throw new Exception('missing card checkout');
				}
			} catch (Exception $e) {
				self::log('Direct card checkout failed for order ' . $order->get_id() . ': ' . $e->getMessage(), 'error');
				wc_add_notice(__('We could not start the card payment. Please try again or choose another payment method.', 'woocommerce-gateway-revenuemonster'), 'error');

				return array('result' => 'failure');
			}

			$order->update_status('on-hold', __('Awaiting card payment', 'woocommerce-gateway-revenuemonster'));

			// Card endpoint origin only (no query string) for the browser POST.
			$endpoint = strtok($card->url, '?');

			// Gate the status endpoint for ~30 min without exposing the oid long term.
			set_transient('rm_direct_card_' . $oid, $order->get_order_key(), 30 * MINUTE_IN_SECONDS);

			$return_url = $order->get_checkout_order_received_url();
			$fail_url   = wc_get_checkout_url();

			return array(
				'result'   => 'success',
				// Fallback redirect if our script never runs; cart stays intact.
				'redirect' => $fail_url,
				// Nested handle for the classic [woocommerce_checkout] flow.
				'rm_card'  => array(
					'endpoint'  => esc_url_raw($endpoint),
					'code'      => strval($card_code),
					'oid'       => strval($oid),
					'key'       => $order->get_order_key(),
					'returnUrl' => $return_url,
					'failUrl'   => $fail_url,
				),
				// Flat mirror for the Blocks flow: the Store API casts every
				// payment-detail value to string, so a nested array cannot survive.
				'rm_card_endpoint'   => esc_url_raw($endpoint),
				'rm_card_code'       => strval($card_code),
				'rm_card_oid'        => strval($oid),
				'rm_card_key'        => $order->get_order_key(),
				'rm_card_return_url' => $return_url,
				'rm_card_fail_url'   => $fail_url,
			);
		}

		/**
		 * AJAX: report the real payment status for a Direct Card order.
		 *
		 * Reuses the existing query_order() so the order lifecycle stays in one
		 * place. A 3DS_CHALLENGE alone never marks the order paid.
		 */
		public function ajax_card_status()
		{
			check_ajax_referer('rm_direct_card', 'nonce');

			$oid = isset($_POST['oid']) ? sanitize_text_field(wp_unslash($_POST['oid'])) : '';
			$key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';

			$parts = explode('-', $oid);
			if (count($parts) !== 2 || $key === '' || get_transient('rm_direct_card_' . $oid) !== $key) {
				wp_send_json_error(array('status' => 'invalid'), 400);
			}

			$order = wc_get_order(absint($parts[0]));
			if (!$order || !hash_equals($order->get_order_key(), $key)) {
				wp_send_json_error(array('status' => 'invalid'), 400);
			}

			if ($order->is_paid()) {
				wp_send_json_success(array('status' => 'success', 'redirect' => $order->get_checkout_order_received_url()));
			}

			$op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';

			// Customer aborted from the 3DS modal: cancel now rather than wait for
			// the requery cron. Stock is untouched and the cart intact, so a retry
			// just works.
			if ('cancel' === $op) {
				if (!$order->has_status(array('cancelled', 'failed', 'refunded'))) {
					$order->update_status('cancelled', __('Card payment cancelled by customer.', 'woocommerce-gateway-revenuemonster'));
				}
				delete_transient('rm_direct_card_' . $oid);
				wc_add_notice(__('Payment cancelled. You have not been charged.', 'woocommerce-gateway-revenuemonster'), 'notice');
				// Force a fresh order on the next attempt.
				if (WC()->session) {
					unset(WC()->session->order_awaiting_payment);
				}
				wp_send_json_success(array('status' => 'cancelled', 'redirect' => wc_get_checkout_url()));
			}

			try {
				$response = $this->get_sdk()->query_order($oid);
				$status   = isset($response->status) ? strtoupper($response->status) : 'PENDING';
			} catch (Exception $e) {
				wp_send_json_success(array('status' => 'pending'));
			}

			if ('SUCCESS' === $status) {
				if (!empty($response->method)) {
					$order->set_payment_method($response->method);
				}
				$order->payment_complete(isset($response->transactionId) ? $response->transactionId : '');
				$order->save();
				if (WC()->cart) {
					WC()->cart->empty_cart();
				}
				delete_transient('rm_direct_card_' . $oid);
				wp_send_json_success(array('status' => 'success', 'redirect' => $order->get_checkout_order_received_url()));
			}

			if ('FAILED' === $status) {
				$order->update_status('failed', __('Card payment failed', 'woocommerce-gateway-revenuemonster'));
				$order->save();
				delete_transient('rm_direct_card_' . $oid);
				wp_send_json_success(array('status' => 'failed', 'redirect' => wc_get_checkout_url()));
			}

			wp_send_json_success(array('status' => 'pending'));
		}

		/**
		 * Function webhook
		 *
		 * @throws \Exception Invalid webhook response.
		 */
		public function webhook()
		{
			// Same endpoint for the hosted checkout redirectUrl and the S2S
			// notifyUrl. Only the browser redirect carries a ?status= param.
			$is_browser = isset($_GET['status']);

			// Browser hits finish with a redirect (+ optional notice); the S2S
			// notify finishes with a bare 200.
			$finish = function ($order, $url, $notice = '', $type = 'error') use ($is_browser) {
				if ($is_browser) {
					if ('' !== $notice
						&& function_exists('wc_add_notice')
						&& function_exists('wc_has_notice')
						&& ! wc_has_notice($notice, $type)
					) {
						wc_add_notice($notice, $type);
					}
					wp_safe_redirect($url ? $url : wc_get_checkout_url());
				} else {
					status_header(200);
				}
				exit;
			};

			$raw   = isset($_GET['orderId']) ? sanitize_text_field(wp_unslash($_GET['orderId'])) : '';
			$parts = explode('-', $raw);
			if ('' === $raw || count($parts) !== 2 || ! ctype_digit($parts[0])) {
				$finish(null, wc_get_checkout_url(), __('We could not verify your payment. Please try again.', 'woocommerce-gateway-revenuemonster'));
			}

			$oid   = $raw;
			$order = wc_get_order(absint($parts[0]));
			if (! $order) {
				$finish(null, wc_get_checkout_url(), __('Order not found.', 'woocommerce-gateway-revenuemonster'));
			}

			// The server notify may have already confirmed the order before the
			// customer's browser made it back here.
			if ($order->is_paid()) {
				$finish($order, $order->get_checkout_order_received_url());
			}

			// Trust the browser's ?status= only to fail an order, never to pay it.
			if ($is_browser) {
				$reported = strtoupper(sanitize_key(wp_unslash($_GET['status'])));
				if (in_array($reported, array('EXPIRED', 'CANCELLED', 'CANCEL', 'FAILED', 'FAIL'), true)) {
					if (! $order->has_status(array('cancelled', 'failed', 'refunded'))) {
						$order->update_status('failed', sprintf(
							/* translators: %s: payment status reported by RevenueMonster */
							__('Payment not completed (RevenueMonster reported: %s).', 'woocommerce-gateway-revenuemonster'),
							$reported
						));
					}
					$finish($order, $order->get_checkout_payment_url(), __('Your payment was not completed. You can try again below.', 'woocommerce-gateway-revenuemonster'));
				}
			}

			$sdk = $this->get_sdk();

			try {
				$response = $sdk->query_order($oid);

				if (! empty($response->method)) {
					$order->set_payment_method($response->method);
				}

				$order->save();

				switch (strtoupper($response->status)) {
					case 'SUCCESS':
						$obj = json_decode(json_encode($response), true);
						$order->payment_complete($obj['transactionId']);
						if (WC()->cart) {
							WC()->cart->empty_cart();
						}
						$finish($order, $order->get_checkout_order_received_url());
						break;
					default:
						throw new Exception('invalid payment status');
				}
			} catch (Exception $e) {
				// https://docs.woocommerce.com/document/managing-orders/.
				// Mark failed (not cancelled) so WooCommerce lets the customer retry
				if (! $order->has_status(array('cancelled', 'failed'))) {
					$order->update_status('failed', __('Payment failed', 'woocommerce-gateway-revenuemonster'));
				}
				self::log('Webhook could not confirm order ' . $order->get_id() . ' (' . $oid . '): ' . $e->getMessage(), 'warning');
				$finish($order, $order->get_checkout_payment_url(), __('Your payment was not completed. You can try again below.', 'woocommerce-gateway-revenuemonster'));
			}
		}
	}

	// Registered at bootstrap, not in the constructor: WooCommerce does not
	// instantiate gateway objects on plain admin-ajax.php requests.
	add_action('wp_ajax_rm_direct_card_status', 'wc_revenuemonster_ajax_card_status');
	add_action('wp_ajax_nopriv_rm_direct_card_status', 'wc_revenuemonster_ajax_card_status');
}

/**
 * Bridge the wp_ajax hook to the gateway instance method.
 */
function wc_revenuemonster_ajax_card_status()
{
	$gateway = new WC_Gateway_RevenueMonster();
	$gateway->ajax_card_status();
}
