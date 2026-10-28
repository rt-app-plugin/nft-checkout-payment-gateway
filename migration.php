<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function nft_checkout_migrate_old_settings()
{
	// Check if option exists under new or old key
	$beta_accounts = get_option('woocommerce_nft-checkout_payment_gateway_accounts');
	if (!$beta_accounts) {
		$beta_accounts = get_option('woocommerce_unified_payment_gateway_accounts');
	}

	if ($beta_accounts) {
		if (is_string($beta_accounts)) {
			$beta_accounts = maybe_unserialize($beta_accounts);
		}

		if (is_array($beta_accounts) && !empty($beta_accounts)) {
			// Enhance each account to ensure all required keys exist
			$enhanced_accounts = array_map(function ($account) {
				$account['live_status']    = $account['live_status'] ?? 'active';
				$account['sandbox_status'] = $account['sandbox_status'] ?? 'active';
				$account['has_sandbox']    = (!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key'])) ? 'on' : 'off';
				$account['priority']       = $account['priority'] ?? 1;
				$account['title']          = $account['title'] ?? 'Default Account';
				return $account;
			}, $beta_accounts);

			// NFT-32: Save array directly without manual serialization
			update_option('woocommerce_nft-checkout_payment_gateway_accounts', $enhanced_accounts);

			// NFT-07: Clean up legacy sensitive keys from main settings option
			nft_checkout_clean_legacy_settings_keys();

			nft_checkout_trigger_sync();
			return; // Migration complete for beta
		}
	}

	// Fallback to legacy settings
	$old_settings = get_option('woocommerce_nft-checkout_settings');
	if (!$old_settings) {
		$old_settings = get_option('woocommerce_unified_settings');
	}
	if (is_string($old_settings)) {
		$old_settings = maybe_unserialize($old_settings);
	}
	if (!$old_settings || !is_array($old_settings)) {
		nft_checkout_clean_legacy_settings_keys();
		return; // Nothing to migrate
	}

	// Extract old settings
	$live_public_key    = $old_settings['public_key'] ?? '';
	$live_secret_key    = $old_settings['secret_key'] ?? '';
	$sandbox_public_key = $old_settings['sandbox_public_key'] ?? '';
	$sandbox_secret_key = $old_settings['sandbox_secret_key'] ?? '';
	$sandbox_enabled    = isset($old_settings['sandbox']) && $old_settings['sandbox'] === 'yes';

	$has_sandbox    = (!empty($sandbox_public_key) && !empty($sandbox_secret_key)) ? 'on' : 'off';
	$live_status    = 'active';
	$sandbox_status = $sandbox_enabled ? 'active' : 'inactive';

	if (empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
		nft_checkout_clean_legacy_settings_keys();
		return; // No keys to migrate
	}

	$new_accounts = [
		[
			'title'              => 'Default Account',
			'priority'           => 1,
			'live_public_key'    => $live_public_key,
			'live_secret_key'    => $live_secret_key,
			'sandbox_public_key' => $sandbox_public_key,
			'sandbox_secret_key' => $sandbox_secret_key,
			'has_sandbox'        => $has_sandbox,
			'live_status'        => $live_status,
			'sandbox_status'     => $sandbox_status,
		]
	];

	// NFT-32: Save array directly without manual serialization
	update_option('woocommerce_nft-checkout_payment_gateway_accounts', $new_accounts);

	// NFT-07: Clean up legacy sensitive keys from main settings option
	nft_checkout_clean_legacy_settings_keys();

	nft_checkout_trigger_sync();
}

/**
 * NFT-07: One-time migration to remove legacy public/secret keys from main settings option
 */
function nft_checkout_clean_legacy_settings_keys()
{
	foreach (['woocommerce_nft-checkout_settings', 'woocommerce_nft_checkout_settings'] as $opt_name) {
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

function nft_checkout_trigger_sync()
{
	if (get_transient('nft_checkout_sync_lock')) {
		return; // Already triggered recently
	}
	set_transient('nft_checkout_sync_lock', true, 5 * MINUTE_IN_SECONDS);

	if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
		$loader = NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance();
		if (method_exists($loader, 'handle_cron_event')) {
			wc_get_logger()->info('Sync account for migration started.', [
				'source' => 'nft-checkout-payment-gateway',
				'context' => ['sync_id' => uniqid('migrate_', true)]
			]);
			$loader->handle_cron_event();
		}
	}
}

function nft_checkout_on_plugin_activate() {
	// Migrate settings
	if (function_exists('nft_checkout_migrate_old_settings')) {
		nft_checkout_migrate_old_settings();
	}

	// Activate cron
	if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
		NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->activate_cron_job();
		NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->nft_checkout_send_plugin_status(1, 0);
	}
}

function nft_checkout_on_plugin_deactivate() {
	// Deactivate cron
	if (class_exists('NFT_CHECKOUT_PAYMENT_GATEWAY_Loader')) {
		NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->deactivate_cron_job();
		NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance()->nft_checkout_send_plugin_status(0, 0);
	}
}

register_activation_hook(NFT_CHECKOUT_PAYMENT_GATEWAY_FILE, 'nft_checkout_on_plugin_activate');
register_deactivation_hook(NFT_CHECKOUT_PAYMENT_GATEWAY_FILE, 'nft_checkout_on_plugin_deactivate');
