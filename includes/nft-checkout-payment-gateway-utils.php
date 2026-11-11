<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function nft_checkout_check_system_requirements()
{
	if (version_compare(phpversion(), NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_PHP_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required PHP version, %2$s is the current PHP version
			__('The NFT Checkout plugin requires PHP version %1$s or greater. You are running %2$s.', 'nft-checkout-payment-gateway'),
			NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_PHP_VER,
			phpversion()
		);
	}

	// Get WooCommerce versions
	$wc_db_version = get_option('woocommerce_db_version');
	$wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

	// Check if the WooCommerce database version is outdated
	if (!$wc_db_version || version_compare($wc_db_version, NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce database version, %2$s is the current WooCommerce database version (or "undefined" if not available)
			__('The NFT Checkout plugin requires WooCommerce database version %1$s or greater. You are running %2$s.', 'nft-checkout-payment-gateway'),
			NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_db_version ? $wc_db_version : __('undefined', 'nft-checkout-payment-gateway')
		);
	}

	// Check if WooCommerce plugin version is outdated
	if (!$wc_plugin_version || version_compare($wc_plugin_version, NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce plugin version, %2$s is the current WooCommerce plugin version (or "undefined" if not available)
			__('The NFT Checkout plugin requires WooCommerce plugin version %1$s or greater. You are running %2$s.', 'nft-checkout-payment-gateway'),
			NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_plugin_version ? $wc_plugin_version : __('undefined', 'nft-checkout-payment-gateway')
		);
	}

	return false;
}


if (!function_exists('nft_checkout_add_unique_order_note')) {

    function nft_checkout_add_unique_order_note($order, $key, $message)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        if (empty($message)) {
            return false;
        }

        // Plugin identifier (IMPORTANT for tracking in WP admin)
        $plugin_prefix = '<strong>NFT Checkout</strong>';

        // Unique meta key per note type (scoped to plugin)
        $meta_key = '_nft_checkout_order_note_' . sanitize_key($key);

        // Check if already exists
        $existing = $order->get_meta($meta_key, true);

        if (!empty($existing)) {
            return false;
        }

        // Prepend plugin identifier to every note
        $final_message = $plugin_prefix . "\n\n" . wp_kses_post($message);

        // Add WooCommerce order note
        $order->add_order_note($final_message);

        // Store timestamp using WooCommerce timezone-aware time
        $order->update_meta_data($meta_key, current_time('timestamp'));

        $order->save();

        return true;
    }
}

if (!function_exists('nft_checkout_get_order_payment_link_table')) {

	/**
	 * Dedicated payment-link table for NFT Checkout.
	 *
	 * @return string
	 */
	function nft_checkout_get_order_payment_link_table()
	{
		global $wpdb;

		return $wpdb->prefix . 'rt_nft_checkout_payment_links';
	}
}

if (!function_exists('nft_checkout_order_payment_link_table_exists')) {

	/**
	 * Whether the NFT Checkout payment-link table is present.
	 *
	 * @param bool $create Create the table when it is missing.
	 * @return bool
	 */
	function nft_checkout_order_payment_link_table_exists($create = false)
	{
		global $wpdb;

		$table_name  = nft_checkout_get_order_payment_link_table();
		$cache_key   = 'rt_nft_checkout_table_exists_' . md5($table_name);
		$cache_group = defined('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP') ? NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP : 'nft_checkout_payment_gateway';

		if (wp_cache_get($cache_key, $cache_group) === $table_name) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$table_exists = $wpdb->get_var(
			$wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
		);

		if ($table_exists === $table_name) {
			wp_cache_set($cache_key, $table_name, $cache_group, HOUR_IN_SECONDS);

			return true;
		}

		if (!$create) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$create_sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			uuid VARCHAR(100) NOT NULL,
			payment_link TEXT NOT NULL,
			customer_email VARCHAR(191) DEFAULT '',
			amount DECIMAL(18,2) DEFAULT 0.00,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_id (order_id)
		) {$charset_collate};";

		dbDelta($create_sql);

		wp_cache_set($cache_key, $table_name, $cache_group, HOUR_IN_SECONDS);

		NFT_Checkout_Payment_Gateway_Logger::info(
			"Created missing `{$table_name}` table.",
			[
				'source'  => 'nft-checkout-payment-gateway',
				'context' => ['table' => $table_name],
			]
		);

		return true;
	}
}

