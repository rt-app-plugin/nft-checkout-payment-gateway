<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php

// NFT-04: Always talk to the payment API over HTTPS.
// The store's own scheme (is_ssl()) must never decide the protocol: an HTTP-only store
// would otherwise send API keys and customer data in plain text. Do NOT change to is_ssl().
if (!defined('NFT_CHECKOUT_PROTOCOL')) {
    define('NFT_CHECKOUT_PROTOCOL', 'https://');
}

// Available environment hosts
if (!defined('NFT_CHECKOUT_DEV_HOST')) {
    define('NFT_CHECKOUT_DEV_HOST', 'dev.rt.app');
}

if (!defined('NFT_CHECKOUT_QA_HOST')) {
    define('NFT_CHECKOUT_QA_HOST', 'qa.rt.app');
}

if (!defined('NFT_CHECKOUT_PROD_HOST')) {
    define('NFT_CHECKOUT_PROD_HOST', 'rt.app');
}

/**
 * Choose which domain Sandbox Mode ("Enable Sandbox Mode" checked) uses:
 * 'qa'  => qa.rt.app  (QA user / QA domain)
 * 'dev' => dev.rt.app (Dev user / Dev domain)
 *
 * Switch this value between 'qa' and 'dev' when changing between QA and Dev users.
 */
if (!defined('NFT_CHECKOUT_SANDBOX_ENVIRONMENT')) {
    define('NFT_CHECKOUT_SANDBOX_ENVIRONMENT', 'dev');
}

/**
 * Resolve active environment:
 * 'dev'        => dev.rt.app (Development)
 * 'qa'         => qa.rt.app  (QA)
 * 'production' => rt.app     (Live - default for clean installations)
 *
 * To force QA or Dev for both live and sandbox keys from plugin config only,
 * uncomment one of the lines below during testing (keep commented for production):
 * // define('NFT_CHECKOUT_ENVIRONMENT', 'qa');
 * // define('NFT_CHECKOUT_ENVIRONMENT', 'dev');
 */
if (!defined('NFT_CHECKOUT_ENVIRONMENT')) {
    $nft_checkout_default_env = 'production';
    if (function_exists('get_option')) {
        $nft_checkout_cfg_settings = get_option('woocommerce_nft-checkout_settings');
        if (empty($nft_checkout_cfg_settings)) {
            $nft_checkout_cfg_settings = get_option('woocommerce_nft_checkout_settings', []);
        }
        if (is_string($nft_checkout_cfg_settings) && function_exists('maybe_unserialize')) {
            $nft_checkout_cfg_settings = maybe_unserialize($nft_checkout_cfg_settings);
        }
        if (is_array($nft_checkout_cfg_settings) && isset($nft_checkout_cfg_settings['sandbox']) && $nft_checkout_cfg_settings['sandbox'] === 'yes') {
            $nft_checkout_sandbox_env = strtolower((string) NFT_CHECKOUT_SANDBOX_ENVIRONMENT);
            $nft_checkout_default_env = in_array($nft_checkout_sandbox_env, ['dev', 'development', 'local'], true)
                ? 'dev'
                : 'qa';
        }
    }
    define('NFT_CHECKOUT_ENVIRONMENT', $nft_checkout_default_env);
}

// Resolve target host: explicit NFT_CHECKOUT_HOST (e.g. from wp-config.php) always takes precedence.
if (!defined('NFT_CHECKOUT_HOST')) {
    $nft_checkout_active_env = strtolower((string) NFT_CHECKOUT_ENVIRONMENT);

    switch ($nft_checkout_active_env) {
        case 'dev':
        case 'development':
        case 'local':
            $nft_checkout_target_host = NFT_CHECKOUT_DEV_HOST;
            break;
        case 'qa':
        case 'staging':
            $nft_checkout_target_host = NFT_CHECKOUT_QA_HOST;
            break;
        case 'prod':
        case 'production':
        default:
            $nft_checkout_target_host = NFT_CHECKOUT_PROD_HOST;
            break;
    }

    define('NFT_CHECKOUT_HOST', $nft_checkout_target_host);
}

if (!defined('NFT_CHECKOUT_BASE_URL')) {
    // Accept an overridden host with or without a scheme / trailing slash, but always force HTTPS.
    $nft_checkout_parsed_host = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', (string) NFT_CHECKOUT_HOST);
    $nft_checkout_parsed_host = rtrim($nft_checkout_parsed_host, '/');
    if ($nft_checkout_parsed_host === '') {
        $nft_checkout_parsed_host = NFT_CHECKOUT_PROD_HOST;
    }
    define('NFT_CHECKOUT_BASE_URL', NFT_CHECKOUT_PROTOCOL . $nft_checkout_parsed_host);
}

if (!defined('NFT_CHECKOUT_API_BASE_URL')) {
    define('NFT_CHECKOUT_API_BASE_URL', NFT_CHECKOUT_BASE_URL . '/payment-api');
}

if (!defined('NFT_CHECKOUT_PLUGIN_VERSION')) {
    define('NFT_CHECKOUT_PLUGIN_VERSION', '1.0.2');
}
