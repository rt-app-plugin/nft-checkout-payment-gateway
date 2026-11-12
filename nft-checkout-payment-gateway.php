<?php

/**
 * Plugin Name: NFT Checkout
 * Description: Accept card payments in WooCommerce through RT's hosted checkout: the customer receives an e-mail voucher and pays on RT's secure page.
 * Author: RT.app
 * Author URI: https://rt.app/
 * Text Domain: nft-checkout-payment-gateway
 * Plugin URI: https://rt.app/
 * Version: 1.0.2
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * WC requires at least: 6.5.4
 * WC tested up to: 11.0
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Copyright (c) 2024 RT.app
 */

if (!defined('ABSPATH')) {
	exit;
}

define('NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_PHP_VER', '8.0');
define('NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER', '6.5.4');
define('NFT_CHECKOUT_PAYMENT_GATEWAY_FILE', __FILE__);
define('NFT_CHECKOUT_PAYMENT_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));

if (!defined('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP')) {
	define('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP', 'nft_checkout_payment_gateway');
}

// Configuration and utility functions
require_once NFT_CHECKOUT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/config.php';
require_once NFT_CHECKOUT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/nft-checkout-payment-gateway-utils.php';

// Migrations functions
include_once plugin_dir_path(__FILE__) . 'migration.php';

// Autoload classes
spl_autoload_register(function ($class) {
	if (strpos($class, 'NFT_CHECKOUT_PAYMENT_GATEWAY') === 0) {
		$class_file = NFT_CHECKOUT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
		if (file_exists($class_file)) {
			require_once $class_file;
		}
	}
});

// Declare HPOS and block checkout compatibility (NFT-18)
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
	}
});

NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance();

// Tables and option migrations on plugin update (after WooCommerce's own init).
add_action('init', 'nft_checkout_maybe_upgrade', 20);

/*
 * NFT-09: WooCommerce's own unpaid-order cancellation is left alone. The
 * 'woocommerce_cancel_unpaid_order' FILTER used to be hooked here with
 * add_action; its callback returned nothing, which told WooCommerce not to
 * cancel ANY unpaid order on the whole store. A cancelled or failed NFT
 * Checkout order tells RT through the status hooks below instead.
 */
add_action('woocommerce_order_status_cancelled', 'nft_checkout_cancel_unpaid_order_action', 10, 1);
add_action('woocommerce_order_status_failed', 'nft_checkout_cancel_unpaid_order_action', 10, 1);

/**
 * NFT-12: the order-received URL is decided from what the order already
 * records; nothing is fetched from RT while a URL is built. (The order is
 * confirmed with RT once, on the thank-you page itself.)
 */
add_filter('woocommerce_get_checkout_order_received_url', function($url, $order) {

	if (!$order || !is_a($order, 'WC_Order')) {
		return $url;
	}

	if (!nft_checkout_is_gateway_order($order)) {
		return $url;
	}

	$wc_status    = $order->get_status();
	$engine_state = $order->get_meta('_nft_checkout_state');
	$success_meta = $order->get_meta('_nft_checkout_payment_success');

	$is_voucher_dispatched = ($wc_status === 'pending' || $engine_state === 'pending') && (
		!empty($order->get_meta('_nft_checkout_voucher_emailed_at')) ||
		!empty($order->get_meta('_nft_checkout_voucher_id')) ||
		!empty($order->get_meta('_nft_checkout_voucher_reference'))
	);

	$is_valid = in_array($wc_status, ['processing', 'completed', 'on-hold'], true)
		|| $success_meta === 'yes'
		|| $engine_state === 'success'
		|| $is_voucher_dispatched;

	if (!$is_valid) {
		return wc_get_checkout_url();
	}

	return $url;

}, 10, 2);

/**
 * Tell RT that an NFT Checkout order was cancelled or failed in WooCommerce so
 * the payment link behind the voucher is closed.
 *
 * Signed with the key pair of the account that raised the order's voucher
 * (NFT-05, NFT-08). An order that names no configured account is skipped.
 *
 * @param int $order_id The ID of the order.
 */
