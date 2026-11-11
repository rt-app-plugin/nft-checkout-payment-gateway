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
	 * Verify webhook HMAC-SHA256 signature bound to the specific order's
	 * merchant account and mode.
	 *
	 * X-Signature = HMAC-SHA256(secret_key, X-Timestamp + '.' + raw_body)
	 *
	 * @param WP_REST_Request $request   Incoming REST request.
	 * @param int             $order_id  Order ID parsed from signed body.
	 * @param array           $body_data Parsed body data.
	 * @return array{valid:bool, order?:WC_Order, error_code?:string}
	 */
	private function nft_checkout_verify_webhook_signature(WP_REST_Request $request, $order_id, array $body_data)
	{
		$signature = trim((string) ($request->get_header('x-signature') ?: $request->get_header('x_signature')));
		$timestamp = trim((string) ($request->get_header('x-timestamp') ?: $request->get_header('x_timestamp')));

		if (
			$signature === '' ||
			$timestamp === '' ||
			!preg_match('/^[a-f0-9]{64}$/i', $signature) ||
			!preg_match('/^\d{1,12}$/', $timestamp)
		) {
			return ['valid' => false, 'error_code' => 'INVALID_WEBHOOK_SIGNATURE'];
		}

		// Reject timestamps outside +/- 5 minutes (300 seconds)
		if (abs(time() - (int) $timestamp) > 300) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				'Webhook timestamp expired',
				['source' => 'nft-checkout-payment-gateway', 'order_id' => $order_id]
			);
			return ['valid' => false, 'error_code' => 'EXPIRED_WEBHOOK_TIMESTAMP'];
		}

		if ($order_id <= 0) {
			return ['valid' => false, 'error_code' => 'INVALID_WEBHOOK_SIGNATURE'];
		}

		$order = wc_get_order($order_id);
		if (
			!$order instanceof WC_Order ||
			($order->get_payment_method() !== 'nft-checkout' && $order->get_payment_method() !== 'nft_checkout')
		) {
			return ['valid' => false, 'error_code' => 'INVALID_WEBHOOK_SIGNATURE'];
		}

		// Bind strictly to the order's recorded environment mode (sandbox vs live)
		$order_is_sandbox = nft_checkout_is_order_sandbox($order);
		if (!empty($body_data['mode'])) {
			$payload_mode  = strtolower(sanitize_text_field((string) $body_data['mode']));
			$expected_mode = $order_is_sandbox ? 'sandbox' : 'live';
			if ($payload_mode !== $expected_mode) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"Webhook mode mismatch for order #{$order_id}: expected {$expected_mode}, got {$payload_mode}",
					['source' => 'nft-checkout-payment-gateway']
				);
				return ['valid' => false, 'error_code' => 'MODE_MISMATCH'];
			}
		}

		// Bind strictly to the single merchant account associated with this order
		$matched_account = nft_checkout_match_order_account($order, $order_is_sandbox);
		if (!is_array($matched_account)) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook rejected: unable to bind order #{$order_id} to a configured merchant account",
				['source' => 'nft-checkout-payment-gateway']
			);
			return ['valid' => false, 'error_code' => 'ACCOUNT_BINDING_FAILED'];
		}

		$secret_key = $order_is_sandbox
			? (string) ($matched_account['sandbox_secret_key'] ?? '')
			: (string) ($matched_account['live_secret_key'] ?? '');

		if ($secret_key === '') {
			return ['valid' => false, 'error_code' => 'INVALID_WEBHOOK_SIGNATURE'];
		}

		$raw_body        = (string) $request->get_body();
		$payload_to_sign = $timestamp . '.' . $raw_body;
		$expected_sig    = hash_hmac('sha256', $payload_to_sign, $secret_key);

		if (!hash_equals($expected_sig, strtolower($signature))) {
			return ['valid' => false, 'error_code' => 'INVALID_WEBHOOK_SIGNATURE'];
		}

		// Replay protection after valid HMAC verification (TTL exceeds timestamp window)
		$sig_cache_key = 'rt_nft_webhook_sig_' . hash('sha256', $order_id . '|' . strtolower($signature));
		if (get_transient($sig_cache_key) !== false) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook replay detected for order #{$order_id}",
				['source' => 'nft-checkout-payment-gateway']
			);
			return ['valid' => false, 'error_code' => 'WEBHOOK_REPLAY_DETECTED'];
		}

		set_transient($sig_cache_key, 1, 600);

		return ['valid' => true, 'order' => $order];
	}

	/**
	 * Handles incoming NFT Checkout API requests (GET return URL navigation & POST webhooks).
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object.
	 */
	public function nft_checkout_handle_api_request(WP_REST_Request $request)
	{
		$method      = $request->get_method();
		$log_context = ['source' => 'nft-checkout-payment-gateway'];

		// ----------------------------------------------------
		// 1. GET REQUEST (CUSTOMER RETURN NAVIGATION ONLY)
		// ----------------------------------------------------
		// Treat the return URL strictly as a navigation mechanism.
		// Never trust query-string payment statuses or payment IDs,
		// and never call payment_complete() or alter order status on GET.
		if ($method === 'GET') {
			$query_params = $request->get_query_params();
			$order_id     = absint($query_params['order_id'] ?? 0);
			$provided_key = sanitize_text_field((string) ($query_params['key'] ?? ($query_params['order_key'] ?? '')));
			$order        = ($order_id > 0) ? wc_get_order($order_id) : false;

			if (
				$order_id <= 0 ||
				!$order instanceof WC_Order ||
				$provided_key === '' ||
				!hash_equals($order->get_order_key(), $provided_key) ||
				($order->get_payment_method() !== 'nft-checkout' && $order->get_payment_method() !== 'nft_checkout')
			) {
				return new WP_REST_Response([
					'success' => false,
					'message' => 'Invalid request'
				], 403);
			}

			// Ensure session recognizes this order for Safari/cookies
			if (function_exists('WC') && WC() && isset(WC()->session)) {
				WC()->session->set('order_awaiting_payment', $order->get_id());
			}

			// Confirm current status synchronously from authenticated server API
			if ($order->has_status(['pending', 'on-hold']) && class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
				NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->sync_single_order_status($order, true);
				$reloaded = wc_get_order($order_id);
				if ($reloaded instanceof WC_Order) {
					$order = $reloaded;
				}
			}

			$wc_status    = $order->get_status();
			$engine_state = (string) $order->get_meta('_nft_checkout_state');
			if (in_array($wc_status, ['failed', 'cancelled'], true) || $engine_state === 'expired') {
				if (function_exists('wc_add_notice')) {
					wc_add_notice(__('Payment was not completed. Please try again.', 'nft-checkout-payment-gateway'), 'error');
				}
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

		// Read webhook fields strictly from the signed request body (never query parameters)
		$body_params = $request->get_json_params();
		if (!is_array($body_params) || empty($body_params)) {
			$body_params = $request->get_body_params();
		}
		if (!is_array($body_params)) {
			$body_params = [];
		}

		$data = isset($body_params['api_data']) && is_array($body_params['api_data'])
			? $body_params['api_data']
			: $body_params;

		$order_id = absint($data['order_id'] ?? 0);
		if ($order_id <= 0 && !empty($data['order_reference']) && is_numeric($data['order_reference'])) {
			$order_id = absint($data['order_reference']);
		}
		if ($order_id <= 0 && !empty($data['meta_data']['order_id']) && is_numeric($data['meta_data']['order_id'])) {
			$order_id = absint($data['meta_data']['order_id']);
		}

		$auth_check = $this->nft_checkout_verify_webhook_signature($request, $order_id, $data);
		if (empty($auth_check['valid']) || empty($auth_check['order'])) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook authentication failed for order #{$order_id}",
				array_merge($log_context, ['error_code' => $auth_check['error_code'] ?? 'INVALID_WEBHOOK_SIGNATURE'])
			);
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => $auth_check['error_code'] ?? 'INVALID_WEBHOOK_SIGNATURE',
			], 401);
		}

		/** @var WC_Order $order */
		$order = $auth_check['order'];

		// -------------------------
		// 3. STRICT PAYLOAD VERIFICATION (NFT-03)
		// -------------------------
		$api_order_status = sanitize_text_field((string) (
			$data['order_status']
			?? ($data['payment_status']
			?? ($data['status']
			?? ($data['transaction_status']
			?? ($data['transaction_details']['status'] ?? ''))))
		));
		$api_order_status = trim(trim($api_order_status), '"\'');

		$pay_id = sanitize_text_field((string) (
			$data['pay_id']
			?? ($data['uuid']
			?? ($data['payment_token']
			?? ($data['transaction_details']['transaction_id'] ?? '')))
		));
		$pay_id = trim(trim($pay_id), '"\'');

		$decoded_uuid = nft_checkout_decode_payment_uuid($pay_id);
		if ($pay_id === '' || $decoded_uuid === '') {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook missing or invalid payment ID for order #{$order_id}",
				$log_context
			);
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'MISSING_OR_INVALID_PAYMENT_ID'
			], 400);
		}

		// Require the webhook payment ID to match the order's recorded payment ID when active/settled/cancelled,
		// while allowing a cryptographically verified retry payment ID only after a failed or expired attempt.
		$existing_pay_id = sanitize_text_field((string) $order->get_meta('_nft_checkout_pay_id'));
		if ($existing_pay_id !== '') {
			$existing_uuid = nft_checkout_decode_payment_uuid($existing_pay_id);
			if ($existing_uuid === '' || !hash_equals($existing_uuid, $decoded_uuid)) {
				$current_engine_state = strtolower((string) $order->get_meta('_nft_checkout_state'));
				$is_retryable_failure = !$order->is_paid()
					&& !$order->has_status(['processing', 'completed', 'refunded', 'cancelled'])
					&& (in_array($current_engine_state, ['failed', 'expired'], true) || $order->has_status('failed'));

				if (!$is_retryable_failure) {
					NFT_Checkout_Payment_Gateway_Logger::warning(
						"Webhook payment ID mismatch for order #{$order_id}",
						$log_context
					);
					return new WP_REST_Response([
						'success'    => false,
						'error_code' => 'PAYMENT_ID_MISMATCH'
					], 400);
				}
			}
		}

		if ($api_order_status === '') {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook missing order_status for order #{$order_id}",
				$log_context
			);
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'MISSING_ORDER_STATUS'
			], 400);
		}

		$raw_amount = $data['amount']
			?? ($data['meta_data']['amount']
			?? ($data['total_amount']
			?? ($data['transaction_details']['amount']
			?? ($data['transaction_details']['total_amount'] ?? null))));

		if ($raw_amount === null || $raw_amount === '' || !is_numeric($raw_amount)) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook missing or non-numeric amount for order #{$order_id}",
				$log_context
			);
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'MISSING_AMOUNT'
			], 400);
		}

		$webhook_amount = (float) $raw_amount;
		$order_amount   = (float) $order->get_total();
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

		$webhook_currency = sanitize_text_field((string) (
			$data['currency']
			?? ($data['curr_code']
			?? ($data['from_currency']
			?? ($data['to_currency'] ?? '')))
		));
		$webhook_currency = strtoupper(trim($webhook_currency));
		$order_currency   = strtoupper(trim((string) $order->get_currency()));
		if ($webhook_currency === '' || $webhook_currency !== $order_currency) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Webhook currency mismatch for order #{$order_id}: order currency {$order_currency} vs webhook {$webhook_currency}",
				$log_context
			);
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'CURRENCY_MISMATCH'
			], 400);
		}

		NFT_Checkout_Payment_Gateway_Logger::info(
			"NFT Checkout Webhook verified | Order #{$order_id} | Status: {$api_order_status}",
			$log_context
		);

		$is_already_paid = $order->is_paid()
			|| $order->has_status(['processing', 'completed', 'refunded'])
			|| $order->get_meta('_nft_checkout_state') === 'success';

		if (!$is_already_paid) {
			$order->update_meta_data('_nft_checkout_pay_id', $pay_id);
			$order->save();

			if (function_exists('nft_checkout_store_order_payment_link')) {
				$payment_link = '';
				if (!empty($data['payment_link']) && nft_checkout_is_valid_rt_url((string) $data['payment_link'])) {
					$payment_link = esc_url_raw((string) $data['payment_link'], ['https']);
				}
				nft_checkout_store_order_payment_link($order, [
					'uuid'           => $pay_id,
					'payment_link'   => $payment_link,
					'customer_email' => $order->get_billing_email(),
					'amount'         => $webhook_amount,
				]);
			}
		}

		// -------------------------
		// 4. STATE ENGINE EXECUTION
		// -------------------------
		$result = NFT_Checkout_Payment_State_Engine::handle_event(
			$order_id,
			'webhook_update',
			[
				'status'        => $api_order_status,
				'payment_token' => $pay_id,
				'source'        => 'Webhook',
			]
		);

		if (is_array($result) && ($result['reason'] ?? '') === 'locked_skip') {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Order is currently locked'
			], 423);
		}

		NFT_Checkout_Payment_Gateway_Logger::info(
			"NFT Checkout ENGINE RESULT | Order #{$order_id} | Reason: " . sanitize_text_field((string) ($result['reason'] ?? 'none')) . " | State: " . sanitize_text_field((string) ($result['state'] ?? '')),
			$log_context
		);

		$order = wc_get_order($order_id);
		$state = NFT_Checkout_Payment_State_Engine::resolve_final_state(
			$order,
			$api_order_status
		);

		$wc_status = $order->get_status();
		if ($order->is_paid() || $wc_status === 'processing' || $wc_status === 'completed') {
			$state = 'success';
		}

		$is_success = ($state === 'success');
		$message    = $is_success ? 'Payment confirmed successfully.' : 'Payment status updated.';

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
