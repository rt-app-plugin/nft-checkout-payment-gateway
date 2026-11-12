<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'nft-checkout-payment-gateway-utils.php';
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
		add_action(NFT_CHECKOUT_CRON_HOOK, [$this, 'handle_cron_event']);
		add_action(NFT_CHECKOUT_ASYNC_SYNC_HOOK, [$this, 'sync_single_order_status'], 10, 1);

		// NFT-15: admin screens queue background checks instead of calling RT
		// while they render. The one synchronous admin call is the explicit
		// "Check NFT Checkout payment status" order action.
		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'nft_checkout_admin_order_sync_on_view']);
		add_action('load-woocommerce_page_wc-orders', [$this, 'nft_checkout_admin_orders_screen_sync']);
		add_action('load-edit.php', [$this, 'nft_checkout_admin_orders_screen_sync']);
		add_filter('woocommerce_order_actions', [$this, 'nft_checkout_add_order_actions'], 10, 2);
		add_action('woocommerce_order_action_nft_checkout_check_status', [$this, 'nft_checkout_process_order_action_check_status']);

		// The customer's order-received / view-order pages: one throttled check.
		add_action('woocommerce_thankyou', [$this, 'nft_checkout_customer_order_sync']);
		add_action('woocommerce_view_order', [$this, 'nft_checkout_customer_order_sync']);

		// Strip order attribution session entry only for NFT Checkout orders to prevent
		// session-derived attribution metadata from interfering with the voucher flow.
		add_action('woocommerce_checkout_create_order', function($order){
			if ($order instanceof WC_Order && nft_checkout_is_gateway_order($order)) {
				$order->delete_meta_data('_wc_order_attribution_session_entry');
			}
		}, 10);

		add_action('woocommerce_before_checkout_form', [$this, 'nft_checkout_show_checkout_error']);

		// Prevent order reuse on standard checkout for this gateway if identity changes
		add_action('woocommerce_before_checkout_process', function() {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified by WooCommerce in WC_Checkout::process_checkout().
			if ( isset( $_POST['payment_method'] ) && NFT_CHECKOUT_GATEWAY_ID === sanitize_text_field(wp_unslash($_POST['payment_method'])) ) {
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
		// Check if the environment is compatible
		$environment_warning = nft_checkout_check_system_requirements();
		if ($environment_warning) {
			return;
		}

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
			'failed'    => __('Your card payment did not go through, so you have not been charged. Please try again or choose another payment method.', 'nft-checkout-payment-gateway'),
			'cancelled' => __('You cancelled the card payment, so you have not been charged. You can try again when you are ready.', 'nft-checkout-payment-gateway'),
			'expired'   => __('The payment link was open for too long and closed, so you have not been charged. Please place the order again to get a new link.', 'nft-checkout-payment-gateway'),
			'duplicate' => __('It looks like you already paid for this order, so we did not start a second payment. Check your email or your order page. If you were not charged, contact the shop.', 'nft-checkout-payment-gateway'),
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
		if (strpos($endpoint, '/payment-api/') === 0) {
			return $this->base_url . $endpoint;
		}
		if (strpos($endpoint, '/api/') === 0) {
			$endpoint = substr($endpoint, 4);
		}
		return NFT_CHECKOUT_API_BASE_URL . $endpoint;
	}

	/**
	 * Add action links to the plugin page.
	 * @param array $links
	 * @return array
	 */
	public function nft_checkout_plugin_action_links($links)
	{
		$plugin_links = [
			'<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . NFT_CHECKOUT_GATEWAY_ID)) . '">' . esc_html__('Settings', 'nft-checkout-payment-gateway') . '</a>',
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
		$schedules[NFT_CHECKOUT_CRON_INTERVAL] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours (NFT Checkout)', 'nft-checkout-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		NFT_Checkout_Payment_Gateway_Logger::info('Automatic payment status checks have been enabled.', ['source' => 'nft-checkout-payment-gateway']);

		// Clear existing scheduled event if it exists
		wp_clear_scheduled_hook(NFT_CHECKOUT_CRON_HOOK);

		// Schedule with new interval
		wp_schedule_event(time() + 60, NFT_CHECKOUT_CRON_INTERVAL, NFT_CHECKOUT_CRON_HOOK);
	}

	function deactivate_cron_job()
	{
		NFT_Checkout_Payment_Gateway_Logger::info('Automatic payment status checks have been disabled.', ['source' => 'nft-checkout-payment-gateway']);
		wp_clear_scheduled_hook(NFT_CHECKOUT_CRON_HOOK);
		wp_clear_scheduled_hook(NFT_CHECKOUT_ASYNC_SYNC_HOOK);
	}


	/**
	 * Check every configured key pair with RT and record its status, then
	 * reconcile the orders still waiting for payment.
	 *
	 * NFT-05: no secret key is ever sent. Each account proves its secret with
	 * a signature over "<timestamp>.<public_key>" (nft_checkout_account_signature()).
	 * Do NOT reintroduce a 'secret_key' field in this payload.
	 */
	public function handle_cron_event()
	{
		$logger_context = ['source' => 'nft-checkout-payment-gateway'];

		$this->last_sync = ['ok' => true, 'reason' => '', 'statuses' => []];

		$accounts = nft_checkout_get_accounts();

		if (empty($accounts)) {
			NFT_Checkout_Payment_Gateway_Logger::warning('No payment accounts found or the account format is invalid. Sync aborted.', $logger_context);
			$this->last_sync = ['ok' => false, 'reason' => 'no_accounts', 'statuses' => []];
			return [];
		}

		$accountsData = [];
		// Status changes decided here, keyed by "<mode>|<public key>". They are
		// merged into a fresh copy of the option just before saving, so a key
		// the shop changed while RT was answering is never put back.
		$changes = [];

		foreach ($accounts as $account) {
			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			if (!empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
				$accountsData[] = array_merge([
					'account_name' => $account['title'] ?? '',
					'public_key'   => $account['live_public_key'],
					'mode'         => 'live',
				], nft_checkout_account_signature($account['live_public_key'], $account['live_secret_key']));
			}

			if ($isSandboxEnabled && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
				$accountsData[] = array_merge([
					'account_name' => $account['title'] ?? '',
					'public_key'   => $account['sandbox_public_key'],
					'mode'         => 'sandbox',
				], nft_checkout_account_signature($account['sandbox_public_key'], $account['sandbox_secret_key']));
			}
		}

		if (empty($accountsData)) {
			NFT_Checkout_Payment_Gateway_Logger::warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
			$this->last_sync = ['ok' => false, 'reason' => 'no_keys', 'statuses' => []];
			return [];
		}

		$sent = array_slice($accountsData, 0, 10);
		$url  = esc_url_raw($this->get_api_url('/sync-account-status'));
		$body = wp_json_encode(['accounts' => $sent]);

		$response = wp_remote_post($url, [
			'headers'   => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'      => $body,
			'timeout'   => 15,
			'sslverify' => true,
		]);

		if (is_wp_error($response)) {
			NFT_Checkout_Payment_Gateway_Logger::error('Unable to connect to the sync service. Please check the server connection or endpoint.', $logger_context);
			$this->last_sync = ['ok' => false, 'reason' => 'unreachable', 'statuses' => []];
			$this->sync_pending_orders();
			return [];
		}

		$response_code = (int) wp_remote_retrieve_response_code($response);
		if ($response_code < 200 || $response_code >= 300) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				'Sync API returned a non-2xx HTTP status. Sync aborted.',
				array_merge($logger_context, [
					'context' => [
						'http_status' => $response_code,
						'url'         => $url,
					],
				])
			);
			// RT answered but refused the request. Accounts that were never
			// accepted are marked so the settings screen does not look fine.
			foreach ($sent as $entry) {
				$changes[$entry['mode'] . '|' . $entry['public_key']] = ['status' => 'not_connected', 'only_if_unconfirmed' => true];
			}
			$summary = $this->nft_checkout_store_account_statuses($changes);
			$this->last_sync = [
				'ok'       => false,
				'reason'   => in_array($response_code, [400, 401, 403, 422], true) ? 'refused' : 'unreachable',
				'statuses' => $summary,
			];
			$this->sync_pending_orders();
			return $summary;
		}

		$response_data = json_decode(wp_remote_retrieve_body($response), true);

		if (is_array($response_data) && !empty($response_data['data']) && is_array($response_data['data'])) {
			foreach ($response_data['data'] as $statusData) {
				if (
					is_array($statusData) &&
					isset($statusData['mode'], $statusData['public_key'], $statusData['status']) &&
					is_string($statusData['status']) && $statusData['status'] !== '' &&
					(empty($statusData['message']) || stripos((string) $statusData['message'], 'missing required fields') === false)
				) {
					$mode = strtolower(sanitize_key((string) $statusData['mode']));
					$changes[$mode . '|' . (string) $statusData['public_key']] = [
						'status' => sanitize_key(strtolower((string) $statusData['status'])),
					];
				}
			}
		}

		// Every account we asked about must come back with an answer.
		$unanswered = 0;
		foreach ($sent as $entry) {
			if (!isset($changes[$entry['mode'] . '|' . $entry['public_key']])) {
				$changes[$entry['mode'] . '|' . $entry['public_key']] = ['status' => 'not_connected', 'only_if_unconfirmed' => true];
				$unanswered++;
			}
		}

		$statusSummary = $this->nft_checkout_store_account_statuses($changes);

		$this->last_sync = [
			'ok'       => ($unanswered === 0),
			'reason'   => ($unanswered === 0) ? '' : 'refused',
			'statuses' => $statusSummary,
		];

		NFT_Checkout_Payment_Gateway_Logger::info('Payment account statuses checked with RT.', [
			'source'  => 'nft-checkout-payment-gateway',
			'context' => ['checked_accounts' => $statusSummary],
		]);

		// Account statuses are refreshed; now reconcile any order still waiting for payment.
		$this->sync_pending_orders();

		$this->nft_checkout_send_plugin_status(1, 1);

		return $statusSummary;
	}

	/**
	 * Result of the last account check in this request.
	 *
	 * @var array
	 */
	private $last_sync = ['ok' => true, 'reason' => '', 'statuses' => []];

	/**
	 * Save new account statuses without touching anything else.
	 *
	 * The accounts option is read again here and only the status of an account
	 * whose public key still matches is changed.
	 *
	 * @param array $changes "<mode>|<public key>" => ['status' => string, 'only_if_unconfirmed' => bool]
	 * @return array Summary rows: title, mode, status.
	 */
	private function nft_checkout_store_account_statuses(array $changes)
	{
		$accounts = nft_checkout_get_accounts();
		$summary  = [];
		$updated  = false;

		foreach ($accounts as &$account) {
			if (!is_array($account)) {
				continue;
			}
			$has_sandbox = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';
			foreach (['live', 'sandbox'] as $mode) {
				$public_key = (string) ($account[$mode . '_public_key'] ?? '');
				$status_key = $mode . '_status';
				$current    = strtolower((string) ($account[$status_key] ?? ''));

				if ($public_key === '' || empty($account[$mode . '_secret_key']) || ($mode === 'sandbox' && !$has_sandbox)) {
					$new = 'inactive';
				} elseif (isset($changes[$mode . '|' . $public_key])) {
					$change = $changes[$mode . '|' . $public_key];
					// A failed check never takes a working account offline.
					if (!empty($change['only_if_unconfirmed']) && $current === 'active') {
						$new = $current;
					} else {
						$new = $change['status'];
					}
					$summary[] = [
						'title'  => $account['title'] ?? '',
						'mode'   => $mode,
						'status' => $new,
					];
				} else {
					continue;
				}

				if ($new !== $current || ($account[$status_key] ?? null) !== $new) {
					$account[$status_key] = $new;
					$updated = true;
				}
			}
		}
		unset($account);

		if ($updated) {
			update_option(NFT_CHECKOUT_ACCOUNTS_OPTION, $accounts, false);
		}

		return $summary;
	}

	/**
	 * A plain sentence about the last account check, or '' when all is well
	 * for the mode the shop is using.
	 *
	 * @param bool $is_sandbox Whether test mode is on.
	 * @return string
	 */
	public function nft_checkout_last_sync_problem($is_sandbox)
	{
		$reason = $this->last_sync['reason'] ?? '';
		if ($reason === 'unreachable') {
			return __('Your settings were saved, but we could not reach RT to check your keys. Card payment stays hidden at checkout until the keys are checked. Press "Check with RT" to try again in a moment.', 'nft-checkout-payment-gateway');
		}
		if ($reason === 'no_keys' || $reason === 'no_accounts') {
			return '';
		}

		$wanted = $is_sandbox ? 'sandbox' : 'live';
		$bad    = [];
		$seen   = false;
		foreach ((array) ($this->last_sync['statuses'] ?? []) as $row) {
			if (($row['mode'] ?? '') !== $wanted) {
				continue;
			}
			$seen = true;
			if (($row['status'] ?? '') !== 'active') {
				$bad[] = (string) ($row['title'] ?? '');
			}
		}

		if ($reason === 'refused' && !$seen) {
			return __('We couldn\'t connect to RT with these keys. Check that the publishable and secret keys are from the same RT account and the same mode (Sandbox or Live).', 'nft-checkout-payment-gateway');
		}
		if (!empty($bad)) {
			return sprintf(
				/* translators: %s: account names */
				__('We couldn\'t connect to RT with the keys for: %s. Check that the publishable and secret keys are from the same RT account and the same mode (Sandbox or Live). Card payment stays hidden at checkout until RT accepts the keys.', 'nft-checkout-payment-gateway'),
				implode(', ', array_filter($bad))
			);
		}
		if (!$seen) {
			return $is_sandbox
				? __('Test mode is on, but no account has sandbox keys. Add sandbox keys to an account, or turn test mode off.', 'nft-checkout-payment-gateway')
				: __('No account has live keys yet. Add live keys to take real payments, or turn test mode on to try it with sandbox keys.', 'nft-checkout-payment-gateway');
		}
		return '';
	}

	/**
	 * Sync every open NFT Checkout order with RT, in pages, within a time budget.
	 *
	 * @param int $max_orders  Upper bound of orders checked in this run.
	 * @param int $time_budget Seconds this run may spend.
	 * @return array Summary of synced order states keyed by order id.
	 */
	public function sync_pending_orders($max_orders = 300, $time_budget = 20)
	{
		if (!function_exists('wc_get_orders')) {
			return [];
		}

		$started   = microtime(true);
		$page_size = 25;
		$synced    = [];
		$seen      = 0;

		foreach (nft_checkout_gateway_ids() as $gateway_id) {
			$page = 1;
			while ($seen < $max_orders && (microtime(true) - $started) < $time_budget) {
				$orders = wc_get_orders([
					'status'         => ['pending', 'on-hold'],
					'payment_method' => $gateway_id,
					'limit'          => $page_size,
					'page'           => $page,
					'orderby'        => 'date',
					'order'          => 'ASC',
					'date_created'   => '>' . (time() - 30 * DAY_IN_SECONDS),
				]);

				if (empty($orders) || !is_array($orders)) {
					break;
				}

				foreach ($orders as $order) {
					if (!$order instanceof WC_Order) {
						continue;
					}
					$seen++;
					if ($seen > $max_orders || (microtime(true) - $started) >= $time_budget) {
						break 2;
					}
					$last_attempt = (int) $order->get_meta('_nft_checkout_last_sync_attempt');
					if ($last_attempt > 0 && (time() - $last_attempt) < 15) {
						continue;
					}
					$order->update_meta_data('_nft_checkout_last_sync_attempt', time());
					$order->save();
					$result = $this->sync_single_order_status($order);
					if ($result) {
						$synced[$order->get_id()] = $result;
					}
				}

				if (count($orders) < $page_size) {
					break;
				}
				$page++;
			}
		}

		return $synced;
	}

	/* =========================================================
	 * PAYMENT IDS
	 * ========================================================= */

	/**
	 * Every payment id this order is known under: current meta, the history of
	 * every id RT reported for it, and the link table.
	 *
	 * @param WC_Order $order
	 * @return string[]
	 */
	public function nft_checkout_known_order_pay_ids($order)
	{
		if (!$order instanceof WC_Order) {
			return [];
		}

		$ids = [sanitize_text_field((string) $order->get_meta('_nft_checkout_pay_id'))];

		$history = $order->get_meta('_nft_checkout_pay_id_history');
		if (is_array($history)) {
			foreach ($history as $entry) {
				$ids[] = sanitize_text_field((string) (is_array($entry) ? ($entry['pay_id'] ?? '') : $entry));
			}
		}

		$row = nft_checkout_get_order_payment_link($order->get_id());
		if (is_array($row)) {
			$ids[] = sanitize_text_field((string) ($row['uuid'] ?? ''));
		}

		return array_values(array_unique(array_filter($ids, static function ($id) {
			return is_string($id) && $id !== '';
		})));
	}

	/**
	 * Record a payment id RT reported for the order through an authenticated
	 * channel (a signed webhook or a signed lookup): meta, history and table.
	 * Never called with an id taken from the customer's browser.
	 *
	 * @param WC_Order $order
	 * @param string   $pay_id
	 * @param array    $link   payment_link, customer_email, amount.
	 */
	public function nft_checkout_remember_pay_id($order, $pay_id, array $link = [])
	{
		$pay_id = sanitize_text_field((string) $pay_id);
		if (!$order instanceof WC_Order || $pay_id === '') {
			return;
		}

		$known = $this->nft_checkout_known_order_pay_ids($order);
		$seen  = false;
		foreach ($known as $id) {
			if (nft_checkout_pay_ids_match($id, $pay_id)) {
				$seen = true;
				break;
			}
		}

		$current = (string) $order->get_meta('_nft_checkout_pay_id');
		if ($current === '') {
			$order->update_meta_data('_nft_checkout_pay_id', $pay_id);
		}

		if (!$seen) {
			$history = $order->get_meta('_nft_checkout_pay_id_history');
			$history = is_array($history) ? $history : [];
			$history[] = ['pay_id' => $pay_id, 'time' => current_time('mysql')];
			$order->update_meta_data('_nft_checkout_pay_id_history', $history);
			// The newest payment email now has its payment id.
			$order->update_meta_data('_nft_checkout_latest_pay_id', $pay_id);
			$order->delete_meta_data('_nft_checkout_voucher_awaiting_pay_id');
		}
		$order->save();

		$payment_link = isset($link['payment_link']) && is_scalar($link['payment_link']) ? (string) $link['payment_link'] : '';
		if ($payment_link === '') {
			$variants = nft_checkout_pay_id_variants($pay_id);
			$payment_link = $this->base_url . '/p/' . rawurlencode(end($variants) ?: $pay_id);
		}

		nft_checkout_store_order_payment_link($order, [
			'uuid'           => $pay_id,
			'payment_link'   => $payment_link,
			'customer_email' => $link['customer_email'] ?? $order->get_billing_email(),
			'amount'         => $link['amount'] ?? $order->get_total(),
		]);
	}

	/* =========================================================
	 * ORDER STATUS SYNC (NFT-03)
	 * ========================================================= */

	/**
	 * Ask RT where an order's payment stands: one signed, merchant-bound call
	 * (POST /update-txn-status with the order's account), nothing else. The
	 * answer names the payment, its amount, currency, order and mode; the
	 * order is marked paid only when every one of them matches it.
	 *
	 * @param WC_Order $order
	 * @return array{state: string|null, reason: string|null, data: array|null}
	 */
	public function nft_checkout_refresh_order_from_remote($order)
	{
		$order_id   = $order->get_id();
		$log_prefix = "[Order #{$order_id}] Sync |";
		$ctx        = ['source' => 'nft-checkout-payment-gateway'];
		$out        = ['state' => null, 'reason' => null, 'data' => null];

		$account = nft_checkout_get_order_account($order);
		if ($account === null || $account['public_key'] === '' || $account['secret_key'] === '') {
			NFT_Checkout_Payment_Gateway_Logger::warning("{$log_prefix} The order names no configured merchant account; status cannot be verified", $ctx);
			$out['reason'] = 'no_account';
			return $out;
		}

		$known_ids = $this->nft_checkout_known_order_pay_ids($order);

		$payload = [
			'order_id'   => (string) $order_id,
			'is_sandbox' => (int) $account['is_sandbox'],
		];
		/*
		 * NFT-01: ask about the newest payment, not the first one. While the
		 * order is waiting to learn the id of a payment email it just sent, no
		 * id is named, so RT answers for the order's newest payment link.
		 */
		$awaiting_new_id = (string) $order->get_meta('_nft_checkout_voucher_awaiting_pay_id') !== '';
		$lookup_id       = '';
		if (!$awaiting_new_id && !empty($known_ids)) {
			$lookup_id = (string) $order->get_meta('_nft_checkout_latest_pay_id');
			if ($lookup_id === '') {
				$lookup_id = (string) end($known_ids);
			}
		}
		if ($lookup_id !== '') {
			$payload['payment_token'] = $lookup_id;
		}
		$raw_body = wp_json_encode($payload);

		$response = wp_remote_post(esc_url_raw($this->get_api_url('/update-txn-status')), [
			'headers'   => nft_checkout_signed_headers($account['public_key'], $account['secret_key'], $raw_body),
			'body'      => $raw_body,
			'timeout'   => 8,
			'sslverify' => true,
		]);

		if (is_wp_error($response)) {
			NFT_Checkout_Payment_Gateway_Logger::warning("{$log_prefix} API connection failed", $ctx);
			$out['reason'] = 'network';
			return $out;
		}

		$http_code = (int) wp_remote_retrieve_response_code($response);
		$data      = json_decode(wp_remote_retrieve_body($response), true);

		if ($http_code === 401 || $http_code === 403) {
			NFT_Checkout_Payment_Gateway_Logger::warning("{$log_prefix} API authentication failed; verify the merchant account keys", $ctx);
			$out['reason'] = 'auth';
			return $out;
		}

		if ($http_code < 200 || $http_code >= 300 || !is_array($data)) {
			NFT_Checkout_Payment_Gateway_Logger::info("{$log_prefix} No usable status from RT (HTTP {$http_code})", $ctx);
			$out['reason'] = 'upstream';
			return $out;
		}

		if (empty($data['status'])) {
			// RT knows no payment link for this order yet: the customer has not
			// opened their voucher. Nothing to apply.
			$out['reason'] = 'no_payment';
			return $out;
		}

		$out['data'] = $data;

		$pay_id = sanitize_text_field((string) ($data['pay_id'] ?? ''));

		// The link must have been raised by THIS shop: RT answers with the return
		// URL the voucher registered, which names this site.
		$redirect_url = isset($data['redirect_url']) && is_string($data['redirect_url']) ? $data['redirect_url'] : '';
		if ($redirect_url !== '') {
			$link_host = strtolower((string) wp_parse_url($redirect_url, PHP_URL_HOST));
			$site_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
			if ($link_host === '' || $link_host !== $site_host) {
				$this->nft_checkout_note_sync_mismatch($order, 'the payment link belongs to another site.');
				$out['reason'] = 'claim_mismatch';
				return $out;
			}
		}

		$payment_status = NFT_Checkout_Payment_State_Engine::map_status($data['payment_status'] ?? null);
		$txn_status     = NFT_Checkout_Payment_State_Engine::map_status($data['transaction_status'] ?? null);
		$settled        = isset($data['settled']) && filter_var($data['settled'], FILTER_VALIDATE_BOOLEAN);

		if ($settled || $payment_status === 'success') {
			$state = 'success';
		} elseif ($payment_status === 'pending' || $payment_status === null) {
			$state = ($txn_status === 'processing' || $txn_status === 'success') ? 'processing' : 'pending';
		} else {
			$state = $payment_status;
		}

		NFT_Checkout_Payment_Gateway_Logger::info("{$log_prefix} RT reports '{$state}'", array_merge($ctx, ['pay_id' => $pay_id]));

		$out['state'] = $state;

		if ($state === 'success') {
			$claim = nft_checkout_verify_payment_claim(
				$order,
				$data,
				$pay_id,
				$known_ids,
				true,
				(int) $account['is_sandbox'],
				nft_checkout_may_learn_new_pay_id($order, $data)
			);
			if (is_wp_error($claim)) {
				$this->nft_checkout_note_sync_mismatch($order, $claim->get_error_message());
				$out['state']  = null;
				$out['reason'] = 'claim_mismatch';
				return $out;
			}
			$this->nft_checkout_remember_pay_id($order, $pay_id, [
				'customer_email' => $order->get_billing_email(),
				'amount'         => $data['amount'] ?? $order->get_total(),
			]);
		} elseif ($pay_id !== '' && (empty($known_ids) || $awaiting_new_id)) {
			// An open link RT raised for this order: remember it so later
			// webhooks and checks are matched against it.
			$this->nft_checkout_remember_pay_id($order, $pay_id, [
				'customer_email' => $order->get_billing_email(),
				'amount'         => $data['amount'] ?? $order->get_total(),
			]);
		}

		if ($state === 'returned') {
			$out['state'] = null;
			return $out;
		}

		// RT answered about an older payment link while the shopper has a newer
		// one: that link closing must not fail the order.
		if (
			in_array($state, ['failed', 'cancelled', 'expired'], true) &&
			nft_checkout_is_superseded_pay_id($order, $pay_id, $known_ids)
		) {
			$out['state']  = 'pending';
			$out['reason'] = 'older_link';
			return $out;
		}

		$result = NFT_Checkout_Payment_State_Engine::handle_event(
			$order_id,
			'api_sync',
			[
				'status'        => $state,
				'payment_token' => $pay_id !== '' ? $pay_id : ($lookup_id !== '' ? $lookup_id : ($known_ids[0] ?? '')),
				'source'        => 'API Sync',
			]
		);

		NFT_Checkout_Payment_Gateway_Logger::info("{$log_prefix} Engine result: " . wp_json_encode($result), $ctx);

		return $out;
	}

	/**
	 * Sync a single NFT Checkout order status with RT.
	 *
	 * @param WC_Order|int $order Order instance or order ID.
	 * @return string|null 'success', 'failed', 'cancelled', 'expired', 'processing', 'pending' or null.
	 */
	public function sync_single_order_status($order)
	{
		if (is_numeric($order)) {
			$order = wc_get_order(absint($order));
		}

		if (!$order instanceof WC_Order || !nft_checkout_is_gateway_order($order)) {
			return null;
		}

		if ($order->has_status(['processing', 'completed']) || $order->get_meta('_nft_checkout_state') === 'success') {
			return 'success';
		}

		$outcome = $this->nft_checkout_refresh_order_from_remote($order);

		return $outcome['state'] ?? null;
	}

	/**
	 * Record (once) why an RT "paid" status was not applied to the order.
	 */
	private function nft_checkout_note_sync_mismatch($order, $detail)
	{
		NFT_Checkout_Payment_Gateway_Logger::warning(
			'[Order #' . $order->get_id() . '] Sync | RT reports paid but ' . $detail . ' Order left unchanged for manual review.',
			['source' => 'nft-checkout-payment-gateway']
		);

		if (!$order->get_meta('_nft_checkout_sync_mismatch_noted')) {
			$order->add_order_note(
				'<strong>NFT Checkout</strong><br><br>' .
				esc_html__('RT reports this payment as completed, but the details do not match the order:', 'nft-checkout-payment-gateway') .
				' ' . esc_html($detail) . '<br>' .
				esc_html__('The order was not changed. Check the payment in your RT account before shipping.', 'nft-checkout-payment-gateway')
			);
			$order->update_meta_data('_nft_checkout_sync_mismatch_noted', 'yes');
			$order->save();
		}
	}

	function nft_checkout_manual_sync_callback()
	{
		$logger_context = ['source' => 'nft-checkout-payment-gateway'];
		// Verify nonce first
		if (!check_ajax_referer('nft_checkout_sync_nonce', 'nonce', false)) {
			NFT_Checkout_Payment_Gateway_Logger::error('Security validation failed during manual sync.', $logger_context);
			wp_send_json_error([
				'message' => __('This page was open for too long. Refresh the page and try again.', 'nft-checkout-payment-gateway')
			], 400);
		}

		// Check user capabilities
		if (!current_user_can('manage_woocommerce')) {
			NFT_Checkout_Payment_Gateway_Logger::error('Unauthorized manual sync attempt by user ID: ' . get_current_user_id(), $logger_context);
			wp_send_json_error([
				'message' => __('You do not have permission to do this. Ask the shop owner or an administrator.', 'nft-checkout-payment-gateway')
			], 403);
		}

		NFT_Checkout_Payment_Gateway_Logger::info("Payment accounts sync initiated", $logger_context);

		try {
			ob_start();
			$statusSummary = $this->handle_cron_event();
			$output = ob_get_clean();

			if (!empty($output)) {
				NFT_Checkout_Payment_Gateway_Logger::warning('Unexpected output generated during sync.', $logger_context);
			}
		} catch (\Throwable $e) {
			if (ob_get_level() > 0) {
				ob_end_clean();
			}
			NFT_Checkout_Payment_Gateway_Logger::error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Something went wrong while checking your keys with RT. Try again in a moment. If it keeps happening, contact RT support.', 'nft-checkout-payment-gateway'),
			], 500);
		}

		$reason = $this->last_sync['reason'] ?? '';
		if ($reason === 'no_accounts' || $reason === 'no_keys') {
			wp_send_json_error([
				'message'  => __('There are no saved keys to check. Add a payment account, paste your keys and press Save changes first.', 'nft-checkout-payment-gateway'),
				'statuses' => [],
			], 200);
		}

		$problem = $this->nft_checkout_last_sync_problem(nft_checkout_is_sandbox());
		if ($problem !== '') {
			NFT_Checkout_Payment_Gateway_Logger::warning('Payment accounts sync finished with a problem.', array_merge($logger_context, ['reason' => $reason]));
			wp_send_json_error([
				'message'  => ($reason === 'unreachable')
					? __('We could not reach RT. Check your internet connection and try again in a moment.', 'nft-checkout-payment-gateway')
					: $problem,
				'statuses' => $statusSummary,
			], 200);
		}

		NFT_Checkout_Payment_Gateway_Logger::info('Payment accounts sync completed successfully.', $logger_context);

		wp_send_json_success([
			'message'   => __('RT accepted your keys. Shoppers can pay by card.', 'nft-checkout-payment-gateway'),
			'timestamp' => current_time('mysql'),
			'statuses'  => $statusSummary,
		]);
	}

	/**
	 * NFT-17: tell RT the plugin's status (domain, versions, account titles and
	 * public keys). Only when the merchant has opted in and has entered keys.
	 */
	public function nft_checkout_send_plugin_status($plugin_status, $gateway_loaded)
	{
		if (!nft_checkout_telemetry_allowed() || !nft_checkout_is_configured()) {
			return;
		}

		$accounts = nft_checkout_get_accounts();

		// Find first available public key
		$public_key = '';

		foreach ($accounts as $account) {
			if (!empty($account['live_public_key'])) {
				$public_key = $account['live_public_key'];
				break;
			}

			if (!empty($account['sandbox_public_key'])) {
				$public_key = $account['sandbox_public_key'];
				break;
			}
		}

		if (empty($public_key)) {
			return;
		}

		// Never the secrets.
		$safe_accounts = array_map(function($acc) {
			unset($acc['live_secret_key'], $acc['sandbox_secret_key'], $acc['secret_key']);
			return $acc;
		}, $accounts);

		$body = [
			'valid_accounts'         => $safe_accounts,
			'plugin_status'          => (int) $plugin_status,
			'gateway_loaded'         => (int) $gateway_loaded,
			'plugin_version'         => NFT_CHECKOUT_PLUGIN_VERSION,
			'wordpress_version'      => get_bloginfo('version'),
			'woocommerce_version'    => class_exists('WooCommerce') && function_exists('WC') ? WC()->version : '',
			'woocommerce_db_version' => get_option('woocommerce_db_version'),
			'group_id'               => get_option('nft_checkout_group_id'),
			'domain_name'            => wp_parse_url(home_url(), PHP_URL_HOST),
		];

		$response = wp_remote_post(
			esc_url_raw($this->get_api_url('/plugin/check/plugin')),
			[
				'method'    => 'POST',
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'body'      => wp_json_encode($body),
			]
		);

		if (is_wp_error($response)) {
			NFT_Checkout_Payment_Gateway_Logger::error(
				'Plugin status API call failed.',
				['source' => 'nft-checkout-payment-gateway', 'error' => $response->get_error_message()]
			);
			return;
		}

		NFT_Checkout_Payment_Gateway_Logger::info(
			'Plugin status sent.',
			[
				'source'  => 'nft-checkout-payment-gateway',
				'context' => [
					'plugin_status'  => $plugin_status,
					'gateway_loaded' => $gateway_loaded,
					'response_code'  => wp_remote_retrieve_response_code($response),
				],
			]
		);
	}

	/* =========================================================
	 * AUTOMATIC SYNC TRIGGERS (ADMIN + CUSTOMER)
	 * ========================================================= */

	/**
	 * NFT-15: queue a status refresh for an order instead of calling RT while
	 * an admin page renders. Action Scheduler when WooCommerce provides it,
	 * else a WP-Cron single event.
	 *
	 * @param int $order_id
	 */
	public function nft_checkout_schedule_async_sync($order_id)
	{
		$order_id = absint($order_id);
		if ($order_id <= 0) {
			return;
		}
		if (function_exists('as_enqueue_async_action')) {
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action(NFT_CHECKOUT_ASYNC_SYNC_HOOK, [$order_id], 'rt-nft-checkout')) {
				return;
			}
			as_enqueue_async_action(NFT_CHECKOUT_ASYNC_SYNC_HOOK, [$order_id], 'rt-nft-checkout');
			return;
		}
		if (!wp_next_scheduled(NFT_CHECKOUT_ASYNC_SYNC_HOOK, [$order_id])) {
			wp_schedule_single_event(time(), NFT_CHECKOUT_ASYNC_SYNC_HOOK, [$order_id]);
		}
	}

	/**
	 * Queue a refresh when an admin opens a pending order's edit screen.
	 */
	public function nft_checkout_admin_order_sync_on_view($order)
	{
		if (!$order instanceof WC_Order || !nft_checkout_is_gateway_order($order)) {
			return;
		}
		if (!$order->has_status(['pending', 'on-hold'])) {
			return;
		}
		$last_sync = (int) $order->get_meta('_nft_checkout_last_admin_sync');
		if (time() - $last_sync < 60) {
			return;
		}
		$order->update_meta_data('_nft_checkout_last_admin_sync', time());
		$order->save();

		$this->nft_checkout_schedule_async_sync($order->get_id());
	}

	/**
	 * Add custom order action in WooCommerce order edit screen.
	 *
	 * @param array         $actions
	 * @param WC_Order|null $order
	 * @return array
	 */
	public function nft_checkout_add_order_actions($actions, $order = null)
	{
		if (!$order instanceof WC_Order) {
			global $theorder;
			$order = $theorder;
		}
		if ($order instanceof WC_Order && nft_checkout_is_gateway_order($order)) {
			$actions['nft_checkout_check_status'] = __('Check this card payment with RT', 'nft-checkout-payment-gateway');
		}
		return $actions;
	}

	/**
	 * Handle manual status check action from order screen (admin-initiated,
	 * so this one call to RT is synchronous).
	 *
	 * @param WC_Order $order
	 */
	public function nft_checkout_process_order_action_check_status($order)
	{
		if (!$order instanceof WC_Order || !nft_checkout_is_gateway_order($order)) {
			return;
		}

		$result = $this->sync_single_order_status($order);
		if ($result === 'success') {
			$order->add_order_note(__('Checked with RT: the customer has paid.', 'nft-checkout-payment-gateway'));
		} elseif ($result) {
			$order->add_order_note(nft_checkout_plain_status_sentence($result));
		} else {
			$order->add_order_note(__('Checked with RT: the customer has not paid yet.', 'nft-checkout-payment-gateway'));
		}
	}

	/**
	 * Queue a refresh of the open orders when an admin loads the Orders list
	 * (throttled; nothing is fetched during the render).
	 *
	 * @param WP_Screen|null $screen
	 */
	public function nft_checkout_admin_orders_screen_sync($screen = null)
	{
		if (!is_admin() || !current_user_can('manage_woocommerce')) {
			return;
		}
		$screen_id = is_object($screen) ? ($screen->id ?? '') : '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page      = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
		$post_type = isset($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';
		$edit_id   = isset($_GET['id']) ? absint($_GET['id']) : (isset($_GET['post']) ? absint($_GET['post']) : 0);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ($edit_id > 0 && ($page === 'wc-orders' || $post_type === 'shop_order')) {
			$edit_order = wc_get_order($edit_id);
			if ($edit_order instanceof WC_Order) {
				$this->nft_checkout_admin_order_sync_on_view($edit_order);
			}
			return;
		}

		$is_orders_screen = in_array($screen_id, ['shop_order', 'woocommerce_page_wc-orders', 'edit-shop_order'], true)
			|| $page === 'wc-orders'
			|| $post_type === 'shop_order';

		if (!$is_orders_screen) {
			return;
		}

		$last_screen_sync = (int) get_transient('rt_nft_checkout_last_orders_screen_sync');
		if (time() - $last_screen_sync < 60) {
			return;
		}
		set_transient('rt_nft_checkout_last_orders_screen_sync', time(), 120);

		foreach (nft_checkout_gateway_ids() as $gateway_id) {
			$orders = wc_get_orders([
				'status'         => ['pending', 'on-hold'],
				'payment_method' => $gateway_id,
				'limit'          => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'return'         => 'ids',
			]);
			foreach ((array) $orders as $order_id) {
				$this->nft_checkout_schedule_async_sync($order_id);
			}
		}
	}

	/**
	 * One throttled check when the customer lands on the order-received page or
	 * views the order in My Account.
	 *
	 * @param int $order_id
	 */
	public function nft_checkout_customer_order_sync($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order || !nft_checkout_is_gateway_order($order)) {
			return;
		}
		if (!$order->has_status(['pending', 'on-hold'])) {
			return;
		}
		$last_sync = (int) $order->get_meta('_nft_checkout_last_customer_sync');
		if (time() - $last_sync < 15) {
			return;
		}
		$order->update_meta_data('_nft_checkout_last_customer_sync', time());
		$order->save();

		$this->sync_single_order_status($order);
	}
}