if (!function_exists('nft_checkout_get_order_payment_link')) {

	/**
	 * Fetch the stored payment-link record for an NFT Checkout order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return object|null
	 */
	function nft_checkout_get_order_payment_link($order_id)
	{
		global $wpdb;

		$order_id = absint($order_id);
		if ($order_id <= 0 || !nft_checkout_order_payment_link_table_exists(false)) {
			return null;
		}

		$cache_key   = 'rt_nft_checkout_payment_row_' . $order_id;
		$cache_group = defined('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP') ? NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP : 'nft_checkout_payment_gateway';
		$cached      = wp_cache_get($cache_key, $cache_group);
		if ($cached !== false) {
			return is_object($cached) ? $cached : null;
		}

		$table_name = nft_checkout_get_order_payment_link_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE order_id = %d LIMIT 1',
				$table_name,
				$order_id
			),
			OBJECT
		);

		if ($row) {
			wp_cache_set($cache_key, $row, $cache_group, 5 * MINUTE_IN_SECONDS);
			return $row;
		}

		return null;
	}
}

if (!function_exists('nft_checkout_store_order_payment_link')) {

	/**
	 * Record the payment link the application created for a NFT Checkout order.
	 *
	 * Checkout creates no payment link: the voucher flow creates the real one
	 * when the customer opens the button in their email, and the store first
	 * sees it when the application reports that payment back. Written the same
	 * way RT writes it - one row per order, refreshed when a later payment
	 * replaces an earlier one - because a cancellation reads its UUID from here.
	 *
	 * @param WC_Order $order Order the payment belongs to.
	 * @param array    $data  Payment fields: uuid, payment_link, customer_email, amount.
	 * @return bool Whether the order now has a payment-link row.
	 */
	function nft_checkout_store_order_payment_link($order, $data = [])
	{
		global $wpdb;

		if (!$order instanceof WC_Order) {
			return false;
		}

		$order_id = (int) $order->get_id();
		$uuid     = sanitize_text_field(is_scalar($data['uuid'] ?? null) ? (string) $data['uuid'] : '');

		/*
		 * The UUID is the whole point of the row: it is what the cancellation
		 * call identifies the payment link by. Nothing is written without it.
		 */
		if ($order_id <= 0 || empty($uuid)) {
			return false;
		}

		if (!nft_checkout_order_payment_link_table_exists(true)) {
			NFT_Checkout_Payment_Gateway_Logger::error(
				'Payment link table missing and could not be created. Payment link not stored.',
				[
					'source'  => 'nft-checkout-payment-gateway',
					'context' => ['order_id' => $order_id],
				]
			);

			return false;
		}

		$table_name = nft_checkout_get_order_payment_link_table();

		$payment_link = esc_url_raw(
			is_scalar($data['payment_link'] ?? null) ? (string) $data['payment_link'] : ''
		);

		$customer_email = sanitize_email(
			is_scalar($data['customer_email'] ?? null) ? (string) $data['customer_email'] : ''
		);

		if (empty($customer_email)) {
			$customer_email = sanitize_email($order->get_billing_email());
		}

		$amount = isset($data['amount']) && is_numeric($data['amount'])
			? (float) $data['amount']
			: (float) $order->get_total();

		$amount = number_format($amount, 2, '.', '');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, uuid, payment_link FROM %i WHERE order_id = %d LIMIT 1',
				$table_name,
				$order_id
			),
			ARRAY_A
		);

		$row = [
			'uuid'           => $uuid,
			'payment_link'   => $payment_link,
			'customer_email' => $customer_email,
			'amount'         => $amount,
			'created_at'     => current_time('mysql', 1),
		];

		if (!empty($existing)) {

			/*
			 * The application reports the same payment more than once - a
			 * return to the store and a webhook for it - and the row it already
			 * has is the row it would be given.
			 */
			if (
				$existing['uuid'] === $uuid &&
				(empty($payment_link) || $existing['payment_link'] === $payment_link)
			) {
				return true;
			}

			// A report without the link does not erase the one already recorded.
			if (empty($payment_link)) {
				unset($row['payment_link']);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$written = $wpdb->update(
				$table_name,
				$row,
				['order_id' => $order_id],
				array_fill(0, count($row), '%s'),
				['%d']
			);

		} else {

			$row['order_id'] = $order_id;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$written = $wpdb->insert(
				$table_name,
				$row,
				['%s', '%s', '%s', '%s', '%s', '%d']
			);
		}

		if (false === $written) {
			NFT_Checkout_Payment_Gateway_Logger::error(
				'Failed to store NFT Checkout order payment link.',
				[
					'source'  => 'nft-checkout-payment-gateway',
					'context' => [
						'order_id' => $order_id,
						'uuid'     => $uuid,
						'error'    => $wpdb->last_error,
					],
				]
			);

			return false;
		}

		$cache_group = defined('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP') ? NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP : 'nft_checkout_payment_gateway';
		wp_cache_delete('nft_checkout_payment_row_' . $order_id, $cache_group);
		wp_cache_delete('rt_nft_checkout_payment_row_' . $order_id, $cache_group);

		// NFT-15: Do not log sensitive payment links
		NFT_Checkout_Payment_Gateway_Logger::info(
			'Stored NFT Checkout order payment link.',
			[
				'source'  => 'nft-checkout-payment-gateway',
				'context' => [
					'order_id' => $order_id,
					'uuid'     => $uuid,
					'amount'   => $amount,
				],
			]
		);

		return true;
	}
}

