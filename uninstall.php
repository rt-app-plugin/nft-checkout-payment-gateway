<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * NFT-18: removes this plugin's options, lock options, transients, cron
 * events, its own payment-link table and the meta it wrote on orders. The
 * table the pre-1.0.2 plugin shared with the bank transfer gateway
 * ({prefix}order_payment_link) belongs to that plugin and is left alone.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

function nft_checkout_uninstall() {
	global $wpdb;

	// Clear scheduled cron events (current and pre-1.0.2 hook names)
	wp_clear_scheduled_hook('rt_nft_checkout_cron_event');
	wp_clear_scheduled_hook('rt_nft_checkout_async_sync_order');
	wp_clear_scheduled_hook('nft_checkout_cron_event');
	wp_clear_scheduled_hook('nft_checkout_async_sync_order');

	// Plugin options to delete
	$nft_checkout_options = [
		'woocommerce_nft-checkout_settings',
		'woocommerce_nft_checkout_settings',
		'woocommerce_nft-checkout_payment_gateway_accounts',
		'woocommerce_nft_checkout_payment_gateway_accounts',
		'nft_checkout_group_id',
		'nft_checkout_unique_id',
		'nft_checkout_active_account',
		'nft_checkout_plugin_version',
		'nft_checkout_schema_version',
		'nft_checkout_legacy_rows_migrated',
	];

	foreach ($nft_checkout_options as $nft_checkout_option) {
		delete_option($nft_checkout_option);
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	// Lock options and transients (current and pre-1.0.2 names)
	$patterns = [
		$wpdb->esc_like('rt_nft_checkout_lock_') . '%',
		$wpdb->esc_like('nft_checkout_lock_') . '%',
		$wpdb->esc_like('_transient_rt_nft_') . '%',
		$wpdb->esc_like('_transient_timeout_rt_nft_') . '%',
		$wpdb->esc_like('_transient_nft_checkout_') . '%',
		$wpdb->esc_like('_transient_timeout_nft_checkout_') . '%',
		$wpdb->esc_like('_transient_nft_webhook_sig_') . '%',
		$wpdb->esc_like('_transient_timeout_nft_webhook_sig_') . '%',
		$wpdb->esc_like('_transient_rate_limit_') . '%',
		$wpdb->esc_like('_transient_timeout_rate_limit_') . '%',
	];
	foreach ($patterns as $pattern) {
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
	}

	// This plugin's own payment-link table.
	$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'rt_nft_checkout_payment_link'));

	// Order meta written by this plugin (posts storage and HPOS storage).
	$meta_like = $wpdb->esc_like('_nft_checkout_') . '%';
	$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $meta_like));
	$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
	if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table)) === $hpos_meta_table) {
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $hpos_meta_table, $meta_like));
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}
nft_checkout_uninstall();
