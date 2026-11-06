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
		$register = function () {
			register_rest_route('nft-checkout/v1', '/data', array(
				'methods'             => ['GET', 'POST'],
				'callback'            => array($this, 'nft_checkout_handle_api_request'),
				'permission_callback' => '__return_true',
			));
		};

		if (did_action('rest_api_init')) {
			$register();
		} else {
			add_action('rest_api_init', $register);
		}
	}

	/**
	 * Verify API key or nonce against configured payment accounts.
	 *
	 * @param string $api_key API key or decoded nonce.
	 * @return bool True if valid, false otherwise.
	 */
	public function nft_checkout_verify_api_key($api_key)
	{
		$api_key = trim(trim(sanitize_text_field($api_key)), '"\'');
		if (empty($api_key)) {
			return false;
		}

		$accounts_data = get_option('woocommerce_nft-checkout_payment_gateway_accounts')
			?: get_option('woocommerce_nft_checkout_payment_gateway_accounts')
			?: get_option('woocommerce_unified_payment_gateway_accounts', []);
		if (is_string($accounts_data)) {
			$accounts_data = maybe_unserialize($accounts_data);
		}
		if (!is_array($accounts_data)) {
			$accounts_data = [];
		}
		if (isset($accounts_data['live_public_key']) || isset($accounts_data['sandbox_public_key'])) {
			$accounts_data = [ $accounts_data ];
		}

		foreach ($accounts_data as $account_id => $account) {
			if (!is_array($account)) {
				continue;
			}
			$keys_to_check = [
				sanitize_text_field($account['live_public_key'] ?? ''),
				sanitize_text_field($account['live_secret_key'] ?? ''),
				sanitize_text_field($account['sandbox_public_key'] ?? ''),
				sanitize_text_field($account['sandbox_secret_key'] ?? ''),
			];
			foreach ($keys_to_check as $key) {
				$clean_key = trim(trim($key), '"\'');
				if (!empty($clean_key) && hash_equals($clean_key, $api_key)) {
					NFT_Checkout_Payment_Gateway_Logger::info('API key matched successfully', [
						'source'     => 'nft-checkout-payment-gateway',
						'account_id' => $account_id,
					]);
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Verify webhook HMAC signature (NFT-05):
	 * X-Signature = HMAC-SHA256(secret_key, timestamp + '.' + raw_body)
	 */
	private function nft_checkout_verify_webhook_signature(WP_REST_Request $request)
	{
		$signature = $request->get_header('x-signature') ?: $request->get_header('x_signature');
		$timestamp = $request->get_header('x-timestamp') ?: $request->get_header('x_timestamp');

		if (empty($signature) || empty($timestamp) || !ctype_digit((string) $timestamp)) {
			return false;
		}

		// Reject timestamps older than 5 minutes (300 seconds)
		if (abs(time() - intval($timestamp)) > 300) {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook timestamp expired', ['source' => 'nft-checkout-payment-gateway']);
			return false;
		}

		// Replay protection - check if signature was already processed in the last 10 minutes
		$sig_cache_key = 'nft_webhook_sig_' . md5($signature);
		if (get_transient($sig_cache_key)) {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook replay detected for signature', ['source' => 'nft-checkout-payment-gateway']);
			return false;
		}

		$raw_body = $request->get_body();
		$payload_to_sign = $timestamp . '.' . $raw_body;

		// Check against secret keys of configured accounts
		$accounts_data = get_option('woocommerce_nft-checkout_payment_gateway_accounts')
			?: get_option('woocommerce_nft_checkout_payment_gateway_accounts')
			?: get_option('woocommerce_unified_payment_gateway_accounts', []);
		if (is_string($accounts_data)) {
			$accounts_data = maybe_unserialize($accounts_data);
		}
		if (!is_array($accounts_data)) {
			$accounts_data = [];
		}
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
	 * Handles incoming NFT Checkout API requests to update order status.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object.
	 */
	public function nft_checkout_handle_api_request(WP_REST_Request $request)
	{
		$method      = $request->get_method();
		$params      = $request->get_params();
		$json_params = $request->get_json_params();
		if (!empty($json_params) && is_array($json_params)) {
			$params = array_merge($params, $json_params);
		}

		$log_context = ['source' => 'nft-checkout-payment-gateway'];

		$data = isset($params['api_data']) && is_array($params['api_data']) ? $params['api_data'] : $params;

		// Resolve order_id flexibly
		$order_id = intval($data['order_id'] ?? 0);
		if ($order_id <= 0 && !empty($data['order_reference']) && is_numeric($data['order_reference'])) {
			$order_id = intval($data['order_reference']);
		}
		if ($order_id <= 0 && !empty($data['meta_data']['order_id']) && is_numeric($data['meta_data']['order_id'])) {
			$order_id = intval($data['meta_data']['order_id']);
		}
		if ($order_id <= 0 && !empty($data['remarks']) && preg_match('/Order\s*#?\s*(\d+)/i', (string) $data['remarks'], $matches)) {
			$order_id = intval($matches[1]);
		}
		if ($order_id <= 0 && !empty($params['order_id']) && is_numeric($params['order_id'])) {
			$order_id = intval($params['order_id']);
		}

		// Resolve api_order_status flexibly
		$api_order_status = sanitize_text_field(
			$data['order_status']
			?? ($data['payment_status']
			?? ($data['status']
			?? ($data['transaction_status']
			?? ($data['transaction_details']['status']
			?? ($params['order_status']
			?? ($params['payment_status']
			?? ''))))))
		);
		$api_order_status = trim(trim($api_order_status), '"\'');

		// Resolve pay_id flexibly
		$pay_id = sanitize_text_field(
			$data['pay_id']
			?? ($data['uuid']
			?? ($data['payment_token']
			?? ($data['transaction_details']['transaction_id']
			?? ($params['pay_id']
			?? ($params['uuid']
			?? '')))))
		);
		$pay_id = trim(trim($pay_id), '"\'');

		// Resolve api key / nonce
		$api_key_raw = trim(trim(sanitize_text_field(
			$data['nonce']
			?? ($params['nonce']
			?? ($data['api_key']
			?? ($params['api_key']
			?? '')))
		)), '"\'');

		NFT_Checkout_Payment_Gateway_Logger::info(
			"NFT Checkout API HIT | Method: {$method} | Order #{$order_id} | Status: {$api_order_status} | Pay ID: {$pay_id}",
			$log_context
		);

		// ----------------------------------------------------
		// 1. GET REQUEST (CUSTOMER RETURN REDIRECT)
		// ----------------------------------------------------
		// The customer's browser lands here coming back from the payment portal.
		// This branch may only confirm the payment with the backend and send the
		// customer to the right page. Nothing in the query string (order_status,
		// pay_id, ...) is allowed to move the order by itself: the order status
		// is cross-checked with the backend by sync_single_order_status(), which
		// authenticates with the merchant account's secret key.
		if ($method === 'GET') {
			$provided_key = sanitize_text_field($data['key'] ?? ($data['order_key'] ?? ($params['key'] ?? ($params['order_key'] ?? ''))));
			$order        = ($order_id > 0) ? wc_get_order($order_id) : false;

			// One uniform response for a missing order, a wrong key or a foreign
			// payment method, so order IDs cannot be enumerated and the
			// order-received URL (which carries the order key) is only ever
			// issued to a caller that already holds that key.
			if (
				$order_id <= 0 ||
				!$order ||
				empty($provided_key) ||
				!hash_equals($order->get_order_key(), $provided_key) ||
				($order->get_payment_method() !== 'nft-checkout' && $order->get_payment_method() !== 'nft_checkout')
			) {
				return new WP_REST_Response([
					'success' => false,
					'message' => 'Invalid request'
				], 403);
			}

			// Ensure session recognizes this order for Safari/cookies
			if (isset(WC()->session)) {
				WC()->session->set('order_awaiting_payment', $order->get_id());
			}

			// A pay_id in the return URL is adopted only when the order has no
			// payment id recorded yet. Otherwise the caller could point the order
			// at a different (already paid) payment request and have the sync
			// below confirm it against the wrong payment.
			if (!empty($pay_id)) {
				$known_pay_id = sanitize_text_field((string) $order->get_meta('_nft_checkout_pay_id'));
				if ($known_pay_id === '' && function_exists('nft_checkout_get_order_payment_link')) {
					$link_row = nft_checkout_get_order_payment_link($order_id);
					if (!empty($link_row->uuid)) {
						$known_pay_id = sanitize_text_field((string) $link_row->uuid);
					}
				}

				if ($known_pay_id === '') {
					$order->update_meta_data('_nft_checkout_pay_id', $pay_id);
					$order->save();

					if (function_exists('nft_checkout_store_order_payment_link')) {
						nft_checkout_store_order_payment_link($order, [
							'uuid'           => $pay_id,
							'payment_link'   => esc_url_raw((string) ($data['payment_link'] ?? '')),
							'customer_email' => $order->get_billing_email(),
							'amount'         => $order->get_total(),
						]);
					}
				} elseif (!hash_equals($known_pay_id, $pay_id)) {
					NFT_Checkout_Payment_Gateway_Logger::warning(
						"Redirect for order #{$order_id} carried pay_id '{$pay_id}' but the order is recorded under '{$known_pay_id}'; ignored.",
						$log_context
					);
				}
			}

			// Confirm the payment with the backend (signed request) while the
			// order is still open. This is the only way a GET can move the order.
			if ($order->has_status(['pending', 'on-hold']) && class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
				NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->sync_single_order_status($order);
				$order = wc_get_order($order_id);
			}

			$wc_status = $order ? $order->get_status() : 'pending';
			if (in_array($wc_status, ['failed', 'cancelled', 'expired'], true)) {
				wc_add_notice(__('Payment was not completed. Please try again.', 'nft-checkout-payment-gateway'), 'error');
				wp_safe_redirect(wc_get_checkout_url());
			} else {
				wp_safe_redirect($order->get_checkout_order_received_url());
			}
			exit;
		}

		// -------------------------
		// 2. POST REQUEST (WEBHOOK)
		// -------------------------
		if ($method !== 'POST') {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Method not allowed'
			], 405);
		}

		// Verify signature first; fall back to API key / nonce authentication
		$sig_valid = $this->nft_checkout_verify_webhook_signature($request);
		if (!$sig_valid) {
			$auth_header  = $request->get_header('authorization');
			$bearer_token = '';
			if (!empty($auth_header) && preg_match('/Bearer\s+(\S+)/i', $auth_header, $matches)) {
				$bearer_token = $matches[1];
			}
			$decoded_nonce = base64_decode($api_key_raw, true);
			$key_to_verify = ($decoded_nonce !== false && !empty($decoded_nonce)) ? $decoded_nonce : $api_key_raw;

			if (
				(!empty($key_to_verify) && $this->nft_checkout_verify_api_key($key_to_verify)) ||
				(!empty($api_key_raw) && $this->nft_checkout_verify_api_key($api_key_raw)) ||
				(!empty($bearer_token) && $this->nft_checkout_verify_api_key($bearer_token))
			) {
				$sig_valid = true;
			}
		}

		if (!$sig_valid) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook authentication failed for order #{$order_id}",
				$log_context
			);
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'INVALID_WEBHOOK_SIGNATURE'
			], 401);
		}

		// -------------------------
		// 3. ORDER LOOKUP (authenticated callers only)
		// -------------------------
		if ($order_id <= 0) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Invalid ID'
			], 400);
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Order not found'
			], 404);
		}

		// Reject non-nft-checkout orders
		if ($order->get_payment_method() !== 'nft-checkout' && $order->get_payment_method() !== 'nft_checkout') {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Invalid payment method'
			], 400);
		}

		// Verify amount if present in payload
		$raw_amount = $data['amount'] ?? ($data['meta_data']['amount'] ?? ($data['total_amount'] ?? ($data['transaction_details']['amount'] ?? ($data['transaction_details']['total_amount'] ?? null))));
		if ($raw_amount !== null && is_numeric($raw_amount)) {
			$webhook_amount = floatval($raw_amount);
			$order_amount   = floatval($order->get_total());
			if (abs($webhook_amount - $order_amount) > 0.05) {
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

		// Verify currency if present in payload
		$webhook_currency = sanitize_text_field(
			$data['currency']
			?? ($data['curr_code']
			?? ($data['from_currency']
			?? ($data['to_currency']
			?? '')))
		);
		if (!empty($webhook_currency) && strtoupper(trim($webhook_currency)) !== strtoupper(trim((string) $order->get_currency()))) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook currency mismatch for order #{$order_id}: order currency " . $order->get_currency() . " vs webhook {$webhook_currency}",
				$log_context
			);
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'CURRENCY_MISMATCH'
			], 400);
		}

		// Store pay_id / uuid
		if (!empty($pay_id)) {
			$order->update_meta_data('_nft_checkout_pay_id', $pay_id);
			$order->save();
		}

		if (
			!empty($pay_id) &&
			($order->get_payment_method() === 'nft-checkout' || $order->get_payment_method() === 'nft_checkout') &&
			function_exists('nft_checkout_store_order_payment_link')
		) {
			nft_checkout_store_order_payment_link($order, [
				'uuid'           => $pay_id,
				'payment_link'   => $data['payment_link'] ?? '',
				'customer_email' => $data['customer_email'] ?? $order->get_billing_email(),
				'amount'         => $raw_amount ?: $order->get_total(),
			]);
		}

		// -------------------------
		// 4. VERIFY PAYLOAD + ENGINE CALL
		// -------------------------
		if (!empty($api_order_status)) {
			$result = NFT_Checkout_Payment_State_Engine::handle_event(
				$order_id,
				'webhook_update',
				[
					'status'        => $api_order_status,
					'payment_token' => $pay_id,
					'source'        => 'Webhook',
				]
			);

			NFT_Checkout_Payment_Gateway_Logger::info(
				"NFT Checkout ENGINE RESULT | Order #{$order_id} | " . json_encode($result),
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