if (!function_exists('nft_checkout_mask_email')) {
	function nft_checkout_mask_email($email) {
		if (empty($email) || !is_string($email) || strpos($email, '@') === false) {
			return '';
		}
		[$user, $domain] = explode('@', $email, 2);
		$len = strlen($user);
		if ($len <= 2) {
			$masked_user = substr($user, 0, 1) . '*';
		} else {
			$masked_user = substr($user, 0, 1) . str_repeat('*', min(5, $len - 2)) . substr($user, -1);
		}
		return $masked_user . '@' . $domain;
	}
}

if (!function_exists('nft_checkout_get_settings')) {
	/**
	 * Load NFT Checkout plugin settings without falling back to unrelated gateways.
	 *
	 * @return array
	 */
	function nft_checkout_get_settings()
	{
		$settings = get_option('woocommerce_nft-checkout_settings');
		if (empty($settings)) {
			$settings = get_option('woocommerce_nft_checkout_settings', []);
		}
		if (is_string($settings)) {
			$settings = maybe_unserialize($settings);
		}
		return is_array($settings) ? $settings : [];
	}
}

if (!function_exists('nft_checkout_get_accounts')) {
	/**
	 * Load configured NFT Checkout merchant accounts without reading legacy Unified options.
	 *
	 * @return array
	 */
	function nft_checkout_get_accounts()
	{
		$accounts = get_option('woocommerce_nft-checkout_payment_gateway_accounts');
		if (empty($accounts)) {
			$accounts = get_option('woocommerce_nft_checkout_payment_gateway_accounts', []);
		}
		if (is_string($accounts)) {
			$accounts = maybe_unserialize($accounts);
		}
		if (!is_array($accounts)) {
			return [];
		}
		if (isset($accounts['live_public_key']) || isset($accounts['sandbox_public_key'])) {
			$accounts = [$accounts];
		}
		return array_values(array_filter($accounts, 'is_array'));
	}
}

