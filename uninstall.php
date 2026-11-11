<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * NFT-34: Cleans up plugin options, transients, and cron events on uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Clear scheduled cron events and async sync tasks
wp_clear_scheduled_hook('nft_checkout_cron_event');
wp_clear_scheduled_hook('nft_checkout_order_sync_cron_event');
wp_clear_scheduled_hook('nft_checkout_async_sync_order');
if (function_exists('as_unschedule_all_actions')) {
	as_unschedule_all_actions('nft_checkout_async_sync_order');
}

// Plugin options to delete
$nft_checkout_options = [
	'woocommerce_nft-checkout_settings',
	'woocommerce_nft_checkout_settings',
	'woocommerce_nft-checkout_payment_gateway_accounts',
	'woocommerce_nft_checkout_payment_gateway_accounts',
	'nft_checkout_group_id',
	'nft_checkout_unique_id',
	'nft_checkout_plugin_version',
	'nft_checkout_active_account',
	'nft_checkout_links_migrated',
];

foreach ($nft_checkout_options as $nft_checkout_option) {
	delete_option($nft_checkout_option);
}

// Clear plugin transients and ephemeral state lock options from wp_options
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like('_transient_rt_nft_') . '%',
		$wpdb->esc_like('_transient_timeout_rt_nft_') . '%',
		$wpdb->esc_like('_transient_nft_checkout_') . '%',
		$wpdb->esc_like('_transient_timeout_nft_checkout_') . '%',
		$wpdb->esc_like('_transient_nft_webhook_') . '%',
		$wpdb->esc_like('_transient_timeout_nft_webhook_') . '%',
		$wpdb->esc_like('rt_nft_checkout_lock_') . '%',
		$wpdb->esc_like('nft_checkout_lock_') . '%'
	)
);

// Clean ephemeral sync/lock order metadata without removing WooCommerce orders or transaction history
$ephemeral_meta_keys = [
	'_nft_checkout_last_sync_attempt',
	'_nft_checkout_last_admin_sync',
	'_nft_checkout_last_row_sync',
	'_nft_checkout_state_lock',
	'_nft_checkout_processed_events',
];
$meta_placeholders = implode(', ', array_fill(0, count($ephemeral_meta_keys), '%s'));

$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$hpos_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table)) === $hpos_meta_table;
if ($hpos_exists) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query($wpdb->prepare("DELETE FROM {$hpos_meta_table} WHERE meta_key IN ({$meta_placeholders})", ...$ephemeral_meta_keys));
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$meta_placeholders})", ...$ephemeral_meta_keys));
