<?php
if (!defined('ABSPATH')) {
	exit();
}

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'class-nft-checkout-payment-logger.php';

class NFT_CHECKOUT_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = 'nft-checkout';

	protected $sandbox;
	private $base_url;
	private $public_key;
	private $secret_key;
	private $sandbox_secret_key;
	private $sandbox_public_key;

	private $admin_notices;
	private $accounts = [];
	private $current_account_index = 0;
	private $used_accounts = [];

	private static $log_once_flags = [];


	/**
	 * Account selected during the availability filter for dynamic title/subtitle.
	 *
	 * @var array|null
	 */
	private $selected_account_for_display = null;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		if (!class_exists('WC_Payment_Gateway_CC')) {
			add_action('admin_notices', [$this, 'nft_checkout_woocommerce_not_active_notice']);
			return;
		}

		$this->admin_notices = new NFT_CHECKOUT_PAYMENT_GATEWAY_Admin_Notices();
		$this->base_url      = NFT_CHECKOUT_BASE_URL;

		$this->id                 = self::ID;
		$this->icon               = '';
		$this->method_title       = __('NFT Checkout', 'nft-checkout-payment-gateway');
		$this->method_description = __('Purchase and pay quickly with NFT Checkout', 'nft-checkout-payment-gateway');

		$this->nft_checkout_init_form_fields();
		$this->init_settings();
		$this->settings['group_id'] = get_option('nft_checkout_group_id') ? get_option('nft_checkout_group_id') : $this->nft_checkout_get_group_id();
		$this->load_gateway_settings();

		$this->register_hooks();
	}

	/**
	 * Load gateway settings.
	 * Called once in constructor AND can be re-called to refresh in AJAX context.
	 */
	public function load_gateway_settings() {
		$this->title       = sanitize_text_field($this->get_option('title'));
		$this->description = !empty($this->get_option('description'))
			? sanitize_textarea_field($this->get_option('description'))
			: ($this->get_option('show_consent_checkbox') === 'yes' ? 1 : 0);

		$this->enabled    = sanitize_text_field($this->get_option('enabled'));
		$this->sandbox    = 'yes' === sanitize_text_field($this->get_option('sandbox'));
		$this->public_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_public_key' : 'public_key'));
		$this->secret_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_secret_key' : 'secret_key'));
		$this->current_account_index = 0;
	}

	/**
	 * Register hooks for the gateway.
	 */
	private function register_hooks() {
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'nft_checkout_process_admin_options']);
		add_action('wp_enqueue_scripts', [$this, 'nft_checkout_enqueue_styles_and_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'nft_checkout_admin_scripts']);

		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'nft_checkout_display_test_order_tag']);
		add_filter('woocommerce_admin_order_preview_line_items', [$this, 'nft_checkout_add_custom_label_to_order_row'], 10, 2);
		add_filter('woocommerce_available_payment_gateways', [$this, 'nft_checkout_hide_custom_payment_gateway_conditionally']);

		add_action('woocommerce_after_checkout_validation', [$this, 'nft_checkout_validate_checkout_fields'], 10, 2);
		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			[$this, 'nft_checkout_validate_blocks_checkout'],
			10,
			2
		);

		add_action('woocommerce_thankyou_' . $this->id, [$this, 'nft_checkout_thankyou_payment_link_notice']);

	}

	/**
	 * Strict validation for Phone and Zip Code.
	 * Runs before process_payment to ensure only clean data reaches your API.
	 */
	public function nft_checkout_validate_checkout_fields($data, $errors)
	{
		$selected_gateway = wc_clean(
			wp_unslash($_POST['payment_method'] ?? '')
		);

		if (empty($selected_gateway)) {
			return;
		}

		if ($selected_gateway !== $this->id) {
			return;
		}

		/*
		|--------------------------------------------------------------------------
		| PHONE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$phone   = trim($data['billing_phone'] ?? '');
		$country = strtoupper($data['billing_country'] ?? '');

		if (!empty($phone)) {

			$country_calling_code = WC()->countries->get_country_calling_code($country);

			if (is_array($country_calling_code)) {
				$country_calling_code = reset($country_calling_code);
			}

			$normalized = $this->nft_checkout_normalize_phone(
				$phone,
				$country_calling_code
			);

			if (empty($normalized['is_valid'])) {
				$errors->add(
					'nft_checkout_phone_error',
					$normalized['error'] ?? __('Invalid phone number.', 'nft-checkout-payment-gateway')
				);
				return;
			}
		}

		/*
		|--------------------------------------------------------------------------
		| PO BOX VALIDATION
		|--------------------------------------------------------------------------
		*/
		$billing_address_1 = trim($data['billing_address_1'] ?? '');

		if (!empty($billing_address_1) && $this->is_po_box($billing_address_1)) {

			NFT_Checkout_Payment_Gateway_Logger::warning(
				'Classic checkout validation failed: PO Box detected',
				[
					'country' => $country,
				]
			);

			$errors->add(
				'nft_checkout_po_box_error',
				__('PO Box addresses are not accepted. Please enter a physical street address.', 'nft-checkout-payment-gateway')
			);

			return;
		}

		/*
		|--------------------------------------------------------------------------
		| ZIP / POSTCODE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$postcode = trim($data['billing_postcode'] ?? '');

		if (!empty($postcode)) {

			$clean = strtoupper(preg_replace('/\s+/', '', $postcode));

			$valid = false;

			switch ($country) {

				case 'US':
					$valid = preg_match('/^\d{5}(-\d{4})?$/', $postcode);
					break;

				case 'CA':
					$valid = preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $clean);
					break;

				case 'GB':
					$valid = preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $clean);
					break;

				default:

					$clean_postcode = preg_replace('/[^A-Z0-9]/', '', strtoupper($postcode));

					// Reject purely numeric long values
					if (preg_match('/^\d{8,}$/', $clean_postcode)) {
						$valid = false;
					} else {

						$valid = preg_match(
							'/^(?=.*[A-Z0-9])[A-Z0-9\- ]{3,12}$/i',
							$postcode
						);
					}

					break;
			}

			if (!$valid) {

				NFT_Checkout_Payment_Gateway_Logger::warning(
					'Classic checkout validation failed: invalid postcode',
					[
						'country'  => $country
					]
				);

				$errors->add(
					'nft_checkout_postcode_error',
					__('Invalid ZIP / postal code.', 'nft-checkout-payment-gateway')
				);

				return;
			}

			NFT_Checkout_Payment_Gateway_Logger::info(
				'Classic checkout postcode validation passed',
				[
					'country'  => $country
				]
			);
		}
	}


	public function nft_checkout_validate_blocks_checkout($order, $request)
	{
		$payment_method = $request['payment_method'] ?? '';

		if (empty($payment_method)) {
			return;
		}

		if ($payment_method !== $this->id) {
			return;
		}

		/*
		|--------------------------------------------------------------------------
		| PHONE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$phone   = trim($request['billing_address']['phone'] ?? '');
		$country = strtoupper($request['billing_address']['country'] ?? '');

		if (!empty($phone)) {

			$country_calling_code = WC()->countries->get_country_calling_code($country);

			if (is_array($country_calling_code)) {
				$country_calling_code = reset($country_calling_code);
			}

			$normalized = $this->nft_checkout_normalize_phone(
				$phone,
				$country_calling_code
			);

			if (empty($normalized['is_valid'])) {

				NFT_Checkout_Payment_Gateway_Logger::warning(
					'Blocks checkout validation failed: invalid phone number',
					[
						'country'    => $country,
						'order_id'   => $order->get_id() ?? null,
						'error'      => $normalized['error'] ?? null,
					]
				);

				throw new Exception(
					$normalized['error'] ?? 'Invalid phone number.'
				);
			}

			// Sync latest validated value to order
			$order->set_billing_phone($phone);

			NFT_Checkout_Payment_Gateway_Logger::info(
				'Blocks checkout phone validation passed',
				[
					'country'    => $country,
					'order_id'   => $order->get_id() ?? null,
				]
			);
		}

		/*
		|--------------------------------------------------------------------------
		| PO BOX VALIDATION
		|--------------------------------------------------------------------------
		*/
		$billing_address_1 = trim($request['billing_address']['address_1'] ?? '');

		if (!empty($billing_address_1) && $this->is_po_box($billing_address_1)) {

			NFT_Checkout_Payment_Gateway_Logger::warning(
				'Blocks checkout validation failed: PO Box detected',
				[
					'country'  => $country,
					'order_id' => $order->get_id() ?? null,
				]
			);

			throw new Exception(
				'PO Box addresses are not accepted. Please enter a physical street address.'
			);
		}

		/*
		|--------------------------------------------------------------------------
		| ZIP / POSTCODE VALIDATION
		|--------------------------------------------------------------------------
		*/
		$postcode = trim($request['billing_address']['postcode'] ?? '');

		if (!empty($postcode)) {

			$clean = strtoupper(preg_replace('/\s+/', '', $postcode));

			$valid = false;

			switch ($country) {

				case 'US':
					$valid = preg_match('/^\d{5}(-\d{4})?$/', $postcode);
					break;

				case 'CA':
					$valid = preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $clean);
					break;

				case 'GB':
					$valid = preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $clean);
					break;

				default:

					$clean_postcode = preg_replace('/[^A-Z0-9]/', '', strtoupper($postcode));

					// Reject purely numeric long values
					if (preg_match('/^\d{8,}$/', $clean_postcode)) {
						$valid = false;
					} else {

						$valid = preg_match(
							'/^(?=.*[A-Z0-9])[A-Z0-9\- ]{3,12}$/i',
							$postcode
						);
					}

					break;
			}

			if (!$valid) {

				NFT_Checkout_Payment_Gateway_Logger::warning(
					'Blocks checkout validation failed: invalid postcode',
					[
						'country'  => $country,
						'order_id' => $order->get_id() ?? null
					]
				);

				throw new Exception(
					'Invalid ZIP / postal code.'
				);
			}

			$order->set_billing_postcode($postcode);

			NFT_Checkout_Payment_Gateway_Logger::info(
				'Blocks checkout postcode validation passed',
				[
					'country'  => $country,
					'order_id' => $order->get_id() ?? null
				]
			);
		}

		return $order;
	}

	private function get_api_url($endpoint) {
		$endpoint = '/' . ltrim($endpoint, '/');
		if (strpos($endpoint, '/api/') !== 0 && $endpoint !== '/api') {
			$endpoint = '/api' . $endpoint;
		}
		return $this->base_url . $endpoint;
	}

	public function nft_checkout_process_admin_options() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Permission denied.', 'nft-checkout-payment-gateway'));
		}

		if (!isset($_POST['nft_checkout_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nft_checkout_accounts_nonce'])), 'nft_checkout_accounts_nonce_action')) {
			NFT_Checkout_Payment_Gateway_Logger::info('CSRF check failed during admin options update.');
			wp_die(esc_html__('Security check failed!', 'nft-checkout-payment-gateway'));
		}

		$enabled     = isset($_POST['woocommerce_' . $this->id . '_enabled']) ? 'yes' : 'no';
		$accounts    = isset($_POST['accounts']) && is_array($_POST['accounts']) ? wp_unslash($_POST['accounts']) : [];
		$keys_entered = false;

		if (!empty($accounts)) {
			foreach ($accounts as $account) {
				if (
					!empty($account['live_public_key']) ||
					!empty($account['live_secret_key']) ||
					!empty($account['sandbox_public_key']) ||
					!empty($account['sandbox_secret_key'])
				) {
					$keys_entered = true;
					break;
				}
			}
		}

		parent::process_admin_options();

		$errors             = [];
		$valid_accounts     = [];
		$unique_live_keys   = [];
		$unique_sandbox_keys = [];
		$normalized_index   = 0;
		$raw_accounts       = [];

		if (isset($_POST['accounts']) && is_array($_POST['accounts'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$unslashed_accounts = wp_unslash($_POST['accounts']);
			$raw_accounts = array_map(
				static function ($account) {
					return is_array($account)
						? array_map('sanitize_text_field', $account)
						: sanitize_text_field($account);
				},
				$unslashed_accounts
			);
		}

		if (!is_array($raw_accounts) || empty($raw_accounts)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'nft-checkout-payment-gateway');
			NFT_Checkout_Payment_Gateway_Logger::info('No accounts submitted in admin options.');
		}

		foreach ((array) $raw_accounts as $account) {
			if (!is_array($account)) continue;

			$account = array_map('sanitize_text_field', $account);

			$account_title      = $account['title'] ?? '';
			$priority           = intval($account['priority'] ?? 1);
			$live_public_key    = $account['live_public_key'] ?? '';
			$live_secret_key    = $account['live_secret_key'] ?? '';
			$sandbox_public_key = $account['sandbox_public_key'] ?? '';
			$sandbox_secret_key = $account['sandbox_secret_key'] ?? '';
			$has_sandbox         = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';
			$live_status        = $account['live_status'] ?? 'Active';
			$sandbox_status     = $has_sandbox ? ($account['sandbox_status'] ?? 'Active') : '';
			$unique_id          = $account['unique_id'] ?? '';
			$checkout_title      = $account['checkout_title'] ?? '';
	        $checkout_subtitle   = $account['checkout_subtitle'] ?? '';

			if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
				continue;
			}

			if (empty($account_title) || empty($live_public_key) || empty($live_secret_key)) {
				$errors[] = sprintf(__('Account "%s": Title, Live Public Key, and Live Secret Key are required.', 'nft-checkout-payment-gateway'), $account_title);
				NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: missing required fields for account '{$account_title}'");
				continue;
			}

			$live_combined = $live_public_key . '|' . $live_secret_key;
			if (in_array($live_combined, $unique_live_keys, true)) {
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be unique.', 'nft-checkout-payment-gateway'), $account_title);
				NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: duplicate live keys for account '{$account_title}'");
				continue;
			}

			if ($live_public_key === $live_secret_key) {
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be different.', 'nft-checkout-payment-gateway'), $account_title);
				NFT_Checkout_Payment_Gateway_Logger::info("Validation warning: live keys are identical for account '{$account_title}'");
			}

			$unique_live_keys[] = $live_combined;

			if ($has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
				$sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
				if (in_array($sandbox_combined, $unique_sandbox_keys, true)) {
					$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be unique.', 'nft-checkout-payment-gateway'), $account_title);
					NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: duplicate sandbox keys for account '{$account_title}'");
					continue;
				}
				if ($sandbox_public_key === $sandbox_secret_key) {
					$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be different.', 'nft-checkout-payment-gateway'), $account_title);
					NFT_Checkout_Payment_Gateway_Logger::info("Validation warning: sandbox keys are identical for account '{$account_title}'");
				}
				$unique_sandbox_keys[] = $sandbox_combined;
			}

			$valid_accounts[$normalized_index++] = [
				'title'              => $account_title,
				'priority'           => $priority,
				'live_public_key'    => $live_public_key,
				'live_secret_key'    => $live_secret_key,
				'sandbox_public_key' => $sandbox_public_key,
				'sandbox_secret_key' => $sandbox_secret_key,
				'has_sandbox'        => $has_sandbox ? 'on' : 'off',
				'sandbox_status'     => $sandbox_status,
				'live_status'        => $live_status,
				'unique_id'          => $unique_id,
				'checkout_title'     => $checkout_title,
	            'checkout_subtitle'  => $checkout_subtitle,
			];

			NFT_Checkout_Payment_Gateway_Logger::info("Validated and added account '{$account_title}' to saved list.");
		}

		if (empty($valid_accounts) && empty($errors)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'nft-checkout-payment-gateway');
			NFT_Checkout_Payment_Gateway_Logger::info('All submitted accounts failed validation. No accounts will be saved.');
		}

		if (empty($errors)) {
			update_option('woocommerce_nft-checkout_payment_gateway_accounts', $valid_accounts);

			$first_account  = reset($valid_accounts);
			$public_key     = $first_account ? ($this->sandbox ? ($first_account['sandbox_public_key'] ?? '') : ($first_account['live_public_key'] ?? '')) : '';
			$api_url        = esc_url($this->get_api_url('/plugin/check/plugin'));
			$plugin_version = NFT_CHECKOUT_PLUGIN_VERSION;

			global $wp_version;

			// NFT-03: Strip secret keys before sending telemetry/status
			$safe_valid_accounts = array_map(function($acc) {
				unset($acc['live_secret_key'], $acc['sandbox_secret_key'], $acc['secret_key']);
				return $acc;
			}, $valid_accounts);

			$body = [
				'valid_accounts' => $safe_valid_accounts,
				'plugin_status'  => $enabled === 'yes' ? 1 : 0,
				'plugin_version' => $plugin_version,
				'gateway_loaded' => 0,
				'group_id'       => get_option('nft_checkout_group_id'),
				'domain_name'    => parse_url(home_url(), PHP_URL_HOST),
				'wordpress_version'     => $wp_version,
				'woocommerce_version'   => class_exists('WooCommerce') ? WC()->version : null,
				'woocommerce_db_version'=> get_option('woocommerce_db_version'),
			];

			if (!empty($public_key)) {
				wp_remote_post($api_url, [
					'method'    => 'POST',
					'timeout'   => 15,
					'body'      => $body,
					'headers'   => [
						'Content-Type'  => 'application/x-www-form-urlencoded',
						'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
					],
					'sslverify' => true,
				]);
			}

			NFT_Checkout_Payment_Gateway_Logger::info('Account settings updated successfully.', ['count' => count($valid_accounts)]);

			if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
				$loader = NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance();
				if (method_exists($loader, 'handle_cron_event')) {
					$loader->handle_cron_event();
					NFT_Checkout_Payment_Gateway_Logger::info('Triggered NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::handle_cron_event() after settings save.');
				}
			}
		} else {
			foreach ($errors as $error) {
				$this->admin_notices->nft_checkout_add_notice('settings_error', 'notice notice-error', $error);
				NFT_Checkout_Payment_Gateway_Logger::info("Admin settings error: {$error}");
			}
		}

		add_action('admin_notices', [$this->admin_notices, 'display_notices']);
	}


	public function nft_checkout_init_form_fields() {
		$this->form_fields = $this->nft_checkout_get_form_fields();
	}

	function nft_checkout_get_group_id() {
		$group_id = get_option('nft_checkout_group_id');
		if (empty($group_id)) {
			$group_id = 'grp_' . wp_generate_uuid4();
			update_option('nft_checkout_group_id', $group_id);
		}
		return $group_id;
	}

	function nft_checkout_get_unique_id() {
		return 'acc_' . wp_generate_uuid4();
	}

	function update_accounts_uniqueID($accounts) {
		if (empty($accounts) || !is_array($accounts)) return $accounts;
		$updated = false;
		foreach ($accounts as $index => &$account) {
			if (!is_array($account)) continue;
			if (empty($account['unique_id'])) {
				$account['unique_id'] = $this->nft_checkout_get_unique_id();
				$updated = true;
			}
		}
		unset($account);
		if ($updated) {
			update_option('woocommerce_nft-checkout_payment_gateway_accounts', $accounts);
		}
		return $accounts;
	}

	public function nft_checkout_get_form_fields() {
		$dev_instructions_link = sprintf(
			'<strong><a class="nft-checkout-instructions-url" href="%s" target="_blank">%s</a></strong><br>',
			esc_url($this->base_url . '/developers'),
			__('click here to access your developer account', 'nft-checkout-payment-gateway')
		);

		return apply_filters('nft_checkout_woocommerce_gateway_settings_fields_' . $this->id, [

			'enabled' => [
				'title'   => __('Enable/Disable', 'nft-checkout-payment-gateway'),
				'label'   => __('Enable NFT Checkout', 'nft-checkout-payment-gateway'),
				'type'    => 'checkbox',
				'default' => 'no',
			],

			'title' => [
				'title'       => __('Title', 'nft-checkout-payment-gateway'),
				'type'        => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'nft-checkout-payment-gateway'),
				'default'     => __('NFT Checkout', 'nft-checkout-payment-gateway'),
				'desc_tip'    => true,
			],

			'description' => [
				'title'       => __('Description', 'nft-checkout-payment-gateway'),
				'type'        => 'textarea',
				'description' => __('Provide a brief description of the payment option.', 'nft-checkout-payment-gateway'),
				'default'     => __('Purchase and pay quickly with NFT Checkout', 'nft-checkout-payment-gateway'),
				'desc_tip'    => true,
			],

			'instructions' => [
				'title'       => __('Instructions', 'nft-checkout-payment-gateway'),
				'type'        => 'title',
				'description' => sprintf(__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'nft-checkout-payment-gateway'), $dev_instructions_link, ''),
				'desc_tip'    => true,
			],

			'sandbox' => [
				'title'       => __('Sandbox', 'nft-checkout-payment-gateway'),
				'label'       => __('Enable Sandbox Mode', 'nft-checkout-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Use sandbox API keys (real payments will not be taken).', 'nft-checkout-payment-gateway'),
				'default'     => 'no',
			],

			'group_id' => [
				'type' => 'hidden',
			],

			'accounts' => [
				'title'       => __('Payment Accounts', 'nft-checkout-payment-gateway'),
				'type'        => 'accounts_repeater',
				'description' => __('Add multiple payment accounts dynamically.', 'nft-checkout-payment-gateway'),
			],

			'order_status' => [
				'title'       => __('Order Status', 'nft-checkout-payment-gateway'),
				'type'        => 'select',
				'description' => __('Order status after successful payment.', 'nft-checkout-payment-gateway'),
				'default'     => '',
				'id'          => 'order_status_select',
				'desc_tip'    => true,
				'options'     => [
					'processing' => __('Processing', 'nft-checkout-payment-gateway'),
					'completed'  => __('Completed', 'nft-checkout-payment-gateway'),
				],
			],

			'show_consent_checkbox' => [
				'title'       => __('Show Consent Checkbox', 'nft-checkout-payment-gateway'),
				'label'       => __('Enable consent checkbox on checkout page', 'nft-checkout-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Show a checkbox for user consent during checkout.', 'nft-checkout-payment-gateway'),
				'default'     => 'no',
			],

		], $this);
	}

	public function generate_accounts_repeater_html($key, $data) {
		$option_value    = get_option('woocommerce_nft-checkout_payment_gateway_accounts') ?: get_option('woocommerce_unified_payment_gateway_accounts', []);
		$option_value    = maybe_unserialize($option_value);
		$active_account  = get_option('nft_checkout_active_account', 0);
		$global_settings = get_option('woocommerce_nft-checkout_settings') ?: get_option('woocommerce_unified_settings', []);
		$global_settings = maybe_unserialize($global_settings);
		$sandbox_enabled = !empty($global_settings['sandbox']) && $global_settings['sandbox'] === 'yes';

		$updated = false;
		if (!empty($option_value)) {
			foreach ($option_value as $index => &$account) {
				if (empty($account['unique_id'])) {
					$account['unique_id'] = $this->nft_checkout_get_unique_id();
					$updated = true;
				}
				// Ensure all fields are present for new/empty accounts
				if (!isset($account['checkout_title'])) {
					$account['checkout_title'] = '';
				}
				if (!isset($account['checkout_subtitle'])) {
					$account['checkout_subtitle'] = '';
				}
			}
		}
		unset($account);

		if ($updated) {
			update_option('woocommerce_nft-checkout_payment_gateway_accounts', $option_value);
		}

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<div id="global-error" class="error-message" style="color: red; margin-bottom: 10px;"></div>
				<div class="nft-checkout-accounts-container">
					<?php if (!empty($option_value)): ?>
						<div class="nft-checkout-sync-account">
							<span id="nft-checkout-sync-status"></span>
							<button class="button" id="nft-checkout-sync-accounts"><span><i class="fa fa-refresh" aria-hidden="true"></i></span> <?php esc_html_e('Sync Accounts', 'nft-checkout-payment-gateway'); ?></button>
						</div>
					<?php endif; ?>

					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No accounts available. Please add one to continue.', 'nft-checkout-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<?php
							$live_status    = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							$unique_id      = (!empty($account['unique_id'])) ? $account['unique_id'] : '';
							?>
							<div class="nft-checkout-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][live_status]" value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]" value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
								<div class="title-blog">
									<h4>
										<span class="account-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('Untitled Account', 'nft-checkout-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down <?php echo esc_attr($this->id); ?>-toggle-btn" aria-hidden="true"></i>
									</h4>
									<div class="action-button">
										<div class="account-status-block" style="float: right;">
											<span class="account-status-label <?php echo esc_attr($sandbox_enabled ? 'sandbox-status' : 'live-status'); ?> <?php echo esc_attr(sanitize_html_class(str_replace([' ', '_'], '-', strtolower($sandbox_enabled ? ($sandbox_status ?? '') : ($live_status ?? ''))))); ?>">
												<?php
												if ($sandbox_enabled) {
													echo esc_html__('Sandbox Account Status: ', 'nft-checkout-payment-gateway') . esc_html(ucfirst($sandbox_status));
												} else {
													echo esc_html__('Live Account Status: ', 'nft-checkout-payment-gateway') . esc_html(ucfirst($live_status));
												}
												?>
											</span>
										</div>
										<button type="button" class="delete-account-btn">
											<i class="fa fa-trash" aria-hidden="true"></i>
										</button>
									</div>
								</div>

								<div class="<?php echo esc_attr($this->id); ?>-info">
									<div class="add-blog title-priority">
										<div class="account-input account-name">
											<label><?php esc_html_e('Account Name', 'nft-checkout-payment-gateway'); ?></label>
											<input type="text" class="account-title" name="accounts[<?php echo esc_attr($index); ?>][title]" placeholder="<?php esc_attr_e('Account Title', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div>
											<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][unique_id]" value="<?php echo esc_attr($unique_id); ?>" readonly>
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Priority', 'nft-checkout-payment-gateway'); ?></label>
											<input type="number" class="account-priority" name="accounts[<?php echo esc_attr($index); ?>][priority]" placeholder="<?php esc_attr_e('Priority', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>

									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout Title', 'nft-checkout-payment-gateway'); ?></label>
											<input type="text"
												name="accounts[<?php echo esc_attr($index); ?>][checkout_title]"
												placeholder="<?php esc_attr_e('Title shown to customers at checkout', 'nft-checkout-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['checkout_title'] ?? ''); ?>">
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout Subtitle', 'nft-checkout-payment-gateway'); ?></label>
											<textarea
												name="accounts[<?php echo esc_attr($index); ?>][checkout_subtitle]"
												placeholder="<?php esc_attr_e('Subtitle/description shown below the title at checkout', 'nft-checkout-payment-gateway'); ?>"
												rows="2"><?php echo esc_textarea($account['checkout_subtitle'] ?? ''); ?></textarea>
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Live Keys', 'nft-checkout-payment-gateway'); ?></label>
											<input type="text" class="live-public-key" name="accounts[<?php echo esc_attr($index); ?>][live_public_key]" placeholder="<?php esc_attr_e('Public Key', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<input type="text" class="live-secret-key" name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]" placeholder="<?php esc_attr_e('Secret Key', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_secret_key'] ?? ''); ?>">
										</div>
									</div>

									<div class="account-checkbox">
										<?php
										$checkbox_id    = $this->id . '-sandbox-checkbox-' . $index;
										$checkbox_class = $this->id . '-sandbox-checkbox';
										?>
										<input type="checkbox" class="<?php echo esc_attr($checkbox_class); ?>" id="<?php echo esc_attr($checkbox_id); ?>" name="accounts[<?php echo esc_attr($index); ?>][has_sandbox]" <?php checked($account['has_sandbox'] == 'on'); ?>>
										<label for="<?php echo esc_attr($checkbox_id); ?>"><?php esc_html_e('Do you have the sandbox keys?', 'nft-checkout-payment-gateway'); ?></label>
									</div>

									<?php
									$sandbox_container_id    = $this->id . '-sandbox-keys-' . $index;
									$sandbox_container_class = $this->id . '-sandbox-keys';
									$sandbox_display_style   = $account['has_sandbox'] == 'off' ? 'display: none;' : '';
									?>
									<div id="<?php echo esc_attr($sandbox_container_id); ?>" class="<?php echo esc_attr($sandbox_container_class); ?>" style="<?php echo esc_attr($sandbox_display_style); ?>">
										<div class="add-blog">
											<div class="account-input">
												<label><?php esc_html_e('Sandbox Keys', 'nft-checkout-payment-gateway'); ?></label>
												<input type="text" class="sandbox-public-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]" placeholder="<?php esc_attr_e('Public Key', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
											</div>
											<div class="account-input">
												<input type="text" class="sandbox-secret-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]" placeholder="<?php esc_attr_e('Secret Key', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_secret_key'] ?? ''); ?>">
											</div>
										</div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php wp_nonce_field('nft_checkout_accounts_nonce_action', 'nft_checkout_accounts_nonce'); ?>
					<div class="add-account-btn">
						<button type="button" class="button nft-checkout-add-account">
							<span>+</span> <?php esc_html_e('Add Account', 'nft-checkout-payment-gateway'); ?>
						</button>
					</div>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function process_payment($order_id, $used_accounts = [])
	{
		global $wpdb;

		$lock_name = '';

		$log_prefix = "[Order #{$order_id}]";

		$start_time = microtime(true);

		NFT_Checkout_Payment_Gateway_Logger::info(
			$log_prefix . ' Payment process started',
			[
				'order_id' => $order_id
			]
		);

		wc_clear_notices();

		// -------------------------------------------------
		// 1. ORDER VALIDATION
		// -------------------------------------------------
		$order = wc_get_order($order_id);

		if (!$order) {


			if (is_checkout()) {
				wc_add_notice(__('Invalid order.', 'nft-checkout-payment-gateway'), 'error');
			}

			return $this->build_response(
				'fail',
				'Invalid order.',
				[],
				400,
				$order_id
			);
		}

		NFT_Checkout_Payment_Gateway_Logger::info(
			"Payment initiated",
			[
				'order_id' => $order_id,
				'status'   =>  $order->get_status()
			]
		);

		$lock_name = 'nft_checkout_order_' . $order_id;

		// Try lock
		$lock_result = $wpdb->get_var(
			$wpdb->prepare("SELECT GET_LOCK(%s, 3)", $lock_name)
		);

		if ((string)$lock_result !== '1') {
			return $this->build_response(
				'fail',
				'Payment already in progress. Please wait a few seconds and try again.',
				[],
				409,
				$order_id
			);
		}

		try {

			// -------------------------------------------------
			// 4. RATE LIMITING (UNCHANGED)
			// -------------------------------------------------
			$ip_address  = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: 'invalid';
			$window_size = 10;
			$max_requests = 5;

			$timestamp_key = "rate_limit_{$ip_address}_timestamps";
			$timestamps    = get_transient($timestamp_key) ?: [];
			$current_time  = time();

			$timestamps = array_filter($timestamps, fn($ts) => $current_time - $ts <= $window_size);

			if (count($timestamps) >= $max_requests) {

				NFT_Checkout_Payment_Gateway_Logger::warning(
					$log_prefix . ' Rate limit exceeded',
					[
						'ip_address' => $ip_address,
					]
				);

				if (is_checkout()) {
					wc_add_notice(__('Too many requests. Please try again later.', 'nft-checkout-payment-gateway'), 'error');
				}

				return $this->build_response(
					'fail',
					'Too many requests. Please try again later.',
					[],
					429,
					$order_id
				);
			}

			$timestamps[] = $current_time;
			set_transient($timestamp_key, $timestamps, $window_size);

			// -------------------------------------------------
			// 5. ORDER STATUS PROTECTION & DUPLICATE PAYMENT PREVENTION
			// -------------------------------------------------
			if ($this->nft_checkout_is_duplicate_payment($order)) {
				$duplicate_warning = __('This order appears to be a duplicate and cannot be processed at this time.', 'nft-checkout-payment-gateway');
				NFT_Checkout_Payment_Gateway_Logger::warning(
					$log_prefix . ' Duplicate payment attempt detected and prevented',
					[
						'order_id'    => $order_id,
						'customer_id' => $order->get_customer_id(),
						'email'       => $this->nft_checkout_mask_email($order->get_billing_email()),
						'total'       => $order->get_total(),
						'status'      => $order->get_status(),
					]
				);

				if (!$this->is_block_checkout_request() && (is_checkout() || (function_exists('is_checkout_pay_page') && is_checkout_pay_page()))) {
					wc_add_notice($duplicate_warning, 'error');
				}

				return $this->build_response(
					'fail',
					$duplicate_warning,
					['is_duplicate' => true],
					400,
					$order_id
				);
			}

			// In-flight concurrency lock to prevent duplicate clicks for the same order and amount
			$active_lock_key = 'nft_checkout_payment_active_' . $order_id;
			set_transient($active_lock_key, [
				'user_id' => $order->get_customer_id(),
				'email'   => $order->get_billing_email(),
				'amount'  => (float) $order->get_total(),
				'time'    => time(),
			], 60);

			// -------------------------------------------------
			// 6. SANDBOX FLAG (UNCHANGED)
			// -------------------------------------------------
			if ($this->sandbox) {
				if (!$order->get_meta('_is_test_order')) {
					$order->update_meta_data('_is_test_order', true);
					nft_checkout_add_unique_order_note(
						$order,
						'sandbox_mode',
						__('This is a test order processed in sandbox mode.', 'nft-checkout-payment-gateway')
					);
				}
			}

			// -------------------------------------------------
			// 7. VOUCHER EMAIL (SENT BY UNIFIED)
			// -------------------------------------------------
			// Checkout creates no payment link. Unified emails the customer a
			// voucher, and the payment link behind its button is created when they
			// open it. All this plugin does is ask for that email and repeat what
			// Unified says about it.
			$order->update_status('pending', __('Awaiting voucher purchase.', 'nft-checkout-payment-gateway'));

			$voucher = $this->nft_checkout_request_voucher_email($order);

			if (!$voucher['success']) {
				delete_transient($active_lock_key);

				$voucher_error = $voucher['message'];

				// Classic checkout only relays queued notices in its failure response.
				if (!$this->is_block_checkout_request() && is_checkout()) {
					wc_add_notice($voucher_error, 'error');
				}

				return $this->build_response(
					'fail',
					$voucher_error,
					['is_duplicate' => !empty($voucher['is_duplicate'])],
					!empty($voucher['is_duplicate']) ? 400 : 502,
					$order_id
				);
			}

			$purchase_url = esc_url_raw((string) ($voucher['data']['purchase_url'] ?? ''), ['https', 'http']);
			// Unsigned /voucher/redeem/ URLs cause 410 "Voucher unavailable" on the server.
			if (false !== strpos($purchase_url, '/voucher/redeem/') && false === strpos($purchase_url, 'signature=')) {
				$purchase_url = '';
			}
			$voucher['data']['purchase_url'] = $purchase_url;

			$this->nft_checkout_record_voucher_sent($order, $voucher['data']);

			NFT_Checkout_Payment_Gateway_Logger::info(
				$log_prefix . ' Voucher email requested',
				[
					'order_id'  => $order_id,
					'reference' => $voucher['data']['reference'] ?? null,
				]
			);

			return $this->build_response(
				'success',
				$voucher['message'],
				[
					'payment_status' => 'pending',
					'order_received' => [
						'order_number' => $order->get_order_number(),
						'email'        => $order->get_billing_email(),
						'items'        => $this->nft_checkout_get_summary_rows($order),
						'amount_due'   => $this->nft_checkout_plain_price($order->get_total(), $order),
						'site_name'    => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
						// Shown on the checkout exactly as Unified worded it.
						'message'      => $voucher['message'],
						'reference'    => $voucher['data']['reference'] ?? '',
						'voucher_id'   => $voucher['data']['voucher_id'] ?? '',
						// The email button's own link, so "Not receiving the email?
						// Click here" opens exactly what the email would.
						'purchase_url' => $purchase_url,
					],
					// Followed by non-AJAX submissions such as the order-pay page.
					'redirect'       => $order->get_checkout_order_received_url(),
				],
				200,
				$order_id
			);

			} catch (\Exception $e) {

				NFT_Checkout_Payment_Gateway_Logger::error(
					"Payment processing exception: " . $e->getMessage(),
					[
						'order_id' => $order_id ?? null,
						'file'     => $e->getFile(),
						'line'     => $e->getLine(),
						'trace'    => $e->getTraceAsString()
					]
				);

				return $this->build_response('fail', 'An internal error occurred.', [], 500, $order_id);

			} finally {

				delete_transient('nft_checkout_payment_active_' . $order_id);
				$wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
			}
	}

	/**
	 * Price as plain text, e.g. "$90.00".
	 *
	 * @param float    $amount Amount.
	 * @param WC_Order $order  Order supplying the currency.
	 * @return string
	 */
	private function nft_checkout_plain_price($amount, $order) {

		return html_entity_decode(
			wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()])),
			ENT_QUOTES,
			'UTF-8'
		);
	}

	/**
	 * Order summary rows (items, then discounts/shipping/fees/tax) as plain text.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array List of ['label' => string, 'value' => string].
	 */
	private function nft_checkout_get_summary_rows($order) {

		$rows = [];

		foreach ($order->get_items() as $item) {

			$label = $item->get_name();

			if ($item->get_quantity() > 1) {
				$label .= ' ×' . $item->get_quantity();
			}

			$rows[] = [
				'label' => $label,
				'value' => $this->nft_checkout_plain_price($order->get_line_total($item, true), $order),
			];
		}

		// The subtotal repeats the items above and the total gets its own row.
		foreach ($order->get_order_item_totals() as $key => $total) {

			if (in_array($key, ['cart_subtotal', 'payment_method', 'order_total'], true)) {
				continue;
			}

			$rows[] = [
				'label' => rtrim(wp_strip_all_tags($total['label']), ': '),
				'value' => html_entity_decode(wp_strip_all_tags($total['value']), ENT_QUOTES, 'UTF-8'),
			];
		}

		return $rows;
	}

	private function build_response(
		string $result,
		string $message = '',
		array $data = [],
		int $code = 200,
		?int $order_id = null
	) {
		return [
			'result'   => $result, // success | fail
			'message'  => $message,
			'data'     => $data,
			'order_id' => $order_id,
			'code'     => $code,
			'success'  => $result === 'success',
		];
	}

	/**
	 * Ask Unified to email the customer their voucher.
	 *
	 * The email is Unified's, not this plugin's: there is no template, no
	 * wp_mail() call and no voucher link here. The order is posted to
	 * /voucher/send and whatever comes back is handed to the checkout as it
	 * is - including the wording of a refusal, which is already written for the
	 * customer.
	 *
	 * The payment link is still created only when the customer opens the button
	 * in that email; this call creates nothing but the voucher itself.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array ['success' => bool, 'message' => string, 'data' => array]
	 */
	private function nft_checkout_request_voucher_email($order) {

		$order_id   = $order->get_id();
		$log_prefix = "[Order #{$order_id}]";

		$accounts = $this->get_all_available_accounts();

		if (empty($accounts)) {

			NFT_Checkout_Payment_Gateway_Logger::error(
				$log_prefix . ' Voucher not requested: no eligible account',
				['order_id' => $order_id]
			);

			return [
				'success' => false,
				'message' => __('No eligible payment provider available.', 'nft-checkout-payment-gateway'),
				'data'    => [],
			];
		}

		$api_url         = esc_url($this->get_api_url('/voucher/send'));
		$last_message    = '';
		$last_error_data = null;

		foreach ($accounts as $account) {

			$public_key = $this->sandbox
				? $account['sandbox_public_key']
				: $account['live_public_key'];

			$secret_key = $this->sandbox
				? $account['sandbox_secret_key']
				: $account['live_secret_key'];

			// The same payload request-payment is given, so the voucher is raised
			// for exactly the order the payment will be for.
			$data = $this->nft_checkout_prepare_payment_data($order, $public_key, $secret_key);

			if (is_array($data) && ($data['result'] ?? '') === 'fail') {

				$last_message = sanitize_text_field($data['error'] ?? '');
				continue;
			}

			// When retrying after a failed, expired, or cancelled payment, we ensure a new
			// attempt reference is generated so a fresh voucher and payment link are created.
			$data['reference'] = $this->nft_checkout_voucher_reference($order);

			// --- START DAILY LIMIT CHECK ---
			$limit_url = $this->get_api_url('/dailylimit');

			$limit_resp = wp_remote_post($limit_url, [
				'method'    => 'POST',
				'timeout'   => 30,
				'sslverify' => true,
				'body'      => $data,
				'headers'   => [
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
			]);

			if (is_wp_error($limit_resp)) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					$log_prefix . ' Daily limit API WP error',
					[
						'account_title' => $account['title'] ?? null,
						'error'         => $limit_resp->get_error_message(),
					]
				);
				continue;
			}

			$limit_data = json_decode(wp_remote_retrieve_body($limit_resp), true);

			if (($limit_data['status'] ?? '') === 'error') {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					$log_prefix . ' Account rejected by daily limit API',
					[
						'account_title' => $account['title'] ?? null,
						'response'      => $limit_data,
					]
				);

				$last_error_data = $limit_data;
				continue;
			}
			// --- END DAILY LIMIT CHECK ---

			// What the customer actually bought, so the redemption page can show
			// it back to them. Carried by the voucher only - request-payment
			// neither wants nor keeps it.
			$data['items'] = $this->nft_checkout_get_voucher_items($order);

			$response = wp_remote_post($api_url, [
				'method'    => 'POST',
				'timeout'   => 30,
				'body'      => $data,
				'headers'   => [
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				],
				'sslverify' => true,
			]);

			if (is_wp_error($response)) {

				NFT_Checkout_Payment_Gateway_Logger::error(
					$log_prefix . ' Voucher request failed to reach the API',
					[
						'order_id'      => $order_id,
						'account_title' => $account['title'] ?? null,
						'error'         => $response->get_error_message(),
					]
				);

				$last_message = __('We could not reach the voucher service. Please try again in a moment.', 'nft-checkout-payment-gateway');
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$body = json_decode(wp_remote_retrieve_body($response), true);
			$body = is_array($body) ? $body : [];

			$message = isset($body['message']) ? sanitize_text_field($body['message']) : '';

			// Check for duplicate payment detection from the portal
			if (
				stripos($message, 'duplicate') !== false ||
				stripos($message, 'already completed') !== false ||
				(!empty($body['response']['error']) && stripos((string) $body['response']['error'], 'duplicate') !== false)
			) {
				$dup_msg = __('This order appears to be a duplicate and cannot be processed at this time.', 'nft-checkout-payment-gateway');
				return [
					'success'      => false,
					'is_duplicate' => true,
					'message'      => $dup_msg,
					'data'         => [],
				];
			}

			NFT_Checkout_Payment_Gateway_Logger::info(
				$log_prefix . ' Voucher API response received',
				[
					'order_id'   => $order_id,
					'http_code'  => $code,
					'status'     => $body['status'] ?? null,
					'voucher_id' => $body['data']['voucher_id'] ?? null,
					'reference'  => $body['data']['reference'] ?? null,
				]
			);

			if (($body['status'] ?? '') === 'success') {
				$voucher_data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
				$voucher_data['account_public_key'] = $public_key;
				$voucher_data['account_unique_id']  = $account['unique_id'] ?? '';

				return [
					'success' => true,
					'message' => $message,
					'data'    => $voucher_data,
				];
			}

			$last_message = $message ?: $last_message;

			/*
			 * What one account is refused - a key that is not accepted, a service
			 * that is down - the next may be granted. A refusal of the order
			 * itself would only be repeated, so it is handed back as it is.
			 */
			if (!in_array($code, [401, 403, 500, 502, 503, 504], true)) {
				break;
			}
		}

		if (!empty($last_error_data)) {
			if (!empty($last_error_data['max_limit_reached'])) {
				return [
					'success' => false,
					'message' => __('The transaction amount exceeds the maximum allowed limit.', 'nft-checkout-payment-gateway'),
					'data'    => [],
				];
			}

			$order->update_meta_data('_nft_checkout_limit_exceeded', true);
			$order->save();

			return [
				'success' => false,
				'message' => sanitize_text_field($last_error_data['message'] ?? __('Payment limit error.', 'nft-checkout-payment-gateway')),
				'data'    => [],
			];
		}

		return [
			'success' => false,
			'message' => $last_message ?: __('We could not email your voucher. Please try again in a moment.', 'nft-checkout-payment-gateway'),
			'data'    => [],
		];
	}

	/**
	 * Checks if the current payment attempt is a duplicate for the same order, user, and amount.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return bool
	 */
	private function nft_checkout_is_duplicate_payment($order) {
		if (!$order instanceof WC_Order) {
			return false;
		}

		$order_id      = $order->get_id();
		$order_total   = (float) $order->get_total();
		$order_user_id = (int) $order->get_customer_id();
		$order_email   = strtolower(trim((string) $order->get_billing_email()));

		$current_user_id = (int) get_current_user_id();
		$is_same_user    = false;

		// 1. Check user identity: logged-in user matches or billing email matches
		if ($order_user_id > 0 && $current_user_id > 0 && $order_user_id === $current_user_id) {
			$is_same_user = true;
		} elseif (!empty($order_email)) {
			$posted_email = '';
			if (!empty($_POST['billing_email'])) {
				$posted_email = strtolower(trim(sanitize_email(wp_unslash($_POST['billing_email']))));
			} elseif ($current_user_id > 0) {
				$current_user = wp_get_current_user();
				$posted_email = strtolower(trim((string) $current_user->user_email));
			}
			if (empty($posted_email) || $posted_email === $order_email) {
				$is_same_user = true;
			}
		} else {
			$is_same_user = true;
		}

		if (!$is_same_user) {
			return false;
		}

		// 2. Check if order is already paid/completed/processing with matching amount
		$is_paid_status       = $order->is_paid() || in_array($order->get_status(), ['completed', 'processing'], true);
		$payment_success_flag = $order->get_meta('_nft_checkout_payment_success') === 'yes';
		$state_is_success     = $order->get_meta('_nft_checkout_state') === 'success';

		if ($is_paid_status || $payment_success_flag || $state_is_success) {
			$recorded_amount = $order->get_meta('_nft_checkout_paid_amount');
			if ($recorded_amount !== '') {
				if (abs((float) $recorded_amount - $order_total) < 0.01) {
					return true;
				}
			} else {
				return true;
			}
		}

		// 3. Check for concurrent in-flight payment request for the same order and amount
		$active_lock = get_transient('nft_checkout_payment_active_' . $order_id);
		if (!empty($active_lock) && is_array($active_lock)) {
			$locked_amount = (float) ($active_lock['amount'] ?? 0);
			if (abs($locked_amount - $order_total) < 0.01) {
				if (time() - (int) ($active_lock['time'] ?? 0) < 30) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Voucher reference for the attempt about to be made.
	 *
	 * When retrying after a failed, expired, or cancelled payment, we ensure a new
	 * attempt reference is generated so a fresh voucher and payment link are created.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return string
	 */
	private function nft_checkout_voucher_reference($order) {

		$last_voucher_attempt = 0;
		$prev_ref = (string) $order->get_meta('_nft_checkout_voucher_reference', true);
		if ($prev_ref !== '') {
			if (preg_match('/-(\d+)$/', $prev_ref, $matches)) {
				$last_voucher_attempt = (int) $matches[1];
			} else {
				$last_voucher_attempt = 1;
			}
		}

		$timeline_attempts = $this->nft_checkout_failed_attempts($order);

		$next_attempt = max($last_voucher_attempt, $timeline_attempts) + 1;

		return $order->get_id() . '-' . $next_attempt;
	}

	/**
	 * Failed, expired, or cancelled payment attempts already made on this order.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return int
	 */
	private function nft_checkout_failed_attempts($order) {

		$timeline = $order->get_meta('_nft_checkout_timeline', true);

		if (!is_array($timeline)) {
			return 0;
		}

		$tokens    = [];
		$untokened = 0;

		foreach ($timeline as $event) {

			if (!is_array($event)) {
				continue;
			}

			$type = strtolower(trim((string) ($event['type'] ?? '')));

			if (!in_array($type, ['failed', 'expired', 'cancelled', 'canceled'], true)) {
				continue;
			}

			$token = trim((string) ($event['payment_token'] ?? ''));

			if ($token === '') {
				$untokened++;
				continue;
			}

			$tokens[$token] = true;
		}

		return count($tokens) + $untokened;
	}

	/**
	 * The ordered products, as this store lists them.
	 *
	 * Line items only - shipping, tax and discounts are the order's totals, not
	 * what the customer picked. Prices are formatted here rather than sent raw
	 * so the redemption page shows the same strings the customer saw at
	 * checkout, in this store's currency and format, and each line carries its
	 * product picture so the page shows what they were looking at.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array List of ['name' => string, 'quantity' => int, 'total' => string, 'image' => string].
	 */
	private function nft_checkout_get_voucher_items($order) {

		$items = [];

		foreach ($order->get_items() as $item) {

			$name = sanitize_text_field($item->get_name());

			if ($name === '') {
				continue;
			}

			$items[] = [
				'name'     => $name,
				'quantity' => max(1, (int) $item->get_quantity()),
				// Line total including tax, matching the order-received summary.
				'total'    => $this->nft_checkout_plain_price($order->get_line_total($item, true), $order),
				'image'    => $this->nft_checkout_get_item_image_url($item),
			];
		}

		return $items;
	}

	/**
	 * Product picture for an order line.
	 *
	 * A variation often has no image of its own, in which case the parent
	 * product's is the one the customer was shown on the product page. The
	 * thumbnail size is used: this ends up in a 36px box, and the full-size
	 * upload would be a slow way to fill it.
	 *
	 * @param WC_Order_Item $item Order line item.
	 * @return string Absolute URL, or '' when the product has no image.
	 */
	private function nft_checkout_get_item_image_url($item) {

		if (!method_exists($item, 'get_product')) {
			return '';
		}

		$product = $item->get_product();

		if (!$product) {
			return '';
		}

		$image_id = $product->get_image_id();

		if (!$image_id && $product->get_parent_id()) {

			$parent = wc_get_product($product->get_parent_id());

			if ($parent) {
				$image_id = $parent->get_image_id();
			}
		}

		if (!$image_id) {
			return '';
		}

		$url = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail');

		return $url ? esc_url_raw($url) : '';
	}

	/**
	 * Note on the order that Unified has the voucher in hand.
	 *
	 * @param WC_Order $order   Order being paid.
	 * @param array    $voucher data block from /voucher/send.
	 * @return void
	 */
	private function nft_checkout_record_voucher_sent($order, $voucher) {

		$reference = isset($voucher['reference']) ? sanitize_text_field($voucher['reference']) : '';
		$to        = sanitize_email($voucher['customer_email'] ?? $order->get_billing_email());

		// Read by the order-received notice to say payment is still to come.
		$order->update_meta_data('_nft_checkout_voucher_emailed_at', time());

		if (!empty($voucher['voucher_id'])) {
			$order->update_meta_data('_nft_checkout_voucher_id', sanitize_text_field($voucher['voucher_id']));
		}

		if (!empty($voucher['account_public_key'])) {
			$order->update_meta_data('_nft_checkout_account_public_key', sanitize_text_field($voucher['account_public_key']));
		}

		if (!empty($voucher['account_unique_id'])) {
			$order->update_meta_data('_nft_checkout_account_unique_id', sanitize_text_field($voucher['account_unique_id']));
		}

		if ($reference !== '') {
			$order->update_meta_data('_nft_checkout_voucher_reference', $reference);
		}

		$order->update_meta_data('_nft_checkout_state', 'pending');

		if (class_exists('NFT_Checkout_Payment_State_Engine')) {
			wp_cache_delete('nft_checkout_state_' . $order->get_id(), 'nft_checkout_payment');
		}

		$link = esc_url_raw((string) ($voucher['purchase_url'] ?? ''), ['https', 'http']);
		if ('' !== $link) {
			$order->update_meta_data('_nft_checkout_voucher_purchase_url', $link);
		}

		$order->add_order_note(
			$reference !== ''
				? sprintf(
					/* translators: 1: customer email address, 2: voucher reference */
					__('NFT Checkout voucher emailed to %1$s (reference %2$s).', 'nft-checkout-payment-gateway'),
					$to,
					$reference
				)
				: sprintf(
					/* translators: %s: customer email address */
					__('NFT Checkout voucher emailed to %s.', 'nft-checkout-payment-gateway'),
					$to
				)
		);

		$order->save();
	}

	/**
	 * Mask an email address for display, e.g. "harry@example.com" → "ha•••@example.com".
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function nft_checkout_mask_email($email) {
		$at = strrpos((string) $email, '@');

		if ($at === false) {
			return '';
		}

		$local   = substr($email, 0, $at);
		$visible = min(2, max(1, mb_strlen($local) - 1));

		return mb_substr($local, 0, $visible) . "\u{2022}\u{2022}\u{2022}" . substr($email, $at);
	}

	/**
	 * Remind the customer on the order-received page that payment happens via the emailed voucher.
	 *
	 * @param int $order_id Order ID.
	 */
	public function nft_checkout_thankyou_payment_link_notice($order_id) {
		$order = wc_get_order($order_id);

		if (!$order || !$order->has_status('pending') || !$order->get_meta('_nft_checkout_voucher_emailed_at')) {
			return;
		}

		printf(
			'<p class="nft-checkout-thankyou-email-notice">%s</p>',
			sprintf(
				/* translators: %s: masked customer email address */
				esc_html__('We have emailed your voucher to %s. Purchase the voucher to complete this order.', 'nft-checkout-payment-gateway'),
				'<strong>' . esc_html($this->nft_checkout_mask_email($order->get_billing_email())) . '</strong>'
			)
		);
	}

	private function is_block_checkout_request() {
		return wp_doing_ajax() && isset($_REQUEST['action'])
			&& sanitize_text_field(wp_unslash($_REQUEST['action'])) === 'nft_checkout_block_gateway_process';
	}

	public function nft_checkout_display_test_order_tag($order) {
		if ($order instanceof WC_Order && $order->get_meta('_is_test_order', true)) {
			echo '<p><strong>' . esc_html__('Test Order', 'nft-checkout-payment-gateway') . '</strong></p>';
		}
	}

	private function nft_checkout_get_return_url_base() {
		return rest_url('/nft-checkout/v1/data');
	}

	private function is_po_box($address) {
		if (empty($address)) return false;

		$clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $address));

		return preg_match('/pob|postoffice/', $clean) === 1;
	}

	private function nft_checkout_prepare_payment_data($order, $api_public_key, $api_secret) {
		$order_id    = $order->get_id();
		$is_sandbox  = $this->get_option('sandbox') === 'yes';
		$request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		$first_name  = sanitize_text_field($order->get_billing_first_name());
		$last_name   = sanitize_text_field($order->get_billing_last_name());
		$amount      = number_format($order->get_total(), 2, '.', '');
		$email       = sanitize_text_field($order->get_billing_email());
		$original_phone = $order->get_billing_phone();
		$phone       = sanitize_text_field($original_phone);
		$country     = $order->get_billing_country();
		$country_code = WC()->countries->get_country_calling_code($country);
		
		$billing_address_1 = sanitize_text_field($order->get_billing_address_1());
		$billing_address_2 = sanitize_text_field($order->get_billing_address_2());
		$billing_city      = sanitize_text_field($order->get_billing_city());
		$billing_postcode  = sanitize_text_field($order->get_billing_postcode());
		$billing_country   = sanitize_text_field($order->get_billing_country());
		$billing_state     = sanitize_text_field($order->get_billing_state());

		$redirect_url = esc_url_raw(add_query_arg([
			'order_id' => $order_id,
			'key'      => $order->get_order_key(),
			'mode'     => 'wp',
		], $this->nft_checkout_get_return_url_base()));

		$ip_address = sanitize_text_field($this->nft_checkout_get_client_ip());

		if (empty($order_id)) {
			NFT_Checkout_Payment_Gateway_Logger::error(
				'Order ID is missing or invalid.',
				[]
			);
			return ['result' => 'fail','error'=>'Order ID is missing or invalid.'];
		}

		$meta_data_array = array_map('sanitize_text_field', [
			'order_id' => $order_id,
			'amount'   => $amount,
			'source'   => 'woocommerce',
		]);

		$user_id = $order->get_customer_id();
		$is_guest = empty($user_id) ? 1 : 0;

		return [
			'api_secret'       => $api_secret,
			'api_public_key'   => $api_public_key,
			'first_name'       => $first_name,
			'last_name'        => $last_name,
			'request_for'      => $request_for,
			'amount'           => $amount,
			'redirect_url'     => $redirect_url,
			'redirect_time'    => 3,
			'ip_address'       => $ip_address,
			'source'           => 'wordpress',
			'meta_data'        => $meta_data_array,
			'remarks'          => 'Order ' . $order->get_order_number(),
			'email'            => $email,
			'phone_number'     => $phone,
			'country_code'     => $country_code,
			'billing_address_1'=> $billing_address_1,
			'billing_address_2'=> $billing_address_2,
			'billing_city'     => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country'  => $billing_country,
			'billing_state'    => $billing_state,
			'is_sandbox'       => $is_sandbox,
			'curr_code'        => sanitize_text_field($order->get_currency()),
			// Tells the application this order came from the unified plugin, which
			// is what turns on its voucher pages (payment received, invoice).
			'plugin_source'    => 'unified',
			'is_guest'         => $is_guest,
			'customer_user_id' => $user_id ? $user_id : 0,
		];
	}

	private function nft_checkout_normalize_phone($phone, $country_code) {
		$cleanedPhone  = preg_replace('/[()\s-]/', '', $phone ?? '');
		$countryCode   = preg_replace('/[^0-9]/', '', $country_code ?? '');
		$phoneNumber   = preg_replace('/[^\d]/', '', $cleanedPhone);

		if (!empty($countryCode) && strlen($phoneNumber) > strlen($countryCode) && strpos($phoneNumber, $countryCode) === 0) {
			$normalizedPhone = substr($phoneNumber, strlen($countryCode));
		} else {
			$normalizedPhone = $phoneNumber;
		}

		$normalizedPhone = ltrim($normalizedPhone, '0');

		if (empty($phoneNumber)) {
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => true, 'error' => null];
		}

		$localLength   = strlen($normalizedPhone);
		$totalLength   = strlen($countryCode . $normalizedPhone);
		$requires10Digits = in_array($countryCode, ['1']);
		$europeCodes   = ['33','34','39','31','44','46','47','48','49','41','45','358'];

		if ($requires10Digits) {
			if ($localLength !== 10) {
				return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => 'Phone number must be exactly 10 digits.'];
			}
		} elseif (in_array($countryCode, $europeCodes)) {
			$min = ($countryCode === '49' || $countryCode === '358') ? 5 : 8;
			$max = ($countryCode === '49' || $countryCode === '358') ? 11 : 10;
			if ($localLength < $min || $localLength > $max) {
				return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => "European number invalid: should be $min-$max digits"];
			}
		} else {
			// Default international validation
			if ($localLength < 10 || $localLength > 15) {

				return [
					'phone'        => $normalizedPhone,
					'country_code' => '+' . $countryCode,
					'is_valid'     => false,
					'error'        => 'Phone number must be between 10 and 15 digits.'
				];
			}
		}

		if ($totalLength > 15) {
			return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => false, 'error' => sprintf('Phone number is too long. Maximum allowed length is 15 digits (including country code). Your phone number has %d digits.', $totalLength)];
		}

		return ['phone' => $normalizedPhone, 'country_code' => '+' . $countryCode, 'is_valid' => true, 'error' => null];
	}

	private function nft_checkout_get_client_ip() {
		// NFT-11: Prioritize validated REMOTE_ADDR to prevent header spoofing via Client-IP or X-Forwarded-For
		$ip = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
			return $ip;
		}
		if (class_exists('WC_Geolocation')) {
			$wc_ip = WC_Geolocation::get_ip_address();
			if (!empty($wc_ip) && filter_var($wc_ip, FILTER_VALIDATE_IP)) {
				return $wc_ip;
			}
		}
		return '0.0.0.0';
	}

	public function nft_checkout_add_custom_label_to_order_row($line_items, $order) {
		$order_origin = $order->get_meta('_order_origin');
		if (!empty($order_origin)) {
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}
		return $line_items;
	}

	public function nft_checkout_woocommerce_not_active_notice() {
		echo '<div class="error"><p>' . esc_html__('Voucher Pay requires WooCommerce to be installed and active.', 'nft-checkout-payment-gateway') . '</p></div>';
	}

	public function payment_fields() {
		$description = $this->get_option('description');
		if (is_array($this->selected_account_for_display) && !empty($this->selected_account_for_display['checkout_subtitle'])) {
			$description = $this->selected_account_for_display['checkout_subtitle'];
		} elseif (WC()->cart) {
			
			$accounts = $this->get_all_accounts();
			$sorted   = $this->get_routing_sorted_accounts($accounts);
			if (!empty($sorted) && !empty($sorted[0]['checkout_subtitle'])) {
				$description = $sorted[0]['checkout_subtitle'];
			}
		}

		if ($description) {
			echo wp_kses_post(wpautop(wptexturize(trim($description))));
		}
		if ('yes' === $this->get_option('show_consent_checkbox')) {
			echo '<p class="form-row form-row-wide">
                <label for="nft_checkout_consent">
                    <input type="checkbox" id="nft_checkout_consent" name="nft_checkout_consent" /> ' .
				esc_html__('I consent to the collection of my data to process this payment', 'nft-checkout-payment-gateway') .
				'</label></p>';
			wp_nonce_field('nft_checkout_payment', 'nft_checkout_nonce');
		}
	}

	public function validate_fields() {

		if ($this->get_option('show_consent_checkbox') === 'yes') {
			$nonce = isset($_POST['nft_checkout_nonce']) ? sanitize_text_field(wp_unslash($_POST['nft_checkout_nonce'])) : '';
			if (empty($nonce) || !wp_verify_nonce($nonce, 'nft_checkout_payment')) {
				wc_add_notice(__('Nonce verification failed. Please try again.', 'nft-checkout-payment-gateway'), 'error');
				return false;
			}
			$consent = isset($_POST['nft_checkout_consent']) ? sanitize_text_field(wp_unslash($_POST['nft_checkout_consent'])) : '';
			if ($consent !== 'on') {
				wc_add_notice(__('You must consent to the collection of your data to process this payment.', 'nft-checkout-payment-gateway'), 'error');
				return false;
			}
		}
		return true;
	}

	public function nft_checkout_enqueue_styles_and_scripts() {
		if (is_checkout()) {
			$image_url = plugin_dir_url(dirname(__FILE__)) . 'assets/images/loader.gif';
			// filemtime versions so browsers and page caches drop the old popup script.
			wp_enqueue_style('nft-checkout-payment-loader-styles', plugins_url('../assets/css/nft-checkout-frontend.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/nft-checkout-frontend.css'), 'all');
			wp_enqueue_script('nft-checkout-js', plugins_url('../assets/js/nft-checkout.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/nft-checkout.js'), true);
			wp_localize_script('nft-checkout-js', 'nft_checkout_params', [
				'ajax_url'       => admin_url('admin-ajax.php'),
				'checkout_url'   => wc_get_checkout_url(),
				'nft_checkout_loader' => $image_url,
				'nft_checkout_nonce'  => wp_create_nonce('nft_checkout_payment'),
				'payment_method' => $this->id,
			]);
		}
	}

	function nft_checkout_admin_scripts($hook) {
		if (
			'woocommerce_page_wc-settings' !== $hook ||
			(sanitize_text_field(wp_unslash($_GET['section'] ?? '')) !== $this->id) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return;
		}
		wp_enqueue_style('nft-checkout-font-awesome', plugins_url('../assets/css/nft-checkout-font-awesome.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/nft-checkout-font-awesome.css'), 'all');
		wp_enqueue_style('nft-checkout-admin-css', plugins_url('../assets/css/nft-checkout-admin.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/nft-checkout-admin.css'), 'all');
		wp_enqueue_script('nft-checkout-admin-script', plugins_url('../assets/js/nft-checkout-admin.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/nft-checkout-admin.js'), true);
		wp_localize_script('nft-checkout-admin-script', 'nft_checkout_admin_data', [
			'ajax_url'   => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce('nft_checkout_sync_nonce'),
			'gateway_id' => $this->id,
		]);
	}

	public function nft_checkout_hide_custom_payment_gateway_conditionally($available_gateways)
	{
		$gateway_id = $this->id;
		$this->selected_account_for_display = null;

		// =====================================================
		// STEP 1: SAFE CART CHECK
		// =====================================================
		if (!WC()->cart) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 2: CHECKOUT CONTEXT (STRICT)
		// =====================================================
		$is_ajax = function_exists('wp_doing_ajax') && wp_doing_ajax();
		$is_blocks = defined('REST_REQUEST') && REST_REQUEST && !is_admin();
		$is_checkout_page = function_exists('is_checkout') && is_checkout();

		if (!$is_checkout_page && !$is_ajax && !$is_blocks) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 3: FLOW LABEL
		// =====================================================
		$flow = 'Checkout (Classic)';

		if ($is_blocks) {
			$flow = 'Checkout (Blocks)';
		} elseif ($is_ajax) {
			$flow = 'Checkout (AJAX)';
		}

		// =====================================================
		// STEP 4: CART INFO
		// =====================================================
		$amount = (float) WC()->cart->get_total('raw');
		if ($amount < 0.01) {
			$amount = (float) (WC()->cart->get_totals()['total'] ?? 0);
		}

		$items = count(WC()->cart->get_cart());

		// =====================================================
		// STEP 5: REQUEST FINGERPRINT (REAL FIX)
		// =====================================================
		static $executed = false;

		$fingerprint = md5(json_encode([
			'flow'   => $flow,
			'items'  => $items,
			'total'  => $amount,
			'ajax'   => $is_ajax,
			'blocks' => $is_blocks
		]));

		if ($executed === $fingerprint) {
			return $available_gateways;
		}

		$executed = $fingerprint;

		// =====================================================
		// STEP 6: LOAD ACCOUNTS
		// =====================================================
		if (!method_exists($this, 'get_all_accounts')) {
			return $available_gateways;
		}

		$accounts = $this->get_all_accounts();

		// =====================================================
		// STEP 7: NO ACCOUNTS
		// =====================================================
		if (empty($accounts)) {

			NFT_Checkout_Payment_Gateway_Logger::info(
				"Unified Gateway Decision",
				[
					'result' => 'HIDDEN',
					'reason' => 'No merchant accounts configured',
					'items'  => $items,
					'total'  => $amount,
					'flow'   => $flow
				]
			);

			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		// =====================================================
		// STEP 8: SORT
		// =====================================================
		usort($accounts, fn($a, $b) =>
			($a['priority'] ?? 1) <=> ($b['priority'] ?? 1)
		);

		// =====================================================
		// STEP 9: EVALUATION
		// =====================================================
		$selected = null;
		$reason   = 'No eligible merchant account';

		$pluginLogApiUrl        = $this->get_api_url('/plugin/check/checkout');
		$all_accounts_limited = true;

		$force_refresh = (
			isset($_GET['refresh_accounts'], $_GET['_wpnonce']) &&
			sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1' &&
			wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		);


		foreach ($accounts as $account) {

			$public = $this->sandbox
				? ($account['sandbox_public_key'] ?? '')
				: ($account['live_public_key'] ?? '');

			$secret = $this->sandbox
				? ($account['sandbox_secret_key'] ?? '')
				: ($account['live_secret_key'] ?? '');

			if (empty($public) || empty($secret)) {
				continue;
			}

			$check_amount = $amount > 0.01 ? $amount : 0.01;

			$data = [
				'is_sandbox'     => $this->sandbox,
				'amount'         => $check_amount,
				'api_public_key' => $public,
				'api_secret_key' => $secret,
			];

			$cache = 'nft_checkout_' . md5($public . $check_amount);

			$status = $this->get_cached_api_response(
				$this->get_api_url('/check-merchant-status'),
				$data,
				$cache . '_status',
				300
			);

			$account_active_locally = strtolower($this->sandbox ? ($account['sandbox_status'] ?? '') : ($account['live_status'] ?? '')) === 'active';
			$is_status_success      = ($status['status'] ?? '') === 'success';

			if (!$is_status_success) {
				// If server explicitly said inactive, invalid, or unauthorized (401/403), reject account
				if (!empty($status['status']) && in_array(strtolower($status['status']), ['inactive', 'invalid', 'unauthorized'], true)) {
					continue;
				}
				if (!empty($status['code']) && in_array((int)$status['code'], [401, 403], true)) {
					continue;
				}
				// If it's a network glitch/timeout, only allow if account was verified active in settings
				if (!$account_active_locally) {
					continue;
				}
			}

			$limit = $this->get_cached_api_response(
				$this->get_api_url('/dailylimit'),
				$data,
				$cache . '_limit',
				300
			);

			// Daily limit: only reject if API explicitly reported limit exceeded
			if (!empty($limit['max_limit_reached']) || (isset($limit['status']) && $limit['status'] === 'error' && isset($limit['code']) && $limit['code'] === 400 && str_contains(strtolower($limit['message'] ?? ''), 'limit'))) {
				continue;
			}

			if (!empty($limit['status']) && $limit['status'] === 'success') {
				$all_accounts_limited = false;
			}

			$selected = $account;
			$reason   = 'Valid merchant account found';
			break;
		}

		// =====================================================
		// STEP 10: SINGLE FINAL LOG ONLY
		// =====================================================
		NFT_Checkout_Payment_Gateway_Logger::info(
			"Unified Gateway Decision",
			[
				'result' => $selected ? 'SHOWN' : 'HIDDEN',
				'reason' => $reason,
				'items'  => $items,
				'total'  => $amount,
				'flow'   => $flow,
				'account'=> $selected['title'] ?? null
			]
		);

		// =====================================================
		// STEP 11: RETURN RESULT
		// =====================================================
		$this->selected_account_for_display = $selected;

		if (!$selected) {
			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		return $available_gateways;
	}
	private function send_plugin_logs($accounts, $public_key, $secret_key, $amount, $gateway_loaded, $pluginLogApiUrl, $force_refresh)
	{
		$plugin_version = NFT_CHECKOUT_PLUGIN_VERSION;
		$accounts       = $this->update_accounts_uniqueID($accounts);
		$group_id       = get_option('nft_checkout_group_id');
		$cache_base     = 'nft_checkout_daily_limit_' . md5($public_key . $amount);

		// NFT-03: Strip secret keys from telemetry/logs
		$safe_accounts = array_map(function($acc) {
			unset($acc['live_secret_key'], $acc['sandbox_secret_key'], $acc['secret_key']);
			return $acc;
		}, $accounts);

		$plugin_logs_data = [
			'valid_accounts' => $safe_accounts,
			'gateway_loaded' => $gateway_loaded,
			'plugin_status'  => $gateway_loaded,
			'plugin_version' => $plugin_version,
			'api_public_key' => $public_key,
			'is_sandbox'     => $this->sandbox,
			'group_id'       => $group_id ? $group_id : $this->nft_checkout_get_group_id(),
			'domain_name'    => parse_url(home_url(), PHP_URL_HOST),
		];

		$this->get_cached_api_response(
			$pluginLogApiUrl,
			$plugin_logs_data,
			$cache_base . '_pluginlogs',
			300,
			$force_refresh
		);
	}

	private function gateway_visibility_label($reason) {

		return match ($reason) {

			'no_accounts' => 'No payment accounts configured',
			'merchant_inactive' => 'Payment provider unavailable',
			'daily_limit_exceeded' => 'Daily limit reached for this account',
			'no_eligible_accounts' => 'No valid payment account found',
			'non_checkout_page' => 'Not on checkout page',

			default => 'Payment validation step executed'
		};
	}

	private function hide_gateway($available_gateways, $gateway_id) {
		unset($available_gateways[$this->id]);
		$GLOBALS['nft_checkout_gateway_visibility_' . $this->id] = $available_gateways;
		return $available_gateways;
	}

	private function log_info_once_per_session($key, $message, $context = [])
	{
		if (!function_exists('WC') || !WC()) {
			return;
		}

		if (!WC()->session) {
			WC()->initialize_session();
		}

		// -----------------------------
		// 🔥 FIXED FLOW DETECTION (REAL WOOCOMMERCE SAFE)
		// -----------------------------
		$flow = 'background';

		$is_ajax = defined('DOING_AJAX') && DOING_AJAX;
		$is_rest = defined('REST_REQUEST') && REST_REQUEST;

		$wc_ajax = isset($_REQUEST['wc-ajax']) ? sanitize_text_field(wp_unslash($_REQUEST['wc-ajax'])) : '';

		if (is_checkout()) {
			$flow = 'checkout_page';
		} elseif ($is_rest) {
			$flow = 'checkout_block';
		} elseif ($is_ajax && $wc_ajax === 'update_order_review') {
			$flow = 'checkout_refresh';
		}

		// -----------------------------
		// SAFE CONTEXT
		// -----------------------------
		$clean_context = [
			'Gateway' => $this->id,
			'Flow'    => $flow,
		];

		if (WC()->cart) {
			$clean_context['Items'] = count(WC()->cart->get_cart());
			$clean_context['Total'] = (float) WC()->cart->get_total('raw');
		}

		if (isset($context['reason'])) {
			$clean_context['Reason'] = $this->gateway_visibility_label($context['reason']);
		}

		if (isset($context['account'])) {
			$clean_context['Account'] = $context['account'];
		}

		// -----------------------------
		// 🔥 FIX: STABLE SESSION KEY
		// -----------------------------
		$session_key = 'nft_checkout_log_' . md5($key . $this->id);

		if (WC()->session->get($session_key)) {
			return;
		}

		WC()->session->set($session_key, true);

		NFT_Checkout_Payment_Gateway_Logger::info($message, $clean_context);
	}



	private function get_cached_api_response($url, $data, $cache_key, $ttl = 120, $force_refresh = false) {
		if (!$force_refresh && isset($_GET['refresh_accounts']) && sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1' && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')) {
			$force_refresh = true;
		}
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if ($cached !== false) return $cached;
		} else {
			delete_transient($cache_key);
		}
		$response = wp_remote_post($url, [
			'method'    => 'POST',
			'timeout'   => 5,
			'body'      => $data,
			'headers'   => [
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Authorization' => 'Bearer ' . ($data['api_public_key'] ?? ''),
			],
			'sslverify' => true,
		]);
		if (is_wp_error($response)) {
			$err_resp = ['status' => 'error', 'message' => $response->get_error_message()];
			set_transient($cache_key, $err_resp, 60);
			return $err_resp;
		}
		$response_code = wp_remote_retrieve_response_code($response);
		$response_data = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($response_data)) {
			$err_resp = ['status' => 'error', 'code' => $response_code];
			set_transient($cache_key, $err_resp, 60);
			return $err_resp;
		}
		if ($response_code === 200 || !empty($response_data['status'])) {
			set_transient($cache_key, $response_data, $ttl);
		} else {
			set_transient($cache_key, $response_data, 60);
		}
		return $response_data;
	}

	private function get_all_accounts() {
		$accounts = get_option('woocommerce_nft-checkout_payment_gateway_accounts') ?: get_option('woocommerce_unified_payment_gateway_accounts', []);
		if (is_string($accounts)) {
			$unserialized = maybe_unserialize($accounts);
			$accounts = is_array($unserialized) ? $unserialized : [];
		}
		$valid_accounts = [];
		foreach ($accounts as $i => $account) {
			if ($this->sandbox) {
				$status   = strtolower($account['sandbox_status'] ?? '');
				$has_keys = !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']);
				if ($status === 'active' && $has_keys) $valid_accounts[] = $account;
			} else {
				$status   = strtolower($account['live_status'] ?? '');
				$has_keys = !empty($account['live_public_key']) && !empty($account['live_secret_key']);
				if ($status === 'active' && $has_keys) $valid_accounts[] = $account;
			}
		}
		$this->accounts = $valid_accounts;
		return $valid_accounts;
	}





	/**
	 * Sort and filter accounts for a given order amount.
	 * Accounts whose max_single_txn is set and less than $amount are excluded.
	 * Remaining accounts are sorted: lowest max_single_txn first (tightest fit),
	 * then by priority.
	 *
	 * @param array $accounts All accounts.
	 * @param float $amount   Order/cart total.
	 * @return array          Sorted array of eligible accounts.
	 */

