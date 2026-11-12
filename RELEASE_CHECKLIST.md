# Release checklist

Security fixes have been lost before by branch merges that overwrote `includes/config.php`
and the account-sync code (NFT-01, NFT-03 and NFT-05 came back in commit `6d01ea9`). Run every
line below before tagging a release.

## Diff against the last audited commit

```
git diff <last-audited-commit> -- includes/config.php
git diff <last-audited-commit> -- includes/class-nft-checkout-payment-gateway-rest-api.php
git diff <last-audited-commit> -- includes/class-nft-checkout-payment-gateway-loader.php
git diff <last-audited-commit> -- includes/nft-checkout-payment-gateway-utils.php
```

Any change to the items below needs an explicit sign-off; a merge must never silently alter them.

## includes/config.php

- [ ] `NFT_CHECKOUT_PROTOCOL` is the literal `'https://'`. It must not use `is_ssl()` or any other store-dependent value.
- [ ] The default host is production: with nothing defined in `wp-config.php` and no `rt_payment_api_base` filter, `NFT_CHECKOUT_API_BASE_URL` is `https://app.rt.app/payment-api` (NFT-04). Sandbox and Live use the same address. Only `NFT_CHECKOUT_HOST` / `NFT_CHECKOUT_ENVIRONMENT` in `wp-config.php` may change it, and `NFT_CHECKOUT_HOST` wins.
- [ ] `NFT_CHECKOUT_BASE_URL` strips any scheme from an overridden host and prefixes `https://`.
- [ ] `NFT_CHECKOUT_API_BASE_URL` is the single setting; `NFT_CHECKOUT_BASE_URL` is derived from it. A value that is not on rt.app falls back to production, and every API call carries the `/payment-api` prefix.
- [ ] `NFT_CHECKOUT_PLUGIN_VERSION` matches the plugin header `Version:` and the `Stable tag` in `readme.txt` / `README.md`, and is higher than the version stores already run.

## Webhook and return link (`includes/class-nft-checkout-payment-gateway-rest-api.php`)

- [ ] The GET branch reads only `order_id` and `key`, verifies the order key with `hash_equals`, and never calls the state engine or stores a `pay_id` from the query string (NFT-01, NFT-03).
- [ ] The POST branch accepts only `X-Signature` / `X-Timestamp` verified with the secret of the account recorded on the order (`nft_checkout_get_order_account()`), 300 s skew, replay store. `grep -n "nonce\|Bearer\|verify_api_key" includes/class-nft-checkout-payment-gateway-rest-api.php` returns nothing that authenticates (NFT-02, NFT-08).
- [ ] `nft_checkout_verify_payment_claim()` runs before the engine: payment id, exact amount, currency, order id and mode must all be present and match (NFT-02).
- [ ] Only the signed JSON body is read; query-string and form parameters are never merged in.

## Status sync (`includes/class-nft-checkout-payment-gateway-loader.php`)

- [ ] `nft_checkout_refresh_order_from_remote()` calls `POST /payment-api/update-txn-status` only, signed with the order's account, and marks the order paid only after `nft_checkout_verify_payment_claim()` passes and the `redirect_url` names this site (NFT-03). No `check-payment-status`, `voucher/redeem` or unauthenticated fallback exists: `grep -n "check-payment-status\|voucher/redeem\|payment/sync-status" includes/` returns nothing.
- [ ] Admin list / edit screens queue background checks (`nft_checkout_schedule_async_sync`); nothing calls RT while an admin page renders (NFT-15).

## No secrets on the wire (NFT-05)

- [ ] `grep -rn "secret_key'\s*=>\|api_secret" includes/` shows no request payload. The account sync sends `timestamp` + `signature` per account; every other call uses `nft_checkout_signed_headers()`.
- [ ] The signature format has been confirmed with the payment API team for this release (backend branch `feature/nft-plugin-security-v1.0.2` or later).

## State engine (`includes/class-nft-checkout-payment-state-engine.php`)

- [ ] `get_state()` reports `success` for any order whose WooCommerce status is processing/completed or `is_paid()`, and nothing transitions out of `success` (NFT-07).
- [ ] Every event type is deduplicated (NFT-08).

## Checkout (`includes/class-nft-checkout-payment-gateway.php`, `assets/js/`)

- [ ] `process_payment()` refuses an order for which `needs_payment()` is false (NFT-06) and returns `redirect` at the top level (NFT-12).
- [ ] No `wp_ajax_nft_checkout_block_gateway_process` handler exists; the block checkout uses the Store API (NFT-11).
- [ ] `assets/js/nft-checkout.js` contains no input "sanitization" of billing fields (NFT-10).
- [ ] `nft-checkout-payment-gateway.php` does not hook `woocommerce_cancel_unpaid_order` (NFT-09).

## Legacy names (NFT-13, NFT-18)

- [ ] `grep -rni "unified\|voucher pay\|dfin" --include=*.php --include=*.js .` returns only the migration/uninstall comments about the old shared table.
- [ ] Transients, cron hooks and the DB table are `rt_nft_checkout_` / `rt_nft_` prefixed.

## Telemetry (NFT-17)

- [ ] `nft_checkout_send_plugin_status()` returns early unless `share_plugin_status` is `yes` and keys exist. Activation makes no other RT call.
- [ ] `readme.txt` has an "External Services" section that matches the endpoints actually called.

## Regression tests

- [ ] `php -n tests/harness/test_nft.php <plugin dir>` passes (0 failed).
- [ ] Classic and block checkout, HPOS on and off, alongside another gateway, with test keys only.
- [ ] Webhooks: valid, expired, replayed, wrong amount, wrong currency, wrong order, unsigned, signed with another account's secret.
- [ ] Customer return URL confirms the payment with RT and never completes the order from the query string.
- [ ] The 2-hour cron completes paid pending orders.
- [ ] Account sync with the signed request.
- [ ] Cancellation on a store with two accounts.
- [ ] Sandbox mode.
- [ ] A staging site overriding `NFT_CHECKOUT_HOST` in `wp-config.php`.
