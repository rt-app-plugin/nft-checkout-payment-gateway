<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Check the environment for compatibility issues.
 *
 * @return string|false
 */
function nft_checkout_check_system_requirements()
{
	if (version_compare(phpversion(), NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_PHP_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required PHP version, %2$s is the current PHP version
			__('NFT Checkout needs PHP version %1$s or newer. This site runs %2$s. Ask your web host to update PHP.', 'nft-checkout-payment-gateway'),
			NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_PHP_VER,
			phpversion()
		);
	}

	// Get WooCommerce versions
	$wc_db_version = get_option('woocommerce_db_version');
	$wc_plugin_version = defined('WC_VERSION') ? WC_VERSION : null;

	// Check if the WooCommerce database version is outdated
	if (!$wc_db_version || version_compare($wc_db_version, NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce database version, %2$s is the current WooCommerce database version (or "undefined" if not available)
			__('NFT Checkout needs WooCommerce database version %1$s or newer. This site has %2$s. Update WooCommerce and run its database update.', 'nft-checkout-payment-gateway'),
			NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_db_version ? $wc_db_version : __('an unknown version', 'nft-checkout-payment-gateway')
		);
	}

	// Check if WooCommerce plugin version is outdated
	if (!$wc_plugin_version || version_compare($wc_plugin_version, NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER, '<')) {
		return sprintf(
			// translators: %1$s is the minimum required WooCommerce plugin version, %2$s is the current WooCommerce plugin version (or "undefined" if not available)
			__('NFT Checkout needs WooCommerce version %1$s or newer. This site has %2$s. Update WooCommerce.', 'nft-checkout-payment-gateway'),
			NFT_CHECKOUT_PAYMENT_GATEWAY_MIN_WC_VER,
			$wc_plugin_version ? $wc_plugin_version : __('an unknown version', 'nft-checkout-payment-gateway')
		);
	}

	return false;
}


if (!function_exists('nft_checkout_add_unique_order_note')) {

    function nft_checkout_add_unique_order_note($order, $key, $message)
    {
        if (!$order instanceof WC_Order) {
            return false;
        }

        if (empty($message)) {
            return false;
        }

        // Plugin identifier (IMPORTANT for tracking in WP admin)
        $plugin_prefix = '<strong>NFT Checkout</strong>';

        // Unique meta key per note type (scoped to plugin)
        $meta_key = '_nft_checkout_order_note_' . sanitize_key($key);

        // Check if already exists
        $existing = $order->get_meta($meta_key, true);

        if (!empty($existing)) {
            return false;
        }

        // Prepend plugin identifier to every note
        $final_message = $plugin_prefix . "\n\n" . wp_kses_post($message);

        // Add WooCommerce order note
        $order->add_order_note($final_message);

        // Store timestamp using WooCommerce timezone-aware time
        $order->update_meta_data($meta_key, current_time('timestamp'));

        $order->save();

        return true;
    }
}

/* =========================================================================
 * GATEWAY IDENTITY
 * ========================================================================= */

/**
 * Every gateway id an order of this plugin may carry.
 *
 * @return string[]
 */
function nft_checkout_gateway_ids()
{
	return [NFT_CHECKOUT_GATEWAY_ID, 'nft_checkout'];
}

/**
 * Whether an order was placed through this gateway.
 *
 * @param WC_Order|mixed $order
 * @return bool
 */
function nft_checkout_is_gateway_order($order)
{
	if (!$order instanceof WC_Order) {
		return false;
	}
	return in_array((string) $order->get_payment_method(), nft_checkout_gateway_ids(), true);
}

/* =========================================================================
 * SETTINGS / ACCOUNTS (NFT-13: this plugin's own options only)
 * ========================================================================= */

/**
 * The gateway settings WooCommerce stores under the gateway id.
 *
 * @return array
 */
function nft_checkout_get_settings()
{
	$settings = get_option(NFT_CHECKOUT_SETTINGS_OPTION, []);
	if (is_string($settings)) {
		$settings = maybe_unserialize($settings);
	}
	return is_array($settings) ? $settings : [];
}

/**
 * Whether the gateway runs in sandbox mode.
 *
 * @return bool
 */