private function get_routing_sorted_accounts(array $accounts): array {
	// No max_single_txn logic: return all accounts sorted by priority only
	usort($accounts, function ($a, $b) {
		return ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1);
	});
	return array_values($accounts);
}

	/**
	 * Get checkout display info (title + subtitle) for a given cart amount.
	 *
	 * @param float $amount Order/cart total.
	 * @return array ['title' => string, 'subtitle' => string]
	 */
	public function get_checkout_info_for_amount(float $amount): array {
		$selected_account = [];
		$sorted_accounts = array();
		$cart_hash = WC()->cart ? WC()->cart->get_cart_hash() : 'no_cart';
		$accounts = $this->get_all_accounts();
		$sorted   = $this->get_routing_sorted_accounts($accounts);
		$account  = !empty($sorted) ? $sorted[0] : null;
		
		$accounts = $this->get_all_accounts();
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		if (empty($accounts)) return $available_gateways;

		usort($accounts, fn($a, $b) => $a['priority'] <=> $b['priority']);

		$accStatusApiUrl        = $this->get_api_url('/check-merchant-status');
		$transactionLimitApiUrl = $this->get_api_url('/dailylimit');
		$user_account_active = false;
		$all_accounts_limited = true;
		$limit_data = [];

		$force_refresh = (
			isset($_GET['refresh_accounts'], $_GET['_wpnonce']) &&
			sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1' &&
			wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		);

		// New logic: filter by daily limit, then pick by priority
		$eligible_accounts = [];
		foreach ($accounts as $account) {
			$acc_title  = $account['title'] ?? '(unknown)';
			$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
			$secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
			if (empty($public_key) || empty($secret_key)) {
				continue;
			}
			$data = [
				'is_sandbox'     => $this->sandbox,
				'amount'         => $amount,
				'api_public_key' => $public_key,
				'api_secret_key' => $secret_key,
			];
			$cache_base  = 'nft_checkout_daily_limit_' . md5($public_key . $amount);
			$status_data = $this->get_cached_api_response($accStatusApiUrl, $data, $cache_base . '_status', 300, $force_refresh);
			
			if (!empty($status_data['status']) && $status_data['status'] === 'success') {
				$user_account_active = true;
			}

			if (($status_data['status'] ?? '') !== 'success') {
				$this->log_info_once_per_session('skip_status_' . $acc_title, "Skipping '{$acc_title}': merchant status check failed", [
					'response_status' => $status_data['status'] ?? 'unknown',
				]);
				continue;
			}

			$limit_data = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_base . '_limit', 300, $force_refresh);
			
			if (($limit_data['status'] ?? '') === 'success') {
				$eligible_accounts[] = $account;
			} else {
				continue;
			}
			if (!empty($limit_data['status']) && $limit_data['status'] === 'success') {
				$all_accounts_limited = false;
			}

			$selected_account = $account;
			break;
		}

		$gateway_id = $this->id;
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ($all_accounts_limited) {
			
			if (!isset($limit_data['max_limit_reached']) || $limit_data['max_limit_reached'] == false) {
				return $this->hide_gateway($available_gateways, $gateway_id);
			}
		}
		// Fallback logic if no eligible account found
		
		if (!$selected_account) {
			$this->log_info_once_per_session('fallback_search', 'No routing-eligible account passed all checks, searching for fallback', [
				'amount' => $amount,
			]);
			usort($accounts, function ($a, $b) {
				return ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1);
			});
			
			if (!$all_accounts_limited) {
				$selected_account = $accounts[0] ?? null;
				$this->log_info_once_per_session('fallback_account', 'Fallback display account: ' . ($selected_account['title'] ?? 'none'));
			} else {
				$this->log_info_once_per_session('no_fallback', 'All accounts are limited, no fallback selected');
				$selected_account = null;
			}
		}

		$this->selected_account_for_display = $selected_account;

		if (!empty($selected_account['checkout_title'])) {
			
			return [
				'title'    => $selected_account['checkout_title'] ?? '',
				'subtitle' => $selected_account['checkout_subtitle'] ?? '',
				'accounts' => $selected_account['checkout_subtitle'] ?? '',
			];
		}

		return [];
	}

	private function get_all_available_accounts()
	{
		$settings = get_option('woocommerce_nft-checkout_payment_gateway_accounts') ?: get_option('woocommerce_unified_payment_gateway_accounts', []);
		$settings = maybe_unserialize($settings);

		if (!is_array($settings)) {
			return [];
		}

		$mode = $this->sandbox ? 'sandbox' : 'live';

		$status_key = $mode . '_status';
		$public_key  = $mode . '_public_key';
		$secret_key  = $mode . '_secret_key';

		$available = [];

		foreach ($settings as $account) {

			if (empty($account[$public_key]) || empty($account[$secret_key])) {
				continue;
			}

			if (strtolower($account[$status_key] ?? '') !== 'active') {
				continue;
			}

			$available[] = $account;
		}

		return $this->get_routing_sorted_accounts($available);
	}


}
