<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-nft-checkout-payment-state-engine.php';
require_once plugin_dir_path(__FILE__) . 'class-nft-checkout-payment-logger.php';
/**
 * Class NFT_CHECKOUT_PAYMENT_GATEWAY_Loader
 * Handles the loading and initialization of the NFT Checkout plugin.
 */
class NFT_CHECKOUT_PAYMENT_GATEWAY_Loader
{
	private static $instance = null;
	private $admin_notices;

	private $base_url;

	/**
	 * Get the singleton instance of this class.
	 * @return NFT_CHECKOUT_PAYMENT_GATEWAY_Loader
	 */
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Constructor. Sets up actions and hooks.
	 */
	private function __construct()
	{

		$this->base_url = NFT_CHECKOUT_BASE_URL;
		
		$this->admin_notices = new NFT_CHECKOUT_PAYMENT_GATEWAY_Admin_Notices();

		add_action('admin_init', [$this, 'nft_checkout_handle_environment_check']);
		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
		add_action('plugins_loaded', [$this, 'nft_checkout_init'], 10);

		add_action('wp_ajax_nft_checkout_manual_sync', [$this, 'nft_checkout_manual_sync_callback']);
		add_action('wp_ajax_nft-checkout_manual_sync', [$this, 'nft_checkout_manual_sync_callback']);
		add_filter('cron_schedules', [$this, 'nft_checkout_add_cron_interval']);
		add_action('nft_checkout_cron_event', [$this, 'handle_cron_event']);
		add_action('nft_checkout_order_sync_cron_event', [$this, 'sync_pending_orders']);
		add_action('nft_checkout_async_sync_order', [$this, 'sync_single_order_status'], 10, 1);
		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'nft_checkout_admin_order_sync_on_view']);
		add_action('load-woocommerce_page_wc-orders', [$this, 'nft_checkout_admin_orders_screen_sync']);
		add_action('load-edit.php', [$this, 'nft_checkout_admin_orders_screen_sync']);
		add_action('woocommerce_thankyou', [$this, 'nft_checkout_customer_order_sync']);
		add_action('woocommerce_view_order', [$this, 'nft_checkout_customer_order_sync']);
		add_filter('woocommerce_order_actions', [$this, 'nft_checkout_add_order_actions']);
		add_action('woocommerce_order_action_nft_checkout_check_status', [$this, 'nft_checkout_process_order_action_check_status']);
		// NFT-29: Strip order attribution session entry only for NFT Checkout orders to prevent
		// session-derived attribution metadata from interfering with external voucher checkout redirects.
		add_action('woocommerce_checkout_create_order', function($order){
			if ($order instanceof WC_Order && $order->get_payment_method() === 'nft-checkout') {
				$order->delete_meta_data('_wc_order_attribution_session_entry');
			}
		}, 10);

		add_action('woocommerce_before_checkout_form', [$this, 'nft_checkout_show_checkout_error']);

		// Prevent order reuse on standard checkout for this gateway if identity changes
		add_action('woocommerce_before_checkout_process', function() {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified by WooCommerce in WC_Checkout::process_checkout().
			if ( isset( $_POST['payment_method'] ) && 'nft-checkout' === sanitize_text_field(wp_unslash($_POST['payment_method'])) ) {
				if ( function_exists('WC') && WC()->session ) {
					$awaiting_order_id = WC()->session->get('order_awaiting_payment');
					if ( $awaiting_order_id ) {
						$awaiting_order = wc_get_order( $awaiting_order_id );
						$posted_email   = isset($_POST['billing_email']) ? sanitize_email(wp_unslash($_POST['billing_email'])) : '';
						$posted_phone   = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
						
						if ( $awaiting_order && ( $awaiting_order->get_billing_email() !== $posted_email || $awaiting_order->get_billing_phone() !== $posted_phone ) ) {
							WC()->session->set( 'order_awaiting_payment', null );
						}
					}
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		});
	}

	/**
	 * Initializes the plugin.
	 * This method is hooked into 'plugins_loaded' action.
	 */
	public function nft_checkout_init()
	{
		// NFT-07: Run legacy key cleanup and table migration on plugin version change (since activation hook does not run on updates)
		if (get_option('nft_checkout_plugin_version') !== NFT_CHECKOUT_PLUGIN_VERSION) {
			if (function_exists('nft_checkout_clean_legacy_settings_keys')) {
				nft_checkout_clean_legacy_settings_keys();
			}
			if (function_exists('nft_checkout_migrate_legacy_payment_links')) {
				nft_checkout_migrate_legacy_payment_links();
			}
			update_option('nft_checkout_plugin_version', NFT_CHECKOUT_PLUGIN_VERSION);
		}

		// Check if the environment is compatible
		$environment_warning = nft_checkout_check_system_requirements();
		if ($environment_warning) {
			return;
		}

		// Ensure prefixed cron schedules are registered and active (also repairs legacy schedules on upgrade)
		$this->ensure_cron_schedules();

		// Initialize gateways
		$this->nft_checkout_init_gateways();

		// Register blocks gateway
		$this->nft_checkout_init_blocks();

		// Initialize REST API
		$rest_api = NFT_CHECKOUT_PAYMENT_GATEWAY_REST_API::get_instance();
		$rest_api->nft_checkout_register_routes();

		// Add plugin action links
		add_filter('plugin_action_links_' . plugin_basename(NFT_CHECKOUT_PAYMENT_GATEWAY_FILE), [$this, 'nft_checkout_plugin_action_links']);

		// Add plugin row meta
		add_filter('plugin_row_meta', [$this, 'nft_checkout_plugin_row_meta'], 10, 2);
	}

	public function nft_checkout_show_checkout_error()
	{
		if (!function_exists('WC') || !WC()->session) return;

		$error = WC()->session->get('nft_checkout_error');
		if (!$error) return;

		$messages = [
			'failed'    => 'Payment failed. Please try again.',
			'cancelled' => 'Payment was cancelled.',
			'expired'   => 'Payment session expired. Please try again.',
			'duplicate' => 'This order appears to be a duplicate and cannot be processed at this time.'
		];

		// Clear error immediately
		WC()->session->__unset('nft_checkout_error');

		if (isset($messages[$error])) {
			wc_add_notice($messages[$error], 'error');
		}
	}

	/**
	 * Initialize gateways.
	 */
	private function nft_checkout_init_gateways()
	{
		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		include_once NFT_CHECKOUT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-nft-checkout-payment-gateway.php';

		add_filter('woocommerce_payment_gateways', function ($methods) {
			$methods[] = 'NFT_CHECKOUT_PAYMENT_GATEWAY';			
			return $methods;
		});
	}

	private function nft_checkout_init_blocks() {
		
			if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {

				require_once NFT_CHECKOUT_PAYMENT_GATEWAY_PLUGIN_DIR . 'includes/class-nft-checkout-blocks-gateway.php';

				add_action( 'woocommerce_blocks_payment_method_type_registration', function( $registry ) {
					$registry->register( new NFT_CHECKOUT_Blocks_Gateway() );
				});
			}
	
	}


	private function get_api_url($endpoint)
	{
		$endpoint = '/' . ltrim($endpoint, '/');
		if (strpos($endpoint, '/payment-api/') !== 0 && $endpoint !== '/payment-api') {
			if (strpos($endpoint, '/api/') === 0) {
				$endpoint = substr($endpoint, 4);
			}
			$endpoint = '/payment-api' . $endpoint;
		}
		return $this->base_url . $endpoint;
	}

	/**
	 * Add action links to the plugin page.
	 * @param array $links
	 * @return array
	 */
	public function nft_checkout_plugin_action_links($links)
	{
		$plugin_links = [
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=nft-checkout')) . '">' . esc_html__('Settings', 'nft-checkout-payment-gateway') . '</a>',
		];

		return array_merge($plugin_links, $links);
	}

	/**
	 * Add row meta to the plugin page.
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function nft_checkout_plugin_row_meta($links, $file)
	{
		if (plugin_basename(NFT_CHECKOUT_PAYMENT_GATEWAY_FILE) === $file) {
			$row_meta = [
				'docs'    => '<a href="' . esc_url(apply_filters('nft_checkout_docs_url', 'https://rt.app/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'nft-checkout-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('nft_checkout_support_url', 'https://rt.app/contact-us')) . '" target="_blank">' . esc_html__('Support', 'nft-checkout-payment-gateway') . '</a>',
			];

			$links = array_merge($links, $row_meta);
		}

		return $links;
	}

	/**
	 * Check the environment and display notices if necessary.
	 */
	public function nft_checkout_handle_environment_check()
	{
		$environment_warning = nft_checkout_check_system_requirements();
		if ($environment_warning) {
			// Sanitize the environment warning before displaying it
			$this->admin_notices->nft_checkout_add_notice('error', 'error', sanitize_text_field($environment_warning));
		}
	}



	/**
	 * Add custom cron schedules.
	 */
	public function nft_checkout_add_cron_interval($schedules)
	{
		$schedules['rt_nft_checkout_every_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS, // 300 seconds
			'display'  => __('Every Five Minutes', 'nft-checkout-payment-gateway')
		);
		$schedules['rt_nft_checkout_every_two_hours'] = array(
			'interval' => 2 * HOUR_IN_SECONDS, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours', 'nft-checkout-payment-gateway')
		);
		return $schedules;
	}

	/**
	 * Ensure both account sync (2h) and pending order sync (5m) cron events are scheduled
	 * with prefixed intervals, migrating any legacy unprefixed schedule entries.
	 *
	 * @return void
	 */
	public function ensure_cron_schedules()
	{
		$acct_event = wp_get_scheduled_event('nft_checkout_cron_event');
		if (!$acct_event) {
			wp_schedule_event(time(), 'rt_nft_checkout_every_two_hours', 'nft_checkout_cron_event');
		} elseif (($acct_event->schedule ?? '') !== 'rt_nft_checkout_every_two_hours') {
			wp_clear_scheduled_hook('nft_checkout_cron_event');
			wp_schedule_event(time(), 'rt_nft_checkout_every_two_hours', 'nft_checkout_cron_event');
		}

		if (!wp_next_scheduled('nft_checkout_order_sync_cron_event')) {
			wp_schedule_event(time() + 60, 'rt_nft_checkout_every_five_minutes', 'nft_checkout_order_sync_cron_event');
		}
	}

	function activate_cron_job()
	{
		NFT_Checkout_Payment_Gateway_Logger::info('Automatic payment status checks have been enabled.', ['source' => 'nft-checkout-payment-gateway']);

		wp_clear_scheduled_hook('nft_checkout_cron_event');
		wp_schedule_event(time(), 'rt_nft_checkout_every_two_hours', 'nft_checkout_cron_event');

		wp_clear_scheduled_hook('nft_checkout_order_sync_cron_event');
		wp_schedule_event(time() + 60, 'rt_nft_checkout_every_five_minutes', 'nft_checkout_order_sync_cron_event');
	}

	function deactivate_cron_job()
	{
		NFT_Checkout_Payment_Gateway_Logger::info('Automatic payment status checks have been disabled.', ['source' => 'nft-checkout-payment-gateway']);
		wp_clear_scheduled_hook('nft_checkout_cron_event');
		wp_clear_scheduled_hook('nft_checkout_order_sync_cron_event');
		wp_clear_scheduled_hook('nft_checkout_async_sync_order');
	}


	/**
	 * Verify a single merchant API key pair against /payment-api/check-merchant-status
	 * using Bearer + x-api-secret headers only (zero secrets in body, URL, or logs).
	 *
	 * @param string $public_key Merchant public API key.
	 * @param string $secret_key Merchant secret API key.
	 * @param bool   $is_sandbox True for sandbox mode, false for live mode.
	 * @return string|null 'Active', 'Inactive', 'Invalid', or null on transient transport/server error.
	 */
	private function check_account_key_pair_status(string $public_key, string $secret_key, bool $is_sandbox): ?string
	{
		$logger_context = ['source' => 'nft-checkout-payment-gateway'];
		$url            = esc_url_raw($this->get_api_url('/check-merchant-status'));

		$response = wp_remote_post($url, [
			'headers'     => [
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				'x-api-secret'  => sanitize_text_field($secret_key),
			],
			'body'        => wp_json_encode([
				'is_sandbox' => $is_sandbox ? 1 : 0,
			]),
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => true,
		]);

		if (is_wp_error($response)) {
			// Preserve existing saved status on transient network failures.
			NFT_Checkout_Payment_Gateway_Logger::error(
				'Unable to connect to the merchant status check service: ' . sanitize_text_field($response->get_error_message()),
				$logger_context
			);
			return null;
		}

		$http_code = (int) wp_remote_retrieve_response_code($response);
		$decoded   = json_decode(wp_remote_retrieve_body($response), true);
		$body_code = is_array($decoded) && isset($decoded['code']) ? (int) $decoded['code'] : $http_code;
		$status    = is_array($decoded) ? strtolower(sanitize_text_field((string) ($decoded['status'] ?? ''))) : '';
		$message   = is_array($decoded) ? strtolower(sanitize_text_field((string) ($decoded['message'] ?? ''))) : '';

		if ($http_code === 200 && ($status === 'success' || $body_code === 200)) {
			return 'Active';
		}

		if ($http_code === 403 || $body_code === 403 || false !== strpos($message, 'not active')) {
			return 'Inactive';
		}

		if ($http_code === 401 || $body_code === 401 || $body_code === 404) {
			return 'Invalid';
		}

		NFT_Checkout_Payment_Gateway_Logger::error(
			'Merchant status check returned an unexpected response code: HTTP ' . $http_code . ' / body ' . $body_code,
			$logger_context
		);
		return null;
	}

	public function handle_cron_event($sync_orders = true)
	{
		$logger_context = ['source' => 'nft-checkout-payment-gateway'];

		$accounts = function_exists('nft_checkout_get_accounts')
			? nft_checkout_get_accounts()
			: get_option('woocommerce_nft-checkout_payment_gateway_accounts', []);

		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts     = is_array($unserialized) ? $unserialized : [];
		}

		if (!$accounts || !is_array($accounts)) {
			NFT_Checkout_Payment_Gateway_Logger::warning('No payment accounts found or the account format is invalid. Sync aborted.', $logger_context);
			return [];
		}

		$checked_count = 0;
		$updated       = false;
		$statusSummary = [];

		foreach ($accounts as &$account) {
			if (!is_array($account)) {
				continue;
			}
			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			if (!empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
				$checked_count++;
				$live_result = $this->check_account_key_pair_status(
					(string) $account['live_public_key'],
					(string) $account['live_secret_key'],
					false
				);
				if ($live_result !== null) {
					if (($account['live_status'] ?? '') !== $live_result) {
						$updated = true;
					}
					$account['live_status'] = $live_result;
					$statusSummary[]        = [
						'title'  => $account['title'] ?? 'N/A',
						'mode'   => 'live',
						'status' => $live_result,
					];
				}
			}

			if ($isSandboxEnabled && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
				$checked_count++;
				$sandbox_result = $this->check_account_key_pair_status(
					(string) $account['sandbox_public_key'],
					(string) $account['sandbox_secret_key'],
					true
				);
				if ($sandbox_result !== null) {
					if (($account['sandbox_status'] ?? '') !== $sandbox_result) {
						$updated = true;
					}
					$account['sandbox_status'] = $sandbox_result;
					$statusSummary[]           = [
						'title'  => $account['title'] ?? 'N/A',
						'mode'   => 'sandbox',
						'status' => $sandbox_result,
					];
				}
			}
		}
		unset($account);

		if ($checked_count === 0) {
			NFT_Checkout_Payment_Gateway_Logger::warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
			return [];
		}

		if (!empty($statusSummary)) {
			if ($updated) {
				update_option('woocommerce_nft-checkout_payment_gateway_accounts', $accounts);

				NFT_Checkout_Payment_Gateway_Logger::info('Payment account statuses were successfully updated after syncing.', [
					'source'  => 'nft-checkout-payment-gateway',
					'context' => ['updated_accounts' => $statusSummary],
				]);
			} else {
				NFT_Checkout_Payment_Gateway_Logger::info('Payment accounts were checked, but no updates were necessary.', [
					'source'  => 'nft-checkout-payment-gateway',
					'context' => ['checked_accounts' => $statusSummary],
				]);
			}
		} else {
			NFT_Checkout_Payment_Gateway_Logger::info('Sync completed. No account status data was updated from the server.', $logger_context);
		}

		if ($sync_orders) {
			$this->sync_pending_orders(20, true);
		}

		return $statusSummary;
	}

	/**
	 * Sync all pending NFT Checkout orders with the remote payment API.
	 *
	 * @param int  $limit                Max number of pending orders to check per run.
	 * @param bool $allow_voucher_lookup Whether to resolve unrecorded voucher links via /voucher/redeem/.
	 * @return array Summary of synced order statuses.
	 */
	public function sync_pending_orders($limit = 20, $allow_voucher_lookup = true)
	{
		if (!function_exists('wc_get_orders')) {
			return [];
		}

		$orders = wc_get_orders([
			'status'         => ['pending', 'on-hold'],
			'payment_method' => ['nft-checkout', 'nft_checkout'],
			'limit'          => absint($limit),
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		$synced = [];
		if (!empty($orders) && is_array($orders)) {
			foreach ($orders as $order) {
				if (!$order instanceof WC_Order) {
					continue;
				}
				if (
					trim((string) $order->get_meta('_nft_checkout_account_unique_id')) === ''
					&& trim((string) $order->get_meta('_nft_checkout_account_public_key')) === ''
				) {
					continue;
				}
				$last_attempt = (int) $order->get_meta('_nft_checkout_last_sync_attempt');
				if ($last_attempt > 0 && (time() - $last_attempt) < 15) {
					continue;
				}
				$order->update_meta_data('_nft_checkout_last_sync_attempt', time());
				$order->save();
				$result = $this->sync_single_order_status($order->get_id(), (bool) $allow_voucher_lookup);
				if ($result) {
					$synced[$order->get_id()] = $result;
				}
			}
		}

		return $synced;
	}

	/**
	 * Sync a single NFT Checkout order status with the remote payment API.
	 *
	 * Uses authenticated /payment-api/update-txn-status bound to the order's
	 * merchant account and mode, then cross-verifies amount, currency,
	 * order_reference, uuid, is_sandbox, and settlement state via
	 * /payment-api/payment-requests/public/{uuid}/status. Fails closed on any
	 * missing or mismatched field.
	 *
	 * @param WC_Order|int $order                Order instance or order ID.
	 * @param bool         $allow_voucher_lookup Whether to query RT /voucher/redeem/{uuid} to resolve missing pay_id.
	 * @return string|null Resolved state ('success', 'refunded', 'failed', 'cancelled', 'expired', 'processing') or null.
	 */
	public function sync_single_order_status($order, $allow_voucher_lookup = true)
	{
		if (is_numeric($order)) {
			$order = wc_get_order(absint($order));
		}

		if (!$order instanceof WC_Order) {
			return null;
		}

		$payment_method = $order->get_payment_method();
		if ($payment_method !== 'nft-checkout' && $payment_method !== 'nft_checkout') {
			return null;
		}

		if ($order->has_status(['refunded'])) {
			return 'refunded';
		}

		if ($order->is_paid() || $order->has_status(['processing', 'completed']) || $order->get_meta('_nft_checkout_state') === 'success') {
			return 'success';
		}

		$order_id   = (int) $order->get_id();
		$pay_id     = sanitize_text_field((string) $order->get_meta('_nft_checkout_pay_id'));
		$is_sandbox = function_exists('nft_checkout_is_order_sandbox')
			? nft_checkout_is_order_sandbox($order)
			: false;

		$target_account = function_exists('nft_checkout_match_order_account')
			? nft_checkout_match_order_account($order, $is_sandbox)
			: null;

		if (!is_array($target_account)) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Order #{$order_id} could not be bound to a configured merchant account for API sync. Failing closed.",
				['source' => 'nft-checkout-payment-gateway']
			);
			return null;
		}

		$public_key = $is_sandbox ? (string) ($target_account['sandbox_public_key'] ?? '') : (string) ($target_account['live_public_key'] ?? '');
		$secret_key = $is_sandbox ? (string) ($target_account['sandbox_secret_key'] ?? '') : (string) ($target_account['live_secret_key'] ?? '');

		if ($public_key === '' || $secret_key === '') {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Missing merchant credentials for order #{$order_id} during API sync. Failing closed.",
				['source' => 'nft-checkout-payment-gateway']
			);
			return null;
		}

		// Resolve pay_id from stored table/URL, or optionally from the signed RT voucher URL once mature.
		if (empty($pay_id)) {
			if (function_exists('nft_checkout_get_order_payment_link')) {
				$link_row = nft_checkout_get_order_payment_link($order_id);
				if (!empty($link_row->uuid)) {
					$pay_id = sanitize_text_field((string) $link_row->uuid);
				}
			}

			if (empty($pay_id)) {
				$purchase_url = (string) $order->get_meta('_nft_checkout_voucher_purchase_url');
				if (!empty($purchase_url)) {
					$emailed_at = (int) $order->get_meta('_nft_checkout_voucher_emailed_at');
					$voucher_age_ok = $allow_voucher_lookup === 'force'
						|| ($allow_voucher_lookup && $emailed_at > 0 && (time() - $emailed_at) >= 20 && (time() - $emailed_at) <= 7 * DAY_IN_SECONDS);
					$pay_id = (string) $this->resolve_pay_id_from_purchase_url($purchase_url, $voucher_age_ok);
				}
			}

			if (!empty($pay_id) && function_exists('nft_checkout_decode_payment_uuid') && nft_checkout_decode_payment_uuid($pay_id) !== '') {
				$order->update_meta_data('_nft_checkout_pay_id', $pay_id);
				$order->save();
			} else {
				$pay_id = '';
			}
		}

		if (empty($pay_id)) {
			return null;
		}

		$expected_uuid = function_exists('nft_checkout_decode_payment_uuid')
			? nft_checkout_decode_payment_uuid($pay_id)
			: '';

		if ($expected_uuid === '') {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"Invalid payment token format on order #{$order_id} during API sync.",
				['source' => 'nft-checkout-payment-gateway']
			);
			return null;
		}

		// Step 1: Authenticated merchant-scoped lookup (/payment-api/update-txn-status).
		// Confirms merchant ownership, sandbox/live mode, order_id, payment_token, and transaction_status.
		$txn_response = wp_remote_post(esc_url_raw($this->get_api_url('/update-txn-status')), [
			'headers'     => [
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				'x-api-secret'  => sanitize_text_field($secret_key),
			],
			'body'        => wp_json_encode([
				'order_id'      => (string) $order_id,
				'payment_token' => $pay_id,
				'is_sandbox'    => $is_sandbox ? 1 : 0,
				'api_secret'    => $secret_key,
			]),
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => true,
		]);

		if (is_wp_error($txn_response)) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"API sync /update-txn-status transport error for order #{$order_id}: " . $txn_response->get_error_message(),
				['source' => 'nft-checkout-payment-gateway']
			);
			return null;
		}

		$txn_http_code = (int) wp_remote_retrieve_response_code($txn_response);
		if ($txn_http_code !== 200) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				"API sync /update-txn-status returned HTTP {$txn_http_code} for order #{$order_id}.",
				['source' => 'nft-checkout-payment-gateway']
			);
			return null;
		}

		$txn_data = json_decode(wp_remote_retrieve_body($txn_response), true);
		if (!is_array($txn_data) || empty($txn_data['status']) || empty($txn_data['transaction_status'])) {
			return null;
		}

		$txn_status = strtolower(sanitize_text_field((string) $txn_data['transaction_status']));

		// Step 2: When authenticated endpoint reports settled payment, strictly cross-verify amount, currency,
		// order reference, UUID, mode, and settled flags against /payment-requests/public/{uuid}/status.
		if (in_array($txn_status, ['paid', 'success', 'completed'], true)) {
			$status_url  = $this->get_api_url('/payment-requests/public/' . rawurlencode($expected_uuid) . '/status');
			$status_resp = wp_remote_get(esc_url_raw($status_url), [
				'headers'     => ['Accept' => 'application/json'],
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => true,
			]);

			if (is_wp_error($status_resp) || (int) wp_remote_retrieve_response_code($status_resp) !== 200) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"API sync cross-verification request failed for order #{$order_id}; failing closed.",
					['source' => 'nft-checkout-payment-gateway']
				);
				return null;
			}

			$status_data = json_decode(wp_remote_retrieve_body($status_resp), true);
			if (!is_array($status_data)) {
				return null;
			}

			$resp_uuid      = strtolower(trim(sanitize_text_field((string) ($status_data['uuid'] ?? ''))));
			$resp_order_ref = trim((string) ($status_data['order_reference'] ?? ''));
			$resp_settled   = !empty($status_data['settled']);
			$resp_pstatus   = strtolower(trim(sanitize_text_field((string) ($status_data['payment_status'] ?? ''))));
			$resp_amount    = $status_data['amount'] ?? null;
			$resp_currency  = strtoupper(trim(sanitize_text_field((string) ($status_data['currency'] ?? ''))));

			if ($resp_uuid === '' || !hash_equals($expected_uuid, $resp_uuid)) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"API sync UUID mismatch for order #{$order_id}.",
					['source' => 'nft-checkout-payment-gateway']
				);
				return null;
			}

			if ($resp_order_ref === '' || $resp_order_ref !== (string) $order_id) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"API sync order_reference mismatch for order #{$order_id}.",
					['source' => 'nft-checkout-payment-gateway']
				);
				return null;
			}

			if (isset($status_data['is_sandbox']) && (bool) $status_data['is_sandbox'] !== (bool) $is_sandbox) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"API sync sandbox/live mode mismatch for order #{$order_id}.",
					['source' => 'nft-checkout-payment-gateway']
				);
				return null;
			}

			if (!$resp_settled || $resp_pstatus !== 'success') {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"API sync settlement check failed for order #{$order_id}.",
					['source' => 'nft-checkout-payment-gateway']
				);
				return null;
			}

			if ($resp_amount === null || $resp_amount === '' || !is_numeric($resp_amount)) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"API sync missing or invalid amount for order #{$order_id}.",
					['source' => 'nft-checkout-payment-gateway']
				);
				return null;
			}

			$api_amount   = (float) $resp_amount;
			$order_amount = (float) $order->get_total();
			if (abs($api_amount - $order_amount) > 0.01) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"API sync amount mismatch for order #{$order_id}: expected {$order_amount}, got {$api_amount}.",
					['source' => 'nft-checkout-payment-gateway']
				);
				return null;
			}

			$order_currency = strtoupper(trim((string) $order->get_currency()));
			if ($resp_currency === '' || $resp_currency !== $order_currency) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					"API sync currency mismatch for order #{$order_id}: expected {$order_currency}, got {$resp_currency}.",
					['source' => 'nft-checkout-payment-gateway']
				);
				return null;
			}

			$tx_ref = sanitize_text_field((string) ($status_data['transaction']['reference'] ?? ($status_data['reference'] ?? '')));
			if (!empty($tx_ref)) {
				$order->update_meta_data('_nft_checkout_transaction_reference', $tx_ref);
				$order->save();
			}

			if (function_exists('nft_checkout_store_order_payment_link')) {
				nft_checkout_store_order_payment_link($order, [
					'uuid'           => $expected_uuid,
					'payment_link'   => $this->base_url . '/p/' . $expected_uuid,
					'customer_email' => $order->get_billing_email(),
					'amount'         => $order->get_total(),
				]);
			}

			NFT_Checkout_Payment_State_Engine::handle_event(
				$order_id,
				'api_sync',
				[
					'status'         => 'success',
					'payment_token'  => $pay_id,
					'transaction_id' => !empty($tx_ref) ? $tx_ref : $expected_uuid,
					'source'         => 'API Sync',
				]
			);

			return 'success';
		}

		if (in_array($txn_status, ['failed', 'cancelled', 'canceled', 'expired'], true)) {
			$normalized_fail = ($txn_status === 'canceled') ? 'cancelled' : $txn_status;
			NFT_Checkout_Payment_State_Engine::handle_event(
				$order_id,
				'api_sync',
				[
					'status'        => $normalized_fail,
					'payment_token' => $pay_id,
					'source'        => 'API Sync',
				]
			);

			return $normalized_fail;
		}

		return 'processing';
	}

	function nft_checkout_manual_sync_callback()
	{
		$logger_context = ['source' => 'nft-checkout-payment-gateway'];
		// Verify nonce first
		if (!check_ajax_referer('nft_checkout_sync_nonce', 'nonce', false)) {
			NFT_Checkout_Payment_Gateway_Logger::error('Security validation failed during manual sync.', $logger_context);
			wp_send_json_error([
				'message' => __('Security check failed. Please refresh the page and try again.', 'nft-checkout-payment-gateway')
			], 400);
			wp_die();
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
			NFT_Checkout_Payment_Gateway_Logger::error('Unauthorized manual sync attempt by user ID: ' . get_current_user_id(), $logger_context);
			wp_send_json_error([
				'message' => __('You do not have permission to perform this action.', 'nft-checkout-payment-gateway')
			], 403);
			wp_die();
		}

		NFT_Checkout_Payment_Gateway_Logger::info('Payment accounts sync initiated', $logger_context);

		try {
			ob_start();

			$statusSummary = $this->handle_cron_event(false);
			$output        = ob_get_clean();

			if (!empty($output)) {
				NFT_Checkout_Payment_Gateway_Logger::warning('Unexpected output generated during sync.', $logger_context);
			}

			NFT_Checkout_Payment_Gateway_Logger::info('Payment accounts sync completed successfully.', $logger_context);

			wp_send_json_success([
				'message'   => __('Payment accounts synchronized successfully.', 'nft-checkout-payment-gateway'),
				'timestamp' => current_time('mysql'),
				'statuses'  => $statusSummary,
			]);
		} catch (Exception $e) {
			NFT_Checkout_Payment_Gateway_Logger::error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Sync failed. Please check the WooCommerce logs for details.', 'nft-checkout-payment-gateway'),
			], 500);
		}

		wp_die(); // Always include this
	}

	/**
	 * Telemetry reporting is disabled (NFT-17 / NFT-05) so plugin and environment
	 * metadata are never transmitted automatically.
	 *
	 * @param int $plugin_status
	 * @param int $gateway_loaded
	 * @return bool
	 */
	public function nft_checkout_send_plugin_status($plugin_status, $gateway_loaded)
	{
		return false;
	}

	/**
	 * Resolve pay_id (UUID) strictly from a validated RT payment URL, optionally resolving
	 * an already-redeemed/paid RT voucher URL via the signed /voucher/redeem/{uuid} endpoint.
	 *
	 * @param string $purchase_url       Stored payment or signed voucher URL.
	 * @param bool   $allow_remote_query Whether to call POST /voucher/redeem/{uuid} for voucher URLs.
	 * @return string|null
	 */
	public function resolve_pay_id_from_purchase_url($purchase_url, $allow_remote_query = false)
	{
		if (empty($purchase_url) || !is_string($purchase_url)) {
			return null;
		}

		if (function_exists('nft_checkout_is_valid_rt_url') && !nft_checkout_is_valid_rt_url($purchase_url)) {
			return null;
		}

		if (preg_match('#/p/(?:t/)?([a-zA-Z0-9_\-]+)#', $purchase_url, $m)) {
			return sanitize_text_field($m[1]);
		}
		if (preg_match('#[?&](?:uuid|pay_id)=([a-zA-Z0-9_\-]+)#', $purchase_url, $m)) {
			return sanitize_text_field($m[1]);
		}

		if ($allow_remote_query && preg_match('#/voucher/redeem/([a-zA-Z0-9_\-]+)#', $purchase_url, $vm)) {
			$voucher_id = sanitize_text_field($vm[1]);
			$query_str  = (string) wp_parse_url($purchase_url, PHP_URL_QUERY);
			$query_args = [];
			if ($query_str !== '') {
				parse_str($query_str, $query_args);
			}
			$signature = isset($query_args['signature']) ? sanitize_text_field((string) $query_args['signature']) : '';

			if ($voucher_id !== '' && $signature !== '') {
				$redeem_api_url = $this->get_api_url('/voucher/redeem/' . rawurlencode($voucher_id));
				$redeem_res     = wp_remote_post(esc_url_raw($redeem_api_url), [
					'headers'     => [
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					'body'        => wp_json_encode(['signature' => $signature]),
					'timeout'     => 15,
					'redirection' => 0,
					'sslverify'   => true,
				]);

				if (!is_wp_error($redeem_res) && (int) wp_remote_retrieve_response_code($redeem_res) === 200) {
					$redeem_data = json_decode(wp_remote_retrieve_body($redeem_res), true);
					$link        = is_array($redeem_data) ? (string) ($redeem_data['data']['payment_link'] ?? '') : '';
					if ($link !== '' && (!function_exists('nft_checkout_is_valid_rt_url') || nft_checkout_is_valid_rt_url($link))) {
						if (preg_match('#/p/(?:t/)?([a-zA-Z0-9_\-]+)#', $link, $lm)) {
							return sanitize_text_field($lm[1]);
						}
					}
				}
			}
		}

		return null;
	}

	/**
	 * Schedule non-blocking background status sync for recent pending NFT Checkout orders
	 * when an admin opens the WooCommerce Orders screen (never performs synchronous HTTP calls during page load).
	 *
	 * @return void
	 */
	public function nft_checkout_admin_orders_screen_sync()
	{
		if (!is_admin() || wp_doing_ajax() || !current_user_can('edit_shop_orders')) {
			return;
		}

		global $pagenow;
		$page      = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		$post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';
		if ($page !== 'wc-orders' && ($pagenow !== 'edit.php' || $post_type !== 'shop_order')) {
			return;
		}

		// Skip single order edit inside HPOS wc-orders?action=edit (handled by order-view hook)
		$action = isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '';
		if ($page === 'wc-orders' && $action === 'edit') {
			return;
		}

		if (get_transient('rt_nft_checkout_orders_screen_sync') !== false) {
			return;
		}
		set_transient('rt_nft_checkout_orders_screen_sync', time(), 12);

		if (!function_exists('wc_get_orders') || !function_exists('nft_checkout_schedule_async_order_sync')) {
			return;
		}

		$orders = wc_get_orders([
			'status'         => ['pending', 'on-hold'],
			'payment_method' => ['nft-checkout', 'nft_checkout'],
			'limit'          => 5,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]);

		if (!empty($orders) && is_array($orders)) {
			foreach ($orders as $order) {
				if (!$order instanceof WC_Order) {
					continue;
				}
				if (
					trim((string) $order->get_meta('_nft_checkout_account_unique_id')) === '' &&
					trim((string) $order->get_meta('_nft_checkout_account_public_key')) === ''
				) {
					continue;
				}
				$last_attempt = (int) $order->get_meta('_nft_checkout_last_sync_attempt');
				if ($last_attempt > 0 && (time() - $last_attempt) < 15) {
					continue;
				}
				$order->update_meta_data('_nft_checkout_last_sync_attempt', time());
				$order->save();
				nft_checkout_schedule_async_order_sync($order->get_id(), 0);
			}
		}
	}

	public function nft_checkout_admin_order_sync_on_view($order)
	{
		if (!$order instanceof WC_Order) {
			return;
		}
		$method = $order->get_payment_method();
		if ($method !== 'nft-checkout' && $method !== 'nft_checkout') {
			return;
		}
		if (!$order->has_status(['pending', 'on-hold'])) {
			return;
		}

		$last_sync = (int) $order->get_meta('_nft_checkout_last_admin_sync');
		if (time() - $last_sync < 15) {
			return;
		}
		$order->update_meta_data('_nft_checkout_last_admin_sync', time());
		$order->save();

		if (function_exists('nft_checkout_schedule_async_order_sync')) {
			nft_checkout_schedule_async_order_sync($order->get_id(), 0);
		}
	}

	/**
	 * Add custom order action in WooCommerce order edit screen.
	 *
	 * @param array $actions
	 * @return array
	 */
	public function nft_checkout_add_order_actions($actions)
	{
		global $theorder;
		if ($theorder instanceof WC_Order) {
			$method = $theorder->get_payment_method();
			if ($method === 'nft-checkout' || $method === 'nft_checkout') {
				$actions['nft_checkout_check_status'] = __('Check NFT Checkout payment status', 'nft-checkout-payment-gateway');
			}
		}
		return $actions;
	}

	/**
	 * Handle manual status check action from order screen.
	 *
	 * @param WC_Order $order
	 */
	public function nft_checkout_process_order_action_check_status($order)
	{
		if ($order instanceof WC_Order) {
			$method = $order->get_payment_method();
			if ($method === 'nft-checkout' || $method === 'nft_checkout') {
				$result = $this->sync_single_order_status($order, 'force');
				if ($result === 'success') {
					$order->add_order_note(__('Manual status check: Payment confirmed successfully.', 'nft-checkout-payment-gateway'));
				} elseif ($result) {
					$order->add_order_note(sprintf(__('Manual status check: Status is %s.', 'nft-checkout-payment-gateway'), $result));
				} elseif ((string) $order->get_meta('_nft_checkout_pay_id') === '') {
					$order->add_order_note(__('Manual status check: No payment ID is recorded for this voucher yet (awaiting signed webhook or mature voucher link).', 'nft-checkout-payment-gateway'));
				} else {
					$order->add_order_note(__('Manual status check: No status update available.', 'nft-checkout-payment-gateway'));
				}
			}
		}
	}

	/**
	 * Schedule a non-blocking background status check when a customer visits the order confirmation
	 * or account view-order page for a pending order that already has a recorded payment token.
	 *
	 * @param int $order_id
	 */
	public function nft_checkout_customer_order_sync($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			return;
		}
		$method = $order->get_payment_method();
		if (($method !== 'nft-checkout' && $method !== 'nft_checkout') || !$order->has_status(['pending', 'on-hold'])) {
			return;
		}

		$pay_id = (string) $order->get_meta('_nft_checkout_pay_id');
		if ($pay_id === '' && function_exists('nft_checkout_get_order_payment_link')) {
			$link_row = nft_checkout_get_order_payment_link($order->get_id());
			if (!empty($link_row->uuid)) {
				$pay_id = (string) $link_row->uuid;
			}
		}
		if ($pay_id === '') {
			return;
		}

		$last_attempt = (int) $order->get_meta('_nft_checkout_last_sync_attempt');
		if ($last_attempt > 0 && (time() - $last_attempt) < 15) {
			return;
		}
		$order->update_meta_data('_nft_checkout_last_sync_attempt', time());
		$order->save();

		if (function_exists('nft_checkout_schedule_async_order_sync')) {
			nft_checkout_schedule_async_order_sync($order->get_id(), 5);
		}
	}
}
