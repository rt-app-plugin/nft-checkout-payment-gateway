# NFT Checkout

Accept card payments in WooCommerce through RT's hosted checkout. When the customer places an order, RT e-mails them a secure payment link; they pay by card on RT's page (app.rt.app) and the order is marked paid once RT confirms it.

## Plugin information

**Contributors:** RT.app
**Tags:** woocommerce, payment gateway, card payments, RT.app
**Requires at least:** 6.2
**Tested up to:** 7.1
**Requires PHP:** 8.0
**WC requires at least:** 6.5.4
**WC tested up to:** 11.0
**Stable tag:** 1.0.2
**License:** GPLv3 or later
**License URI:** [GPLv3 License](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

For any issues or enhancement requests with this plugin, contact the RT.app support team at https://rt.app/contact-us. Provide your plugin, WooCommerce and WordPress versions to expedite troubleshooting.

## Getting started

1. Obtain your API keys from your RT merchant dashboard under Developer Settings > API Keys.
2. Install and activate the plugin (below).
3. Enter at least one account with its live public and secret keys, press "Sync Accounts" and enable the gateway.

## Installation

### Minimum requirements

- WordPress 6.2 or greater
- WooCommerce 6.5.4 or greater
- PHP 8.0 or greater

### Steps

1. Download the plugin ZIP file.
2. In WordPress go to Plugins > Add New > Upload Plugin and upload the ZIP, or extract it into `wp-content/plugins`.
3. Activate the plugin through the Plugins menu.
4. Go to WooCommerce > Settings > Payments > NFT Checkout.
5. Enable the gateway, add your account(s) and keys, and save. The plugin checks the keys with RT when you save.

## How a payment works

1. The customer chooses "Pay by card" and places the order. The order is created pending.
2. The plugin asks RT to e-mail the customer a voucher (`POST /payment-api/voucher/send`, signed with your secret key).
3. The customer opens the link in the e-mail and pays on RT's page.
4. RT posts a signed status update to `/wp-json/nft-checkout/v1/data`. The plugin verifies the signature with the secret key of the account that raised the order, checks that the payment id, exact amount, currency and order match, and only then marks the order paid.
5. The plugin also confirms open orders with RT (`POST /payment-api/update-txn-status`, signed) when the customer returns to the shop, on the thank-you page, in the background when you open a pending order, and every two hours.

## Configuration

- **Sandbox mode** - use your sandbox keys; no real funds are taken. An admin notice reminds you while it is on.
- **Order status** - Processing or Completed after a successful payment.
- **Show consent checkbox** - a consent checkbox at checkout (classic and block checkout).
- **Share plugin status with RT** - off by default. When on, the plugin reports its activation state, versions, domain and account titles/public keys to RT.
- **Host override** - every site talks to production (`https://app.rt.app/payment-api`) for both Sandbox and Live keys; the keys decide the mode. A test shop can return another RT address from the `rt_payment_api_base` filter (for example `https://dev.rt.app/payment-api`) or define `NFT_CHECKOUT_API_BASE_URL` in `wp-config.php`. The older `NFT_CHECKOUT_HOST` / `NFT_CHECKOUT_ENVIRONMENT` settings still work. The address must be on rt.app. Traffic is always HTTPS.

## External services and data

See the "External Services" section of `readme.txt` for every endpoint called, when, and what is sent. Secret keys are only ever used to sign requests and never leave your server; card details never pass through your shop.

## Security notes for developers

- The customer return link (`GET /wp-json/nft-checkout/v1/data?order_id=&key=`) never changes an order by itself; it triggers a signed, merchant-bound confirmation with RT.
- Webhooks require `X-Timestamp` and `X-Signature` = hex HMAC-SHA256 of `"<timestamp>.<raw body>"` keyed with the order's account secret, within 300 seconds, not replayed. No key, nonce or bearer token is accepted.
- An order is marked paid only when the payment id, exact amount, currency, order id and mode all match (`nft_checkout_verify_payment_claim()`); a paid order is never moved down automatically.
- `tests/harness/` holds a stub harness (no WordPress, no network) that exercises these paths: `php -n tests/harness/test_nft.php <plugin dir>`.