if (!function_exists('nft_checkout_is_order_sandbox')) {
	/**
	 * Resolve whether a WooCommerce order belongs to sandbox or live mode.
	 *
	 * Prefers metadata stamped onto the order at payment creation time so later
	 * settings toggles cannot mix live and sandbox keys.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	function nft_checkout_is_order_sandbox($order)
	{
		if ($order instanceof WC_Order) {
			$meta_sandbox = $order->get_meta('_nft_checkout_is_sandbox');
			if ($meta_sandbox === 'yes' || $meta_sandbox === '1' || $meta_sandbox === true) {
				return true;
			}
			if ($meta_sandbox === 'no' || $meta_sandbox === '0' || $meta_sandbox === false) {
				return false;
			}
			if ($order->get_meta('_is_test_order')) {
				return true;
			}
		}

		$settings = nft_checkout_get_settings();
		return isset($settings['sandbox']) && $settings['sandbox'] === 'yes';
	}
}

if (!function_exists('nft_checkout_match_order_account')) {
	/**
	 * Find the single configured merchant account bound to an order.
	 *
	 * Fails closed (returns null) if the order's bound account cannot be matched
	 * or has no secret key for the requested mode.
	 *
	 * @param WC_Order  $order      WooCommerce order.
	 * @param bool|null $is_sandbox Optional explicit mode; defaults to order's recorded mode.
	 * @return array|null
	 */
	function nft_checkout_match_order_account($order, $is_sandbox = null)
	{
		if (!$order instanceof WC_Order) {
			return null;
		}

		$sandbox              = ($is_sandbox === null) ? nft_checkout_is_order_sandbox($order) : (bool) $is_sandbox;
		$accounts             = nft_checkout_get_accounts();
		$order_account_uid    = trim((string) $order->get_meta('_nft_checkout_account_unique_id'));
		$order_account_pubkey = trim((string) $order->get_meta('_nft_checkout_account_public_key'));

		if ($order_account_uid === '' && $order_account_pubkey === '') {
			return null;
		}

		foreach ($accounts as $account) {
			$acc_pub = $sandbox
				? (string) ($account['sandbox_public_key'] ?? '')
				: (string) ($account['live_public_key'] ?? '');
			$acc_sec = $sandbox
				? (string) ($account['sandbox_secret_key'] ?? '')
				: (string) ($account['live_secret_key'] ?? '');

			if ($acc_pub === '' || $acc_sec === '') {
				continue;
			}

			if ($order_account_uid !== '' && !empty($account['unique_id']) && hash_equals((string) $account['unique_id'], $order_account_uid)) {
				return $account;
			}

			if ($order_account_pubkey !== '' && hash_equals($acc_pub, $order_account_pubkey)) {
				return $account;
			}
		}

		return null;
	}
}

if (!function_exists('nft_checkout_decode_payment_uuid')) {
	/**
	 * Decode a base64-encoded UUID payment token or normalize a plain UUID.
	 *
	 * @param string $token Raw or base64-encoded payment UUID.
	 * @return string Lowercase UUID, or empty string if invalid.
	 */
	function nft_checkout_decode_payment_uuid($token)
	{
		$token = trim((string) $token);
		if ($token === '') {
			return '';
		}

		$uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
		if (preg_match($uuid_pattern, $token)) {
			return strtolower($token);
		}

		$decoded = base64_decode($token, true);
		if (is_string($decoded) && preg_match($uuid_pattern, trim($decoded))) {
			return strtolower(trim($decoded));
		}

		return '';
	}
}

