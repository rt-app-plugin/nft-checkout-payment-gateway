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
		add_action('wp_ajax_nft_checkout_block_gateway_process', [$this,'handle_nft_checkout_gateway_ajax']);
		add_action('wp_ajax_nopriv_nft_checkout_block_gateway_process', [$this,'handle_nft_checkout_gateway_ajax']);
		add_action('woocommerce_checkout_create_order', function($order){
			if ($order instanceof WC_Order && $order->get_payment_method() === 'nft-checkout') {
				$order->delete_meta_data('_wc_order_attribution_session_entry');
			}
		}, 10);

		add_action('woocommerce_before_checkout_form', [$this, 'nft_checkout_show_checkout_error']);

		// Prevent order reuse on standard checkout for this gateway if identity changes
		add_action('woocommerce_before_checkout_process', function() {
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
		});
	}

	/**
	 * ── FIXED ──────────────────────────────────────────────────────────────────
	 * Handle the block checkout AJAX payment request.
	 *
	 * Root cause of "No available payment accounts":
	 * `new NFT_CHECKOUT_PAYMENT_GATEWAY()` creates a cold instance. In an AJAX
	 * context WooCommerce has not called init_settings() on it, so
	 * $this->sandbox defaults to false and get_option() returns empty values.
	 * get_next_available_account() then finds no matching keys → returns false.
	 *
	 * Fix: pull the already-booted instance from WC()->payment_gateways().
	 * That instance was fully initialised during the normal WC boot cycle so
	 * sandbox mode and account keys are correct.
	 * ───────────────────────────────────────────────────────────────────────────
	 */
	function handle_nft_checkout_gateway_ajax(){

		// Nonce verification
		$nonce = isset($_POST['nonce'])
			? sanitize_text_field(wp_unslash($_POST['nonce']))
			: '';

		if (empty($nonce) || !wp_verify_nonce($nonce, 'nft_checkout_payment')) {
			wp_send_json(['result' => 'fail', 'error' => 'Security check failed.']);
			die;
		}

		// Pull the already-initialised gateway from the WC registry.
		// Never use `new NFT_CHECKOUT_PAYMENT_GATEWAY()` here — see note above.
		$gateways       = WC()->payment_gateways()->payment_gateways();
		$nftCheckoutPayment = $gateways['nft-checkout'] ?? null;

		if (!$nftCheckoutPayment) {
			// Fallback: manually instantiate and force-load settings from DB.
			// Should never happen in normal operation.
			$nftCheckoutPayment = new NFT_CHECKOUT_PAYMENT_GATEWAY();
			$nftCheckoutPayment->init_settings();
			$nftCheckoutPayment->load_gateway_settings();

			NFT_Checkout_Payment_Gateway_Logger::warning(
				'NFT Checkout: gateway not found in WC registry during AJAX — fell back to manual instantiation.',
				['source' => 'nft-checkout-payment-gateway']
			);
		}

		$orderID = WC()->session
			? ( WC()->session->get('store_api_draft_order') ?: WC()->session->get('order_awaiting_payment') )
			: null;

		if ( ! empty( WC()->cart ) && ! WC()->cart->is_empty() ) {
			$orderID = $this->nft_checkout_create_block_order() ?: $orderID;
		}

		$status = [];
		if($orderID){
			$status = $nftCheckoutPayment->process_payment($orderID);
		}else{
			wc_add_notice(__('Invalid order.', 'nft-checkout-payment-gateway'), 'error');
			$status = ['result' => 'fail','error' => 'Invalid order.'];
		}
		
		wp_send_json($status);
		die;
	}

	/**
	 * Create the WooCommerce order for a block checkout payment.
	 *
	 * Delegates to WC_Checkout::create_order() so line items, shipping lines,
	 * coupons, taxes and totals are built by WooCommerce itself rather than by
	 * hand here.
	 *
	 * @return int Order ID, or 0 on failure.
	 */
	private function nft_checkout_create_block_order() {

		$checkout = WC()->checkout();
		$data     = $checkout->get_posted_data();
		$customer = WC()->customer;

		if ( $customer ) {

			$fields = [
				'first_name',
				'last_name',
				'company',
				'address_1',
				'address_2',
				'city',
				'state',
				'postcode',
				'country',
				'phone',
				'email',
			];

			foreach ( $fields as $field ) {

				$key = 'billing_' . $field;

				if ( ! empty( $data[ $key ] ) ) {
					continue;
				}

				$getter = 'get_' . $key;

				if ( is_callable( [ $customer, $getter ] ) ) {
					$data[ $key ] = $customer->$getter();
				}
			}
		}

		if ( empty( $data['payment_method'] ) ) {
			$data['payment_method'] = 'nft-checkout';
		}

		// Prevent reusing an order if the email address OR phone number doesn't match
		if ( WC()->session ) {
			$awaiting_order_id = WC()->session->get('order_awaiting_payment') ?: WC()->session->get('store_api_draft_order');
			if ( $awaiting_order_id ) {
				$awaiting_order = wc_get_order( $awaiting_order_id );
				$posted_email   = sanitize_email( $data['billing_email'] ?? '' );
				$posted_phone   = sanitize_text_field( $data['billing_phone'] ?? '' );
				
				if ( $awaiting_order && ( $awaiting_order->get_billing_email() !== $posted_email || $awaiting_order->get_billing_phone() !== $posted_phone ) ) {
					WC()->session->set( 'order_awaiting_payment', null );
					WC()->session->set( 'store_api_draft_order', null );
				}
			}
		}

		$order_id = $checkout->create_order( $data );

		if ( is_wp_error( $order_id ) ) {

			NFT_Checkout_Payment_Gateway_Logger::error(
				'Could not create order for block checkout',
				[
					'error' => $order_id->get_error_message(),
				]
			);

			return 0;
		}

		if ( WC()->session ) {
			WC()->session->set( 'order_awaiting_payment', $order_id );
		}

		$created = wc_get_order( $order_id );

		$email        = $created ? $created->get_billing_email() : '';
		$masked_email = !empty($email) && function_exists('nft_checkout_mask_email') ? nft_checkout_mask_email($email) : '';

		NFT_Checkout_Payment_Gateway_Logger::info(
			'Created order for block checkout',
			[
				'order_id' => $order_id,
				'total'    => $created ? $created->get_total() : null,
				'email'    => $masked_email,
			]
		);

		return $order_id;
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
		
		add_action( 'enqueue_block_assets', [ $this, 'register_blocks_assets' ] );

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
		if (!function_exists('WC')) return;

		$error = WC()->session->get('nft_checkout_error') ?: WC()->session->get('nft_checkout_error');
		if (!$error) return;

		$messages = [
			'failed'    => 'Payment failed. Please try again.',
			'cancelled' => 'Payment was cancelled.',
			'expired'   => 'Payment session expired. Please try again.',
			'duplicate' => 'This order appears to be a duplicate and cannot be processed at this time.'
		];

		// Clear error immediately
		WC()->session->__unset('nft_checkout_error');
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
	
	public function register_blocks_assets() {
		
		if (is_checkout()) {
			$image_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/loader.gif';
			wp_register_script(
				'nft-checkout-blocks-js',
				plugin_dir_url( NFT_CHECKOUT_PAYMENT_GATEWAY_FILE ) . 'assets/js/nft-checkout-blocks.js',
				[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
				'1.0.0',
				true
			);

			$raw_settings = get_option('woocommerce_nft-checkout_settings') ?: get_option('woocommerce_unified_settings', []);
			$settings     = [
				'title'       => $raw_settings['title'] ?? 'NFT Checkout',
				'description' => $raw_settings['description'] ?? '',
				'enabled'     => $raw_settings['enabled'] ?? 'no',
			];

			wp_localize_script(
				'nft-checkout-blocks-js',
				'nft_checkout_params',
				[ 'settings' => $settings,
				 'ajax_url' => admin_url('admin-ajax.php'),
				 'nft_checkout_loader' => $image_url,
				 'nft_checkout_nonce' => wp_create_nonce('nft_checkout_payment'), 
				 'checkout_url' => wc_get_checkout_url(),
				 'payment_method' => 'nft-checkout' 
				]
			);
	
		}
	}


	private function get_api_url($endpoint)
	{
		$endpoint = '/' . ltrim($endpoint, '/');
		if (strpos($endpoint, '/api/') !== 0 && $endpoint !== '/api') {
			$endpoint = '/api' . $endpoint;
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
				'docs'    => '<a href="' . esc_url(apply_filters('nft_checkout_docs_url', 'https://qa.rt.app/docs/wordpress-plugin')) . '" target="_blank">' . esc_html__('Documentation', 'nft-checkout-payment-gateway') . '</a>',
				'support' => '<a href="' . esc_url(apply_filters('nft_checkout_support_url', 'https://qa.rt.app/contact-us')) . '" target="_blank">' . esc_html__('Support', 'nft-checkout-payment-gateway') . '</a>',
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
		$schedules['every_two_hours'] = array(
			'interval' => 2 * 60 * 60, // 2 hours in seconds = 7200
			'display'  => __('Every Two Hours', 'nft-checkout-payment-gateway')
		);
		return $schedules;
	}

	function activate_cron_job()
	{
		NFT_Checkout_Payment_Gateway_Logger::info('Automatic payment status checks have been enabled.', ['source' => 'nft-checkout-payment-gateway']);

		// Clear existing scheduled event if it exists
		$timestamp = wp_next_scheduled('nft_checkout_cron_event');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'nft_checkout_cron_event');
		}

		// Schedule with new interval
		wp_schedule_event(time(), 'every_two_hours', 'nft_checkout_cron_event');
	}

	function deactivate_cron_job()
	{
		NFT_Checkout_Payment_Gateway_Logger::info('Automatic payment status checks have been disabled.', ['source' => 'nft-checkout-payment-gateway']);
		wp_clear_scheduled_hook('nft_checkout_cron_event');
	}


	public function handle_cron_event()
	{
		$logger_context = ['source' => 'nft-checkout-payment-gateway'];

		$accounts = get_option('woocommerce_nft-checkout_payment_gateway_accounts') ?: get_option('woocommerce_unified_payment_gateway_accounts');
		if (empty($accounts)) {
			$accounts = get_option('woocommerce_nft-checkout_payment_gateway_accounts') ?: get_option('woocommerce_unified_payment_gateway_accounts');
		}
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}

		if (!$accounts || !is_array($accounts)) {
			NFT_Checkout_Payment_Gateway_Logger::warning('No payment accounts found or the account format is invalid. Sync aborted.', $logger_context);
			return [];
		}

		$accountsData     = [];
		$first_public_key = '';
		$first_secret_key = '';

		foreach ($accounts as &$account) {
			$isSandboxEnabled = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';

			// NFT-03: Do not send secret keys to remote API. Send public key with HMAC signature instead.
			if (!empty($account['live_public_key']) && !empty($account['live_secret_key'])) {
				$timestamp   = (string) time();
				$sig_payload = $timestamp . '.' . $account['live_public_key'];
				$accountsData[] = [
					'account_name' => $account['title'] ?? '',
					'public_key'   => $account['live_public_key'],
					'signature'    => hash_hmac('sha256', $sig_payload, $account['live_secret_key']),
					'timestamp'    => $timestamp,
					'mode'         => 'live',
				];
				if (empty($first_public_key)) {
					$first_public_key = $account['live_public_key'];
					$first_secret_key = $account['live_secret_key'];
				}
			}

			if ($isSandboxEnabled && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) {
				$timestamp   = (string) time();
				$sig_payload = $timestamp . '.' . $account['sandbox_public_key'];
				$accountsData[] = [
					'account_name' => $account['title'] ?? '',
					'public_key'   => $account['sandbox_public_key'],
					'signature'    => hash_hmac('sha256', $sig_payload, $account['sandbox_secret_key']),
					'timestamp'    => $timestamp,
					'mode'         => 'sandbox',
				];
				if (empty($first_public_key)) {
					$first_public_key = $account['sandbox_public_key'];
					$first_secret_key = $account['sandbox_secret_key'];
				}
			}
		}

		if (empty($accountsData)) {
			NFT_Checkout_Payment_Gateway_Logger::warning('No valid credentials found in any payment account. Sync skipped.', $logger_context);
			return [];
		}

		$request_body = wp_json_encode(['accounts' => $accountsData]);
		$headers      = [
			'Content-Type' => 'application/json',
		];
		if (!empty($first_public_key)) {
			$headers['Authorization'] = 'Bearer ' . sanitize_text_field($first_public_key);
		}
		if (!empty($first_secret_key)) {
			$cron_timestamp         = (string) time();
			$headers['X-Timestamp'] = $cron_timestamp;
			$headers['X-Signature'] = hash_hmac('sha256', $cron_timestamp . '.' . $request_body, $first_secret_key);
		}

		$url = esc_url($this->get_api_url('/sync-account-status'));
		$response = wp_remote_post($url, [
			'headers'   => $headers,
			'body'      => $request_body,
			'timeout'   => 15,
			'sslverify' => true,
		]);

		if (is_wp_error($response)) {
			NFT_Checkout_Payment_Gateway_Logger::error('Unable to connect to the sync service. Please check the server connection or endpoint.', array_merge($logger_context, [
				'error' => $response->get_error_message(),
			]));
			return [];
		}

		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		$updated = false;
		$statusSummary = [];

		if (!empty($response_data['data'])) {
			foreach ($response_data['data'] as $statusData) {
				if (
					isset($statusData['mode'], $statusData['public_key'], $statusData['status']) &&
					!empty($statusData['status'])
				) {
					foreach ($accounts as &$account) {
						if (
							$statusData['mode'] === 'live' &&
							$account['live_public_key'] === $statusData['public_key']
						) {
							$account['live_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}

						if (
							$statusData['mode'] === 'sandbox' &&
							$account['sandbox_public_key'] === $statusData['public_key']
						) {
							$account['sandbox_status'] = $statusData['status'];
							$updated = true;
							$statusSummary[] = [
								'title'  => $account['title'] ?? 'N/A',
								'mode'   => $statusData['mode'],
								'status' => $statusData['status'],
							];
						}
					}
				}
			}
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
			NFT_Checkout_Payment_Gateway_Logger::info('Sync completed. No account status data was returned from the server.', $logger_context);
		}

		return $statusSummary;
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

		NFT_Checkout_Payment_Gateway_Logger::info("Payment accounts sync initiated", $logger_context);

		try {
			ob_start();

			$statusSummary = $this->handle_cron_event();
			$output = ob_get_clean();

			if (!empty($output)) {
				NFT_Checkout_Payment_Gateway_Logger::warning('Unexpected output generated during sync: ' . $output, $logger_context);
			}

			NFT_Checkout_Payment_Gateway_Logger::info('Payment accounts sync completed successfully.', $logger_context);

			wp_send_json_success([
				'message'  => __('Payment accounts synchronized successfully.', 'nft-checkout-payment-gateway'),
				'timestamp' => current_time('mysql'),
				'statuses' => $statusSummary
			]);
		} catch (Exception $e) {
			NFT_Checkout_Payment_Gateway_Logger::error('Payment accounts sync failed: ' . $e->getMessage(), $logger_context);
			wp_send_json_error([
				'message' => __('Sync failed: ', 'nft-checkout-payment-gateway') . $e->getMessage(),
				'code'    => $e->getCode()
			], 500);
		}

		wp_die(); // Always include this
	}

	public function nft_checkout_send_plugin_status($plugin_status, $gateway_loaded)
	{
		$accounts = get_option('woocommerce_nft-checkout_payment_gateway_accounts') ?: get_option('woocommerce_unified_payment_gateway_accounts', []);
		if (empty($accounts)) {
			$accounts = get_option('woocommerce_nft-checkout_payment_gateway_accounts') ?: get_option('woocommerce_unified_payment_gateway_accounts', []);
		}

		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}

		if (empty($accounts) || !is_array($accounts)) {
			return;
		}

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
			NFT_Checkout_Payment_Gateway_Logger::error(
				'Unable to send plugin status. No public key found.',
				[
					'source' => 'nft-checkout-payment-gateway',
				]
			);
			return;
		}

		global $wp_version;

		// NFT-03: Strip secret keys from telemetry
		$safe_accounts = array_map(function($acc) {
			unset($acc['live_secret_key'], $acc['sandbox_secret_key'], $acc['secret_key']);
			return $acc;
		}, $accounts);

		$body = [
			'valid_accounts'         => $safe_accounts,
			'plugin_status'          => (int) $plugin_status,
			'gateway_loaded'         => (int) $gateway_loaded,
			'plugin_version'         => NFT_CHECKOUT_PLUGIN_VERSION,
			'wordpress_version'      => $wp_version,
			'woocommerce_version'    => class_exists('WooCommerce') && function_exists('WC')
				? WC()->version
				: '',
			'woocommerce_db_version' => get_option('woocommerce_db_version'),
			'group_id'               => get_option('nft_checkout_group_id') ?: get_option('unified_group_id'),
			'domain_name'            => wp_parse_url(home_url(), PHP_URL_HOST),
		];

		$response = wp_remote_post(
			$this->get_api_url('/plugin/check/plugin'),
			[
				'method'    => 'POST',
				'timeout'   => 30,
				'sslverify' => true,
				'headers'   => [
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'body'      => $body,
			]
		);

		if (is_wp_error($response)) {
			NFT_Checkout_Payment_Gateway_Logger::error(
				'Plugin status API call failed.',
				[
					'source'  => 'nft-checkout-payment-gateway',
					'context' => [
						'error' => $response->get_error_message(),
					],
				]
			);
			return;
		}

		NFT_Checkout_Payment_Gateway_Logger::info(
			'Plugin status updated successfully.',
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
}
