=== NFT Checkout ===
Contributors: RT.app
Tags: woocommerce, payment gateway, fiat, RT.app
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
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

Visit the DFin website and log in to your account. Navigate to Developer Settings to generate or retrieve API keys.

== Changelog ==

= 0.1.0 =
* New voucher checkout: placing an order emails the customer a redeemable voucher, and the payment page opens only when they use it. No payment link is opened during checkout.
* The order confirmation shown at checkout now includes the voucher reference and the products ordered.
* Ordered products, with their images, are sent with the voucher so customers can see what they are paying for.
* Fixed payment account settings not being read consistently across the plugin.

= 1.0.2 =
* Improved failed payment handling by preventing duplicate payment failure notices when redirecting customers back to the checkout page.
* Expanded PO Box detection to support additional address variations such as `POB`, `Postal Box`, and `Post Office Box`.
* Enhanced billing address validation by requiring a minimum of 5 characters and rejecting numeric-only street addresses to help prevent invalid or incomplete address submissions.

= 1.0.1 =
* Resolved mobile number validation issues during checkout.
* Fixed ZIP/postal code validation to support valid customer inputs.
* Corrected PO Box address validation.
* Fixed invalid API key validation and improved error handling.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.0 =
* Switches checkout to the voucher flow: customers receive a voucher by email and complete payment when they open it. Re-check your payment account settings after updating.

= 1.0.2 =
* Fixes duplicate payment failure notices on redirect, enhances PO Box address detection, and strengthens billing address validation.

= 1.0.1 =
* This release improves the payment experience by resolving mobile number, ZIP/postal code, and PO Box validation issues. It also fixes invalid API key handling to improve plugin reliability and ensure a smoother checkout experience.

= 1.0.0 =
Initial release.

== Support ==

For support, visit: [https://qa.rt.app/contact-us](https://qa.rt.app/contact-us)
