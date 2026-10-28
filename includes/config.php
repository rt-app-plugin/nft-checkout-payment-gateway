<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// config.php
if (!defined('NFT_CHECKOUT_PROTOCOL')) {
    define('NFT_CHECKOUT_PROTOCOL', 'https://');
}

if (!defined('NFT_CHECKOUT_HOST')) {
    define('NFT_CHECKOUT_HOST', 'rt.app');
}

if (!defined('NFT_CHECKOUT_BASE_URL')) {
    $parsed_host = preg_replace('#^https?://#', '', NFT_CHECKOUT_HOST);
    $parsed_host = rtrim($parsed_host, '/');
	define('NFT_CHECKOUT_BASE_URL', NFT_CHECKOUT_PROTOCOL . $parsed_host);
}

if (!defined('NFT_CHECKOUT_PLUGIN_VERSION')) {
    define('NFT_CHECKOUT_PLUGIN_VERSION', '0.1.0');
}
