<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php
if (!defined('NFT_CHECKOUT_PROTOCOL')) {
    define('NFT_CHECKOUT_PROTOCOL', 'https://');
}

if (!defined('NFT_CHECKOUT_QA_HOST')) {
    define('NFT_CHECKOUT_QA_HOST', 'rt.app');
}

if (!defined('NFT_CHECKOUT_HOST')) {
    $nft_checkout_environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
    $nft_checkout_is_qa = in_array($nft_checkout_environment, ['development', 'staging', 'local'], true);
    define('NFT_CHECKOUT_HOST', $nft_checkout_is_qa ? NFT_CHECKOUT_QA_HOST : 'rt.app');
}

if (!defined('NFT_CHECKOUT_BASE_URL')) {
    $nft_checkout_parsed_host = preg_replace('#^https?://#', '', NFT_CHECKOUT_HOST);
    $nft_checkout_parsed_host = rtrim($nft_checkout_parsed_host, '/');
	define('NFT_CHECKOUT_BASE_URL', NFT_CHECKOUT_PROTOCOL . $nft_checkout_parsed_host);
}

if (!defined('NFT_CHECKOUT_PLUGIN_VERSION')) {
    define('NFT_CHECKOUT_PLUGIN_VERSION', '1.0.0');
}
