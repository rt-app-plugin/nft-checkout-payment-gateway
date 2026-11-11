<?php

/**
 * Plugin Name: NFT Checkout
 * Description: Purchase and pay quickly with NFT Checkout
 * Author: RT.app
 * Author URI: https://rt.app/
 * Text Domain: nft-checkout-payment-gateway
 * Plugin URI: https://github.com/rt/nft-checkout-payment-gateway
 * Version: 1.0.2
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * WC requires at least: 6.5.4
 * WC tested up to: 11.2.2
 * Requires Plugins: woocommerce
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

// NFT-23: Prefixed cache key and group constants
if (!defined('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP')) {
	define('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP', 'nft_checkout_payment_gateway');
}

// Include utility functions
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

// Declare WooCommerce HPOS and Cart/Checkout Blocks compatibility
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
	}
});

NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance();

add_action('woocommerce_order_status_cancelled', 'nft_checkout_cancel_unpaid_order_action', 10, 3);
add_action('woocommerce_order_status_failed', 'nft_checkout_cancel_unpaid_order_action', 10, 3);

add_filter('woocommerce_get_checkout_order_received_url', function($url, $order) {

    if (!$order || !is_a($order, 'WC_Order')) {
        return $url;
    }

    // Only evaluate NFT Checkout orders
    if (!in_array($order->get_payment_method(), ['nft-checkout', 'nft_checkout'], true)) {
        return $url;
    }

	$wc_status    = $order->get_status();
	$engine_state = $order->get_meta('_nft_checkout_state');
	$success_meta = $order->get_meta('_nft_checkout_payment_success');
	
	NFT_Checkout_Payment_Gateway_Logger::info(	
		sprintf(
			"[Order #%d] ThankYou Filter | WC=%s | Engine=%s | Success=%s",
			$order->get_id(),
			$wc_status,
			$engine_state,
			$success_meta ?: 'EMPTY'
		)
	);

	$is_voucher_dispatched = ($wc_status === 'pending' || $engine_state === 'pending') && (
		!empty($order->get_meta('_nft_checkout_voucher_emailed_at')) ||
		!empty($order->get_meta('_nft_checkout_voucher_id')) ||
		!empty($order->get_meta('_nft_checkout_voucher_reference'))
	);

    $is_valid = $order->is_paid()
		|| in_array($wc_status, ['processing', 'completed'], true) 
		|| $success_meta === 'yes' 
		|| $engine_state === 'success'
		|| $is_voucher_dispatched;

	if (!$is_valid) {
		NFT_Checkout_Payment_Gateway_Logger::info(
			sprintf(
				'[Order #%d] ThankYou Filter BLOCKED -> %s',
				$order->get_id(),
				wc_get_checkout_url()
				)
			);
		return wc_get_checkout_url();
	}

   	NFT_Checkout_Payment_Gateway_Logger::info(
		sprintf(
			'[Order #%d] ThankYou Filter ALLOWED -> %s',
			$order->get_id(),
			$url
		)
	);

	return $url;

}, 10, 2);

/**
 * Cancels or updates status of the NFT Checkout payment link when an order is cancelled or fails.
 *
 * For voucher orders, the payment link may not exist yet in the database table because it is
 * created only after the customer clicks the purchase button in the email. Fallback identifiers
 * (pay_id, voucher_id, voucher_reference, order_id) ensure the portal is always updated.
 *
 * Handles calls from:
 * - woocommerce_order_status_cancelled: ($order_id, $order, $status_transition)
 * - woocommerce_order_status_failed: ($order_id, $order, $status_transition)
 *
 * @param int                $order_id    The ID of the order.
 * @param string|WC_Order|null $from_status Previous order status or WC_Order instance.
 * @param string|array|null  $to_status   New order status or status transition array.
 * @param WC_Order|null      $order       Order object.
 */