if (!function_exists('nft_checkout_is_valid_rt_url')) {
	/**
	 * Validate that an external payment/voucher URL uses HTTPS and points to the configured RT host.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	function nft_checkout_is_valid_rt_url($url)
	{
		$url = trim((string) $url);
		if ($url === '' || !wp_http_validate_url($url)) {
			return false;
		}

		$parts = wp_parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}

		$expected_host = defined('NFT_CHECKOUT_BASE_URL')
			? (string) wp_parse_url(NFT_CHECKOUT_BASE_URL, PHP_URL_HOST)
			: '';

		$allowed_hosts = array_filter([
			strtolower($expected_host),
			defined('NFT_CHECKOUT_PROD_HOST') ? strtolower((string) NFT_CHECKOUT_PROD_HOST) : '',
			defined('NFT_CHECKOUT_QA_HOST') ? strtolower((string) NFT_CHECKOUT_QA_HOST) : '',
			defined('NFT_CHECKOUT_DEV_HOST') ? strtolower((string) NFT_CHECKOUT_DEV_HOST) : '',
		]);

		return in_array(strtolower($parts['host']), $allowed_hosts, true);
	}
}

if (!function_exists('nft_checkout_ip_in_cidr')) {
	/**
	 * Check whether an IP address falls within an IP/CIDR entry.
	 *
	 * @param string $ip   IPv4 or IPv6 address.
	 * @param string $cidr Exact IP or CIDR block.
	 * @return bool
	 */
	function nft_checkout_ip_in_cidr($ip, $cidr)
	{
		if (strpos($cidr, '/') === false) {
			return $ip === $cidr;
		}

		[$subnet, $bits] = explode('/', $cidr, 2);
		$ip_bin     = @inet_pton($ip);
		$subnet_bin = @inet_pton($subnet);
		if ($ip_bin === false || $subnet_bin === false || strlen($ip_bin) !== strlen($subnet_bin)) {
			return false;
		}

		$max_bits = strlen($ip_bin) * 8;
		$bits     = (int) $bits;
		if ($bits < 0 || $bits > $max_bits) {
			return false;
		}

		$bytes     = intdiv($bits, 8);
		$remainder = $bits % 8;

		if ($bytes > 0 && substr($ip_bin, 0, $bytes) !== substr($subnet_bin, 0, $bytes)) {
			return false;
		}

		if ($remainder > 0) {
			$mask = (0xFF << (8 - $remainder)) & 0xFF;
			if ((ord($ip_bin[$bytes]) & $mask) !== (ord($subnet_bin[$bytes]) & $mask)) {
				return false;
			}
		}

		return true;
	}
}

if (!function_exists('nft_checkout_get_trusted_client_ip')) {
	/**
	 * Resolve client IP safely.
	 *
	 * Forwarded headers are inspected ONLY when REMOTE_ADDR belongs to an explicitly
	 * configured trusted proxy list (`NFT_CHECKOUT_TRUSTED_PROXIES` or the
	 * `nft_checkout_trusted_proxies` filter).
	 *
	 * @return string
	 */
	function nft_checkout_get_trusted_client_ip()
	{
		$remote_addr = isset($_SERVER['REMOTE_ADDR'])
			? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
			: '';

		if (empty($remote_addr) || !filter_var($remote_addr, FILTER_VALIDATE_IP)) {
			return '0.0.0.0';
		}

		$trusted_proxies = defined('NFT_CHECKOUT_TRUSTED_PROXIES') ? NFT_CHECKOUT_TRUSTED_PROXIES : [];
		if (is_string($trusted_proxies)) {
			$trusted_proxies = array_filter(array_map('trim', explode(',', $trusted_proxies)));
		}
		$trusted_proxies = apply_filters('nft_checkout_trusted_proxies', (array) $trusted_proxies);

		$is_trusted_proxy = false;
		foreach ($trusted_proxies as $proxy) {
			$proxy = trim((string) $proxy);
			if ($proxy !== '' && nft_checkout_ip_in_cidr($remote_addr, $proxy)) {
				$is_trusted_proxy = true;
				break;
			}
		}

		if ($is_trusted_proxy) {
			foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
				if (empty($_SERVER[$header])) {
					continue;
				}
				$raw_header = sanitize_text_field(wp_unslash($_SERVER[$header]));
				$candidates = array_map('trim', explode(',', $raw_header));
				foreach ($candidates as $candidate) {
					if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
						return $candidate;
					}
				}
				foreach ($candidates as $candidate) {
					if (filter_var($candidate, FILTER_VALIDATE_IP)) {
						return $candidate;
					}
				}
			}
		}

		return $remote_addr;
	}
}