function nft_checkout_is_sandbox()
{
	$settings = nft_checkout_get_settings();
	return isset($settings['sandbox']) && $settings['sandbox'] === 'yes';
}

/**
 * NFT-17: whether the merchant agreed to share the plugin's status (domain,
 * versions, account titles and public keys) with RT. Off until they opt in.
 *
 * @return bool
 */
function nft_checkout_telemetry_allowed()
{
	$settings = nft_checkout_get_settings();
	return isset($settings['share_plugin_status']) && $settings['share_plugin_status'] === 'yes';
}

/**
 * The configured merchant accounts, as a list of account arrays.
 *
 * @return array[]
 */
function nft_checkout_get_accounts()
{
	$accounts = get_option(NFT_CHECKOUT_ACCOUNTS_OPTION, []);
	if (is_string($accounts)) {
		$accounts = maybe_unserialize($accounts);
	}
	if (!is_array($accounts)) {
		return [];
	}
	if (isset($accounts['live_secret_key']) || isset($accounts['sandbox_secret_key']) || isset($accounts['live_public_key'])) {
		$accounts = [$accounts];
	}
	return array_values(array_filter($accounts, 'is_array'));
}

/**
 * The key pair of one configured account in one mode.
 *
 * @param array $account
 * @param bool  $sandbox
 * @return array ['public_key' => string, 'secret_key' => string, 'is_sandbox' => int, 'unique_id' => string, 'title' => string]
 */
function nft_checkout_account_credentials(array $account, $sandbox)
{
	return [
		'public_key' => (string) ($sandbox ? ($account['sandbox_public_key'] ?? '') : ($account['live_public_key'] ?? '')),
		'secret_key' => (string) ($sandbox ? ($account['sandbox_secret_key'] ?? '') : ($account['live_secret_key'] ?? '')),
		'is_sandbox' => $sandbox ? 1 : 0,
		'unique_id'  => (string) ($account['unique_id'] ?? ''),
		'title'      => (string) ($account['title'] ?? ''),
	];
}

/**
 * NFT-02 / NFT-08: the account that raised an order's voucher, found by the
 * public key (and account id) the order recorded when the voucher was sent.
 * The mode is fixed by where that public key lives, not by the current
 * sandbox setting. Null when the order names no configured account: such an
 * order cannot be verified against RT and is never marked paid.
 *
 * @param WC_Order $order
 * @return array|null See nft_checkout_account_credentials().
 */
function nft_checkout_get_order_account($order)
{
	if (!$order instanceof WC_Order) {
		return null;
	}

	$order_public_key = (string) $order->get_meta('_nft_checkout_account_public_key');
	$order_unique_id  = (string) $order->get_meta('_nft_checkout_account_unique_id');

	if ($order_public_key === '' && $order_unique_id === '') {
		return null;
	}

	foreach (nft_checkout_get_accounts() as $account) {
		foreach ([false, true] as $sandbox) {
			$creds = nft_checkout_account_credentials($account, $sandbox);
			if ($creds['public_key'] === '' || $creds['secret_key'] === '') {
				continue;
			}
			if ($order_public_key !== '' && hash_equals($creds['public_key'], $order_public_key)) {
				return $creds;
			}
		}
	}

	// A recorded public key that matches no configured account is final: the
	// account was removed or its keys rotated, and nothing else may stand in.
	if ($order_public_key !== '') {
		return null;
	}

	// Older orders recorded only the account id; its key pair in the mode the
	// order was placed in is the one.
	if ($order_unique_id !== '') {
		$sandbox = (bool) $order->get_meta('_is_test_order');
		foreach (nft_checkout_get_accounts() as $account) {
			if (!empty($account['unique_id']) && hash_equals((string) $account['unique_id'], $order_unique_id)) {
				$creds = nft_checkout_account_credentials($account, $sandbox);
				if ($creds['public_key'] !== '' && $creds['secret_key'] !== '') {
					return $creds;
				}
			}
		}
	}

	return null;
}

/**
 * Whether the merchant has entered at least one usable key pair. Nothing is
 * sent to RT before this is true (NFT-17).
 *
 * @return bool
 */
