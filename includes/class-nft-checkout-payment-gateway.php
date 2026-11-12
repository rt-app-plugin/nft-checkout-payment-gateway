<?php
if (!defined('ABSPATH')) {
	exit();
}

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'nft-checkout-payment-gateway-utils.php';
require_once plugin_dir_path(__FILE__) . 'class-nft-checkout-payment-logger.php';

class NFT_CHECKOUT_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = NFT_CHECKOUT_GATEWAY_ID;

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
		$this->method_description = __('Let shoppers pay by card. After placing the order, the shopper gets an email from RT with a secure link to pay. Refunds are made in RT, not here: open the payment in your RT account and refund it there.', 'nft-checkout-payment-gateway');

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
					$normalized['error'] ?? __('Please check your phone number. Enter it with the area code, for example 415 555 0132.', 'nft-checkout-payment-gateway')
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
				__('Card payment needs a street address, not a PO Box. Please enter your street address.', 'nft-checkout-payment-gateway')
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
					__('Please check your ZIP / postal code.', 'nft-checkout-payment-gateway')
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

				throw $this->nft_checkout_checkout_exception($normalized['error'] ?? __('Please check your phone number. Enter it with the area code, for example 415 555 0132.', 'nft-checkout-payment-gateway'));
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

			throw $this->nft_checkout_checkout_exception(__('Card payment needs a street address, not a PO Box. Please enter your street address.', 'nft-checkout-payment-gateway'));
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

				throw $this->nft_checkout_checkout_exception(__('Please check your ZIP / postal code.', 'nft-checkout-payment-gateway'));
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

	/**
	 * A validation refusal the Store API reports to the shopper as a 400 with
	 * the message (a bare Exception would surface as a 500).
	 */
	private function nft_checkout_checkout_exception($message) {
		if (class_exists('\Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
			return new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'nft_checkout_invalid_address',
				esc_html($message),
				400
			);
		}
		return new \Exception(esc_html($message));
	}

	/**
	 * Whether the current request is a WooCommerce Store API (block checkout) call.
	 */
	private function is_store_api_request() {
		if (function_exists('WC') && WC() && method_exists(WC(), 'is_store_api_request')) {
			return (bool) WC()->is_store_api_request();
		}
		if (!defined('REST_REQUEST') || !REST_REQUEST) {
			return false;
		}
		$uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return strpos($uri, 'wc/store') !== false;
	}

	private function get_api_url($endpoint) {
		$endpoint = '/' . ltrim($endpoint, '/');
		if (strpos($endpoint, '/payment-api/') === 0) {
			return $this->base_url . $endpoint;
		}
		if (strpos($endpoint, '/api/') === 0) {
			$endpoint = substr($endpoint, 4);
		}
		return NFT_CHECKOUT_API_BASE_URL . $endpoint;
	}

	public function nft_checkout_process_admin_options() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to change these settings. Ask the shop owner or an administrator.', 'nft-checkout-payment-gateway'));
		}

		if (!isset($_POST['nft_checkout_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nft_checkout_accounts_nonce'])), 'nft_checkout_accounts_nonce_action')) {
			NFT_Checkout_Payment_Gateway_Logger::info('CSRF check failed during admin options update.');
			wp_die(esc_html__('This page was open for too long. Go back, refresh the settings page and save again.', 'nft-checkout-payment-gateway'));
		}

		$enabled     = isset($_POST['woocommerce_' . $this->id . '_enabled']) ? 'yes' : 'no';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized recursively via map_deep with sanitize_text_field.
		$accounts    = isset($_POST['accounts']) && is_array($_POST['accounts']) ? map_deep(wp_unslash($_POST['accounts']), 'sanitize_text_field') : [];
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

		$no_accounts_submitted = (!is_array($raw_accounts) || empty($raw_accounts));
		if ($no_accounts_submitted) {
			NFT_Checkout_Payment_Gateway_Logger::info('No accounts submitted in admin options.');
		}

		// BK-09: only this plugin's own accounts; never another plugin's keys.
		$existing_accounts = nft_checkout_get_accounts();

		$existing_by_id = [];
		foreach ($existing_accounts as $idx => $existing_acc) {
			if (is_array($existing_acc)) {
				if (!empty($existing_acc['unique_id'])) {
					$existing_by_id[$existing_acc['unique_id']] = $existing_acc;
				}
				$existing_by_id['idx_' . $idx] = $existing_acc;
			}
		}

		foreach ((array) $raw_accounts as $raw_index => $account) {
			if (!is_array($account)) continue;

			$account = array_map('sanitize_text_field', $account);

			$account_title      = $account['title'] ?? '';
			$priority           = intval($account['priority'] ?? 1);
			$live_public_key    = $account['live_public_key'] ?? '';
			$live_secret_key    = $account['live_secret_key'] ?? '';
			$sandbox_public_key = $account['sandbox_public_key'] ?? '';
			$sandbox_secret_key = $account['sandbox_secret_key'] ?? '';
			$has_sandbox         = isset($account['has_sandbox']) && $account['has_sandbox'] === 'on';
			$live_status        = $account['live_status'] ?? '';
			$sandbox_status     = $has_sandbox ? ($account['sandbox_status'] ?? 'unknown') : 'inactive';
			$unique_id          = $account['unique_id'] ?? '';
			$submitted_unique_id = $unique_id;
			if (empty($unique_id)) {
				// Back-fill missing IDs here, on save, rather than while rendering the settings page.
				$unique_id = $this->nft_checkout_get_unique_id();
			}
			$checkout_title      = $account['checkout_title'] ?? '';
	        $checkout_subtitle   = $account['checkout_subtitle'] ?? '';

			// Write-only secret key handling: preserve existing secret if submitted value is empty
			$existing_account = null;
			if (!empty($unique_id) && isset($existing_by_id[$unique_id])) {
				$existing_account = $existing_by_id[$unique_id];
			} elseif ($submitted_unique_id === '' && isset($existing_by_id['idx_' . $raw_index])) {
				// Only an account saved before ids existed is matched by position.
				// A new account must never pick up another account's secret key.
				$existing_account = $existing_by_id['idx_' . $raw_index];
			}

			if (empty($live_secret_key) && $existing_account && !empty($existing_account['live_secret_key'])) {
				$live_secret_key = $existing_account['live_secret_key'];
			}
			if ($has_sandbox) {
				if (empty($sandbox_secret_key) && $existing_account && !empty($existing_account['sandbox_secret_key']) && !empty($sandbox_public_key)) {
					$sandbox_secret_key = $existing_account['sandbox_secret_key'];
				}
			} else {
				$sandbox_public_key = '';
				$sandbox_secret_key = '';
				$sandbox_status     = 'inactive';
			}

			if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
				continue;
			}

			if (empty($account_title)) {
				$errors[] = __('Give each payment account a name, for example "Main account".', 'nft-checkout-payment-gateway');
				NFT_Checkout_Payment_Gateway_Logger::info('Validation failed: an account has no name.');
				continue;
			}

			// A business may start with test keys only, live keys only, or both.
			$has_live_keys    = !empty($live_public_key) && !empty($live_secret_key);
			$has_sandbox_keys = $has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key);

			if (!$has_live_keys && (!empty($live_public_key) || !empty($live_secret_key))) {
				/* translators: %s: Account name */
				$errors[] = sprintf(__('Account "%s": paste both live keys (publishable and secret), or leave both live boxes empty to test with sandbox keys only.', 'nft-checkout-payment-gateway'), $account_title);
				NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: incomplete live keys for account '{$account_title}'");
				continue;
			}

			if ($has_sandbox && !$has_sandbox_keys) {
				/* translators: %s: Account name */
				$errors[] = sprintf(__('Account "%s": paste both sandbox keys (publishable and secret), or untick "I have sandbox (test) keys".', 'nft-checkout-payment-gateway'), $account_title);
				NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: missing sandbox keys for account '{$account_title}'");
				continue;
			}

			if (!$has_live_keys && !$has_sandbox_keys) {
				/* translators: %s: Account name */
				$errors[] = sprintf(__('Account "%s": add your sandbox keys to test, or your live keys to take real payments. You can copy them in RT under Developers.', 'nft-checkout-payment-gateway'), $account_title);
				NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: no keys for account '{$account_title}'");
				continue;
			}

			if ($has_live_keys) {
				$live_combined = $live_public_key . '|' . $live_secret_key;
				if (in_array($live_combined, $unique_live_keys, true)) {
					/* translators: %s: Account name */
					$errors[] = sprintf(__('Account "%s": these live keys are already used by another account on this page. Remove one of the two accounts.', 'nft-checkout-payment-gateway'), $account_title);
					NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: duplicate live keys for account '{$account_title}'");
					continue;
				}

				if ($live_public_key === $live_secret_key) {
					/* translators: %s: Account name */
					$errors[] = sprintf(__('Account "%s": the live publishable key and live secret key are the same. Paste your secret key in the second box.', 'nft-checkout-payment-gateway'), $account_title);
					NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: live keys are identical for account '{$account_title}'");
					continue;
				}

				$unique_live_keys[] = $live_combined;
				if ($live_status === '') {
					$live_status = 'unknown';
				}
			} else {
				$live_status = 'inactive';
			}

			if ($has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
				$sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;
				if (in_array($sandbox_combined, $unique_sandbox_keys, true)) {
					/* translators: %s: Account name */
					$errors[] = sprintf(__('Account "%s": these sandbox keys are already used by another account on this page. Remove one of the two accounts.', 'nft-checkout-payment-gateway'), $account_title);
					NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: duplicate sandbox keys for account '{$account_title}'");
					continue;
				}
				if ($sandbox_public_key === $sandbox_secret_key) {
					/* translators: %s: Account name */
					$errors[] = sprintf(__('Account "%s": the sandbox publishable key and sandbox secret key are the same. Paste your sandbox secret key in the second box.', 'nft-checkout-payment-gateway'), $account_title);
					NFT_Checkout_Payment_Gateway_Logger::info("Validation failed: sandbox keys are identical for account '{$account_title}'");
					continue;
				}
				$unique_sandbox_keys[] = $sandbox_combined;
			}

			// The status is what RT last told us about these exact keys. It is
			// never taken from the submitted form, and it starts again as
			// "not checked yet" whenever a key changes.
			$live_unchanged = $existing_account
				&& hash_equals((string) ($existing_account['live_public_key'] ?? ''), (string) $live_public_key)
				&& hash_equals((string) ($existing_account['live_secret_key'] ?? ''), (string) $live_secret_key);
			$sandbox_unchanged = $existing_account
				&& hash_equals((string) ($existing_account['sandbox_public_key'] ?? ''), (string) $sandbox_public_key)
				&& hash_equals((string) ($existing_account['sandbox_secret_key'] ?? ''), (string) $sandbox_secret_key);

			if (!$has_live_keys) {
				$live_status = 'inactive';
			} else {
				$live_status = $live_unchanged ? sanitize_key((string) ($existing_account['live_status'] ?? '')) : 'unknown';
				if ($live_status === '') {
					$live_status = 'unknown';
				}
			}
			if (!$has_sandbox_keys) {
				$sandbox_status = 'inactive';
			} else {
				$sandbox_status = $sandbox_unchanged ? sanitize_key((string) ($existing_account['sandbox_status'] ?? '')) : 'unknown';
				if ($sandbox_status === '') {
					$sandbox_status = 'unknown';
				}
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
			// Nothing to check and nothing wrong: the list is simply empty (a new
			// install, or the business removed its last account). Save it, and
			// say what to do next. Checkout hides card payment until an
			// approved account exists, so shoppers are never offered it.
			update_option(NFT_CHECKOUT_ACCOUNTS_OPTION, [], false);
			$this->admin_notices->nft_checkout_add_notice(
				'no_accounts',
				'notice notice-warning',
				__('Add a payment account to accept card payments. Card payment stays hidden at checkout until an account is added and approved.', 'nft-checkout-payment-gateway')
			);
			add_action('admin_notices', [$this->admin_notices, 'display_notices']);
			return;
		}

		if (empty($errors)) {
			update_option(NFT_CHECKOUT_ACCOUNTS_OPTION, $valid_accounts, false);

			NFT_Checkout_Payment_Gateway_Logger::info('Account settings updated successfully.', ['count' => count($valid_accounts)]);

			if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader') && nft_checkout_is_configured()) {
				$loader = NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance();
				if (method_exists($loader, 'handle_cron_event')) {
					$loader->handle_cron_event();
					NFT_Checkout_Payment_Gateway_Logger::info('Triggered NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::handle_cron_event() after settings save.');

					// Say plainly whether RT accepted the keys that were just saved.
					$sync_problem = method_exists($loader, 'nft_checkout_last_sync_problem') ? $loader->nft_checkout_last_sync_problem($this->sandbox) : '';
					if ($sync_problem !== '') {
						$this->admin_notices->nft_checkout_add_notice('sync_problem', 'notice notice-error', $sync_problem);
					}
				}
			}
		} else {
			$this->admin_notices->nft_checkout_add_notice(
				'settings_error_intro',
				'notice notice-error',
				__('Your payment accounts were not saved. Fix the following and save again:', 'nft-checkout-payment-gateway')
			);
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
			update_option(NFT_CHECKOUT_ACCOUNTS_OPTION, $accounts);
		}
		return $accounts;
	}

	public function nft_checkout_get_form_fields() {
		$dev_instructions_link = sprintf(
			'<strong><a class="nft-checkout-instructions-url" href="%s" target="_blank" rel="noopener noreferrer">%s</a></strong><br>',
			esc_url($this->base_url . '/developer'),
			esc_html__('Open the Developers page in RT', 'nft-checkout-payment-gateway')
		);

		return apply_filters('nft_checkout_woocommerce_gateway_settings_fields_' . $this->id, [

			'enabled' => [
				'title'   => __('Turn on', 'nft-checkout-payment-gateway'),
				'label'   => __('Offer card payment at checkout', 'nft-checkout-payment-gateway'),
				'type'    => 'checkbox',
				'default' => 'no',
			],

			'title' => [
				'title'       => __('Title', 'nft-checkout-payment-gateway'),
				'type'        => 'text',
				'description' => __('The name shoppers see for this payment method at checkout.', 'nft-checkout-payment-gateway'),
				'default'     => __('Pay by card', 'nft-checkout-payment-gateway'),
				'desc_tip'    => true,
			],

			'description' => [
				'title'       => __('Description', 'nft-checkout-payment-gateway'),
				'type'        => 'textarea',
				'description' => __('A short note shoppers see under the title at checkout.', 'nft-checkout-payment-gateway'),
				'default'     => __('Pay securely by card. After you place the order, we email you a secure link from RT to pay.', 'nft-checkout-payment-gateway'),
				'desc_tip'    => true,
			],

			'instructions' => [
				'title'       => __('How to set up', 'nft-checkout-payment-gateway'),
				'type'        => 'title',
				'description' => wp_kses(
					__('1. Copy your keys from RT (Developers).', 'nft-checkout-payment-gateway') . ' ' . $dev_instructions_link .
					esc_html__('2. Under Payment accounts below, press Add account and paste the keys.', 'nft-checkout-payment-gateway') . '<br>' .
					esc_html__('3. Press Save changes. The account status shows whether RT accepted the keys.', 'nft-checkout-payment-gateway') . '<br><br>' .
					'<strong>' . esc_html__('Refunds:', 'nft-checkout-payment-gateway') . '</strong> ' .
					esc_html__('refund a card payment in RT, not in WooCommerce. Open the payment in your RT account and refund it there.', 'nft-checkout-payment-gateway'),
					['a' => ['href' => true, 'target' => true, 'class' => true, 'rel' => true], 'strong' => [], 'br' => []]
				),
			],

			'sandbox' => [
				'title'       => __('Test mode', 'nft-checkout-payment-gateway'),
				'label'       => __('Use sandbox (test) keys', 'nft-checkout-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('While this is on, no real money moves. Turn it off before taking real orders.', 'nft-checkout-payment-gateway'),
				'default'     => 'no',
			],

			'group_id' => [
				'type' => 'hidden',
			],

			'accounts' => [
				'title'       => __('Payment accounts', 'nft-checkout-payment-gateway'),
				'type'        => 'accounts_repeater',
				'description' => __('The RT account that receives the money. Most shops need one.', 'nft-checkout-payment-gateway'),
			],

			'order_status' => [
				'title'       => __('Order status when paid', 'nft-checkout-payment-gateway'),
				'type'        => 'select',
				'description' => __('The status an order moves to once the shopper has paid.', 'nft-checkout-payment-gateway'),
				'default'     => '',
				'id'          => 'order_status_select',
				'desc_tip'    => true,
				'options'     => [
					'processing' => __('Processing', 'nft-checkout-payment-gateway'),
					'completed'  => __('Completed', 'nft-checkout-payment-gateway'),
				],
			],

			'show_consent_checkbox' => [
				'title'       => __('Consent tick box', 'nft-checkout-payment-gateway'),
				'label'       => __('Ask shoppers to agree before paying', 'nft-checkout-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('Adds a consent tick box for shoppers at checkout.', 'nft-checkout-payment-gateway'),
				'default'     => 'no',
			],

			'share_plugin_status' => [
				'title'       => __('Share plugin status with RT', 'nft-checkout-payment-gateway'),
				'label'       => __('Send the plugin status to RT', 'nft-checkout-payment-gateway'),
				'type'        => 'checkbox',
				'description' => __('When this is on, the plugin tells RT when it is turned on or off, when its settings are saved and after each check of your keys. It sends your shop address, the plugin, WordPress and WooCommerce versions, and your account names and publishable keys. Secret keys and shopper details are never sent. Off unless you turn it on.', 'nft-checkout-payment-gateway'),
				'default'     => 'no',
			],

		], $this);
	}

	public function generate_accounts_repeater_html($key, $data) {
		$option_value    = nft_checkout_get_accounts();
		$sandbox_enabled = nft_checkout_is_sandbox();

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

		// Rendering never writes options; a missing id is saved by the save handler.

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<p class="description" style="margin-bottom: 10px;"><?php echo esc_html($data['description'] ?? ''); ?></p>
				<div id="nft-checkout-global-error" class="nft-checkout-global-error" style="color: #b32d2e; margin-bottom: 10px;" role="alert"></div>
				<div class="nft-checkout-accounts-container">
					<?php if (!empty($option_value)): ?>
						<div class="nft-checkout-sync-account">
							<span id="nft-checkout-sync-status"></span>
							<button type="button" class="button" id="nft-checkout-sync-accounts"><span><i class="fa fa-refresh" aria-hidden="true"></i></span> <?php esc_html_e('Check with RT', 'nft-checkout-payment-gateway'); ?></button>
						</div>
					<?php endif; ?>

					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No payment account yet. Press "Add account" and paste your RT keys.', 'nft-checkout-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<?php
							$live_status    = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							$unique_id      = (!empty($account['unique_id'])) ? $account['unique_id'] : '';
							?>
							<div class="nft-checkout-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" class="live-status" name="accounts[<?php echo esc_attr($index); ?>][live_status]" value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" class="sandbox-status" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]" value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
								<div class="title-blog">
									<h4>
										<span class="account-name-display nft-checkout-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('New account', 'nft-checkout-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down nft-checkout-toggle-btn account-toggle-btn" aria-hidden="true"></i>
									</h4>
									<div class="action-button">
										<div class="account-status-block" style="float: right;">
											<?php $shown_status = nft_checkout_account_status_view($sandbox_enabled ? $sandbox_status : $live_status, $sandbox_enabled); ?>
											<span class="account-status-label <?php echo esc_attr($shown_status['class']); ?>" title="<?php echo esc_attr($shown_status['help']); ?>">
												<?php echo esc_html($shown_status['label']); ?>
											</span>
										</div>
										<button type="button" class="delete-account-btn" aria-label="<?php esc_attr_e('Remove this account', 'nft-checkout-payment-gateway'); ?>" title="<?php esc_attr_e('Remove this account', 'nft-checkout-payment-gateway'); ?>">
											<i class="fa fa-trash" aria-hidden="true"></i>
										</button>
									</div>
								</div>

								<div class="nft-checkout-info account-info">
									<div class="add-blog title-priority">
										<div class="account-input account-name">
											<label><?php esc_html_e('Account name (only you see this)', 'nft-checkout-payment-gateway'); ?></label>
											<input type="text" class="account-title" name="accounts[<?php echo esc_attr($index); ?>][title]" placeholder="<?php esc_attr_e('For example: Main account', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div>
											<input type="hidden" class="unique-id" name="accounts[<?php echo esc_attr($index); ?>][unique_id]" value="<?php echo esc_attr($unique_id); ?>" readonly>
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Order of use (1 is used first)', 'nft-checkout-payment-gateway'); ?></label>
											<input type="number" class="account-priority" name="accounts[<?php echo esc_attr($index); ?>][priority]" placeholder="<?php esc_attr_e('Priority', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>

									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout title (optional)', 'nft-checkout-payment-gateway'); ?></label>
											<input type="text"
												name="accounts[<?php echo esc_attr($index); ?>][checkout_title]"
												placeholder="<?php esc_attr_e('Leave empty to use the title above', 'nft-checkout-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['checkout_title'] ?? ''); ?>">
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Checkout note (optional)', 'nft-checkout-payment-gateway'); ?></label>
											<textarea
												name="accounts[<?php echo esc_attr($index); ?>][checkout_subtitle]"
												placeholder="<?php esc_attr_e('Leave empty to use the description above', 'nft-checkout-payment-gateway'); ?>"
												rows="2"><?php echo esc_textarea($account['checkout_subtitle'] ?? ''); ?></textarea>
										</div>
									</div>

									<div class="add-blog">
										<div class="account-input">
											<label><?php esc_html_e('Live keys (for real payments)', 'nft-checkout-payment-gateway'); ?></label>
											<input type="text" class="live-public-key" name="accounts[<?php echo esc_attr($index); ?>][live_public_key]" placeholder="<?php esc_attr_e('Publishable key', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<?php
											// The saved secret key is never printed into the page. Leave the box
											// empty to keep it; type a new key to replace it.
											$live_secret_saved = !empty($account['live_secret_key']);
											$live_secret_hint  = $live_secret_saved
												? (str_repeat('•', 8) . substr((string) $account['live_secret_key'], -4))
												: __('Secret key', 'nft-checkout-payment-gateway');
											?>
											<div class="secret-key-wrapper" style="position: relative; display: flex; align-items: center;">
												<input type="password" autocomplete="new-password" spellcheck="false" class="live-secret-key" name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]" placeholder="<?php echo esc_attr($live_secret_hint); ?>" value="" data-has-existing="<?php echo $live_secret_saved ? '1' : '0'; ?>" style="padding-right: 30px;">
												<button type="button" class="toggle-secret-visibility" style="position: absolute; right: 15px; top: calc(50% - 4px); background: none; border: none; cursor: pointer; color: #555; padding: 0; outline: none;" title="<?php esc_attr_e('Show or hide the secret key', 'nft-checkout-payment-gateway'); ?>" aria-label="<?php esc_attr_e('Show or hide the secret key', 'nft-checkout-payment-gateway'); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button>
											</div>
										</div>
									</div>

									<div class="account-checkbox">
										<?php
										$checkbox_id    = $this->id . '-sandbox-checkbox-' . $index;
										$checkbox_class = $this->id . '-sandbox-checkbox';
										?>
										<input type="checkbox" class="<?php echo esc_attr($checkbox_class); ?>" id="<?php echo esc_attr($checkbox_id); ?>" name="accounts[<?php echo esc_attr($index); ?>][has_sandbox]" <?php checked(($account['has_sandbox'] ?? '') === 'on'); ?>>
										<label for="<?php echo esc_attr($checkbox_id); ?>"><?php esc_html_e('I have sandbox (test) keys', 'nft-checkout-payment-gateway'); ?></label>
									</div>

									<?php
									$sandbox_container_id    = $this->id . '-sandbox-keys-' . $index;
									$sandbox_container_class = $this->id . '-sandbox-keys';
									$sandbox_display_style   = ($account['has_sandbox'] ?? '') === 'on' ? '' : 'display: none;';
									?>
									<div id="<?php echo esc_attr($sandbox_container_id); ?>" class="<?php echo esc_attr($sandbox_container_class); ?>" style="<?php echo esc_attr($sandbox_display_style); ?>">
										<div class="add-blog">
											<div class="account-input">
												<label><?php esc_html_e('Sandbox keys (for testing)', 'nft-checkout-payment-gateway'); ?></label>
												<input type="text" class="sandbox-public-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]" placeholder="<?php esc_attr_e('Publishable key', 'nft-checkout-payment-gateway'); ?>" value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
											</div>
											<div class="account-input">
												<?php
											// The saved secret key is never printed into the page. Leave the box
											// empty to keep it; type a new key to replace it.
											$sandbox_secret_saved = !empty($account['sandbox_secret_key']);
											$sandbox_secret_hint  = $sandbox_secret_saved
												? (str_repeat('•', 8) . substr((string) $account['sandbox_secret_key'], -4))
												: __('Secret key', 'nft-checkout-payment-gateway');
											?>
											<div class="secret-key-wrapper" style="position: relative; display: flex; align-items: center;">
												<input type="password" autocomplete="new-password" spellcheck="false" class="sandbox-secret-key" name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]" placeholder="<?php echo esc_attr($sandbox_secret_hint); ?>" value="" data-has-existing="<?php echo $sandbox_secret_saved ? '1' : '0'; ?>" style="padding-right: 30px;">
												<button type="button" class="toggle-secret-visibility" style="position: absolute; right: 15px; top: calc(50% - 4px); background: none; border: none; cursor: pointer; color: #555; padding: 0; outline: none;" title="<?php esc_attr_e('Show or hide the secret key', 'nft-checkout-payment-gateway'); ?>" aria-label="<?php esc_attr_e('Show or hide the secret key', 'nft-checkout-payment-gateway'); ?>"><i class="fa fa-eye" aria-hidden="true"></i></button>
											</div>
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
							<span>+</span> <?php esc_html_e('Add account', 'nft-checkout-payment-gateway'); ?>
						</button>
					</div>
				</div>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function process_payment($order_id)
	{
		$result = $this->nft_checkout_do_process_payment($order_id);

		// NFT-11: the block checkout goes through the Store API, which reports a
		// refusal to the shopper only as an exception.
		if (($result['result'] ?? '') !== 'success' && $this->is_store_api_request()) {
			throw new \Exception(esc_html($result['message'] ?: __('We could not start your card payment. You have not been charged. Please try again in a moment, or choose another payment method.', 'nft-checkout-payment-gateway')));
		}

		return $result;
	}

	private function nft_checkout_do_process_payment($order_id)
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
				wc_add_notice(__('Something went wrong with your order. Please try again, or contact the shop.', 'nft-checkout-payment-gateway'), 'error');
			}

			return $this->build_response(
				'failure',
				'Invalid order.',
				[],
				400,
				$order_id
			);
		}

		if (!nft_checkout_is_gateway_order($order)) {
			return $this->build_response('failure', __('Card payment cannot be used for this order. Please choose another payment method.', 'nft-checkout-payment-gateway'), [], 400, $order_id);
		}

		// NFT-06: an order that no longer needs payment (paid, cancelled, refunded)
		// is never reset to pending and never gets another voucher.
		if (!$order->needs_payment()) {
			NFT_Checkout_Payment_Gateway_Logger::warning(
				$log_prefix . ' Payment refused: the order does not need payment',
				['order_id' => $order_id, 'status' => $order->get_status()]
			);
			$message = __('This order is already paid or closed, so there is nothing to pay. Check your order page, or contact the shop.', 'nft-checkout-payment-gateway');
			if (!$this->is_store_api_request() && is_checkout()) {
				wc_add_notice($message, 'error');
			}
			return $this->build_response('failure', $message, [], 400, $order_id);
		}

		NFT_Checkout_Payment_Gateway_Logger::info(
			"Payment initiated",
			[
				'order_id'       => $order_id,
				'status'         => $order->get_status(),
				'plugin_version' => defined('NFT_CHECKOUT_PLUGIN_VERSION') ? NFT_CHECKOUT_PLUGIN_VERSION : '',
			]
		);

		$lock_name = 'nft_checkout_order_' . $order_id;

		// Try lock
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory MySQL lock cannot be cached.
		$lock_result = $wpdb->get_var(
			$wpdb->prepare("SELECT GET_LOCK(%s, 3)", $lock_name)
		);

		if ((string)$lock_result !== '1') {
			return $this->build_response(
				'failure',
				'Payment already in progress. Please wait a few seconds and try again.',
				[],
				409,
				$order_id
			);
		}

		try {

			// -------------------------------------------------
			// 4. RATE LIMITING (NFT-14)
			// -------------------------------------------------
			// Counted per WooCommerce session (else per client IP as WooCommerce
			// resolves it), never per shared REMOTE_ADDR, and - because every
			// allowed call e-mails a voucher - per order and per address as well.
			$limits = [
				['key' => 'rt_nft_rl_s_' . md5(nft_checkout_rate_limit_subject()), 'max' => 5, 'window' => 10],
				['key' => 'rt_nft_rl_o_' . $order_id, 'max' => 3, 'window' => 10 * MINUTE_IN_SECONDS],
				['key' => 'rt_nft_rl_e_' . md5(strtolower((string) $order->get_billing_email())), 'max' => 5, 'window' => 10 * MINUTE_IN_SECONDS],
			];
			$current_time = time();
			foreach ($limits as $limit) {
				$timestamps = get_transient($limit['key']) ?: [];
				$timestamps = array_values(array_filter((array) $timestamps, fn($ts) => $current_time - (int) $ts <= $limit['window']));
				if (count($timestamps) >= $limit['max']) {
					NFT_Checkout_Payment_Gateway_Logger::warning($log_prefix . ' Rate limit exceeded', ['order_id' => $order_id]);
					if (!$this->is_store_api_request() && is_checkout()) {
						wc_add_notice(__('Too many tries in a short time. Please wait a few minutes and try again.', 'nft-checkout-payment-gateway'), 'error');
					}
					return $this->build_response('failure', __('Too many tries in a short time. Please wait a few minutes and try again.', 'nft-checkout-payment-gateway'), [], 429, $order_id);
				}
				$timestamps[] = $current_time;
				set_transient($limit['key'], $timestamps, $limit['window']);
			}

			// -------------------------------------------------
			// 5. ORDER STATUS PROTECTION & DUPLICATE PAYMENT PREVENTION
			// -------------------------------------------------
			if ($this->nft_checkout_is_duplicate_payment($order)) {
				$duplicate_warning = __('It looks like you already paid for this order, so we did not start a second payment. Check your email or your order page. If you were not charged, contact the shop.', 'nft-checkout-payment-gateway');
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
					'failure',
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
						__('This is a test order. No real money moves.', 'nft-checkout-payment-gateway')
					);
				}
			}

			// -------------------------------------------------
			// 7. VOUCHER EMAIL (SENT BY RT)
			// -------------------------------------------------
			// Checkout creates no payment link. RT emails the customer a voucher,
			// and the payment link behind its button is created when they open it.
			// All this plugin does is ask for that email and repeat what RT says
			// about it.
			if (!$order->has_status('pending')) {
				$order->update_status('pending', __('Waiting for the customer to pay with the link RT emailed them.', 'nft-checkout-payment-gateway'));
			}

			$voucher = $this->nft_checkout_request_voucher_email($order);

			if (!$voucher['success']) {
				delete_transient($active_lock_key);

				$voucher_error = $voucher['message'];

				// Classic checkout only relays queued notices in its failure response.
				if (!$this->is_block_checkout_request() && is_checkout()) {
					wc_add_notice($voucher_error, 'error');
				}

				return $this->build_response(
					'failure',
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

			// NFT-12: 'redirect' sits at the top level, where WooCommerce (the
			// order-pay page, the Store API) reads it. The classic checkout script
			// shows the order-received panel in place from data.order_received.
			$response = $this->build_response(
				'success',
				$voucher['message'],
				$this->is_store_api_request() ? ['payment_status' => 'pending'] : [
					'payment_status' => 'pending',
					'order_received' => [
						'order_number' => $order->get_order_number(),
						'email'        => $order->get_billing_email(),
						'items'        => $this->nft_checkout_get_summary_rows($order),
						'amount_due'   => $this->nft_checkout_plain_price($order->get_total(), $order),
						'site_name'    => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
						// Shown on the checkout exactly as RT worded it.
						'message'      => $voucher['message'],
						'reference'    => $voucher['data']['reference'] ?? '',
						'voucher_id'   => $voucher['data']['voucher_id'] ?? '',
						// The email button's own link, so "Not receiving the email?
						// Click here" opens exactly what the email would.
						'purchase_url' => $purchase_url,
					],
				],
				200,
				$order_id
			);
			$response['redirect'] = $order->get_checkout_order_received_url();

			return $response;

			} catch (\Exception $e) {

				NFT_Checkout_Payment_Gateway_Logger::error(
					"Payment processing exception: " . $e->getMessage(),
					[
						'order_id' => $order_id ?? null,
						'file'     => basename((string) $e->getFile()),
						'line'     => $e->getLine(),
					]
				);

				return $this->build_response('failure', __('Something went wrong on our side while starting your card payment. You have not been charged. Please try again, or contact the shop.', 'nft-checkout-payment-gateway'), [], 500, $order_id);

			} finally {

				delete_transient('nft_checkout_payment_active_' . $order_id);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Advisory MySQL lock release cannot be cached.
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
			'result'   => $result, // success | failure
			'message'  => $message,
			'data'     => $data,
			'order_id' => $order_id,
			'code'     => $code,
			'success'  => $result === 'success',
		];
	}

	/**
	 * Ask RT to email the customer their voucher.
	 *
	 * The email is RT's, not this plugin's: there is no template, no
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
				'message' => __('Card payment is not available right now. You have not been charged. Please choose another payment method, or contact the shop.', 'nft-checkout-payment-gateway'),
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
			// for exactly the order the payment will be for. NFT-05: the secret
			// never travels in it; every request below is signed with it instead.
			$data = $this->nft_checkout_prepare_payment_data($order, $public_key);

			if (is_array($data) && ($data['result'] ?? '') === 'fail') {

				$last_message = sanitize_text_field($data['error'] ?? '');
				continue;
			}

			// When retrying after a failed, expired, or cancelled payment, we ensure a new
			// attempt reference is generated so a fresh voucher and payment link are created.
			$data['reference'] = $this->nft_checkout_voucher_reference($order);

			// --- START DAILY LIMIT CHECK ---
			$limit_url = $this->get_api_url('/dailylimit');

			$limit_body = wp_json_encode([
				'amount'     => $data['amount'],
				'is_sandbox' => $data['is_sandbox'],
				'curr_code'  => $data['curr_code'],
				'order_id'   => $data['order_id'],
			]);
			$limit_resp = wp_remote_post($limit_url, [
				'method'    => 'POST',
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => $limit_body,
				'headers'   => nft_checkout_signed_headers($public_key, $secret_key, $limit_body),
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
						'response'      => nft_checkout_log_excerpt($limit_data),
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

			$voucher_body = wp_json_encode($data);
			$response = wp_remote_post($api_url, [
				'method'    => 'POST',
				'timeout'   => 20,
				'body'      => $voucher_body,
				'headers'   => nft_checkout_signed_headers($public_key, $secret_key, $voucher_body),
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

				$last_message = __('We could not reach RT to start your card payment. You have not been charged. Please try again in a moment.', 'nft-checkout-payment-gateway');
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
				$dup_msg = __('It looks like you already paid for this order, so we did not start a second payment. Check your email or your order page. If you were not charged, contact the shop.', 'nft-checkout-payment-gateway');
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

			// RT's own wording is shown only when it is about this order (400 /
			// 422, for example an amount RT cannot take). Anything about the
			// shop's keys or RT's service is not the shopper's problem.
			$last_message = in_array($code, [400, 422], true) && $message !== ''
				? $message
				: __('Card payment is not available right now. You have not been charged. Please choose another payment method, or contact the shop.', 'nft-checkout-payment-gateway');

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
					'message' => __('This order is more than the shop can take by card in one payment. Please choose another payment method, or contact the shop.', 'nft-checkout-payment-gateway'),
					'data'    => [],
				];
			}

			$order->update_meta_data('_nft_checkout_limit_exceeded', true);
			$order->save();

			return [
				'success' => false,
				'message' => __('The shop cannot take more card payments today. Please choose another payment method, or try again tomorrow.', 'nft-checkout-payment-gateway'),
				'data'    => [],
			];
		}

		return [
			'success' => false,
			'message' => $last_message ?: __('We could not start your card payment. You have not been charged. Please try again in a moment, or choose another payment method.', 'nft-checkout-payment-gateway'),
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
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce is verified during checkout processing before duplicate payment check.
			if (!empty($_POST['billing_email'])) {
				$posted_email = strtolower(trim(sanitize_email(wp_unslash($_POST['billing_email']))));
			} elseif ($current_user_id > 0) {
				$current_user = wp_get_current_user();
				$posted_email = strtolower(trim((string) $current_user->user_email));
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
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
	 * Note on the order that RT has the voucher in hand.
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

		// NFT-01: keep every payment email sent for the order, so the payment
		// that follows a retry is recognised even though its id is new.
		nft_checkout_remember_voucher(
			$order,
			$reference,
			(string) ($voucher['voucher_id'] ?? ''),
			(string) ($voucher['account_public_key'] ?? '')
		);

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
					/* translators: 1: customer email address, 2: RT reference for the payment email */
					__('RT emailed the customer a link to pay by card, at %1$s (RT reference %2$s). The order is paid once they use it.', 'nft-checkout-payment-gateway'),
					$to,
					$reference
				)
				: sprintf(
					/* translators: %s: customer email address */
					__('RT emailed the customer a link to pay by card, at %s. The order is paid once they use it.', 'nft-checkout-payment-gateway'),
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
	}

	private function is_block_checkout_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only check of AJAX action name; nonce is verified in the AJAX handler.
		return wp_doing_ajax() && isset($_REQUEST['action'])
			&& sanitize_text_field(wp_unslash($_REQUEST['action'])) === 'nft_checkout_block_gateway_process';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	public function nft_checkout_display_test_order_tag($order) {
		if ($order instanceof WC_Order && $order->get_meta('_is_test_order', true)) {
			echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Test order: no real money was received.', 'nft-checkout-payment-gateway') . '</strong></p>';
		}
		// Refunds are made in RT by design; say so where the shop owner looks for the button.
		if ($order instanceof WC_Order && nft_checkout_is_gateway_order($order)) {
			echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Refunds:', 'nft-checkout-payment-gateway') . '</strong> ' .
				esc_html__('to refund this card payment, open it in your RT account and refund it there. The Refund button here only records a refund in WooCommerce; it does not send money back.', 'nft-checkout-payment-gateway') . '</p>';
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

	private function nft_checkout_prepare_payment_data($order, $api_public_key) {
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

		$plugin_version = defined('NFT_CHECKOUT_PLUGIN_VERSION') ? NFT_CHECKOUT_PLUGIN_VERSION : '';

		$meta_data_array = array_map('sanitize_text_field', [
			'order_id'       => $order_id,
			'amount'         => $amount,
			'source'         => 'woocommerce',
			'plugin_version' => $plugin_version,
		]);

		$user_id = $order->get_customer_id();
		$is_guest = empty($user_id) ? 1 : 0;

		// NFT-05: no 'api_secret' here, ever. The request is signed with it.
		return [
			'order_id'         => $order_id,
			'plugin_version'   => $plugin_version,
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
			'plugin_source'    => 'nft-checkout',
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
		echo '<div class="error"><p>' . esc_html__('NFT Checkout needs WooCommerce. Install and activate WooCommerce first.', 'nft-checkout-payment-gateway') . '</p></div>';
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
				wc_add_notice(__('This page was open for too long. Please refresh it and try again.', 'nft-checkout-payment-gateway'), 'error');
				return false;
			}
			$consent = isset($_POST['nft_checkout_consent']) ? sanitize_text_field(wp_unslash($_POST['nft_checkout_consent'])) : '';
			if ($consent !== 'on') {
				wc_add_notice(__('Please tick the consent box to pay by card.', 'nft-checkout-payment-gateway'), 'error');
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
		$status_texts = [];
		foreach (nft_checkout_account_status_texts() as $status_key => $status_text) {
			$status_texts[$status_key] = ['label' => $status_text[0], 'help' => $status_text[1]];
		}
		wp_localize_script('nft-checkout-admin-script', 'nft_checkout_admin_data', [
			'ajax_url'    => admin_url('admin-ajax.php'),
			'nonce'       => wp_create_nonce('nft_checkout_sync_nonce'),
			'gateway_id'  => $this->id,
			'sync_action' => 'nft_checkout_manual_sync',
			'statuses'    => $status_texts,
			'i18n'        => [
				'sandbox'            => __('Sandbox', 'nft-checkout-payment-gateway'),
				'live'               => __('Live', 'nft-checkout-payment-gateway'),
				'new_account'        => __('New account', 'nft-checkout-payment-gateway'),
				'account_name'       => __('Account name (only you see this)', 'nft-checkout-payment-gateway'),
				'account_name_hint'  => __('For example: Main account', 'nft-checkout-payment-gateway'),
				'order_of_use'       => __('Order of use (1 is used first)', 'nft-checkout-payment-gateway'),
				'checkout_title'     => __('Checkout title (optional)', 'nft-checkout-payment-gateway'),
				'checkout_title_hint' => __('Leave empty to use the title above', 'nft-checkout-payment-gateway'),
				'checkout_note'      => __('Checkout note (optional)', 'nft-checkout-payment-gateway'),
				'checkout_note_hint' => __('Leave empty to use the description above', 'nft-checkout-payment-gateway'),
				'live_keys'          => __('Live keys (for real payments)', 'nft-checkout-payment-gateway'),
				'sandbox_keys'       => __('Sandbox keys (for testing)', 'nft-checkout-payment-gateway'),
				'publishable_key'    => __('Publishable key', 'nft-checkout-payment-gateway'),
				'secret_key'         => __('Secret key', 'nft-checkout-payment-gateway'),
				'show_hide'          => __('Show or hide the secret key', 'nft-checkout-payment-gateway'),
				'have_sandbox'       => __('I have sandbox (test) keys', 'nft-checkout-payment-gateway'),
				'remove'             => __('Remove this account', 'nft-checkout-payment-gateway'),
				'confirm_remove'     => __('Remove this account? Its keys are deleted from this site when you press Save changes.', 'nft-checkout-payment-gateway'),
				'removed_last'       => __('No payment account is left. Press Save changes to confirm; card payment will be hidden at checkout until you add one.', 'nft-checkout-payment-gateway'),
				'new_account_hint'   => __('Paste your keys, then press Save changes.', 'nft-checkout-payment-gateway'),
				'checking'           => __('Checking with RT...', 'nft-checkout-payment-gateway'),
				'check_done'         => __('Checked with RT.', 'nft-checkout-payment-gateway'),
				'check_failed'       => __('We couldn\'t connect to RT with these keys. Check that the publishable and secret keys are from the same RT account and the same mode (Sandbox or Live).', 'nft-checkout-payment-gateway'),
				'check_unreachable'  => __('We could not reach RT. Check your internet connection and try again in a moment.', 'nft-checkout-payment-gateway'),
				'fix_errors'         => __('Some boxes need your attention. Fix the ones marked in red, then save again.', 'nft-checkout-payment-gateway'),
				'need_title'         => __('Enter a name for this payment method. Shoppers see it at checkout.', 'nft-checkout-payment-gateway'),
				'need_description'   => __('Add a short note for shoppers, for example "Pay securely by card".', 'nft-checkout-payment-gateway'),
				'need_name'          => __('Give this account a name, for example "Main account".', 'nft-checkout-payment-gateway'),
				'name_used'          => __('Another account already uses this name. Choose a different one.', 'nft-checkout-payment-gateway'),
				'need_order'         => __('Enter a number. 1 is used first.', 'nft-checkout-payment-gateway'),
				'order_used'         => __('Another account already uses this number. Give each account its own.', 'nft-checkout-payment-gateway'),
				'need_keys'          => __('Add your sandbox keys to test, or your live keys to take real payments.', 'nft-checkout-payment-gateway'),
				'need_both_live'     => __('Paste both live keys, or leave both boxes empty to use sandbox keys only.', 'nft-checkout-payment-gateway'),
				'need_both_sandbox'  => __('Paste both sandbox keys, or untick "I have sandbox (test) keys".', 'nft-checkout-payment-gateway'),
				'key_used'           => __('This key is already used in another box on this page. Each box needs its own key.', 'nft-checkout-payment-gateway'),
				'same_keys'          => __('The publishable key and the secret key are the same. Paste your secret key here.', 'nft-checkout-payment-gateway'),
			],
		]);
	}

	public function nft_checkout_hide_custom_payment_gateway_conditionally($available_gateways)
	{
		$gateway_id = $this->id;
		$this->selected_account_for_display = null;

		// Nothing to decide when this method is not on offer (switched off,
		// or already removed by WooCommerce or another plugin).
		if (!is_array($available_gateways) || !isset($available_gateways[$gateway_id])) {
			return $available_gateways;
		}

		// =====================================================
		// STEP 1: SAFE CART CHECK
		// =====================================================
		if (!function_exists('WC') || !WC()->cart) {
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
				"NFT Checkout Gateway Decision",
				[
					'result' => 'HIDDEN',
					'reason' => 'No approved payment account for the current mode',
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
		$reason   = 'No eligible payment account';

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
				'is_sandbox'     => $this->sandbox ? 1 : 0,
				'amount'         => $check_amount,
				'api_public_key' => $public,
			];

			$cache = 'rt_nft_checkout_' . md5($public . $check_amount);

			$status = $this->get_cached_api_response(
				$this->get_api_url('/check-merchant-status'),
				$data,
				$cache . '_status',
				300,
				$force_refresh,
				$secret
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
				300,
				$force_refresh,
				$secret
			);

			// Daily limit: only reject if API explicitly reported limit exceeded
			if (!empty($limit['max_limit_reached']) || (isset($limit['status']) && $limit['status'] === 'error' && isset($limit['code']) && $limit['code'] === 400 && str_contains(strtolower($limit['message'] ?? ''), 'limit'))) {
				continue;
			}

			if (!empty($limit['status']) && $limit['status'] === 'success') {
				$all_accounts_limited = false;
			}

			$selected = $account;
			$reason   = 'Valid payment account found';
			break;
		}

		// =====================================================
		// STEP 10: SINGLE FINAL LOG ONLY
		// =====================================================
		NFT_Checkout_Payment_Gateway_Logger::info(
			"NFT Checkout Gateway Decision",
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of WooCommerce AJAX endpoint name for logging context.
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



	/**
	 * A cached, signed call to a merchant-status / limit endpoint. NFT-05: the
	 * secret key only signs the request; it is never part of the body.
	 */
	private function get_cached_api_response($url, $data, $cache_key, $ttl = 120, $force_refresh = false, $secret_key = '') {
		if (!$force_refresh && isset($_GET['refresh_accounts']) && sanitize_text_field(wp_unslash($_GET['refresh_accounts'])) === '1' && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')) {
			$force_refresh = true;
		}
		if (!$force_refresh) {
			$cached = get_transient($cache_key);
			if ($cached !== false) return $cached;
		} else {
			delete_transient($cache_key);
		}
		unset($data['api_secret'], $data['api_secret_key'], $data['secret_key']);
		$raw_body = wp_json_encode($data);
		$response = wp_remote_post(esc_url_raw($url), [
			'method'    => 'POST',
			'timeout'   => 5,
			'body'      => $raw_body,
			'headers'   => nft_checkout_signed_headers($data['api_public_key'] ?? '', $secret_key, $raw_body),
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
		$accounts = nft_checkout_get_accounts();
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

	public function is_available() {
		if ('yes' !== $this->enabled) {
			return false;
		}
		return !empty($this->get_all_accounts());
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
		$accounts = $this->get_all_accounts();
		if (empty($accounts)) {
			return [];
		}

		usort($accounts, fn($a, $b) => ($a['priority'] ?? 1) <=> ($b['priority'] ?? 1));

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
				'is_sandbox'     => $this->sandbox ? 1 : 0,
				'amount'         => $amount,
				'api_public_key' => $public_key,
			];
			$cache_base  = 'rt_nft_checkout_daily_limit_' . md5($public_key . $amount);
			$status_data = $this->get_cached_api_response($accStatusApiUrl, $data, $cache_base . '_status', 300, $force_refresh, $secret_key);
			
			if (!empty($status_data['status']) && $status_data['status'] === 'success') {
				$user_account_active = true;
			}

			if (($status_data['status'] ?? '') !== 'success') {
				$this->log_info_once_per_session('skip_status_' . $acc_title, "Skipping '{$acc_title}': merchant status check failed", [
					'response_status' => $status_data['status'] ?? 'unknown',
				]);
				continue;
			}

			$limit_data = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_base . '_limit', 300, $force_refresh, $secret_key);
			
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

		if ($all_accounts_limited) {
			if (!isset($limit_data['max_limit_reached']) || $limit_data['max_limit_reached'] == false) {
				return [];
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
		$settings = nft_checkout_get_accounts();

		if (empty($settings)) {
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
