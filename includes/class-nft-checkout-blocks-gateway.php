<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'nft-checkout-payment-gateway-utils.php';

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WooCommerce Blocks integration.
 *
 * NFT-11: the block checkout goes through WooCommerce's Store API like every
 * other gateway. Place Order is not intercepted; the Store API validates the
 * checkout (required fields, terms, stock, captcha / anti-fraud hooks, this
 * gateway's own validators and consent checkbox) and calls process_payment(),
 * whose redirect sends the shopper to the order-received page while RT
 * e-mails the voucher.
 */
class NFT_CHECKOUT_Blocks_Gateway extends AbstractPaymentMethodType {

	protected $name = NFT_CHECKOUT_GATEWAY_ID;
	protected $id   = NFT_CHECKOUT_GATEWAY_ID;

	public function initialize() {
		$this->settings = nft_checkout_get_settings();
	}

	public function is_active() {
		if (
			!isset($this->settings['enabled']) ||
			$this->settings['enabled'] !== 'yes'
		) {
			return false;
		}

		$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
		$gateway  = $gateways[$this->name] ?? null;
		if ($gateway && method_exists($gateway, 'is_available')) {
			return $gateway->is_available();
		}

		return true;
	}

	public function get_payment_method_script_handles() {
		$blocks_js_path = plugin_dir_path(NFT_CHECKOUT_PAYMENT_GATEWAY_FILE) . 'assets/js/nft-checkout-blocks.js';
		$blocks_js_ver  = file_exists($blocks_js_path) ? filemtime($blocks_js_path) : NFT_CHECKOUT_PLUGIN_VERSION;

		wp_register_script(
			'nft-checkout-blocks-js',
			plugin_dir_url(NFT_CHECKOUT_PAYMENT_GATEWAY_FILE) . 'assets/js/nft-checkout-blocks.js',
			['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
			$blocks_js_ver,
			true
		);
		return ['nft-checkout-blocks-js'];
	}

	public function get_payment_method_data() {
		$title       = $this->settings['title'] ?? __('Pay by card', 'nft-checkout-payment-gateway');
		$description = $this->settings['description'] ?? __('Pay securely by card. After you place the order, we email you a secure link from RT to pay.', 'nft-checkout-payment-gateway');

		if (WC()->cart) {
			$amount   = (float) WC()->cart->get_total('edit');
			if ($amount < 0.01) {
				$totals = WC()->cart->get_totals();
				$amount = (float) ($totals['total'] ?? 0);
			}
			$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
			$gateway  = $gateways[$this->name] ?? null;
			if ($gateway && method_exists($gateway, 'get_checkout_info_for_amount')) {
				$info = $gateway->get_checkout_info_for_amount($amount);
				if (!empty($info['title']))    $title       = $info['title'];
				if (!empty($info['subtitle'])) $description = $info['subtitle'];
			}
		}

		$is_active = $this->is_active();
		return [
			'id'                    => $this->name,
			'title'                 => sanitize_text_field($title),
			'description'           => wp_kses_post($description),
			'supports'              => ['products'],
			'isActive'              => $is_active,
			'can_pay'               => $is_active,
			'show_consent_checkbox' => ($this->settings['show_consent_checkbox'] ?? 'no') === 'yes',
			'consent_label'         => __('I consent to the collection of my data to process this payment', 'nft-checkout-payment-gateway'),
			'consent_nonce'         => wp_create_nonce('nft_checkout_payment'),
			'email_notice'          => __('After you place the order, we email you a secure link to pay by card. Your order is saved until you pay.', 'nft-checkout-payment-gateway'),
		];
	}
}
