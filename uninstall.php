<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * NFT-34: Cleans up plugin options, transients, and cron events on uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Clear scheduled cron events
wp_clear_scheduled_hook('nft_checkout_cron_event');

// Plugin options to delete
$nft_checkout_options = [
	'woocommerce_nft-checkout_settings',
	'woocommerce_nft_checkout_settings',
	'woocommerce_nft-checkout_payment_gateway_accounts',
	'woocommerce_nft_checkout_payment_gateway_accounts',
	'nft_checkout_group_id',
	'nft_checkout_unique_id',
	'nft_checkout_plugin_version',
];

foreach ($nft_checkout_options as $nft_checkout_option) {
	delete_option($nft_checkout_option);
}

// Clear plugin transients from wp_options
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like('_transient_nft_checkout_') . '%',
		$wpdb->esc_like('_transient_timeout_nft_checkout_') . '%'
	)
);
