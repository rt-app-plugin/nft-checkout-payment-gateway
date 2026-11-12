<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'nft-checkout-payment-gateway-utils.php';
require_once plugin_dir_path(__FILE__) . 'class-nft-checkout-payment-state-engine.php';

/**
 * The plugin's one public endpoint: /wp-json/nft-checkout/v1/data.
 *
 * GET  - the customer's browser coming back from RT. It may only confirm the
 *        payment with RT (a signed, merchant-bound lookup) and send the
 *        customer to the right page. Nothing in the query string moves the
 *        order (NFT-01), and nothing from it is stored (NFT-03).
 * POST - RT's status webhook. Accepted only with a valid HMAC signature made
 *        with the secret key of the account that raised THIS order's voucher
 *        (NFT-02, NFT-08); the payment id, exact amount, currency and order
 *        must all match the order (NFT-02); every delivery is deduplicated.
 */
class NFT_CHECKOUT_PAYMENT_GATEWAY_REST_API
{
	/** Webhook timestamps further from now than this are refused (seconds). */
	const WEBHOOK_MAX_SKEW = 300;

	/** How long a seen signature is remembered for replay detection (seconds). */
	const WEBHOOK_REPLAY_TTL = 900;

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

		// Scoped CORS headers for this plugin's route only. Matched on the
		// resolved REST route, never on REQUEST_URI.
		add_filter('rest_pre_serve_request', function ($value, $result, $request) {
			$route = ($request instanceof WP_REST_Request) ? (string) $request->get_route() : '';

			if ($route !== '' && strpos($route, '/' . NFT_CHECKOUT_REST_NAMESPACE . '/') === 0) {
				header('Access-Control-Allow-Origin: ' . NFT_CHECKOUT_BASE_URL);
				header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
				header('Access-Control-Allow-Headers: Content-Type, Accept, User-Agent, X-Signature, X-Timestamp');

				if (strtoupper((string) $request->get_method()) === 'OPTIONS') {
					status_header(200);
					exit;
				}
			}

			return $value;
		}, 15, 3);
	}

	public function nft_checkout_register_routes()
	{
		$register = function () {
			register_rest_route(NFT_CHECKOUT_REST_NAMESPACE, '/data', array(
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
	 * NFT-02 / NFT-08: a webhook is accepted only with a valid HMAC-SHA256
	 * signature of "<X-Timestamp>.<raw body>" made with the secret key of the
	 * account that raised the order's voucher, with the timestamp within
	 * WEBHOOK_MAX_SKEW and the signature not seen before. No other credential
	 * is accepted: a key in the body, a bearer token or a nonce is refused.
	 *
	 * @return true|WP_Error
	 */
	private function nft_checkout_verify_webhook(WP_REST_Request $request, $order)
	{
		if (!$order instanceof WC_Order) {
			return new WP_Error('no_order', 'Authentication failed.');
		}

		$account = nft_checkout_get_order_account($order);
		if ($account === null || $account['secret_key'] === '') {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook rejected: no configured account matches the order', ['order_id' => $order->get_id()]);
			return new WP_Error('no_account', 'Authentication failed.');
		}

		$secret = trim(trim((string) $account['secret_key']), '"\'');

		$sig_header       = trim((string) $request->get_header('x-signature'));
		$timestamp_header = trim((string) $request->get_header('x-timestamp'));

		if ($sig_header === '' || $timestamp_header === '') {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook rejected: X-Signature / X-Timestamp headers required', ['order_id' => $order->get_id()]);
			return new WP_Error('signature_required', 'Authentication failed.');
		}

		// X-Timestamp must be a strict unsigned digit string.
		if (!ctype_digit($timestamp_header) || strlen($timestamp_header) > 12) {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook timestamp malformed', ['order_id' => $order->get_id()]);
			return new WP_Error('timestamp_malformed', 'Authentication failed.');
		}

		if (abs(time() - (int) $timestamp_header) > self::WEBHOOK_MAX_SKEW) {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook timestamp expired', ['order_id' => $order->get_id()]);
			return new WP_Error('timestamp_expired', 'Authentication failed.');
		}

		$raw_body     = $request->get_body();
		$expected_sig = hash_hmac('sha256', $timestamp_header . '.' . $raw_body, $secret);
		if (!hash_equals($expected_sig, strtolower($sig_header))) {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook HMAC signature mismatch', ['order_id' => $order->get_id()]);
			return new WP_Error('signature_mismatch', 'Authentication failed.');
		}

		// Replay store: the same signature is only ever accepted once.
		$replay_key = 'rt_nft_wh_' . substr(hash('sha256', $order->get_id() . '|' . $sig_header), 0, 40);
		if (get_transient($replay_key) !== false) {
			NFT_Checkout_Payment_Gateway_Logger::warning('Webhook replay ignored', ['order_id' => $order->get_id()]);
			return new WP_Error('replay', 'Duplicate delivery.');
		}
		set_transient($replay_key, 1, self::WEBHOOK_REPLAY_TTL);

		return true;
	}

	/**
	 * Handles incoming NFT Checkout API requests.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object.
	 */
	/**
	 * NFT-02: write one note and send one email the first time RT reports a
	 * second completed payment for an order that is already paid.
	 *
	 * @param WC_Order $order
	 * @param string   $pay_id
	 */
	private function nft_checkout_flag_second_payment($order, $pay_id)
	{
		$flagged = $order->get_meta('_nft_checkout_duplicate_payments');
		$flagged = is_array($flagged) ? array_values(array_filter($flagged, 'is_string')) : [];
		if (in_array($pay_id, $flagged, true)) {
			return;
		}
		$flagged[] = $pay_id;
		$order->update_meta_data('_nft_checkout_duplicate_payments', $flagged);

		$note = __('The customer has paid for this order twice. The order was not changed. Refund the extra payment in your RT account.', 'nft-checkout-payment-gateway');
		$order->add_order_note($note);
		$order->save();

		$recipient = get_option('admin_email');
		if (is_string($recipient) && is_email($recipient)) {
			wp_mail(
				$recipient,
				sprintf(
					/* translators: %s: order number */
					__('Order #%s was paid twice', 'nft-checkout-payment-gateway'),
					$order->get_order_number()
				),
				$note . "\n\n" . admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id())
			);
		}
	}

	public function nft_checkout_handle_api_request(WP_REST_Request $request)
	{
		$method      = strtoupper((string) $request->get_method());
		$log_context = ['source' => 'nft-checkout-payment-gateway'];

		// ----------------------------------------------------
		// 1. GET REQUEST (CUSTOMER RETURN REDIRECT)
		// ----------------------------------------------------
		if ($method === 'GET') {
			$params       = $request->get_query_params();
			$order_id     = intval($params['order_id'] ?? 0);
			$provided_key = sanitize_text_field((string) ($params['key'] ?? ($params['order_key'] ?? '')));
			$order        = ($order_id > 0) ? wc_get_order($order_id) : false;

			NFT_Checkout_Payment_Gateway_Logger::info(
				"NFT Checkout API HIT | Method: {$method} | Order #{$order_id}",
				$log_context
			);

			// One uniform response for a missing order, a wrong key or a foreign
			// payment method, so order IDs cannot be enumerated and the
			// order-received URL is only ever issued to a caller that already
			// holds the order key.
			if (
				$order_id <= 0 ||
				!$order ||
				empty($provided_key) ||
				!hash_equals($order->get_order_key(), $provided_key) ||
				!nft_checkout_is_gateway_order($order)
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

			// Confirm the payment with RT (signed, bound to the order's account)
			// while the order is still open. This is the only way a GET can move
			// the order; the query string is not consulted.
			if ($order->has_status(['pending', 'on-hold']) && class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
				NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->sync_single_order_status($order);
				$order = wc_get_order($order_id);
			}

			$wc_status = $order ? $order->get_status() : 'pending';
			if (in_array($wc_status, ['failed', 'cancelled'], true)) {
				if (function_exists('wc_add_notice') && function_exists('WC') && WC() && WC()->session) {
					wc_add_notice(__('Your card payment was not completed, so you have not been charged. Please try again or choose another payment method.', 'nft-checkout-payment-gateway'), 'error');
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

		// Only the signed body is read. Query-string and form parameters are
		// never merged in: they are not covered by the signature.
		$raw_body = $request->get_body();
		$body     = json_decode($raw_body, true);
		if (!is_array($body)) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Invalid payload'
			], 400);
		}

		$data = isset($body['api_data']) && is_array($body['api_data']) ? $body['api_data'] : $body;

		$order_id = intval($data['order_id'] ?? 0);
		if ($order_id <= 0 && !empty($data['meta_data']['order_id']) && is_numeric($data['meta_data']['order_id'])) {
			$order_id = intval($data['meta_data']['order_id']);
		}
		if ($order_id <= 0 && !empty($data['order_reference']) && is_numeric($data['order_reference'])) {
			$order_id = intval($data['order_reference']);
		}

		// A bare top-level "status" is deliberately ignored: it describes the call.
		$api_order_status = sanitize_text_field((string) (
			$data['order_status']
			?? ($data['payment_status']
			?? ($data['transaction_status'] ?? ''))
		));
		$api_order_status = strtolower(trim(trim($api_order_status), '"\''));
		if ($api_order_status === '' && isset($data['settled']) && filter_var($data['settled'], FILTER_VALIDATE_BOOLEAN)) {
			$api_order_status = 'success';
		}

		$pay_id = sanitize_text_field((string) (
			$data['pay_id']
			?? ($data['uuid']
			?? ($data['payment_token'] ?? ''))
		));
		$pay_id = trim(trim($pay_id), '"\'');

		NFT_Checkout_Payment_Gateway_Logger::info(
			"NFT Checkout API HIT | Method: {$method} | Order #{$order_id} | Status: {$api_order_status} | Pay ID: {$pay_id}",
			$log_context
		);

		$order = ($order_id > 0) ? wc_get_order($order_id) : false;
		if ($order_id <= 0 || !$order || !nft_checkout_is_gateway_order($order)) {
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'INVALID_WEBHOOK_SIGNATURE',
				'message'    => 'Authentication failed.'
			], 401);
		}

		$verified = $this->nft_checkout_verify_webhook($request, $order);
		if (is_wp_error($verified)) {
			if ($verified->get_error_code() === 'replay') {
				// Already processed once; acknowledge without acting on it.
				return new WP_REST_Response([
					'success' => false,
					'message' => 'Duplicate delivery ignored.',
					'state'   => NFT_Checkout_Payment_State_Engine::resolve_final_state($order, $api_order_status),
				], 200);
			}
			return new WP_REST_Response([
				'success'    => false,
				'error_code' => 'INVALID_WEBHOOK_SIGNATURE',
				'message'    => 'Authentication failed.'
			], 401);
		}

		// -------------------------
		// 3. ONE STRICT CHECK (payment id, exact amount, currency, order, mode)
		// -------------------------
		$loader    = class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader') ? NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance() : null;
		$known_ids = $loader
			? $loader->nft_checkout_known_order_pay_ids($order)
			: array_values(array_filter([(string) $order->get_meta('_nft_checkout_pay_id')]));
		$account   = nft_checkout_get_order_account($order);

		// The voucher's payment link is created when the customer opens their
		// e-mail, so the first payment id an order learns may arrive with this
		// (signed) webhook. After that only the ids the order was issued count.
		// NFT-01: when a first payment failed and the shopper was sent a new
		// payment email, the payment that follows carries a new id. This
		// message is signed with the order's own account secret, so it may
		// introduce that id (see nft_checkout_may_learn_new_pay_id()).
		$id_is_known = false;
		foreach ($known_ids as $known_id) {
			if (nft_checkout_pay_ids_match($known_id, $pay_id)) {
				$id_is_known = true;
				break;
			}
		}

		// NFT-02: a second payment for an order that is already paid. The order
		// is never paid twice; the shop owner is told so the extra payment can
		// be refunded, and RT is told the message was received.
		if (
			!$id_is_known && $pay_id !== '' && $order->is_paid() &&
			NFT_Checkout_Payment_State_Engine::map_status($api_order_status) === 'success'
		) {
			$second = nft_checkout_verify_payment_claim($order, $data, $pay_id, $known_ids, true, $account ? (int) $account['is_sandbox'] : null, true);
			if (!is_wp_error($second)) {
				$this->nft_checkout_flag_second_payment($order, $pay_id);
				return new WP_REST_Response([
					'success'       => true,
					'message'       => 'Second payment recorded for review.',
					'state'         => 'success',
					'order_status'  => $order->get_status(),
					'engine_reason' => 'duplicate_payment_flagged',
				], 200);
			}
		}

		$claim = nft_checkout_verify_payment_claim(
			$order,
			$data,
			$pay_id,
			$known_ids,
			true,
			$account ? (int) $account['is_sandbox'] : null,
			nft_checkout_may_learn_new_pay_id($order, $data)
		);
		if (is_wp_error($claim)) {
			NFT_Checkout_Payment_Gateway_Logger::warning("Webhook refused for Order #{$order_id}: " . $claim->get_error_message(), [
				'code'     => $claim->get_error_code(),
				'received' => $pay_id,
			]);
			return new WP_REST_Response([
				'success' => false,
				'message' => $claim->get_error_message(),
			], 400);
		}

		if ($api_order_status === '') {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Status required.'
			], 422);
		}

		// News that an older payment link closed must not fail an order whose
		// shopper has since been sent a newer link.
		if (
			in_array(NFT_Checkout_Payment_State_Engine::map_status($api_order_status), ['failed', 'cancelled', 'expired'], true) &&
			nft_checkout_is_superseded_pay_id($order, $pay_id, $known_ids)
		) {
			NFT_Checkout_Payment_Gateway_Logger::info("Webhook for an older payment link ignored for Order #{$order_id}", $log_context);
			return new WP_REST_Response([
				'success'       => false,
				'message'       => 'Older payment link; order unchanged.',
				'state'         => NFT_Checkout_Payment_State_Engine::resolve_final_state($order, null),
				'order_status'  => $order->get_status(),
				'engine_reason' => 'superseded_link_ignored',
			], 200);
		}

		// The claim is verified: remember the payment id (meta, history, table).
		if ($loader) {
			$loader->nft_checkout_remember_pay_id($order, $pay_id, [
				'payment_link'   => $data['payment_link'] ?? '',
				'customer_email' => $order->get_billing_email(),
				'amount'         => $data['amount'] ?? $order->get_total(),
			]);
		}

		// -------------------------
		// 4. ENGINE CALL
		// -------------------------
		$result = NFT_Checkout_Payment_State_Engine::handle_event(
			$order_id,
			'webhook_update',
			[
				'status'         => $api_order_status,
				'payment_token'  => $pay_id,
				'source'         => 'Webhook',
				'return_code'    => sanitize_text_field((string) ($data['return_code'] ?? '')),
				'failure_reason' => sanitize_text_field((string) ($data['failure_reason'] ?? '')),
			]
		);

		NFT_Checkout_Payment_Gateway_Logger::info(
			"NFT Checkout ENGINE RESULT | Order #{$order_id} | " . wp_json_encode($result),
			$log_context
		);

		$reason = is_array($result) ? ($result['reason'] ?? null) : null;

		// 423 Locked when the order lock is held, so RT retries.
		if ($reason === 'locked_skip') {
			// NFT-03: RT is asked to send this again, so the retry must not be
			// mistaken for a replay of a message that was already applied.
			$sig_header = trim((string) $request->get_header('x-signature'));
			delete_transient('rt_nft_wh_' . substr(hash('sha256', $order_id . '|' . $sig_header), 0, 40));
			return new WP_REST_Response([
				'success' => false,
				'message' => 'Order is currently locked, please retry.'
			], 423);
		}

		// -------------------------
		// 5. RESOLVE FINAL STATE
		// -------------------------
		$order = wc_get_order($order_id);
		$state = NFT_Checkout_Payment_State_Engine::resolve_final_state($order, $api_order_status);

		$wc_status = $order->get_status();
		if ($wc_status === 'processing' || $wc_status === 'completed') {
			$state = 'success';
		}

		$is_success = ($state === 'success');
		$message    = match ($state) {
			'success'    => 'Payment confirmed successfully.',
			'failed'     => 'Payment failed.',
			'cancelled'  => 'Payment was cancelled.',
			'expired'    => 'Payment link expired.',
			'processing' => 'Payment is being processed.',
			'pending'    => 'Payment is pending.',
			default      => 'Payment status updated.'
		};

		// A webhook the shop did not act on is not acknowledged with 200:
		// 422 the status word is unknown; 409 the transition is not allowed.
		$http_code = match ($reason) {
			'no_state'                   => 422,
			'invalid_transition_blocked' => 409,
			default                      => ($result === false ? 400 : 200)
		};

		return new WP_REST_Response([
			'success'       => $is_success,
			'message'       => $message,
			'state'         => $state,
			'order_status'  => $wc_status,
			'engine_reason' => $reason,
		], $http_code);
	}
}
