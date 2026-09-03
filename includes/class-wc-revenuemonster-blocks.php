<?php
/**
 * Cart & Checkout Blocks integration for the RevenueMonster gateway.
 *
 * In the block checkout the gateway shows the configured title and description.
 * When Direct Card Checkout mode is on AND the Revenue Monster account has card
 * payments active, it also renders a "Card" / "E-wallet / Online Banking" choice
 * with native card inputs, mirroring the classic [woocommerce_checkout] flow:
 * the card details are POSTed straight from the browser to Revenue Monster (they
 * never reach this server), 3-D Secure runs in an overlay iframe, and the real
 * order status is settled by polling the shared rm_direct_card_status endpoint.
 * Hosted Checkout, and the "E-wallet / Online Banking" choice, use the redirect
 * flow driven by the gateway's process_payment().
 *
 * @package WC_Gateway_RevenueMonster
 */

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Class WC_RevenueMonster_Blocks_Support
 */
final class WC_RevenueMonster_Blocks_Support extends AbstractPaymentMethodType
{
	/**
	 * Payment method id. Must match the gateway id.
	 *
	 * @var string
	 */
	protected $name = 'revenuemonster';

	/**
	 * Load the gateway settings.
	 */
	public function initialize()
	{
		$this->settings = get_option('woocommerce_revenuemonster_settings', array());
	}

	/**
	 * The live gateway instance, or null if it is not registered yet.
	 *
	 * @return WC_Payment_Gateway|null
	 */
	private function get_gateway()
	{
		if (!function_exists('WC') || !WC()->payment_gateways) {
			return null;
		}
		$gateways = WC()->payment_gateways->payment_gateways();

		return isset($gateways[$this->name]) ? $gateways[$this->name] : null;
	}

	/**
	 * Whether the payment method should be available in the block checkout.
	 *
	 * @return bool
	 */
	public function is_active()
	{
		return !empty($this->settings['enabled']) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Register the block integration script and stylesheet, return the handle(s).
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles()
	{
		$js  = WC_REVENUEMONSTER_PATH . 'assets/js/rm-blocks.js';
		$css = WC_REVENUEMONSTER_PATH . 'assets/css/rm-direct-card.css';

		wp_register_script(
			'rm-blocks',
			WC_REVENUEMONSTER_URL . 'assets/js/rm-blocks.js',
			array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
			file_exists($js) ? (string) filemtime($js) : '1.0.10',
			true
		);

		// Same card form / 3-D Secure modal styles the classic checkout uses.
		wp_enqueue_style(
			'rm-direct-card',
			WC_REVENUEMONSTER_URL . 'assets/css/rm-direct-card.css',
			array(),
			file_exists($css) ? (string) filemtime($css) : '1.0.10'
		);

		return array('rm-blocks');
	}

	/**
	 * Data passed to the block script via wc.wcSettings.getSetting('revenuemonster_data').
	 *
	 * @return array
	 */
	public function get_payment_method_data()
	{
		$gateway = $this->get_gateway();
		$direct  = $gateway && method_exists($gateway, 'direct_card_available')
			? (bool) $gateway->direct_card_available()
			: false;

		return array(
			'title'       => isset($this->settings['title']) && '' !== $this->settings['title']
				? $this->settings['title']
				: __('RevenueMonster', 'woocommerce-gateway-revenuemonster'),
			'description' => isset($this->settings['description']) ? $this->settings['description'] : '',
			'supports'    => array('products'),
			'directCard'  => $direct,
			'ajaxUrl'     => admin_url('admin-ajax.php'),
			'nonce'       => wp_create_nonce('rm_direct_card'),
			'i18n'        => array(
				'card'           => __('Card', 'woocommerce-gateway-revenuemonster'),
				'ewallet'        => __('E-wallet / Online Banking', 'woocommerce-gateway-revenuemonster'),
				'redirectHint'   => __('You will be redirected to Revenue Monster to complete payment.', 'woocommerce-gateway-revenuemonster'),
				'cardName'       => __('Cardholder Name', 'woocommerce-gateway-revenuemonster'),
				'cardNumber'     => __('Card Number', 'woocommerce-gateway-revenuemonster'),
				'cardExpiry'     => __('Expiry (MM / YY)', 'woocommerce-gateway-revenuemonster'),
				'cardCvv'        => __('Security Code / CVV', 'woocommerce-gateway-revenuemonster'),
				'invalidNumber'  => __('Please enter a valid card number.', 'woocommerce-gateway-revenuemonster'),
				'invalidName'    => __('Please enter the cardholder name.', 'woocommerce-gateway-revenuemonster'),
				'invalidExpiry'  => __('Please enter a valid expiry date.', 'woocommerce-gateway-revenuemonster'),
				'invalidCvv'     => __('Please enter a valid security code.', 'woocommerce-gateway-revenuemonster'),
				'generic'        => __('We could not process your card. Please try again.', 'woocommerce-gateway-revenuemonster'),
				'authInProgress' => __('Completing bank authentication…', 'woocommerce-gateway-revenuemonster'),
				'cancel'         => __('Cancel payment', 'woocommerce-gateway-revenuemonster'),
				'cancelled'      => __('Payment cancelled. You have not been charged.', 'woocommerce-gateway-revenuemonster'),
			),
		);
	}
}
