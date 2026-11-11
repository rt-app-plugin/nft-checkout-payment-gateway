=== NFT Checkout ===
Contributors: RT.app
Tags: woocommerce, payment gateway, fiat, RT.app
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
WC requires at least: 6.5.4
WC tested up to: 11.2.2
Requires Plugins: woocommerce
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The NFT Checkout plugin for WooCommerce 8.9+ allows you to accept fiat payments to sell products on your WooCommerce store.

== Description ==

This plugin integrates NFT Checkout with WooCommerce, enabling you to accept fiat payments. 

== Installation ==

1. Download the plugin ZIP file from GitHub.
2. Extract the ZIP file and upload it to the `wp-content/plugins` directory.
3. Activate the plugin through the 'Plugins' menu in WordPress.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Visit the RT.app website and log in to your account. Navigate to Developer Settings to generate or retrieve API keys.

== External Services ==

This plugin connects to the RT.app payment API (`https://rt.app`, or the sandbox host `https://qa.rt.app` / custom `NFT_CHECKOUT_HOST`) to verify merchant account status, check daily transaction limits, create payment vouchers during checkout, verify payment statuses, and cancel unpaid payment links when WooCommerce orders fail or are cancelled.

During checkout and order lifecycle events, order and customer billing details (order ID, amount, currency, billing name, email, phone number, billing address, line items, and IP address) are transmitted over HTTPS to the configured RT.app backend service.

* Service homepage: [https://rt.app](https://rt.app)
* Support & documentation: [https://rt.app/docs/wordpress-plugin](https://rt.app/docs/wordpress-plugin)

== Changelog ==

= 1.0.2 =
* Checkout wording: shoppers now see plain card-payment text ("Pay by card") instead of voucher/NFT wording. No payment changes.

= 1.0.1 =
* Security: the account status sync no longer sends secret keys to the API; accounts are identified by public key and the request is HMAC-signed.
* Security: all API requests are always sent over HTTPS, regardless of the store's own protocol.
* Staging/development sites talk to the QA host automatically; NFT_CHECKOUT_HOST in wp-config.php still overrides the host.
* Documentation and Support links now point at the production site.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.2 =
Customer-facing checkout wording update. No payment changes.

= 1.0.1 =
Security release: signed account sync and HTTPS-only API traffic. All stores should update.

= 1.0.0 =
Initial release.

== Support ==

For support, visit: [https://rt.app/contact-us](https://rt.app/contact-us)
