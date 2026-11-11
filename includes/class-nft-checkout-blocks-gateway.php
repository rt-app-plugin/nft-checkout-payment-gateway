<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class NFT_CHECKOUT_Blocks_Gateway extends AbstractPaymentMethodType {

	protected $name = 'nft-checkout';
	protected $id   = 'nft-checkout';

	public function initialize() {
		$this->settings = get_option('woocommerce_' . $this->name . '_settings', []);
	}

	public function is_active() {
		return (
			isset($this->settings['enabled']) &&
			$this->settings['enabled'] === 'yes'
		);
	}

	public function get_payment_method_script_handles() {
		$script_path = NFT_CHECKOUT_PAYMENT_GATEWAY_PLUGIN_DIR . 'assets/js/nft-checkout-blocks.js';
		$script_ver  = file_exists($script_path) ? (string) filemtime($script_path) : NFT_CHECKOUT_PLUGIN_VERSION;

		wp_register_script(
			'nft-checkout-blocks-js',
			plugin_dir_url(NFT_CHECKOUT_PAYMENT_GATEWAY_FILE) . 'assets/js/nft-checkout-blocks.js',
			['wc-blocks-registry', 'wc-settings', 'wp-element'],
			$script_ver,
			true
		);
		return ['nft-checkout-blocks-js'];
	}

	public function get_payment_method_data() {
		$title       = $this->settings['title'] ?? 'Pay by card';
		$description = $this->settings['description'] ?? 'Pay securely by card. Your payment is processed by RT.';

		if (WC()->cart) {
			$amount = (float) WC()->cart->get_total('edit');
			if ($amount < 0.01) {
				$totals = WC()->cart->get_totals();
				$amount = (float) ($totals['total'] ?? 0);
			}
			$gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
			$gateway  = $gateways['nft-checkout'] ?? null;
			if ($gateway && method_exists($gateway, 'get_checkout_info_for_amount')) {
				$info = $gateway->get_checkout_info_for_amount($amount);
				if (!empty($info['title'])) {
					$title = $info['title'];
				}
				if (!empty($info['subtitle'])) {
					$description = $info['subtitle'];
				}
			}
		}

		$show_consent = isset($this->settings['show_consent_checkbox']) && $this->settings['show_consent_checkbox'] === 'yes';

		return [
			'id'                    => $this->name,
			'title'                 => wp_strip_all_tags((string) $title),
			'description'           => wp_strip_all_tags((string) $description),
			'supports'              => ['products'],
			'isActive'              => $this->is_active(),
			'show_consent_checkbox' => $show_consent,
			'consent_label'         => __('I consent to the collection of my data to process this payment', 'nft-checkout-payment-gateway'),
			'nonce'                 => wp_create_nonce('nft_checkout_payment'),
		];
	}
}