function nft_checkout_is_configured()
{
	foreach (nft_checkout_get_accounts() as $account) {
		if (
			(!empty($account['live_public_key']) && !empty($account['live_secret_key'])) ||
			(!empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']))
		) {
			return true;
		}
	}
	return false;
}

/* =========================================================================
 * REQUEST SIGNING (NFT-05)
 * ========================================================================= */

/* =========================================================================
 * ACCOUNT STATUS WORDING
 * ========================================================================= */

/**
 * Plain words for each account status, for the settings screen.
 *
 * @return array status => [label, help]
 */
function nft_checkout_account_status_texts()
{
	return [
		'active'        => [
			__('Connected', 'nft-checkout-payment-gateway'),
			__('RT accepted these keys. Shoppers can pay by card.', 'nft-checkout-payment-gateway'),
		],
		'pending'       => [
			__('Waiting for RT approval', 'nft-checkout-payment-gateway'),
			__('RT has your keys but your account is not approved yet. Shoppers cannot pay by card until it is. Check your RT account for anything left to finish.', 'nft-checkout-payment-gateway'),
		],
		'inactive'      => [
			__('Not active', 'nft-checkout-payment-gateway'),
			__('This account cannot take payments right now. Check your RT account, or add keys for this mode.', 'nft-checkout-payment-gateway'),
		],
		'invalid'       => [
			__('Keys not accepted', 'nft-checkout-payment-gateway'),
			__('RT did not accept these keys. Check that the publishable and secret keys are from the same RT account and the same mode (Sandbox or Live).', 'nft-checkout-payment-gateway'),
		],
		'not_connected' => [
			__('Could not connect', 'nft-checkout-payment-gateway'),
			__('We could not reach RT to check these keys. Press "Check with RT" to try again.', 'nft-checkout-payment-gateway'),
		],
		'unknown'       => [
			__('Not checked yet', 'nft-checkout-payment-gateway'),
			__('These keys have not been checked with RT yet. Save your changes, then press "Check with RT".', 'nft-checkout-payment-gateway'),
		],
	];
}

/**
 * What to show for one account's status in the current mode.
 *
 * @param string $status     Stored status word.
 * @param bool   $is_sandbox Whether test mode is on.
 * @return array ['class' => string, 'label' => string, 'help' => string]
 */
function nft_checkout_account_status_view($status, $is_sandbox)
{
	$texts  = nft_checkout_account_status_texts();
	$status = strtolower(trim((string) $status));
	if (!isset($texts[$status])) {
		$status = 'unknown';
	}
	$mode = $is_sandbox
		? __('Sandbox', 'nft-checkout-payment-gateway')
		: __('Live', 'nft-checkout-payment-gateway');

	return [
		'class' => str_replace('_', '-', $status),
		'label' => $mode . ': ' . $texts[$status][0],
		'help'  => $texts[$status][1],
	];
}

/**
 * Headers for a call to the RT payment API: the public key as bearer and an
 * HMAC-SHA256 signature of "<timestamp>.<raw body>" made with the secret key.
 * The secret itself is never sent.
 *
 * @param string $public_key
 * @param string $secret_key
 * @param string $raw_body   The exact body bytes that will be sent.
 * @param string $content_type
 * @return array
 */
function nft_checkout_signed_headers($public_key, $secret_key, $raw_body, $content_type = 'application/json')
{
	$timestamp = (string) time();
	return [
		'Content-Type'  => $content_type,
		'Accept'        => 'application/json',
		'Authorization' => 'Bearer ' . sanitize_text_field($public_key),
		'X-Timestamp'   => $timestamp,
		'X-Signature'   => hash_hmac('sha256', $timestamp . '.' . (string) $raw_body, (string) $secret_key),
	];
}

/**
 * Proof of an account's secret for /sync-account-status: a signature over
 * "<timestamp>.<public_key>", so the secret stays on the shop.
 *
 * @param string $public_key
 * @param string $secret_key
 * @return array ['timestamp' => string, 'signature' => string]
 */
function nft_checkout_account_signature($public_key, $secret_key)
{
	$timestamp = (string) time();
	return [
		'timestamp' => $timestamp,
		'signature' => hash_hmac('sha256', $timestamp . '.' . (string) $public_key, (string) $secret_key),
	];
}

/* =========================================================================
 * PAYMENT IDS
 * ========================================================================= */

/**
 * RT names one payment two ways: the plain uuid (the /p/{uuid} link) and its
 * base64 (what webhooks and request-payment carry as pay_id). Both spellings
 * of one id, so comparisons and lookups work whichever was stored.
 *
 * @param string $pay_id
 * @return string[]
 */
function nft_checkout_pay_id_variants($pay_id)
{
	$pay_id = trim((string) $pay_id);
	if ($pay_id === '') {
		return [];
	}
	$variants = [$pay_id];

	$decoded = base64_decode($pay_id, true);
	if ($decoded !== false && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($decoded))) {
		$variants[] = trim($decoded);
	} elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $pay_id)) {
		$variants[] = base64_encode($pay_id);
		$variants[] = rtrim(base64_encode($pay_id), '=');
	}

	return array_values(array_unique($variants));
}