function nft_checkout_cancel_unpaid_order_action($order_id, $from_status = null, $to_status = null, $order = null)
{
	if (empty($order_id) || !is_numeric($order_id) || $order_id <= 0) {
		return;
	}

	// woocommerce_order_status_cancelled / failed passes ($order_id, $order, $status_transition)
	if ($from_status instanceof WC_Order) {
		$order = $from_status;
		if (is_array($to_status) && isset($to_status['to'])) {
			$current_status = $to_status['to'];
		} else {
			$current_status = $order->get_status();
		}
		$from_status = is_array($to_status) && isset($to_status['from']) ? $to_status['from'] : null;
	} elseif (is_array($to_status) && isset($to_status['to'])) {
		$current_status = $to_status['to'];
	} elseif (is_string($to_status) && !empty($to_status)) {
		$current_status = $to_status;
	} else {
		$order          = $order ?: wc_get_order($order_id);
		$current_status = $order ? $order->get_status() : '';
	}

	$order = $order ?: wc_get_order($order_id);

	if (!$order) {
		return;
	}

	/*
	 * Only process NFT Checkout orders.
	 */
	$payment_method  = $order->get_payment_method();
	$is_nft_checkout = ($payment_method === 'nft-checkout' || $payment_method === 'nft_checkout')
		|| !empty($order->get_meta('_nft_checkout_pay_id'))
		|| !empty($order->get_meta('_nft_checkout_voucher_id'))
		|| !empty($order->get_meta('_nft_checkout_voucher_reference'));

	if (!$is_nft_checkout) {
		return;
	}

	if (class_exists('NFT_Checkout_Payment_State_Engine') && NFT_Checkout_Payment_State_Engine::is_applying_remote_sync()) {
		return;
	}

	if ($order->get_meta('_nft_checkout_payment_success') === 'yes') {
		return;
	}

	$current_status = strtolower(trim((string) $current_status));
	$current_status = preg_replace('/^wc-/', '', $current_status);

	/*
	 * Process orders that are cancelled or failed.
	 */
	if (!in_array($current_status, ['cancelled', 'canceled', 'failed'], true)) {
		return;
	}

	$target_api_status = ($current_status === 'failed') ? 'failed' : 'canceled';

	$payment_row = function_exists('nft_checkout_get_order_payment_link')
		? nft_checkout_get_order_payment_link($order_id)
		: null;

	$uuid           = sanitize_text_field((string) ($payment_row->uuid ?? ''));
	$customer_email = sanitize_email((string) ($payment_row->customer_email ?? $order->get_billing_email()));

	/*
	 * Fallback UUIDs if table has no record yet (e.g. voucher not yet opened)
	 */
	if (empty($uuid)) {
		$uuid = sanitize_text_field((string) $order->get_meta('_nft_checkout_pay_id'));
	}
	if (empty($uuid)) {
		$uuid = sanitize_text_field((string) $order->get_meta('_nft_checkout_voucher_id'));
	}
	if (empty($uuid)) {
		$uuid = sanitize_text_field((string) $order->get_meta('_nft_checkout_voucher_reference'));
	}
	if (empty($uuid)) {
		$uuid = (string) $order_id;
	}

	/*
	 * Prevent duplicate API calls for the same order and status.
	 */
	$sync_signature = $uuid . ':' . $target_api_status;
	if ($order->get_meta('_nft_checkout_cancelled_link_uuid') === $sync_signature) {
		NFT_Checkout_Payment_Gateway_Logger::info(
			"NFT Checkout payment status already synced as {$target_api_status}. Skipping duplicate API call.",
			[
				'source'  => 'nft-checkout-payment-gateway',
				'context' => [
					'order_id' => $order_id,
					'uuid'     => $uuid,
					'status'   => $target_api_status,
				],
			]
		);

		return;
	}

	$is_sandbox     = function_exists('nft_checkout_is_order_sandbox') ? nft_checkout_is_order_sandbox($order) : false;
	$target_account = function_exists('nft_checkout_match_order_account') ? nft_checkout_match_order_account($order, $is_sandbox) : null;

	if (!is_array($target_account)) {
		NFT_Checkout_Payment_Gateway_Logger::warning(
			"Cancel/fail API call aborted: could not bind order #{$order_id} to a configured merchant account.",
			['source' => 'nft-checkout-payment-gateway']
		);
		return;
	}

	$public_key = $is_sandbox ? (string) ($target_account['sandbox_public_key'] ?? '') : (string) ($target_account['live_public_key'] ?? '');
	$secret_key = $is_sandbox ? (string) ($target_account['sandbox_secret_key'] ?? '') : (string) ($target_account['live_secret_key'] ?? '');

	if ($public_key === '' || $secret_key === '') {
		NFT_Checkout_Payment_Gateway_Logger::warning(
			"Cancel/fail API call aborted: missing merchant credentials for order #{$order_id}.",
			['source' => 'nft-checkout-payment-gateway']
		);
		return;
	}

	$apiPath = '/cancel-order-link';
	if (strpos($apiPath, '/payment-api/') !== 0 && $apiPath !== '/payment-api') {
		if (strpos($apiPath, '/api/') === 0) {
			$apiPath = substr($apiPath, 4);
		}
		$apiPath = '/payment-api' . $apiPath;
	}
	$url      = NFT_CHECKOUT_BASE_URL . $apiPath;
	$cleanUrl = esc_url_raw(
		preg_replace('#(?<!:)//+#', '/', $url)
	);

	$request_payload = [
		'order_id'   => (string) $order_id,
		'order_uuid' => $uuid,
		'status'     => $target_api_status,
		'is_sandbox' => $is_sandbox ? 1 : 0,
	];

	$headers = [
		'Content-Type'  => 'application/json',
		'Accept'        => 'application/json',
		'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
		'x-api-secret'  => sanitize_text_field($secret_key),
	];

	$json_body = wp_json_encode($request_payload);

	$response = wp_remote_post(
		$cleanUrl,
		[
			'method'      => 'POST',
			'timeout'     => 15,
			'redirection' => 0,
			'body'        => $json_body,
			'headers'     => $headers,
			'sslverify'   => true,
		]
	);

	if (is_wp_error($response)) {
		NFT_Checkout_Payment_Gateway_Logger::error(
			"Cancel/fail API call failed. Order ID: {$order_id}",
			[
				'source'  => 'nft-checkout-payment-gateway',
				'context' => [
					'order_id' => $order_id,
					'uuid'     => $uuid,
					'status'   => $target_api_status,
					'error'    => $response->get_error_message(),
				],
			]
		);

		return;
	}

	$response_code    = (int) wp_remote_retrieve_response_code($response);
	$response_body    = wp_remote_retrieve_body($response);
	$decoded_response = json_decode($response_body, true);

	/*
	 * NFT-10: Check HTTP status code as well as response body status
	 */
	if ($response_code >= 200 && $response_code < 300 && !empty($decoded_response['status'])) {
		$order->update_meta_data('_nft_checkout_cancelled_link_uuid', $sync_signature);
		$order->save();
	}

	// NFT-15: Mask customer email and never log payment link in logs
	$masked_email = !empty($customer_email) ? (function_exists('nft_checkout_mask_email') ? nft_checkout_mask_email($customer_email) : preg_replace('/(?<=.{2}).(?=.*@)/u', '*', $customer_email)) : '';

	NFT_Checkout_Payment_Gateway_Logger::info(
		"Cancel/fail API response received for Order ID: {$order_id}.",
		[
			'source'  => 'nft-checkout-payment-gateway',
			'context' => [
				'order_id'       => $order_id,
				'uuid'           => $uuid,
				'status'         => $target_api_status,
				'customer_email' => $masked_email,
				'response_code'  => $response_code,
				'api_status'     => $decoded_response['status'] ?? null,
			],
		]
	);
}