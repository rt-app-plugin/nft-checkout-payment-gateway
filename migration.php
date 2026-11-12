<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Schema version written once every step below has run. Bump it whenever a
 * step is added; the main plugin file runs the steps when the stored version
 * is older.
 */
define('NFT_CHECKOUT_SCHEMA_VERSION', '1.0.2');

/**
 * NFT-13: copy this gateway's rows out of the table the pre-1.0.2 plugin
 * shared with the bank transfer gateway, once. That table is left untouched
 * for the other plugin.
 */
function nft_checkout_migrate_legacy_rows()
{
	global $wpdb;

	if (get_option('nft_checkout_legacy_rows_migrated')) {
		return;
	}

	$legacy_table = $wpdb->prefix . 'order_payment_link';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table));
	if ($exists === $legacy_table && function_exists('wc_get_order')) {
		$rows = $wpdb->get_results(
			$wpdb->prepare('SELECT order_id, uuid, payment_link, customer_email, amount, created_at FROM %i', $legacy_table),
			ARRAY_A
		);
		foreach ((array) $rows as $row) {
			$order = wc_get_order((int) ($row['order_id'] ?? 0));
			if (!nft_checkout_is_gateway_order($order) || empty($row['uuid'])) {
				continue;
			}
			nft_checkout_store_order_payment_link($order, [
				'uuid'           => (string) $row['uuid'],
				'payment_link'   => (string) ($row['payment_link'] ?? ''),
				'customer_email' => (string) ($row['customer_email'] ?? ''),
				'amount'         => $row['amount'] ?? $order->get_total(),
			]);
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	update_option('nft_checkout_legacy_rows_migrated', '1');
}

/**
 * Normalise this plugin's own settings. NFT-13: nothing is read from, or
 * copied out of, the Unified / Voucher Pay plugin's options any more; a
 * merchant who used that plugin enters their RT keys here themselves.
 */
function nft_checkout_migrate_old_settings()
{
	nft_checkout_clean_legacy_settings_keys();

	$accounts = get_option(NFT_CHECKOUT_ACCOUNTS_OPTION);
	if (!$accounts) {
		return;
	}
	if (is_string($accounts)) {
		$accounts = maybe_unserialize($accounts);
	}
	if (!is_array($accounts) || empty($accounts)) {
		return;
	}

	$enhanced_accounts = array_map(function ($account) {
		if (!is_array($account)) {
			return $account;
		}
		$account['live_status']    = $account['live_status'] ?? 'active';
		$account['sandbox_status'] = $account['sandbox_status'] ?? 'active';
		$account['has_sandbox']    = (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) ? 'on' : 'off';
		$account['priority']       = $account['priority'] ?? 1;
		$account['title']          = $account['title'] ?? 'Default Account';
		if (empty($account['unique_id'])) {
			$account['unique_id'] = 'acc_' . wp_generate_uuid4();
		}
		return $account;
	}, array_values(array_filter($accounts, 'is_array')));

	update_option(NFT_CHECKOUT_ACCOUNTS_OPTION, $enhanced_accounts);
}

/**
 * Remove key material from the gateway settings option: keys live in the
 * accounts option only.
 */
function nft_checkout_clean_legacy_settings_keys()
{
	foreach ([NFT_CHECKOUT_SETTINGS_OPTION, 'woocommerce_nft_checkout_settings'] as $opt_name) {
		$settings = get_option($opt_name);
		if (is_array($settings)) {
			$cleaned = false;
			foreach (['public_key', 'secret_key', 'sandbox_public_key', 'sandbox_secret_key'] as $key) {
				if (isset($settings[$key])) {
					unset($settings[$key]);
					$cleaned = true;
				}
			}
			if ($cleaned) {
				update_option($opt_name, $settings);
			}
		}
	}
}

/**
 * NFT-17: nothing is sent to RT during activation or an upgrade. The account
 * check runs on the scheduled cron (and when the merchant saves keys or
 * presses "Sync Accounts"), and only once keys exist.
 */
function nft_checkout_trigger_sync()
{
	if (!nft_checkout_is_configured()) {
		return;
	}

	if (get_transient('rt_nft_checkout_sync_lock')) {
		return;
	}
	set_transient('rt_nft_checkout_sync_lock', true, 5 * MINUTE_IN_SECONDS);

	wp_schedule_single_event(time() + 30, NFT_CHECKOUT_CRON_HOOK);
}

/**
 * Run every schema step the stored version has not seen yet.
 */
function nft_checkout_maybe_upgrade()
{
	$current_schema = get_option('nft_checkout_schema_version', '0');
	if (version_compare($current_schema, NFT_CHECKOUT_SCHEMA_VERSION, '>=')) {
		return;
	}

	nft_checkout_create_tables();
	nft_checkout_migrate_legacy_rows();
	nft_checkout_migrate_old_settings();

	// The cron hook and interval were renamed (NFT-13): drop the old schedule.
	wp_clear_scheduled_hook('nft_checkout_cron_event');
	if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
		NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->activate_cron_job();
	}

	update_option('nft_checkout_schema_version', NFT_CHECKOUT_SCHEMA_VERSION);
	update_option('nft_checkout_plugin_version', NFT_CHECKOUT_PLUGIN_VERSION);
}

function nft_checkout_on_plugin_activate() {
	nft_checkout_create_tables();
	nft_checkout_migrate_legacy_rows();
	nft_checkout_migrate_old_settings();
	update_option('nft_checkout_schema_version', NFT_CHECKOUT_SCHEMA_VERSION);
	update_option('nft_checkout_plugin_version', NFT_CHECKOUT_PLUGIN_VERSION);

	if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
		NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->activate_cron_job();
		// NFT-17: reported only when the merchant opted in (and has keys).
		NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->nft_checkout_send_plugin_status(1, 0);
	}
}

function nft_checkout_on_plugin_deactivate() {
	if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
		NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->deactivate_cron_job();
		NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->nft_checkout_send_plugin_status(0, 0);
	}
}

register_activation_hook(NFT_CHECKOUT_PAYMENT_GATEWAY_FILE, 'nft_checkout_on_plugin_activate');
register_deactivation_hook(NFT_CHECKOUT_PAYMENT_GATEWAY_FILE, 'nft_checkout_on_plugin_deactivate');