/**
 * Whether two payment ids name the same payment, in any spelling.
 *
 * @param string $a
 * @param string $b
 * @return bool
 */
function nft_checkout_pay_ids_match($a, $b)
{
	$a = trim((string) $a);
	$b = trim((string) $b);
	if ($a === '' || $b === '') {
		return false;
	}
	foreach (nft_checkout_pay_id_variants($a) as $variant_a) {
		foreach (nft_checkout_pay_id_variants($b) as $variant_b) {
			if (hash_equals($variant_a, $variant_b)) {
				return true;
			}
		}
	}
	return false;
}

/**
 * A plain sentence for an order note about what RT says of a payment.
 *
 * @param string $state Engine state word.
 * @return string
 */
function nft_checkout_plain_status_sentence($state)
{
	switch (strtolower((string) $state)) {
		case 'success':
			return __('Checked with RT: the customer has paid.', 'nft-checkout-payment-gateway');
		case 'processing':
			return __('Checked with RT: the payment is being processed. No action is needed yet.', 'nft-checkout-payment-gateway');
		case 'failed':
			return __('Checked with RT: the payment did not go through. No money was received.', 'nft-checkout-payment-gateway');
		case 'cancelled':
			return __('Checked with RT: the customer cancelled the payment. No money was received.', 'nft-checkout-payment-gateway');
		case 'expired':
			return __('Checked with RT: the payment link closed before the customer paid. No money was received.', 'nft-checkout-payment-gateway');
		default:
			return __('Checked with RT: the customer has not paid yet.', 'nft-checkout-payment-gateway');
	}
}

/* =========================================================================
 * PAYMENT EMAILS SENT FOR AN ORDER (NFT-01)
 * ========================================================================= */

/**
 * Every payment email (voucher) this shop asked RT to send for the order.
 *
 * @param WC_Order $order
 * @return array[] Each: reference, voucher_id, public_key, time.
 */
function nft_checkout_order_vouchers($order)
{
	if (!$order instanceof WC_Order) {
		return [];
	}
	$history = $order->get_meta('_nft_checkout_voucher_history');
	$history = is_array($history) ? array_values(array_filter($history, 'is_array')) : [];

	// Orders placed before the history existed still carry their last voucher.
	$last_reference = (string) $order->get_meta('_nft_checkout_voucher_reference');
	$last_id        = (string) $order->get_meta('_nft_checkout_voucher_id');
	if ($last_reference !== '' || $last_id !== '') {
		$seen = false;
		foreach ($history as $entry) {
			if (
				($last_reference !== '' && (string) ($entry['reference'] ?? '') === $last_reference) ||
				($last_id !== '' && (string) ($entry['voucher_id'] ?? '') === $last_id)
			) {
				$seen = true;
				break;
			}
		}
		if (!$seen) {
			$history[] = ['reference' => $last_reference, 'voucher_id' => $last_id, 'public_key' => '', 'time' => 0];
		}
	}
	return $history;
}

/**
 * Remember a payment email that was just sent for the order, and note that
 * the order is now waiting to learn that email's payment id.
 *
 * @param WC_Order $order
 * @param string   $reference
 * @param string   $voucher_id
 * @param string   $public_key
 */
