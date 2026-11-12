<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php

/*
 * Where this plugin talks to RT. ONE setting decides it: NFT_CHECKOUT_API_BASE_URL.
 *
 * Production (the default, for Live AND Sandbox keys) is
 * https://app.rt.app/payment-api. Sandbox mode does not use a different
 * address: the same service answers both, and the keys plus the is_sandbox
 * flag in each request decide the mode.
 *
 * A test shop can point at another RT environment without editing code:
 *
 *   add_filter('rt_payment_api_base', function () { return 'https://dev.rt.app/payment-api'; });
 *
 * (in a must-use plugin, so it runs before this file), or by defining
 * NFT_CHECKOUT_API_BASE_URL in wp-config.php. The older wp-config
 * settings NFT_CHECKOUT_HOST (a bare host) and NFT_CHECKOUT_ENVIRONMENT
 * ('dev' | 'qa' | 'production') are still honoured.
 * The address is always HTTPS and must be on rt.app, so a shop's keys and its
 * customers' details can never be sent anywhere else by a filter.
 */
if (!defined('NFT_CHECKOUT_DEFAULT_API_BASE_URL')) {
	define('NFT_CHECKOUT_DEFAULT_API_BASE_URL', 'https://app.rt.app/payment-api');
}

if (!function_exists('nft_checkout_clean_api_base')) {
	/**
	 * A safe API base: https, a host on rt.app, no trailing slash. Anything
	 * else falls back to production.
	 *
	 * @param mixed $candidate
	 * @return string
	 */
	function nft_checkout_clean_api_base($candidate)
	{
		$candidate = is_string($candidate) ? trim($candidate) : '';
		if ($candidate === '') {
			return NFT_CHECKOUT_DEFAULT_API_BASE_URL;
		}
		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate)) {
			$candidate = 'https://' . $candidate;
		}
		$parts = function_exists('wp_parse_url') ? wp_parse_url($candidate) : parse_url($candidate);
		$host  = is_array($parts) && !empty($parts['host']) ? strtolower((string) $parts['host']) : '';
		if ($host === '' || !($host === 'rt.app' || substr($host, -7) === '.rt.app')) {
			return NFT_CHECKOUT_DEFAULT_API_BASE_URL;
		}
		$path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
		if ($path === '') {
			$path = '/payment-api';
		}
		return 'https://' . $host . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
	}
}

if (!defined('NFT_CHECKOUT_PROTOCOL')) {
	define('NFT_CHECKOUT_PROTOCOL', 'https://');
}

if (!defined('NFT_CHECKOUT_API_BASE_URL')) {
	$nft_checkout_api_base = NFT_CHECKOUT_DEFAULT_API_BASE_URL;

	// Older wp-config settings. An explicit host wins over an environment name.
	if (defined('NFT_CHECKOUT_HOST') && is_string(NFT_CHECKOUT_HOST) && trim(NFT_CHECKOUT_HOST) !== '') {
		$nft_checkout_api_base = 'https://' . preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', rtrim(trim(NFT_CHECKOUT_HOST), '/')) . '/payment-api';
	} elseif (defined('NFT_CHECKOUT_ENVIRONMENT')) {
		switch (strtolower((string) NFT_CHECKOUT_ENVIRONMENT)) {
			case 'dev':
			case 'development':
			case 'local':
				$nft_checkout_api_base = 'https://dev.rt.app/payment-api';
				break;
			case 'qa':
			case 'staging':
				$nft_checkout_api_base = 'https://qa.rt.app/payment-api';
				break;
		}
	}

	define('NFT_CHECKOUT_API_BASE_URL', nft_checkout_clean_api_base(apply_filters('rt_payment_api_base', $nft_checkout_api_base, 'nft-checkout-payment-gateway')));
}

/* Derived from the API base; never set separately. Used for links to RT pages
 * (Developers) and to recognise RT's own origin. */
if (!defined('NFT_CHECKOUT_HOST')) {
	define('NFT_CHECKOUT_HOST', (string) parse_url(NFT_CHECKOUT_API_BASE_URL, PHP_URL_HOST));
}
if (!defined('NFT_CHECKOUT_BASE_URL')) {
	define('NFT_CHECKOUT_BASE_URL', NFT_CHECKOUT_PROTOCOL . (string) parse_url(NFT_CHECKOUT_API_BASE_URL, PHP_URL_HOST));
}

if (!defined('NFT_CHECKOUT_PLUGIN_VERSION')) {
    define('NFT_CHECKOUT_PLUGIN_VERSION', '1.0.2');
}

/* Gateway id. Orders placed before 1.0.2 may carry the underscore spelling. */
if (!defined('NFT_CHECKOUT_GATEWAY_ID')) {
    define('NFT_CHECKOUT_GATEWAY_ID', 'nft-checkout');
}

/* REST namespace of the return / webhook endpoint. */
if (!defined('NFT_CHECKOUT_REST_NAMESPACE')) {
    define('NFT_CHECKOUT_REST_NAMESPACE', 'nft-checkout/v1');
}

/* NFT-13: this plugin's own payment-link table, never shared with another gateway. */
if (!defined('NFT_CHECKOUT_TABLE')) {
    define('NFT_CHECKOUT_TABLE', 'rt_nft_checkout_payment_link');
}

/* NFT-13: every name registered with WordPress carries a plugin-specific prefix. */
if (!defined('NFT_CHECKOUT_CRON_HOOK')) {
    define('NFT_CHECKOUT_CRON_HOOK', 'rt_nft_checkout_cron_event');
}
if (!defined('NFT_CHECKOUT_CRON_INTERVAL')) {
    define('NFT_CHECKOUT_CRON_INTERVAL', 'rt_nft_checkout_every_two_hours');
}
if (!defined('NFT_CHECKOUT_ASYNC_SYNC_HOOK')) {
    define('NFT_CHECKOUT_ASYNC_SYNC_HOOK', 'rt_nft_checkout_async_sync_order');
}

/* Option names. Only this plugin's own options are ever read (NFT-13). */
if (!defined('NFT_CHECKOUT_SETTINGS_OPTION')) {
    define('NFT_CHECKOUT_SETTINGS_OPTION', 'woocommerce_nft-checkout_settings');
}
if (!defined('NFT_CHECKOUT_ACCOUNTS_OPTION')) {
    define('NFT_CHECKOUT_ACCOUNTS_OPTION', 'woocommerce_nft-checkout_payment_gateway_accounts');
}