if (!function_exists('nft_checkout_get_rate_limit_identity')) {
	/**
	 * Build a customer/session/order-scoped rate-limit key component so users
	 * sharing a CDN or proxy IP do not throttle each other.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	function nft_checkout_get_rate_limit_identity($order_id = 0)
	{
		$parts = [];
		$user_id = get_current_user_id();
		if ($user_id > 0) {
			$parts[] = 'u_' . $user_id;
		} elseif (function_exists('WC') && WC() && WC()->session && method_exists(WC()->session, 'get_customer_id')) {
			$session_id = (string) WC()->session->get_customer_id();
			if ($session_id !== '') {
				$parts[] = 's_' . $session_id;
			}
		}

		$parts[] = 'ip_' . nft_checkout_get_trusted_client_ip();

		if ($order_id > 0 && empty($user_id) && count($parts) === 1) {
			$parts[] = 'o_' . absint($order_id);
		}

		return hash('sha256', implode('|', $parts));
	}
}

if (!function_exists('nft_checkout_schedule_async_order_sync')) {
	/**
	 * Schedule a non-blocking background status sync for an NFT Checkout order.
	 *
	 * @param int $order_id Order ID.
	 * @param int $delay    Delay in seconds.
	 * @return void
	 */
	function nft_checkout_schedule_async_order_sync($order_id, $delay = 5)
	{
		$order_id = absint($order_id);
		if ($order_id <= 0) {
			return;
		}

		$args = [$order_id];
		if (function_exists('as_schedule_single_action') && function_exists('as_has_scheduled_action')) {
			if (!as_has_scheduled_action('nft_checkout_async_sync_order', $args, 'nft-checkout-payment-gateway')) {
				as_schedule_single_action(time() + max(0, (int) $delay), 'nft_checkout_async_sync_order', $args, 'nft-checkout-payment-gateway');
			}
			return;
		}

		if (!wp_next_scheduled('nft_checkout_async_sync_order', $args)) {
			wp_schedule_single_event(time() + max(0, (int) $delay), 'nft_checkout_async_sync_order', $args);
		}
	}
}

if (!function_exists('nft_checkout_migrate_legacy_payment_links')) {
	/**
	 * One-time copy of NFT Checkout rows from the old shared `order_payment_link`
	 * table into NFT Checkout's own dedicated table, without modifying other gateways' rows.
	 *
	 * @return void
	 */
	function nft_checkout_migrate_legacy_payment_links()
	{
		global $wpdb;

		if (get_option('nft_checkout_links_migrated') === 'yes') {
			return;
		}

		$legacy_table = $wpdb->prefix . 'order_payment_link';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$legacy_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table));
		if ($legacy_exists !== $legacy_table) {
			update_option('nft_checkout_links_migrated', 'yes');
			return;
		}

		if (!nft_checkout_order_payment_link_table_exists(true)) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare('SELECT order_id, uuid, payment_link, customer_email, amount FROM %i ORDER BY id DESC LIMIT 500', $legacy_table),
			ARRAY_A
		);

		if (is_array($rows)) {
			foreach ($rows as $row) {
				$oid = absint($row['order_id'] ?? 0);
				if ($oid <= 0) {
					continue;
				}
				$order = function_exists('wc_get_order') ? wc_get_order($oid) : false;
				if (!$order instanceof WC_Order) {
					continue;
				}
				$method = $order->get_payment_method();
				if ($method !== 'nft-checkout' && $method !== 'nft_checkout') {
					continue;
				}
				if (!nft_checkout_get_order_payment_link($oid)) {
					nft_checkout_store_order_payment_link($order, $row);
				}
			}
		}

		update_option('nft_checkout_links_migrated', 'yes');
	}
}