function nft_checkout_remember_voucher($order, $reference, $voucher_id, $public_key)
{
	if (!$order instanceof WC_Order) {
		return;
	}
	$reference  = sanitize_text_field((string) $reference);
	$voucher_id = sanitize_text_field((string) $voucher_id);
	if ($reference === '' && $voucher_id === '') {
		return;
	}

	$history = $order->get_meta('_nft_checkout_voucher_history');
	$history = is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
	foreach ($history as $entry) {
		if (
			(string) ($entry['reference'] ?? '') === $reference &&
			(string) ($entry['voucher_id'] ?? '') === $voucher_id
		) {
			// RT handed back the same open voucher: nothing new to wait for.
			return;
		}
	}
	$history[] = [
		'reference'  => $reference,
		'voucher_id' => $voucher_id,
		'public_key' => sanitize_text_field((string) $public_key),
		'time'       => time(),
	];
	$order->update_meta_data('_nft_checkout_voucher_history', array_slice($history, -20));
	$order->update_meta_data('_nft_checkout_voucher_awaiting_pay_id', $reference !== '' ? $reference : $voucher_id);
}

/**
 * Whether an authenticated message may introduce a payment id this order
 * has not seen yet.
 *
 * RT creates the payment link (and its id) only when the shopper opens the
 * payment email, so the shop cannot know the id in advance. When a first
 * payment fails and the shopper pays again, the shop sends a new email and
 * the payment that follows carries a new id. That id is accepted when:
 *
 *  - the order is not paid yet (a paid order is never paid twice),
 *  - the order really did send a payment email, and
 *  - a voucher reference or id in the message, if RT includes one, is one
 *    this order was issued.
 *
 * The caller has already authenticated the source (signature made with the
 * order's own account secret, or the answer to this shop's signed lookup),
 * and the amount, currency, order number and mode are still checked exactly.
 *
 * @param WC_Order $order
 * @param array    $data  The message.
 * @return bool
 */