function nft_checkout_cancel_unpaid_order_action($order_id)
{
	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	$order = wc_get_order($order_id);
	if (!$order instanceof WC_Order || !nft_checkout_is_gateway_order($order)) {
		return;
	}

	$current_status = $order->get_status();
	if (!in_array($current_status, ['cancelled', 'failed'], true)) {
		return;
	}

	$target_api_status = ($current_status === 'failed') ? 'failed' : 'canceled';

	$uuid = '';
	if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
		$known = NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->nft_checkout_known_order_pay_ids($order);
		$uuid  = (string) ($known[0] ?? '');
	}
	if ($uuid === '') {
		$uuid = sanitize_text_field((string) $order->get_meta('_nft_checkout_voucher_id'));
	}
	if ($uuid === '') {
		$uuid = sanitize_text_field((string) $order->get_meta('_nft_checkout_voucher_reference'));
	}
	if ($uuid === '') {
		// No voucher, no link: nothing to close at RT.
		return;
	}

	// Prevent duplicate API calls for the same order and status.
	$sync_signature = $uuid . ':' . $target_api_status;
	if ($order->get_meta('_nft_checkout_cancelled_link_uuid') === $sync_signature) {
		return;
	}

	$account = nft_checkout_get_order_account($order);
	if (!is_array($account) || $account['secret_key'] === '') {
		NFT_Checkout_Payment_Gateway_Logger::warning("Cancel skipped: no configured account matches order #{$order_id}", [
			'order_id' => $order_id,
		]);
		return;
	}

	$request_payload = [
		'order_id'   => (int) $order_id,
		'order_uuid' => $uuid,
		'status'     => $target_api_status,
		'is_sandbox' => (int) $account['is_sandbox'],
	];
	$json_body = wp_json_encode($request_payload);

	$response = wp_remote_post(
		esc_url_raw(NFT_CHECKOUT_API_BASE_URL . '/cancel-order-link'),
		[
			'method'    => 'POST',
			'timeout'   => 15,
			'body'      => $json_body,
			'headers'   => nft_checkout_signed_headers($account['public_key'], $account['secret_key'], $json_body),
			'sslverify' => true,
		]
	);

	if (is_wp_error($response)) {
		NFT_Checkout_Payment_Gateway_Logger::error("Cancel/fail API call failed. Order ID: {$order_id}", [
			'order_id' => $order_id,
			'status'   => $target_api_status,
			'error'    => $response->get_error_message(),
		]);
		return;
	}

	$response_code    = (int) wp_remote_retrieve_response_code($response);
	$decoded_response = json_decode(wp_remote_retrieve_body($response), true);

	if ($response_code >= 200 && $response_code < 300 && !empty($decoded_response['status'])) {
		$order->update_meta_data('_nft_checkout_cancelled_link_uuid', $sync_signature);
		$order->save();
	}

	NFT_Checkout_Payment_Gateway_Logger::info("Cancel/fail API response received for Order ID: {$order_id}.", [
		'order_id'      => $order_id,
		'status'        => $target_api_status,
		'response_code' => $response_code,
		'api_status'    => $decoded_response['status'] ?? null,
	]);
}

/* Sandbox mode is a merchant-visible state, not a quiet one. */
add_action('admin_notices', function () {
	if (!current_user_can('manage_woocommerce') || !nft_checkout_is_sandbox()) {
		return;
	}
	$settings = nft_checkout_get_settings();
	if (($settings['enabled'] ?? 'no') !== 'yes') {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>' .
		esc_html__('NFT Checkout: test mode is on.', 'nft-checkout-payment-gateway') . '</strong> ' .
		esc_html__('Card orders are test orders and no real money is received. Turn test mode off in the NFT Checkout settings before taking real orders.', 'nft-checkout-payment-gateway') .
		'</p></div>';
});
