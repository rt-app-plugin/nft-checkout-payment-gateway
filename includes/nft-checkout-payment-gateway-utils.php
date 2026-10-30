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
			__('The Voucher Pay plugin requires PHP version %1$s or greater. You are running %2$s.', 'nft-checkout-payment-gateway'),
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
			__('The Voucher Pay plugin requires WooCommerce database version %1$s or greater. You are running %2$s.', 'nft-checkout-payment-gateway'),
			NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_db_version ? $wc_db_version : __('undefined', 'nft-checkout-payment-gateway')
		);
	}

	// Check if WooCommerce plugin version is outdated
	if (!$wc_plugin_version || version_compare($wc_plugin_version, NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce plugin version, %2$s is the current WooCommerce plugin version (or "undefined" if not available)
			__('The Voucher Pay plugin requires WooCommerce plugin version %1$s or greater. You are running %2$s.', 'nft-checkout-payment-gateway'),
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
        $plugin_prefix = '<strong>Voucher Pay</strong>';

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
	 * Name of the payment-link table.
	 *
	 * Shared with the other gateways in this family, so the columns and the
	 * name are theirs and are not changed here.
	 *
	 * @return string
	 */
	function nft_checkout_get_order_payment_link_table()
	{
		global $wpdb;

		return $wpdb->prefix . 'order_payment_link';
	}
}

if (!function_exists('nft_checkout_order_payment_link_table_exists')) {

	/**
	 * Whether the payment-link table is present.
	 *
	 * Each gateway in this family creates the table the first time it stores a
	 * payment link. Voucher Pay can be the only one installed, so it creates it
	 * the same way and with exactly the same columns.
	 *
	 * @param bool $create Create the table when it is missing.
	 * @return bool
	 */
	function nft_checkout_order_payment_link_table_exists($create = false)
	{
		global $wpdb;

		$table_name  = nft_checkout_get_order_payment_link_table();
		$cache_key   = 'unified_table_exists_' . md5($table_name);
		$cache_group = 'unified_payment_gateway';

		// Only a table that is there is remembered: another gateway in this
		// family may create it at any time, so its absence is looked up again.
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

		$create_sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL,
			uuid VARCHAR(100) NOT NULL,
			payment_link TEXT NOT NULL,
			customer_email VARCHAR(191),
			amount DECIMAL(18,2),
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP
		) $charset_collate;";

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

		$table_name      = nft_checkout_get_order_payment_link_table();
		$safe_table_name = esc_sql($table_name);

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

		// NFT-23: Match cache key and group used by cancellation
		wp_cache_delete('nft_checkout_payment_row_' . $order_id, defined('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP') ? NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP : 'nft_checkout_payment_gateway');

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