function nft_checkout_may_learn_new_pay_id($order, array $data)
{
	if (!$order instanceof WC_Order || $order->is_paid()) {
		return false;
	}
	$vouchers = nft_checkout_order_vouchers($order);
	if (empty($vouchers)) {
		return false;
	}

	$named = [];
	foreach (['reference', 'voucher_reference', 'voucher_id'] as $field) {
		if (isset($data[$field]) && is_scalar($data[$field]) && trim((string) $data[$field]) !== '') {
			$named[] = trim((string) $data[$field]);
		}
	}
	if (empty($named)) {
		return true;
	}
	foreach ($vouchers as $voucher) {
		foreach ($named as $value) {
			if (
				((string) ($voucher['reference'] ?? '') !== '' && hash_equals((string) $voucher['reference'], $value)) ||
				((string) ($voucher['voucher_id'] ?? '') !== '' && hash_equals((string) $voucher['voucher_id'], $value))
			) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Whether a "failed", "cancelled" or "expired" report is about an older
 * payment link than the one the shopper is now using.
 *
 * Each "pay again" sends a new payment email with a new link. News that an
 * older link closed says nothing about the current one, so it must not fail
 * the order.
 *
 * @param WC_Order $order
 * @param string   $pay_id   The payment id the report is about.
 * @param string[] $known_ids
 * @return bool
 */
function nft_checkout_is_superseded_pay_id($order, $pay_id, array $known_ids)
{
	if (!$order instanceof WC_Order || trim((string) $pay_id) === '') {
		return false;
	}
	$known = false;
	foreach ($known_ids as $known_id) {
		if (nft_checkout_pay_ids_match($known_id, $pay_id)) {
			$known = true;
			break;
		}
	}
	if (!$known) {
		return false;
	}
	// A newer payment email has been sent and its payment id is not known yet.
	if ((string) $order->get_meta('_nft_checkout_voucher_awaiting_pay_id') !== '') {
		return true;
	}
	$latest = (string) $order->get_meta('_nft_checkout_latest_pay_id');
	return $latest !== '' && !nft_checkout_pay_ids_match($latest, $pay_id);
}

/* =========================================================================
 * PAYMENT CLAIM VALIDATION (NFT-02 / NFT-03)
 * ========================================================================= */

/**
 * Format an amount the way every comparison in this plugin does: two
 * decimals, dot separator, no thousands separator.
 *
 * @param mixed $amount
 * @return string
 */
function nft_checkout_format_amount($amount)
{
	return number_format((float) $amount, 2, '.', '');
}

/**
 * The one check every status path runs before an order is treated as paid.
 * Fails closed: every field must be present and must match.
 *
 *  - the payment id must be one the order was issued; an order that has no
 *    payment id yet (the voucher's link is created when the customer opens
 *    the e-mail) may learn its first id from an authenticated source only
 *    (`$allow_first_id`: a signed webhook or a signed merchant lookup);
 *  - the amount must equal the order total exactly;
 *  - the currency must match;
 *  - the order id must name this order;
 *  - a mode in the payload must be the mode of the order's account.
 *
 * @param WC_Order $order
 * @param array    $data            The payload (webhook body or status response).
 * @param string   $pay_id          The payment id the payload is about.
 * @param string[] $known_ids       Every payment id the order was issued.
 * @param bool     $allow_first_id  Accept $pay_id when the order has none yet.
 * @param int|null $account_mode    1 sandbox / 0 live, of the order's account.
 * @param bool     $allow_new_id    Accept a payment id the order has not seen
 *                                  yet. Only passed as true by an authenticated
 *                                  source (RT's signed message, or RT's answer
 *                                  to this shop's own signed lookup) for an
 *                                  order that sent the shopper a new payment
 *                                  email: see nft_checkout_may_learn_new_pay_id().
 * @return true|WP_Error
 */
function nft_checkout_verify_payment_claim($order, array $data, $pay_id, array $known_ids, $allow_first_id = false, $account_mode = null, $allow_new_id = false)
{
	if (!$order instanceof WC_Order) {
		return new WP_Error('nft_checkout_no_order', 'Order not found.');
	}

	$pay_id = trim((string) $pay_id);
	if ($pay_id === '') {
		return new WP_Error('nft_checkout_pay_id_required', 'Payment ID required.');
	}
	$known_ids = array_values(array_filter(array_map('strval', $known_ids), 'strlen'));
	$id_matches = false;
	foreach ($known_ids as $known_id) {
		if (nft_checkout_pay_ids_match($known_id, $pay_id)) {
			$id_matches = true;
			break;
		}
	}
	if (!$id_matches && !(empty($known_ids) && $allow_first_id) && !$allow_new_id) {
		return new WP_Error('nft_checkout_pay_id_mismatch', 'Payment ID mismatch.');
	}

	$raw_amount = $data['amount'] ?? ($data['total_amount'] ?? null);
	if ($raw_amount === null || $raw_amount === '' || is_array($raw_amount) || !is_numeric($raw_amount)) {
		return new WP_Error('nft_checkout_amount_required', 'Amount required.');
	}
	if (nft_checkout_format_amount($raw_amount) !== nft_checkout_format_amount($order->get_total())) {
		return new WP_Error('nft_checkout_amount_mismatch', 'Amount mismatch.');
	}

	$raw_currency = $data['curr_code'] ?? ($data['currency'] ?? '');
	if (!is_string($raw_currency) || trim($raw_currency) === '') {
		return new WP_Error('nft_checkout_currency_required', 'Currency required.');
	}
	if (strtoupper(trim(sanitize_text_field($raw_currency))) !== strtoupper(trim((string) $order->get_currency()))) {
		return new WP_Error('nft_checkout_currency_mismatch', 'Currency mismatch.');
	}

	$raw_order_id = $data['order_id'] ?? ($data['order_reference'] ?? ($data['meta_data']['order_id'] ?? null));
	if ($raw_order_id === null || $raw_order_id === '' || is_array($raw_order_id) || is_bool($raw_order_id)) {
		return new WP_Error('nft_checkout_order_required', 'Order ID required.');
	}
	if ((string) absint($raw_order_id) !== (string) $order->get_id()) {
		return new WP_Error('nft_checkout_order_mismatch', 'Order mismatch.');
	}

	if ($account_mode !== null && isset($data['mode']) && is_string($data['mode']) && $data['mode'] !== '') {
		$payload_sandbox = strtolower(trim($data['mode'])) === 'sandbox' ? 1 : 0;
		if ($payload_sandbox !== (int) $account_mode) {
			return new WP_Error('nft_checkout_mode_mismatch', 'Mode mismatch.');
		}
	}

	return true;
}

/* =========================================================================
 * DATABASE (NFT-13: own table)
 * ========================================================================= */

/**
 * This plugin's payment-link table name, with the site prefix.
 *
 * @return string
 */
function nft_checkout_table_name()
{
	global $wpdb;
	return $wpdb->prefix . NFT_CHECKOUT_TABLE;
}

/**
 * Create this plugin's payment-link table (idempotent).
 */
function nft_checkout_create_tables()
{
	global $wpdb;

	$table_name      = nft_checkout_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		order_id bigint(20) unsigned NOT NULL,
		uuid varchar(255) NOT NULL DEFAULT '',
		payment_link text DEFAULT '',
		customer_email varchar(191) DEFAULT '',
		amount varchar(50) DEFAULT '0.00',
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY order_id (order_id),
		KEY uuid (uuid(191))
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);

	wp_cache_set('nft_checkout_table_exists', $table_name, 'nft_checkout_payment_gateway', HOUR_IN_SECONDS);
}

/**
 * Whether this plugin's payment-link table is present.
 *
 * @param bool $create Create it when missing.
 * @return bool
 */
function nft_checkout_order_payment_link_table_exists($create = false)
{
	global $wpdb;

	$table_name = nft_checkout_table_name();

	if (wp_cache_get('nft_checkout_table_exists', 'nft_checkout_payment_gateway') === $table_name) {
		return true;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
	if ($exists === $table_name) {
		wp_cache_set('nft_checkout_table_exists', $table_name, 'nft_checkout_payment_gateway', HOUR_IN_SECONDS);
		return true;
	}

	if (!$create) {
		return false;
	}

	nft_checkout_create_tables();
	return true;
}

/**
 * The newest payment-link row of an order.
 *
 * @param int $order_id
 * @return array|null
 */
function nft_checkout_get_order_payment_link($order_id)
{
	global $wpdb;

	$order_id = absint($order_id);
	if ($order_id <= 0 || !nft_checkout_order_payment_link_table_exists()) {
		return null;
	}

	$cache_key   = 'nft_checkout_payment_row_' . $order_id;
	$cache_group = defined('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP') ? NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP : 'nft_checkout_payment_gateway';
	$cached      = wp_cache_get($cache_key, $cache_group);
	if (is_array($cached)) {
		return $cached;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 1',
			nft_checkout_table_name(),
			$order_id
		),
		ARRAY_A
	);

	if (is_array($row)) {
		wp_cache_set($cache_key, $row, $cache_group, 60);
		return $row;
	}

	return null;
}

/**
 * Record the payment link RT created for an order: one row per payment id.
 * Only ever written from an authenticated source (a signed webhook, a signed
 * status lookup), never from the customer's browser.
 *
 * @param WC_Order $order
 * @param array    $data  uuid, payment_link, customer_email, amount.
 * @return bool
 */
function nft_checkout_store_order_payment_link($order, $data = [])
{
	global $wpdb;

	if (!$order instanceof WC_Order) {
		return false;
	}

	$order_id = (int) $order->get_id();
	$uuid     = sanitize_text_field(is_scalar($data['uuid'] ?? null) ? (string) $data['uuid'] : '');

	if ($order_id <= 0 || $uuid === '') {
		return false;
	}

	if (!nft_checkout_order_payment_link_table_exists(true)) {
		return false;
	}

	$table_name = nft_checkout_table_name();

	$payment_link   = esc_url_raw(is_scalar($data['payment_link'] ?? null) ? (string) $data['payment_link'] : '');
	$customer_email = sanitize_email(is_scalar($data['customer_email'] ?? null) ? (string) $data['customer_email'] : '');
	if ($customer_email === '') {
		$customer_email = sanitize_email($order->get_billing_email());
	}
	$amount = nft_checkout_format_amount(isset($data['amount']) && is_numeric($data['amount']) ? $data['amount'] : $order->get_total());

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing = $wpdb->get_row(
		$wpdb->prepare('SELECT id, uuid, payment_link FROM %i WHERE order_id = %d AND uuid = %s LIMIT 1', $table_name, $order_id, $uuid),
		ARRAY_A
	);

	if (is_array($existing)) {
		if ($payment_link !== '' && $existing['payment_link'] !== $payment_link) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update($table_name, ['payment_link' => $payment_link], ['id' => (int) $existing['id']], ['%s'], ['%d']);
		}
		wp_cache_delete('nft_checkout_payment_row_' . $order_id, defined('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP') ? NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP : 'nft_checkout_payment_gateway');
		return true;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$written = $wpdb->insert(
		$table_name,
		[
			'order_id'       => $order_id,
			'uuid'           => $uuid,
			'payment_link'   => $payment_link,
			'customer_email' => $customer_email,
			'amount'         => $amount,
			'created_at'     => current_time('mysql', 1),
		],
		['%d', '%s', '%s', '%s', '%s', '%s']
	);

	wp_cache_delete('nft_checkout_payment_row_' . $order_id, defined('NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP') ? NFT_CHECKOUT_PAYMENT_ROW_CACHE_GROUP : 'nft_checkout_payment_gateway');

	if (false === $written) {
		NFT_Checkout_Payment_Gateway_Logger::error(
			'Failed to store NFT Checkout order payment link.',
			['order_id' => $order_id, 'error' => $wpdb->last_error]
		);
		return false;
	}

	NFT_Checkout_Payment_Gateway_Logger::info(
		'Stored NFT Checkout order payment link.',
		['order_id' => $order_id, 'uuid' => $uuid, 'amount' => $amount]
	);

	return true;
}

/* =========================================================================
 * RATE LIMITING / LOGGING HELPERS (NFT-14, NFT-16)
 * ========================================================================= */

/**
 * Who a public request is counted against: the WooCommerce session when
 * there is one, else the client IP as WooCommerce resolves it, so one
 * shopper behind a CDN cannot lock the checkout for everyone sharing its IP.
 *
 * @return string
 */
function nft_checkout_rate_limit_subject()
{
	if (function_exists('WC') && WC() && WC()->session && method_exists(WC()->session, 'get_customer_id')) {
		$customer_id = (string) WC()->session->get_customer_id();
		if ($customer_id !== '') {
			return 'session:' . $customer_id;
		}
	}
	$ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
	return 'ip:' . (filter_var($ip, FILTER_VALIDATE_IP) ?: 'unknown');
}

/**
 * An IP fit for a log line.
 *
 * @param string $ip
 * @return string
 */
function nft_checkout_anonymize_ip($ip)
{
	if (function_exists('wp_privacy_anonymize_ip')) {
		return wp_privacy_anonymize_ip((string) $ip);
	}
	return '***';
}

/**
 * A short, secret-free excerpt of an API response body for a log line.
 *
 * @param mixed $body
 * @param int   $limit
 * @return string
 */
function nft_checkout_log_excerpt($body, $limit = 200)
{
	$text = is_scalar($body) ? (string) $body : wp_json_encode($body);
	$text = preg_replace('/("?(?:secret|api_secret|secret_key|api_secret_key|token|signature)"?\s*[:=]\s*)"[^"]*"/i', '$1"[redacted]"', (string) $text);
	return mb_substr(trim((string) $text), 0, $limit);
}

if (!function_exists('nft_checkout_mask_email')) {
	function nft_checkout_mask_email($email) {
		if (empty($email) || !is_string($email) || strpos($email, '@') === false) {
			return '';
		}
		[$user, $domain] = explode('@', $email, 2);
		$len = strlen($user);
		if ($len <= 2) {
			$masked_user = substr($user, 0, 1) . '*';
		} else {
			$masked_user = substr($user, 0, 1) . str_repeat('*', min(5, $len - 2)) . substr($user, -1);
		}
		return $masked_user . '@' . $domain;
	}
}
