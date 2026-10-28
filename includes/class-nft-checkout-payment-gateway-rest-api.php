<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'class-nft-checkout-payment-state-engine.php';

class NFT_CHECKOUT_PAYMENT_GATEWAY_REST_API
{
	private $logger;
	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct()
	{
		// Initialize the logger
		$this->logger = wc_get_logger();

		// NFT-14: Scoped CORS headers for /nft-checkout/v1/ routes only (no global override or credentials)
		add_action('rest_api_init', function () {
			add_filter('rest_pre_serve_request', function ($value, $result, $request, $server) {
				if ($request instanceof WP_REST_Request) {
					$route = $request->get_route();
					if (strpos($route, '/nft-checkout/v1/') === 0) {
						header('Access-Control-Allow-Origin: ' . NFT_CHECKOUT_BASE_URL);
						header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
						header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, User-Agent, Accept, X-Signature, X-Timestamp');

						if ($request->get_method() === 'OPTIONS') {
							status_header(200);
							exit;
						}
					}
				}
				return $value;
			}, 15, 4);
		});
	}

	public function nft_checkout_register_routes()
	{
		add_action('rest_api_init', function () {
			register_rest_route('nft-checkout/v1', '/data', array(
				'methods' => ['GET', 'POST'],
				'callback' => array($this, 'nft_checkout_handle_api_request'),
				'permission_callback' => '__return_true',
			));
		});
	}

