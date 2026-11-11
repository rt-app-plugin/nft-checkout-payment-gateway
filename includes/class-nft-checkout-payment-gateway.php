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
			: '';

		$this->enabled    = sanitize_text_field($this->get_option('enabled'));
		$this->sandbox    = 'yes' === sanitize_text_field($this->get_option('sandbox'));
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by WooCommerce in WC_Checkout::process_checkout().
		$selected_gateway = sanitize_text_field(wp_unslash($_POST['payment_method'] ?? ''));

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


	private function throw_blocks_validation_error($message)
	{
		if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'nft_checkout_validation_error',
				esc_html($message),
				400
			);
		}
		throw new Exception(esc_html($message));
	}

	public function nft_checkout_validate_blocks_checkout($order, $request)
	{
		if ($request instanceof \WP_REST_Request && strtoupper($request->get_method()) !== 'POST') {
			return $order;
		}

		$payment_method = $request['payment_method'] ?? '';

		if (empty($payment_method) || !in_array($payment_method, [$this->id, 'nft_checkout'], true)) {
			return $order;
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
						'country'  => $country,
						'order_id' => $order->get_id() ?? null,
						'error'    => $normalized['error'] ?? null,
					]
				);

				$this->throw_blocks_validation_error($normalized['error'] ?? __('Invalid phone number.', 'nft-checkout-payment-gateway'));
			}

			// Sync latest validated value to order
			$order->set_billing_phone($phone);

			NFT_Checkout_Payment_Gateway_Logger::info(
				'Blocks checkout phone validation passed',
				[
					'country'  => $country,
					'order_id' => $order->get_id() ?? null,
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

			$this->throw_blocks_validation_error(__('PO Box addresses are not accepted. Please enter a physical street address.', 'nft-checkout-payment-gateway'));
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
						'order_id' => $order->get_id() ?? null,
					]
				);

				$this->throw_blocks_validation_error(__('Invalid ZIP / postal code.', 'nft-checkout-payment-gateway'));
			}

			$order->set_billing_postcode($postcode);

			NFT_Checkout_Payment_Gateway_Logger::info(
				'Blocks checkout postcode validation passed',
				[
					'country'  => $country,
					'order_id' => $order->get_id() ?? null,
				]
			);
		}

		return $order;
	}

	private function get_api_url($endpoint) {
		$endpoint = '/' . ltrim($endpoint, '/');
		if (strpos($endpoint, '/payment-api/') !== 0 && $endpoint !== '/payment-api') {
			if (strpos($endpoint, '/api/') === 0) {
				$endpoint = substr($endpoint, 4);
			}
			$endpoint = '/payment-api' . $endpoint;
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

		parent::process_admin_options();
		$this->load_gateway_settings();

		$errors              = [];
		$valid_accounts      = [];
		$unique_live_keys    = [];
		$unique_sandbox_keys = [];
		$normalized_index    = 0;
		$raw_accounts        = [];

		if (isset($_POST['accounts']) && is_array($_POST['accounts'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$unslashed_accounts = wp_unslash($_POST['accounts']);
			$raw_accounts       = map_deep($unslashed_accounts, 'sanitize_text_field');
		}

		if (!is_array($raw_accounts) || empty($raw_accounts)) {
			$errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'nft-checkout-payment-gateway');
			NFT_Checkout_Payment_Gateway_Logger::info('No accounts submitted in admin options.');
		}

		$existing_accounts        = function_exists('nft_checkout_get_accounts') ? nft_checkout_get_accounts() : [];
		$existing_by_unique_id    = [];
		foreach ($existing_accounts as $existing_acc) {
			if (!empty($existing_acc['unique_id'])) {
				$existing_by_unique_id[(string) $existing_acc['unique_id']] = $existing_acc;
			}
		}

		foreach ((array) $raw_accounts as $account) {
			if (!is_array($account)) {
				continue;
			}

			$account = array_map('sanitize_text_field', $account);

			$submitted_uid      = $account['unique_id'] ?? '';
			$existing_match     = (!empty($submitted_uid) && isset($existing_by_unique_id[$submitted_uid]))
				? $existing_by_unique_id[$submitted_uid]
				: null;

			$account_title      = $account['title'] ?? '';
			$priority           = intval($account['priority'] ?? 1);
			$live_public_key    = $account['live_public_key'] ?? '';
			$live_secret_key    = !empty($account['live_secret_key'])
				? $account['live_secret_key']
				: (string) ($existing_match['live_secret_key'] ?? '');
			$sandbox_public_key = $account['sandbox_public_key'] ?? '';
			$sandbox_secret_key = !empty($account['sandbox_secret_key'])
				? $account['sandbox_secret_key']
				: (string) ($existing_match['sandbox_secret_key'] ?? '');
			$has_sandbox        = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';
			$live_status        = !empty($account['live_status']) ? $account['live_status'] : 'Pending';
			$sandbox_status     = $has_sandbox ? (!empty($account['sandbox_status']) ? $account['sandbox_status'] : 'Pending') : '';
			$unique_id          = (!empty($submitted_uid) && !preg_match('/^acc_\d+$/', $submitted_uid))
				? $submitted_uid
				: $this->nft_checkout_get_unique_id();
			$checkout_title     = $account['checkout_title'] ?? '';
			$checkout_subtitle  = $account['checkout_subtitle'] ?? '';

			if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
				continue;
			}

			if (empty($account_title) || empty($live_public_key) || empty($live_secret_key)) {
				/* translators: %s: Payment account title */
				$errors[] = sprintf(__('Account "%s": Title, Live Public Key, and Live Secret Key are required.', 'nft-checkout-payment-gateway'), $account_title);
				NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: missing required fields for account '{$account_title}'");
				continue;
			}

			$live_combined = $live_public_key . '|' . $live_secret_key;
			if (in_array($live_combined, $unique_live_keys, true)) {
				/* translators: %s: Payment account title */
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be unique.', 'nft-checkout-payment-gateway'), $account_title);
				NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: duplicate live keys for account '{$account_title}'");
				continue;
			}

			if ($live_public_key === $live_secret_key) {
				/* translators: %s: Payment account title */
				$errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be different.', 'nft-checkout-payment-gateway'), $account_title);
				NFT_Checkout_Payment_Gateway_Logger::info("Validation warning: live keys are identical for account '{$account_title}'");
			}

			$unique_live_keys[] = $live_combined;

			if ($has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
				$sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
				if (in_array($sandbox_combined, $unique_sandbox_keys, true)) {
					/* translators: %s: Payment account title */
					$errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be unique.', 'nft-checkout-payment-gateway'), $account_title);
					NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: duplicate sandbox keys for account '{$account_title}'");
					continue;
				}
				if ($sandbox_public_key === $sandbox_secret_key) {
					/* translators: %s: Payment account title */
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

			NFT_Checkout_Payment_Gateway_Logger::info('Account settings updated successfully.', ['count' => count($valid_accounts)]);

			if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
				$loader = NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance();
				if (method_exists($loader, 'handle_cron_event')) {
					$loader->handle_cron_event(false);
					NFT_Checkout_Payment_Gateway_Logger::info('Triggered NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::handle_cron_event(false) after settings save.');
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
				'default'     => __('Pay by card', 'nft-checkout-payment-gateway'),
				'desc_tip'    => true,
			],

			'description' => [
				'title'       => __('Description', 'nft-checkout-payment-gateway'),
				'type'        => 'textarea',
				'description' => __('Provide a brief description of the payment option.', 'nft-checkout-payment-gateway'),
				'default'     => __('Pay securely by card. Your payment is processed by RT.', 'nft-checkout-payment-gateway'),
				'desc_tip'    => true,
			],

			'instructions' => [
				'title'       => __('Instructions', 'nft-checkout-payment-gateway'),
				'type'        => 'title',
				/* translators: 1: Developer account instructions link HTML, 2: Additional text */
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
		$option_value    = function_exists('nft_checkout_get_accounts') ? nft_checkout_get_accounts() : [];
		$global_settings = function_exists('nft_checkout_get_settings') ? nft_checkout_get_settings() : [];
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
							$live_status        = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status     = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							$unique_id          = (!empty($account['unique_id'])) ? $account['unique_id'] : '';
							$has_live_secret    = !empty($account['live_secret_key']);
							$has_sandbox_secret = !empty($account['sandbox_secret_key']);
							?>
							<div class="nft-checkout-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" class="live-status" name="accounts[<?php echo esc_attr($index); ?>][live_status]" value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" class="sandbox-status" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]" value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
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
											<input type="password" autocomplete="new-password" class="live-secret-key" name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]" data-has-secret="<?php echo $has_live_secret ? '1' : '0'; ?>" placeholder="<?php echo $has_live_secret ? esc_attr__('Secret key saved (enter new key to replace)', 'nft-checkout-payment-gateway') : esc_attr__('Secret Key', 'nft-checkout-payment-gateway'); ?>" value="">
										</div>
									</div>

									<div class="account-checkbox">
										<?php
										$checkbox_id    = $this->id . '-sandbox-checkbox-' . $index;
										$checkbox_class = $this->id . '-sandbox-checkbox';
										?>
										<input type="checkbox" class="<?php echo esc_attr($checkbox_class); ?>" id="<?php echo esc_attr($checkbox_id); ?>" name="accounts[<?php echo esc_attr($index); ?>][has_sandbox]" <?php checked(($account['has_sandbox'] ?? 'off') === 'on'); ?>>
										<label for="<?php echo esc_attr($checkbox_id); ?>"><?php esc_html_e('Do you have the sandbox keys?', 'nft-checkout-payment-gateway'); ?></label>
									</div>

									<?php
									$sandbox_container_id    = $this->id . '-sandbox-keys-' . $index;
									$sandbox_container_class = $this->id . '-sandbox-keys';
									$sandbox_display_style   = (($account['has_sandbox'] ?? 'off') === 'off') ? 'display: none;' : '';
									?>
									<div id="<?php echo esc_attr($sandbox_container_id); ?>" class="<?php echo esc_attr($sandbox_container_class); ?>" style="<?php echo esc_attr($sandbox_display_style); ?>">
										<div class="add-blog">
											<div class="account-input">
												<label><?php esc_html_e('Sandbox Keys', 'nft-checkout-payment-gateway'); ?></label>
												<input type="text" class="sandbox-public-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]" placeholder="<?php esc_attr_e('Public Key', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
											</div>
											<div class="account-input">
												<input type="password" autocomplete="new-password" class="sandbox-secret-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]" data-has-secret="<?php echo $has_sandbox_secret ? '1' : '0'; ?>" placeholder="<?php echo $has_sandbox_secret ? esc_attr__('Secret key saved (enter new key to replace)', 'nft-checkout-payment-gateway') : esc_attr__('Secret Key', 'nft-checkout-payment-gateway'); ?>" value="">
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

		$order_id   = absint($order_id);
		$lock_name  = '';
		$log_prefix = "[Order #{$order_id}]";

		NFT_Checkout_Payment_Gateway_Logger::info(
			$log_prefix . ' Payment process started',
			[
				'order_id' => $order_id,
			]
		);

		wc_clear_notices();

		// -------------------------------------------------
		// 1. ORDER & AUTHORIZATION VALIDATION
		// -------------------------------------------------
		$order = wc_get_order($order_id);

		if (!$order) {
			wc_add_notice(__('Invalid order.', 'nft-checkout-payment-gateway'), 'error');

			return $this->build_response(
				'failure',
				__('Invalid order.', 'nft-checkout-payment-gateway'),
				[],
				400,
				$order_id
			);
		}

		if (
			$order->get_customer_id() > 0 &&
			is_user_logged_in() &&
			get_current_user_id() !== (int) $order->get_customer_id() &&
			!current_user_can('manage_woocommerce') &&
			!current_user_can('pay_for_order', $order_id)
		) {
			$auth_error = __('You are not authorized to pay for this order.', 'nft-checkout-payment-gateway');
			wc_add_notice($auth_error, 'error');

			return $this->build_response(
				'failure',
				$auth_error,
				[],
				403,
				$order_id
			);
		}

		$billing_email = sanitize_email((string) $order->get_billing_email());
		if (empty($billing_email) || !is_email($billing_email)) {
			$email_error = __('A valid billing email address is required to complete NFT Checkout payment.', 'nft-checkout-payment-gateway');
			wc_add_notice($email_error, 'error');

			return $this->build_response(
				'failure',
				$email_error,
				[],
				400,
				$order_id
			);
		}

		// Fast-fail if WooCommerce says the order no longer needs payment or is already paid.
		if (
			!$order->needs_payment() ||
			$order->is_paid() ||
			$order->has_status(['processing', 'completed', 'refunded']) ||
			$order->get_meta('_nft_checkout_payment_success') === 'yes' ||
			$order->get_meta('_nft_checkout_state') === 'success'
		) {
			$paid_error = __('This order has already been paid or does not require payment.', 'nft-checkout-payment-gateway');
			NFT_Checkout_Payment_Gateway_Logger::warning(
				$log_prefix . ' Rejected payment initiation for already-paid or settled order',
				['order_id' => $order_id, 'status' => $order->get_status()]
			);
			wc_add_notice($paid_error, 'error');

			return $this->build_response(
				'failure',
				$paid_error,
				['is_duplicate' => true],
				400,
				$order_id
			);
		}

		NFT_Checkout_Payment_Gateway_Logger::info(
			'Payment initiated',
			[
				'order_id'       => $order_id,
				'status'         => $order->get_status(),
				'plugin_version' => defined('NFT_CHECKOUT_PLUGIN_VERSION') ? NFT_CHECKOUT_PLUGIN_VERSION : '',
			]
		);

		$lock_name = 'rt_nft_checkout_order_' . $order_id;

		// Try lock
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory MySQL lock cannot be cached.
		$lock_result = $wpdb->get_var(
			$wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock_name)
		);

		if ((string) $lock_result !== '1') {
			$lock_msg = __('Payment already in progress. Please wait a few seconds and try again.', 'nft-checkout-payment-gateway');
			wc_add_notice($lock_msg, 'error');

			return $this->build_response(
				'failure',
				$lock_msg,
				[],
				409,
				$order_id
			);
		}

		$active_lock_key = 'rt_nft_checkout_payment_active_' . $order_id;

		try {
			// Re-fetch authoritative order state inside lock to avoid TOCTOU races.
			$order = wc_get_order($order_id);
			if (
				!$order ||
				!$order->needs_payment() ||
				$order->is_paid() ||
				$order->has_status(['processing', 'completed', 'refunded']) ||
				$order->get_meta('_nft_checkout_payment_success') === 'yes' ||
				$order->get_meta('_nft_checkout_state') === 'success'
			) {
				$paid_error = __('This order has already been paid or does not require payment.', 'nft-checkout-payment-gateway');
				wc_add_notice($paid_error, 'error');

				return $this->build_response(
					'failure',
					$paid_error,
					['is_duplicate' => true],
					400,
					$order_id
				);
			}

			// -------------------------------------------------
			// 4. IDENTITY-SCOPED RATE LIMITING (NFT-14)
			// -------------------------------------------------
			$window_size  = 60;
			$max_requests = 10;
			$identity     = function_exists('nft_checkout_get_rate_limit_identity')
				? nft_checkout_get_rate_limit_identity($order_id)
				: hash('sha256', $this->nft_checkout_get_client_ip() . '|' . $order_id);

			$timestamp_key = 'rt_nft_checkout_rl_' . $identity;
			$timestamps    = get_transient($timestamp_key);
			$timestamps    = is_array($timestamps) ? $timestamps : [];
			$current_time  = time();

			$timestamps = array_values(array_filter($timestamps, static fn($ts) => $current_time - (int) $ts <= $window_size));

			if (count($timestamps) >= $max_requests) {
				NFT_Checkout_Payment_Gateway_Logger::warning(
					$log_prefix . ' Rate limit exceeded',
					['identity' => substr($identity, 0, 12)]
				);

				$rate_limit_msg = __('Too many requests. Please try again later.', 'nft-checkout-payment-gateway');
				wc_add_notice($rate_limit_msg, 'error');

				return $this->build_response(
					'failure',
					$rate_limit_msg,
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

				wc_add_notice($duplicate_warning, 'error');

				return $this->build_response(
					'failure',
					$duplicate_warning,
					['is_duplicate' => true],
					400,
					$order_id
				);
			}

			// In-flight concurrency lock to prevent duplicate clicks for the same order and amount
			set_transient($active_lock_key, [
				'user_id' => $order->get_customer_id(),
				'amount'  => (float) $order->get_total(),
				'time'    => time(),
			], 60);

			// -------------------------------------------------
			// 6. SANDBOX FLAG
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
			// 7. VOUCHER EMAIL (SENT BY RT)
			// -------------------------------------------------
			if (!$order->has_status('pending')) {
				$order->update_status('pending', __('Awaiting voucher purchase.', 'nft-checkout-payment-gateway'));
			}

			$voucher = $this->nft_checkout_request_voucher_email($order);

			if (!$voucher['success']) {
				delete_transient($active_lock_key);

				$voucher_error = $voucher['message'];
				wc_add_notice($voucher_error, 'error');

				return $this->build_response(
					'failure',
					$voucher_error,
					['is_duplicate' => !empty($voucher['is_duplicate'])],
					!empty($voucher['is_duplicate']) ? 400 : 502,
					$order_id
				);
			}

			$purchase_url = esc_url_raw((string) ($voucher['data']['purchase_url'] ?? ''), ['https']);
			if (function_exists('nft_checkout_is_valid_rt_url') && $purchase_url !== '' && !nft_checkout_is_valid_rt_url($purchase_url)) {
				$purchase_url = '';
			}
			// Unsigned /voucher/redeem/ URLs cause 410 "Voucher unavailable" on the server.
			if ($purchase_url !== '' && false !== strpos($purchase_url, '/voucher/redeem/') && false === strpos($purchase_url, 'signature=')) {
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
						'message'      => $voucher['message'],
						'reference'    => $voucher['data']['reference'] ?? '',
						'voucher_id'   => $voucher['data']['voucher_id'] ?? '',
						'purchase_url' => $purchase_url,
					],
					'redirect'       => $order->get_checkout_order_received_url(),
				],
				200,
				$order_id
			);
		} catch (\Exception $e) {
			NFT_Checkout_Payment_Gateway_Logger::error(
				'Payment processing exception: ' . $e->getMessage(),
				[
					'order_id' => $order_id ?? null,
					'file'     => $e->getFile(),
					'line'     => $e->getLine(),
				]
			);

			$err_msg = __('An internal error occurred.', 'nft-checkout-payment-gateway');
			wc_add_notice($err_msg, 'error');

			return $this->build_response('failure', $err_msg, [], 500, $order_id);
		} finally {
			delete_transient($active_lock_key);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory MySQL lock release cannot be cached.
			$wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
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

	private function is_store_api_checkout_request(): bool {
		if (function_exists('WC') && WC() && method_exists(WC(), 'is_store_api_request') && WC()->is_store_api_request()) {
			return true;
		}
		return defined('REST_REQUEST') && REST_REQUEST && isset($_SERVER['REQUEST_URI']) && false !== strpos((string) wp_unslash($_SERVER['REQUEST_URI']), '/wc/store/');
	}

	private function build_response(
		string $result,
		string $message = '',
		array $data = [],
		int $code = 200,
		?int $order_id = null
	) {
		$normalized_result = ($result === 'success') ? 'success' : 'failure';

		// Store API PaymentResult::set_payment_details() expects flat scalar values.
		if ($this->is_store_api_checkout_request()) {
			$response = [
				'result'   => $normalized_result,
				'message'  => $message,
				'order_id' => $order_id !== null ? (string) $order_id : '',
			];
			if (!empty($data['redirect']) && is_string($data['redirect'])) {
				$response['redirect'] = $data['redirect'];
			}
			return $response;
		}

		$response = [
			'result'   => $normalized_result,
			'message'  => $message,
			'data'     => $data,
			'order_id' => $order_id,
			'code'     => $code,
			'success'  => $normalized_result === 'success',
		];

		if (!empty($data['redirect']) && is_string($data['redirect'])) {
			$response['redirect'] = $data['redirect'];
		}

		return $response;
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

		$api_url         = esc_url_raw($this->get_api_url('/voucher/send'));
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
			$limit_url = esc_url_raw($this->get_api_url('/dailylimit'));

			$limit_resp = wp_remote_post($limit_url, [
				'method'      => 'POST',
				'timeout'     => 10,
				'redirection' => 0,
				'sslverify'   => true,
				'body'        => [
					'amount'     => $data['amount'],
					'is_sandbox' => $data['is_sandbox'],
				],
				'headers'     => [
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
					'x-api-secret'  => sanitize_text_field($secret_key),
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
						'status'        => sanitize_text_field((string) ($limit_data['status'] ?? '')),
						'code'          => $limit_data['code'] ?? null,
						'message'       => sanitize_text_field((string) ($limit_data['message'] ?? '')),
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

			// NFT-05 (Backend Blocker): Backend VoucherSendRequest requires api_secret in body;
			// x-api-secret header is also sent so future backend cleanup requires no breaking change.
			// Send JSON so nested meta_data (order_id) and items are preserved by the backend parser.
			$response = wp_remote_post($api_url, [
				'method'      => 'POST',
				'timeout'     => 15,
				'redirection' => 0,
				'body'        => wp_json_encode($data),
				'headers'     => [
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
					'x-api-secret'  => sanitize_text_field($secret_key),
				],
				'sslverify'   => true,
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

				$last_message = __('We could not reach the payment service. Please try again in a moment.', 'nft-checkout-payment-gateway');
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
			'message' => $last_message ?: __('We could not start your payment. Please try again in a moment.', 'nft-checkout-payment-gateway'),
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

		$order_id    = $order->get_id();
		$order_total = (float) $order->get_total();

		// 1. Check authoritative WooCommerce order payment state (never bypass on email change).
		$is_paid_status       = !$order->needs_payment() || $order->is_paid() || $order->has_status(['completed', 'processing', 'refunded']);
		$payment_success_flag = $order->get_meta('_nft_checkout_payment_success') === 'yes';
		$state_is_success     = $order->get_meta('_nft_checkout_state') === 'success';

		if ($is_paid_status || $payment_success_flag || $state_is_success) {
			return true;
		}

		// 2. Check for concurrent in-flight payment request for the same order and amount
		$active_lock = get_transient('rt_nft_checkout_payment_active_' . $order_id);
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
	 * Reuses the open reference when retrying an unpaid pending attempt with no
	 * prior failed/cancelled/expired attempts; increments only after failures so
	 * a fresh voucher and payment link are minted on retry.
	 *
	 * @param WC_Order $order Order being paid.
	 * @return string
	 */
	private function nft_checkout_voucher_reference($order) {

		$last_voucher_attempt = 0;
		$prev_ref             = (string) $order->get_meta('_nft_checkout_voucher_reference', true);
		if ($prev_ref !== '') {
			if (preg_match('/-(\d+)$/', $prev_ref, $matches)) {
				$last_voucher_attempt = (int) $matches[1];
			} else {
				$last_voucher_attempt = 1;
			}
		}

		$timeline_attempts = $this->nft_checkout_failed_attempts($order);
		$engine_state      = (string) $order->get_meta('_nft_checkout_state', true);

		if (
			$prev_ref !== '' &&
			$order->has_status('pending') &&
			($engine_state === 'pending' || $engine_state === '') &&
			$last_voucher_attempt > $timeline_attempts
		) {
			return $prev_ref;
		}

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
		$prev_ref  = (string) $order->get_meta('_nft_checkout_voucher_reference');

		// Guard against overwriting an already paid/settled order state.
		if ($order->is_paid() || $order->get_meta('_nft_checkout_payment_success') === 'yes') {
			return;
		}

		// Record the exact mode and account binding used at payment initiation.
		$order->update_meta_data('_nft_checkout_is_sandbox', $this->sandbox ? 'yes' : 'no');

		// Clear stale pay_id only when initiating a brand-new retry voucher after failure.
		if ($reference !== '' && $prev_ref !== '' && $reference !== $prev_ref && $this->nft_checkout_failed_attempts($order) > 0) {
			$order->delete_meta_data('_nft_checkout_pay_id');
			$order->delete_meta_data('_nft_checkout_cancelled_link_uuid');
		}

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

		$link = esc_url_raw((string) ($voucher['purchase_url'] ?? ''), ['https']);
		if (function_exists('nft_checkout_is_valid_rt_url') && $link !== '' && !nft_checkout_is_valid_rt_url($link)) {
			$link = '';
		}
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
				esc_html__('Almost done — check your email (%s) and open the link to pay by card. Your order is saved until you finish.', 'nft-checkout-payment-gateway'),
				'<strong>' . esc_html($this->nft_checkout_mask_email($order->get_billing_email())) . '</strong>'
			)
		);

		$purchase_url = (string) $order->get_meta('_nft_checkout_voucher_purchase_url');
		if (
			$purchase_url !== '' &&
			(!function_exists('nft_checkout_is_valid_rt_url') || nft_checkout_is_valid_rt_url($purchase_url)) &&
			(false === strpos($purchase_url, '/voucher/redeem/') || false !== strpos($purchase_url, 'signature='))
		) {
			printf(
				'<p class="nft-checkout-thankyou-direct-link"><a href="%s" class="button" target="_blank" rel="noopener noreferrer">%s</a></p>',
				esc_url($purchase_url),
				esc_html__('Not receiving the email? Click here to pay', 'nft-checkout-payment-gateway')
			);
		}
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
		$order_id          = $order->get_id();
		$is_sandbox        = $this->get_option('sandbox') === 'yes';
		$request_for       = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		$first_name        = sanitize_text_field($order->get_billing_first_name());
		$last_name         = sanitize_text_field($order->get_billing_last_name());
		$amount            = number_format($order->get_total(), 2, '.', '');
		$email             = sanitize_text_field($order->get_billing_email());
		$original_phone    = $order->get_billing_phone();
		$phone             = sanitize_text_field($original_phone);
		$country           = $order->get_billing_country();
		$country_code      = WC()->countries->get_country_calling_code($country);

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
			return ['result' => 'fail', 'error' => 'Order ID is missing or invalid.'];
		}

		$plugin_version = defined('NFT_CHECKOUT_PLUGIN_VERSION') ? NFT_CHECKOUT_PLUGIN_VERSION : '';

		$meta_data_array = array_map('sanitize_text_field', [
			'order_id'       => $order_id,
			'amount'         => $amount,
			'source'         => 'woocommerce',
			'plugin_version' => $plugin_version,
		]);

		$user_id  = $order->get_customer_id();
		$is_guest = empty($user_id) ? 1 : 0;

		return [
			'order_id'          => $order_id,
			'plugin_version'    => $plugin_version,
			// NFT-05 (Backend Blocker): Backend VoucherSendRequest requires api_secret in body.
			'api_secret'        => $api_secret,
			'api_public_key'    => $api_public_key,
			'first_name'        => $first_name,
			'last_name'         => $last_name,
			'request_for'       => $request_for,
			'amount'            => $amount,
			'redirect_url'      => $redirect_url,
			'redirect_time'     => 3,
			'ip_address'        => $ip_address,
			'source'            => 'wordpress',
			'meta_data'         => $meta_data_array,
			'remarks'           => 'Order ' . $order->get_order_number(),
			'email'             => $email,
			'phone_number'      => $phone,
			'country_code'      => $country_code,
			'billing_address_1' => $billing_address_1,
			'billing_address_2' => $billing_address_2,
			'billing_city'      => $billing_city,
			'billing_postcode'  => $billing_postcode,
			'billing_country'   => $billing_country,
			'billing_state'     => $billing_state,
			'is_sandbox'        => $is_sandbox ? 1 : 0,
			'curr_code'         => sanitize_text_field($order->get_currency()),
			'plugin_source'     => 'nft-checkout',
			'is_guest'          => $is_guest,
			'customer_user_id'  => $user_id ? $user_id : 0,
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
		if (function_exists('nft_checkout_get_trusted_client_ip')) {
			return nft_checkout_get_trusted_client_ip();
		}
		// NFT-14: Use validated REMOTE_ADDR rather than trusting arbitrary proxy headers.
		$ip = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
			return $ip;
		}
		return '0.0.0.0';
	}

	public function nft_checkout_add_custom_label_to_order_row($line_items, $order) {
		if (!$order instanceof WC_Order || empty($line_items) || !isset($line_items[0]['name'])) {
			return $line_items;
		}
		$order_origin = $order->get_meta('_order_origin');
		if (!empty($order_origin)) {
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}
		return $line_items;
	}

	public function nft_checkout_woocommerce_not_active_notice() {
		echo '<div class="error"><p>' . esc_html__('NFT Checkout requires WooCommerce to be installed and active.', 'nft-checkout-payment-gateway') . '</p></div>';
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
			// Store API already validates its own CSRF Nonce / Cart-Token before invoking validate_fields().
			if (!$this->is_store_api_checkout_request()) {
				$nonce = isset($_POST['nft_checkout_nonce']) ? sanitize_text_field(wp_unslash($_POST['nft_checkout_nonce'])) : '';
				if (empty($nonce) || !wp_verify_nonce($nonce, 'nft_checkout_payment')) {
					wc_add_notice(__('Nonce verification failed. Please try again.', 'nft-checkout-payment-gateway'), 'error');
					return false;
				}
			}
			$consent = isset($_POST['nft_checkout_consent']) ? strtolower(sanitize_text_field(wp_unslash($_POST['nft_checkout_consent']))) : '';
			if (!in_array($consent, ['on', '1', 'true', 'yes'], true)) {
				wc_add_notice(__('You must consent to the collection of your data to process this payment.', 'nft-checkout-payment-gateway'), 'error');
				return false;
			}
		}
		return true;
	}

	public function nft_checkout_enqueue_styles_and_scripts() {
		$is_order_received = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received');
		if (is_checkout() && !$is_order_received) {
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
		$gateway_id                         = $this->id;
		$this->selected_account_for_display = null;

		// =====================================================
		// STEP 1: CHECKOUT & ORDER-PAY CONTEXT (STRICT)
		// =====================================================
		$is_order_received = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received');
		$is_ajax           = function_exists('wp_doing_ajax') && wp_doing_ajax() && !is_admin();
		$is_blocks         = !is_admin() && $this->is_store_api_checkout_request();
		$is_checkout_page  = function_exists('is_checkout') && is_checkout() && !$is_order_received;
		$is_order_pay      = function_exists('is_checkout_pay_page') && is_checkout_pay_page();

		if ($is_order_received || (!$is_checkout_page && !$is_order_pay && !$is_ajax && !$is_blocks)) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 2: SAFE AMOUNT & CART RESOLUTION (SUPPORTS ORDER-PAY)
		// =====================================================
		$amount       = 0.0;
		$items        = 0;
		$pay_order_id = 0;

		if ($is_order_pay) {
			$pay_order_id = absint(get_query_var('order-pay'));
			$pay_order    = $pay_order_id > 0 ? wc_get_order($pay_order_id) : null;
			if ($pay_order instanceof WC_Order) {
				$amount = (float) $pay_order->get_total();
				$items  = count($pay_order->get_items());
			}
		} elseif (WC()->cart) {
			$amount = (float) WC()->cart->get_total('raw');
			if ($amount < 0.01) {
				$amount = (float) (WC()->cart->get_totals()['total'] ?? 0);
			}
			$items = count(WC()->cart->get_cart());
		} else {
			return $available_gateways;
		}

		// =====================================================
		// STEP 3: FLOW LABEL
		// =====================================================
		$flow = 'Checkout (Classic)';

		if ($is_order_pay) {
			$flow = 'Checkout (Order Pay)';
		} elseif ($is_blocks) {
			$flow = 'Checkout (Blocks)';
		} elseif ($is_ajax) {
			$flow = 'Checkout (AJAX)';
		}

		// =====================================================
		// STEP 4: REQUEST FINGERPRINT CACHE (PRESERVES HIDDEN STATE)
		// =====================================================
		static $decision_cache = [];

		$fingerprint = md5(wp_json_encode([
			'flow'         => $flow,
			'items'        => $items,
			'total'        => $amount,
			'order_pay'    => $pay_order_id,
			'sandbox'      => (bool) $this->sandbox,
			'ajax'         => $is_ajax,
			'blocks'       => $is_blocks,
		]));

		if (isset($decision_cache[$fingerprint])) {
			$cached_decision                    = $decision_cache[$fingerprint];
			$this->selected_account_for_display = $cached_decision['account'];
			if (!empty($cached_decision['hidden'])) {
				return $this->hide_gateway($available_gateways, $gateway_id);
			}
			return $available_gateways;
		}

		// =====================================================
		// STEP 5: LOAD ACCOUNTS
		// =====================================================
		if (!method_exists($this, 'get_all_accounts')) {
			return $available_gateways;
		}

		$accounts = $this->get_all_accounts();

		// =====================================================
		// STEP 6: NO ACCOUNTS
		// =====================================================
		if (empty($accounts)) {

			NFT_Checkout_Payment_Gateway_Logger::info(
				'NFT Checkout Gateway Decision',
				[
					'result' => 'HIDDEN',
					'reason' => 'No merchant accounts configured',
					'items'  => $items,
					'total'  => $amount,
					'flow'   => $flow,
				]
			);

			$decision_cache[$fingerprint] = ['hidden' => true, 'account' => null];
			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		// =====================================================
		// STEP 7: SORT
		// =====================================================
		usort($accounts, fn($a, $b) => ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1));

		// =====================================================
		// STEP 8: EVALUATION
		// =====================================================
		$selected             = null;
		$reason               = 'No eligible merchant account';
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
			$mode_flag    = $this->sandbox ? 1 : 0;

			// Never place secret keys into request bodies; authenticate via Bearer + x-api-secret headers.
			$body = [
				'is_sandbox' => $mode_flag,
				'amount'     => $check_amount,
			];
			$headers = [
				'Authorization' => 'Bearer ' . sanitize_text_field($public),
				'x-api-secret'  => sanitize_text_field($secret),
			];

			$cache_suffix = md5($public . '|' . $check_amount . '|' . $mode_flag);

			$status = $this->get_cached_api_response(
				$this->get_api_url('/check-merchant-status'),
				$body,
				'rt_nft_checkout_status_' . $cache_suffix,
				300,
				$force_refresh,
				$headers
			);

			$account_active_locally = strtolower($this->sandbox ? ($account['sandbox_status'] ?? '') : ($account['live_status'] ?? '')) === 'active';
			$is_status_success      = ($status['status'] ?? '') === 'success';

			if (!$is_status_success) {
				// If server explicitly said inactive, invalid, or unauthorized (401/403), reject account
				if (!empty($status['status']) && in_array(strtolower($status['status']), ['inactive', 'invalid', 'unauthorized'], true)) {
					continue;
				}
				if (!empty($status['code']) && in_array((int) $status['code'], [401, 403], true)) {
					continue;
				}
				// If it's a network glitch/timeout, only allow if account was verified active in settings
				if (!$account_active_locally) {
					continue;
				}
			}

			$limit = $this->get_cached_api_response(
				$this->get_api_url('/dailylimit'),
				$body,
				'rt_nft_checkout_limit_' . $cache_suffix,
				300,
				$force_refresh,
				$headers
			);

			// Daily limit: only reject if API explicitly reported limit exceeded
			if (!empty($limit['max_limit_reached']) || (isset($limit['status']) && $limit['status'] === 'error' && isset($limit['code']) && (int) $limit['code'] === 400 && str_contains(strtolower((string) ($limit['message'] ?? '')), 'limit'))) {
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
		// STEP 9: SINGLE FINAL LOG ONLY
		// =====================================================
		NFT_Checkout_Payment_Gateway_Logger::info(
			'NFT Checkout Gateway Decision',
			[
				'result'  => $selected ? 'SHOWN' : 'HIDDEN',
				'reason'  => $reason,
				'items'   => $items,
				'total'   => $amount,
				'flow'    => $flow,
				'account' => $selected['title'] ?? null,
			]
		);

		// =====================================================
		// STEP 10: RETURN RESULT
		// =====================================================
		$this->selected_account_for_display = $selected;

		if (!$selected) {
			$decision_cache[$fingerprint] = ['hidden' => true, 'account' => null];
			return $this->hide_gateway($available_gateways, $gateway_id);
		}

		$decision_cache[$fingerprint] = ['hidden' => false, 'account' => $selected];
		return $available_gateways;
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

		$flow = 'background';

		$is_ajax = defined('DOING_AJAX') && DOING_AJAX;
		$is_rest = defined('REST_REQUEST') && REST_REQUEST;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of WooCommerce AJAX endpoint name for logging context.
		$wc_ajax = isset($_REQUEST['wc-ajax']) ? sanitize_text_field(wp_unslash($_REQUEST['wc-ajax'])) : '';

		if (is_checkout()) {
			$flow = 'checkout_page';
		} elseif ($is_rest) {
			$flow = 'checkout_block';
		} elseif ($is_ajax && $wc_ajax === 'update_order_review') {
			$flow = 'checkout_refresh';
		}

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

		$session_key = 'rt_nft_checkout_log_' . md5($key . $this->id);

		if (WC()->session->get($session_key)) {
			return;
		}

		WC()->session->set($session_key, true);

		NFT_Checkout_Payment_Gateway_Logger::info($message, $clean_context);
	}



	private function get_cached_api_response($url, $data, $cache_key, $ttl = 120, $force_refresh = false, $headers = []) {
		if (!$force_refresh && isset($_GET['refresh_accounts']) && sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1' && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')) {
			$force_refresh = true;
		}
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if ($cached !== false) {
				return $cached;
			}
		} else {
			delete_transient($cache_key);
		}

		$request_headers = array_merge([
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Accept'       => 'application/json',
		], is_array($headers) ? $headers : []);

		if (empty($request_headers['Authorization']) && !empty($data['api_public_key'])) {
			$request_headers['Authorization'] = 'Bearer ' . sanitize_text_field($data['api_public_key']);
		}

		$response = wp_remote_post(esc_url_raw($url), [
			'method'      => 'POST',
			'timeout'     => 5,
			'redirection' => 0,
			'body'        => $data,
			'headers'     => $request_headers,
			'sslverify'   => true,
		]);
		if (is_wp_error($response)) {
			$err_resp = ['status' => 'error', 'message' => $response->get_error_message()];
			set_transient($cache_key, $err_resp, 60);
			return $err_resp;
		}
		$response_code = (int) wp_remote_retrieve_response_code($response);
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
		$accounts       = function_exists('nft_checkout_get_accounts') ? nft_checkout_get_accounts() : [];
		$valid_accounts = [];
		foreach ($accounts as $account) {
			if ($this->sandbox) {
				$status   = strtolower($account['sandbox_status'] ?? '');
				$has_keys = !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']);
				if ($status === 'active' && $has_keys) {
					$valid_accounts[] = $account;
				}
			} else {
				$status   = strtolower($account['live_status'] ?? '');
				$has_keys = !empty($account['live_public_key']) && !empty($account['live_secret_key']);
				if ($status === 'active' && $has_keys) {
					$valid_accounts[] = $account;
				}
			}
		}
		$this->accounts = $valid_accounts;
		return $valid_accounts;
	}

	/**
	 * Sort accounts by priority.
	 *
	 * @param array $accounts All accounts.
	 * @return array Sorted array of eligible accounts.
	 */
	private function get_routing_sorted_accounts(array $accounts): array {
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
		$accounts         = $this->get_all_accounts();
		if (empty($accounts)) {
			return [];
		}

		usort($accounts, fn($a, $b) => ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1));

		$accStatusApiUrl        = $this->get_api_url('/check-merchant-status');
		$transactionLimitApiUrl = $this->get_api_url('/dailylimit');
		$all_accounts_limited   = true;
		$limit_data             = [];

		$force_refresh = (
			isset($_GET['refresh_accounts'], $_GET['_wpnonce']) &&
			sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1' &&
			wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		);

		$check_amount = $amount > 0.01 ? $amount : 0.01;
		$mode_flag    = $this->sandbox ? 1 : 0;

		foreach ($accounts as $account) {
			$acc_title  = $account['title'] ?? '(unknown)';
			$public_key = $this->sandbox ? ($account['sandbox_public_key'] ?? '') : ($account['live_public_key'] ?? '');
			$secret_key = $this->sandbox ? ($account['sandbox_secret_key'] ?? '') : ($account['live_secret_key'] ?? '');
			if (empty($public_key) || empty($secret_key)) {
				continue;
			}

			$body = [
				'is_sandbox' => $mode_flag,
				'amount'     => $check_amount,
			];
			$headers = [
				'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
				'x-api-secret'  => sanitize_text_field($secret_key),
			];

			$cache_suffix = md5($public_key . '|' . $check_amount . '|' . $mode_flag);
			$status_data  = $this->get_cached_api_response(
				$accStatusApiUrl,
				$body,
				'rt_nft_checkout_status_' . $cache_suffix,
				300,
				$force_refresh,
				$headers
			);

			if (($status_data['status'] ?? '') !== 'success') {
				$this->log_info_once_per_session('skip_status_' . $acc_title, "Skipping '{$acc_title}': merchant status check failed", [
					'response_status' => $status_data['status'] ?? 'unknown',
				]);
				continue;
			}

			$limit_data = $this->get_cached_api_response(
				$transactionLimitApiUrl,
				$body,
				'rt_nft_checkout_limit_' . $cache_suffix,
				300,
				$force_refresh,
				$headers
			);

			if (($limit_data['status'] ?? '') !== 'success') {
				continue;
			}

			$all_accounts_limited = false;
			$selected_account     = $account;
			break;
		}

		if ($all_accounts_limited) {
			if (!isset($limit_data['max_limit_reached']) || $limit_data['max_limit_reached'] == false) {
				return [];
			}
		}

		$this->selected_account_for_display = $selected_account ?: null;

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
		$settings = function_exists('nft_checkout_get_accounts') ? nft_checkout_get_accounts() : [];

		if (!is_array($settings)) {
			return [];
		}

		$mode       = $this->sandbox ? 'sandbox' : 'live';
		$status_key = $mode . '_status';
		$public_key = $mode . '_public_key';
		$secret_key = $mode . '_secret_key';

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