	/**
	 * Verify webhook HMAC signature (NFT-05):
	 * X-Signature = HMAC-SHA256(secret_key, timestamp + '.' + raw_body)
	 */
	private function nft_checkout_verify_webhook_signature(WP_REST_Request $request)
	{
		$signature = $request->get_header('x-signature') ?: $request->get_header('x_signature');
		$timestamp = $request->get_header('x-timestamp') ?: $request->get_header('x_timestamp');

		if (empty($signature) || empty($timestamp)) {
			return false; // NFT-05: Signature and timestamp are strictly required
		}

		// Reject timestamps older than 5 minutes (300 seconds)
		if (abs(time() - intval($timestamp)) > 300) {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook timestamp expired', ['source' => 'nft-checkout-payment-gateway']);
			return false;
		}

		// NFT-05: Replay protection - check if signature was already processed in the last 10 minutes
		$sig_cache_key = 'nft_webhook_sig_' . md5($signature);
		if (get_transient($sig_cache_key)) {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook replay detected for signature', ['source' => 'nft-checkout-payment-gateway']);
			return false;
		}

		$raw_body = $request->get_body();
		$payload_to_sign = $timestamp . '.' . $raw_body;

		// Check against secret keys of configured accounts
		$accounts_data = get_option('woocommerce_nft-checkout_payment_gateway_accounts') ?: get_option('woocommerce_nft_checkout_payment_gateway_accounts', []);
		if (isset($accounts_data['live_secret_key']) || isset($accounts_data['sandbox_secret_key'])) {
			$accounts_data = [ $accounts_data ];
		}
		$general_settings = get_option('woocommerce_nft-checkout_settings') ?: get_option('woocommerce_nft_checkout_settings', []);
		$sandbox = isset($general_settings['sandbox']) && $general_settings['sandbox'] === 'yes';

		foreach ($accounts_data as $account) {
			if (!is_array($account)) continue;
			$secret_key = $sandbox
				? ($account['sandbox_secret_key'] ?? '')
				: ($account['live_secret_key'] ?? '');

			if (!empty($secret_key)) {
				$expected_sig = hash_hmac('sha256', $payload_to_sign, $secret_key);
				if (hash_equals($expected_sig, $signature)) {
					// Store signature for 10 minutes (600 seconds) to prevent replays
					set_transient($sig_cache_key, 1, 600);
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Handles incoming Unified API requests to update order status.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object.
	 */
	public function nft_checkout_handle_api_request(WP_REST_Request $request)
	{
		$method      = $request->get_method();
		$params      = $request->get_params();
		$log_context = ['source' => 'nft-checkout-payment-gateway'];

		$data = isset($params['api_data']) && is_array($params['api_data']) ? $params['api_data'] : $params;

		$order_id         = intval($data['order_id'] ?? 0);
		$api_order_status = sanitize_text_field($data['order_status'] ?? '');
		$pay_id           = sanitize_text_field($data['pay_id'] ?? '');
		$api_key_raw      = $data['nonce'] ?? '';

		// -------------------------
		// 1. VALIDATION
		// -------------------------
		if ($order_id <= 0) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Invalid ID'
			], 400);
		}

		$order = wc_get_order($order_id);

		// ----------------------------------------------------
		// NFT-01 & NFT-02: GET REQUEST (CUSTOMER RETURN REDIRECT)
		// ----------------------------------------------------
		if ($method === 'GET') {
			$provided_key = sanitize_text_field($data['key'] ?? $data['order_key'] ?? '');

			// Reject uniformly for missing order, invalid key, or non-nft-checkout order
			// Prevents customer order data leaks and order ID enumeration (NFT-02)
			if (
				!$order ||
				empty($provided_key) ||
				!hash_equals($order->get_order_key(), $provided_key) ||
				($order->get_payment_method() !== 'nft-checkout' && $order->get_payment_method() !== 'nft_checkout')
			) {
				return new WP_REST_Response([
					'success' => false,
					'message' => 'Order not found or invalid key'
				], 403);
			}

			// Ensure session recognizes this order for Safari/cookies
			if (isset(WC()->session)) {
				WC()->session->set('order_awaiting_payment', $order->get_id());
			}

			// Verify order status server-to-server with the payment API before redirecting
			if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
				NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->sync_single_order_status($order);
				$order = wc_get_order($order_id);
			}

			// NFT-01: NEVER alter state from unverified GET params! Only redirect based on verified order status
			$wc_status = $order ? $order->get_status() : 'failed';
			if (in_array($wc_status, ['failed', 'cancelled', 'expired'], true)) {
				wc_add_notice('Payment was not completed. Please try again.', 'error');
				wp_safe_redirect(wc_get_checkout_url());
			} else {
				wp_safe_redirect($order->get_checkout_order_received_url());
			}
			exit;
		}

		// -------------------------
		// POST REQUEST (WEBHOOK)
		// -------------------------
		// NFT-39: Verify signature first and return uniform 401 before checking order existence or payment method
		$sig_valid = $this->nft_checkout_verify_webhook_signature($request);
		if (!$sig_valid) {
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'INVALID_WEBHOOK_SIGNATURE'
			], 401);
		}

		if (!$order) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Order not found'
			], 404);
		}

		// Reject non-nft-checkout orders (NFT-01, NFT-09)
		if ($order->get_payment_method() !== 'nft-checkout' && $order->get_payment_method() !== 'nft_checkout') {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Invalid payment method'
			], 400);
		}

		// NFT-05: Require amount for success events and verify against order total
		$is_success_event = in_array(strtolower($api_order_status), ['completed', 'success', 'paid'], true);
		if ($is_success_event && !isset($data['amount'])) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook amount missing for success event on order #{$order_id}",
				$log_context
			);
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'AMOUNT_REQUIRED'
			], 400);
		}

		if (isset($data['amount'])) {
			$webhook_amount = floatval($data['amount']);
			$order_amount   = floatval($order->get_total());
			if (abs($webhook_amount - $order_amount) > 0.01) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"Webhook amount mismatch for order #{$order_id}: order total {$order_amount} vs webhook {$webhook_amount}",
					$log_context
				);
				return new WP_REST_Response([
					'success'    => false,
					'error_code' => 'AMOUNT_MISMATCH'
				], 400);
			}
		}

		// -------------------------
		// 2b. PAYMENT LINK RECORD (NFT-22: 'nft-checkout')
		// -------------------------
		if (
			!empty($pay_id) &&
			($order->get_payment_method() === 'nft-checkout' || $order->get_payment_method() === 'nft_checkout') &&
			function_exists('nft_checkout_store_order_payment_link')
		) {
			nft_checkout_store_order_payment_link($order, [
				'uuid'           => $pay_id,
				'payment_link'   => $data['payment_link'] ?? '',
				'customer_email' => $data['customer_email'] ?? '',
				'amount'         => $data['amount'] ?? '',
			]);
		}

		// -------------------------
		// 4. ENGINE CALL (POST ONLY - NFT-01)
		// -------------------------
		if (!empty($api_order_status)) {
			$result = NFT_Checkout_Payment_State_Engine::handle_event(
				$order_id,
				'webhook_update',
				[
					'status'        => $api_order_status,
					'payment_token' => $pay_id,
					'source'        => 'Webhook'
				]
			);

			NFT_Checkout_Payment_Gateway_Logger::info(
				"Unified ENGINE RESULT | Order #{$order_id} | " . json_encode($result),
				$log_context
			);
		}

		$order = wc_get_order($order_id);
		$state = NFT_Checkout_Payment_State_Engine::resolve_final_state(
			$order,
			$api_order_status
		);

		$wc_status = $order->get_status();
		if ($wc_status === 'processing' || $wc_status === 'completed') {
			$state = 'success';
		}

		$is_success = ($state === 'success');
		$message    = ($is_success) ? 'Payment confirmed successfully.' : 'Payment status updated.';

		return $this->nft_checkout_finalize_response(
			$method,
			$order,
			$is_success,
			$message,
			$wc_status
		);
	}

	/**
	 * HELPER: Handles API responses
	 */
	private function nft_checkout_finalize_response($method, $order, $success, $message, $target_status = '', $redirect_url = '') 
	{
		return new WP_REST_Response(['success' => $success, 'message' => $message], 200);
	}
}
