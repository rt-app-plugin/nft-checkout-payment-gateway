<?php
if (PHP_SAPI !== 'cli') { exit; } // Test code: never runs over the web.
/**
 * php -n test_nft.php <plugin dir>
 *
 * Exercises the NFT Checkout plugin's payment paths with WordPress and
 * WooCommerce stubbed out (dummy keys, no network).
 */
$GLOBALS['plugin_dir'] = rtrim($argv[1] ?? dirname(__DIR__, 2), '/\\');
require __DIR__ . '/stubs.php';

const PUB_LIVE  = 'pk_live_ACCOUNT_A';
const SEC_LIVE  = 'sk_live_ACCOUNT_A_SECRET';
const PUB_SB    = 'pk_test_ACCOUNT_A';
const SEC_SB    = 'sk_test_ACCOUNT_A_SECRET';
const PUB_OTHER = 'pk_live_ACCOUNT_B';
const SEC_OTHER = 'sk_live_ACCOUNT_B_SECRET';
const UUID      = '0b9f3c51-2f2e-4a43-9d38-5b7c1a1f0e11';

$results = [];
function check($name, $cond, $detail = '') { global $results; $results[] = [$name, (bool) $cond, $detail]; echo ($cond ? 'PASS ' : 'FAIL ') . $name . ($detail !== '' ? " ($detail)" : '') . "\n"; }

function reset_world() {
	$GLOBALS['options'] = [
		'admin_email' => 'owner@shop.test',
		NFT_CHECKOUT_SETTINGS_OPTION => ['enabled' => 'yes', 'sandbox' => 'no', 'order_status' => 'processing'],
		NFT_CHECKOUT_ACCOUNTS_OPTION => [
			['title' => 'A', 'unique_id' => 'acc_a', 'live_public_key' => PUB_LIVE, 'live_secret_key' => SEC_LIVE, 'sandbox_public_key' => PUB_SB, 'sandbox_secret_key' => SEC_SB, 'has_sandbox' => 'on', 'live_status' => 'active', 'sandbox_status' => 'active', 'priority' => 1],
			['title' => 'B', 'unique_id' => 'acc_b', 'live_public_key' => PUB_OTHER, 'live_secret_key' => SEC_OTHER, 'has_sandbox' => 'off', 'live_status' => 'active', 'priority' => 2],
		],
	];
	$GLOBALS['transients'] = [];
	$GLOBALS['cache'] = [];
	$GLOBALS['wpdb']->rows = [];
	$GLOBALS['http_log'] = [];
	$GLOBALS['mails'] = [];
	$GLOBALS['notices'] = [];
	$GLOBALS['http'] = function ($url, $args) { return ['response' => ['code' => 200], 'body' => json_encode(['status' => false, 'message' => 'Transaction not found'])]; };
	$order = new WC_Order(123);
	$order->update_meta_data('_nft_checkout_account_public_key', PUB_LIVE);
	$order->update_meta_data('_nft_checkout_account_unique_id', 'acc_a');
	$order->update_meta_data('_nft_checkout_voucher_emailed_at', time());
	$order->update_meta_data('_nft_checkout_state', 'pending');
	$GLOBALS['orders'] = [123 => $order];
	return $order;
}

function rest() { return NFT_CHECKOUT_PAYMENT_GATEWAY_REST_API::get_instance(); }

function get_return($query) {
	$r = new WP_REST_Request('GET');
	$r->set_query_params($query);
	try { $resp = rest()->nft_checkout_handle_api_request($r); return ['status' => $resp->get_status(), 'redirect' => null]; }
	catch (RedirectException $e) { return ['status' => 302, 'redirect' => $e->url]; }
}

function webhook(array $body, array $headers = [], $raw = null) {
	$raw = $raw ?? json_encode($body);
	$r = new WP_REST_Request('POST');
	$r->set_body($raw);
	foreach ($headers as $k => $v) $r->set_header($k, $v);
	$resp = rest()->nft_checkout_handle_api_request($r);
	return [$resp->get_status(), $resp->get_data()];
}

function signed_headers($raw, $secret, $ts = null) {
	$ts = $ts ?? (string) time();
	return ['X-Timestamp' => $ts, 'X-Signature' => hash_hmac('sha256', $ts . '.' . $raw, $secret)];
}

$good = ['order_id' => 123, 'order_status' => 'success', 'payment_status' => 'success', 'pay_id' => base64_encode(UUID), 'amount' => '90.00', 'curr_code' => 'USD', 'mode' => 'live', 'source' => 'rt-payment-backend'];

echo "== NFT-01: return link cannot mark the order paid\n";
foreach (['completed', 'paid', 'success'] as $status) {
	$order = reset_world();
	$out = get_return(['order_id' => 123, 'key' => $order->get_order_key(), 'status' => $status, 'pay_id' => base64_encode(UUID), 'order_status' => $status]);
	check("T1 GET status=$status leaves order pending", $order->get_status() === 'pending' && !$order->is_paid(), 'status=' . $order->get_status());
	check("T1 GET status=$status redirects to order-received", $out['status'] === 302 && str_contains((string) $out['redirect'], 'order-received'));
	check("T1 GET status=$status stores no browser pay_id", $order->get_meta('_nft_checkout_pay_id') === '');
}
$order = reset_world();
$out = get_return(['order_id' => 123, 'status' => 'completed']);
check('T1b GET without order key is refused (403)', $out['status'] === 403 && $order->get_status() === 'pending');
$out = get_return(['order_id' => 123, 'key' => 'wrong', 'status' => 'completed']);
check('T1c GET with wrong order key is refused (403)', $out['status'] === 403);

echo "== NFT-02: no key / nonce / bearer fallback\n";
$order = reset_world();
[$code] = webhook(['order_id' => 123, 'status' => 'completed', 'nonce' => PUB_LIVE]);
check('T2 nonce = public key -> 401, unpaid', $code === 401 && $order->get_status() === 'pending', "http $code");
[$code] = webhook(['order_id' => 123, 'status' => 'completed', 'nonce' => base64_encode(PUB_LIVE)]);
check('T2b base64 nonce -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');
[$code] = webhook(['order_id' => 123, 'status' => 'completed', 'api_key' => SEC_LIVE]);
check('T2c api_key = secret key in body -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');
[$code] = webhook(['order_id' => 123, 'status' => 'completed'], ['Authorization' => 'Bearer ' . SEC_LIVE]);
check('T3 Bearer secret key -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');
[$code] = webhook(['order_id' => 123, 'status' => 'completed'], ['Authorization' => 'Bearer ' . PUB_LIVE]);
check('T3b Bearer public key -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');

echo "== NFT-08: signature must be the order's own account, in its mode\n";
$order = reset_world();
$raw = json_encode($good);
[$code] = webhook($good, signed_headers($raw, SEC_OTHER), $raw);
check('T4 signed with another configured account -> 401, unpaid', $code === 401 && $order->get_status() === 'pending', "http $code");
[$code] = webhook($good, signed_headers($raw, SEC_SB), $raw);
check('T4b signed with the sandbox secret for a live order -> 401, unpaid', $code === 401 && $order->get_status() === 'pending', "http $code");
[$code] = webhook($good, signed_headers($raw, SEC_LIVE, (string) (time() - 3600)), $raw);
check('T4c stale timestamp -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');
$order->update_meta_data('_nft_checkout_account_public_key', 'pk_live_UNKNOWN');
[$code] = webhook($good, signed_headers($raw, SEC_LIVE), $raw);
check('T4d order naming no configured account -> 401, unpaid', $code === 401 && $order->get_status() === 'pending');

echo "== NFT-02/03: amount, currency, order, pay_id must match\n";
$order = reset_world();
$bad = ['amount' => '1.00']; $b = json_encode(array_merge($good, $bad));
[$code, $data] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6 amount mismatch -> 400, unpaid', $code === 400 && $order->get_status() === 'pending', "http $code " . ($data['message'] ?? ''));
$b = json_encode(array_merge($good, ['curr_code' => 'EUR']));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6b currency mismatch -> 400, unpaid', $code === 400 && $order->get_status() === 'pending');
$b = json_encode(array_diff_key($good, ['amount' => 1]));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6c missing amount -> 400, unpaid (fail closed)', $code === 400 && $order->get_status() === 'pending');
$b = json_encode(array_diff_key($good, ['curr_code' => 1]));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6d missing currency -> 400, unpaid (fail closed)', $code === 400 && $order->get_status() === 'pending');
$b = json_encode(array_diff_key($good, ['pay_id' => 1]));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6e missing pay_id -> 400, unpaid', $code === 400 && $order->get_status() === 'pending');
$b = json_encode(array_merge($good, ['mode' => 'sandbox']));
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6f sandbox mode claimed for a live order -> 400, unpaid', $code === 400 && $order->get_status() === 'pending');
$order->update_meta_data('_nft_checkout_pay_id', base64_encode('11111111-2222-4333-8444-555555555555'));
$b = json_encode($good);
[$code] = webhook([], signed_headers($b, SEC_LIVE), $b);
check('T6g pay_id differs from the one the order was issued -> 400, unpaid', $code === 400 && $order->get_status() === 'pending');

echo "== Valid signed webhook\n";
$order = reset_world();
$raw = json_encode($good);
[$code, $data] = webhook([], signed_headers($raw, SEC_LIVE), $raw);
check('T7 valid signed success -> 200, order processing + paid', $code === 200 && $order->get_status() === 'processing' && $order->is_paid(), "http $code state=" . ($data['state'] ?? ''));
check('T7 pay_id recorded from the signed webhook', nft_checkout_pay_ids_match($order->get_meta('_nft_checkout_pay_id'), UUID));
check('T7 payment-link row written', count($GLOBALS['wpdb']->rows) === 1);
[$code, $data] = webhook([], signed_headers($raw, SEC_LIVE), $raw);
check('T8 exact replay -> ignored as duplicate', $code === 200 && str_contains($data['message'], 'Duplicate'), $data['message'] ?? '');
$raw2 = json_encode(array_merge($good, ['sent_at' => 'later']));
[$code, $data] = webhook([], signed_headers($raw2, SEC_LIVE), $raw2);
check('T8b re-delivery of the same event -> deduplicated, still paid once', $code === 200 && $order->get_status() === 'processing', ($data['engine_reason'] ?? ''));

echo "== NFT-07: a paid order is never moved down\n";
foreach (['failed', 'cancelled', 'expired'] as $down) {
	$raw3 = json_encode(array_merge($good, ['order_status' => $down, 'payment_status' => $down]));
	[$code, $data] = webhook([], signed_headers($raw3, SEC_LIVE), $raw3);
	check("T9 signed '$down' after payment leaves the order processing", $order->get_status() === 'processing' && $order->is_paid(), "http $code " . ($data['engine_reason'] ?? ''));
}
$raw4 = json_encode(array_merge($good, ['order_status' => 'returned', 'return_code' => 'R01']));
[$code, $data] = webhook([], signed_headers($raw4, SEC_LIVE), $raw4);
check('T9b signed "returned" records a note + mail, status unchanged', $order->get_status() === 'processing' && count($GLOBALS['mails']) === 1 && $order->get_meta('_nft_checkout_payment_returned') === 'yes', "http $code");
$paid_other_way = reset_world();
$paid_other_way->update_status('completed');
$raw5 = json_encode(array_merge($good, ['order_status' => 'failed']));
[$code] = webhook([], signed_headers($raw5, SEC_LIVE), $raw5);
check('T9c order completed another way is not downgraded by a webhook', $paid_other_way->get_status() === 'completed');

echo "== NFT-03: status sync fails closed\n";
$loader = NFT_CHECKOUT_PAYMENT_GATEWAY_Loader::get_instance();
$answer = fn($over = []) => ['status' => true, 'redirect_url' => 'https://shop.test/wp-json/nft-checkout/v1/data?order_id=123', 'transaction_status' => 'paid', 'payment_status' => 'success', 'settled' => true, 'pay_id' => base64_encode(UUID), 'order_id' => '123', 'amount' => '90.00', 'curr_code' => 'USD', 'mode' => 'live'] + $over;
$sync_http = function ($url, $args) use (&$answer, &$override) { $GLOBALS['last_req'] = $args; return ['response' => ['code' => 200], 'body' => json_encode(array_merge($answer(), $override))]; };
$GLOBALS["http"] = $sync_http;

$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['amount' => '1.00'];
$loader->sync_single_order_status($order);
check('T10 sync: amount mismatch -> order stays pending, mismatch note added', $order->get_status() === 'pending' && $order->get_meta('_nft_checkout_sync_mismatch_noted') === 'yes');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['curr_code' => 'EUR'];
$loader->sync_single_order_status($order);
check('T10b sync: currency mismatch -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['order_id' => '124'];
$loader->sync_single_order_status($order);
check('T10c sync: another order id -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['redirect_url' => 'https://other-shop.test/wp-json/nft-checkout/v1/data?order_id=123'];
$loader->sync_single_order_status($order);
check('T10d sync: link raised by another site -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = ['mode' => 'sandbox'];
$loader->sync_single_order_status($order);
check('T10e sync: sandbox payment for a live order -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = [];
$order->update_meta_data('_nft_checkout_pay_id', base64_encode('11111111-2222-4333-8444-555555555555'));
$loader->sync_single_order_status($order);
check('T10f sync: paid under a different pay_id than issued -> pending', $order->get_status() === 'pending');
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = [];
$state = $loader->sync_single_order_status($order);
check('T10g sync: exact match -> processing + paid', $state === 'success' && $order->get_status() === 'processing' && $order->is_paid());
$req = $GLOBALS['last_req'];
check('T10h sync request is signed, carries no secret, goes to update-txn-status', isset($req['headers']['X-Signature'], $req['headers']['X-Timestamp']) && !str_contains($req['body'], SEC_LIVE) && str_contains($GLOBALS['http_log'][0][1], '/payment-api/update-txn-status') && hash_hmac('sha256', $req['headers']['X-Timestamp'] . '.' . $req['body'], SEC_LIVE) === $req['headers']['X-Signature']);
check('T10i sync uses only one endpoint (no unauthenticated fallbacks)', count($GLOBALS['http_log']) === 1);
$order = reset_world(); $GLOBALS["http"] = $sync_http; $override = [];
$order->update_meta_data('_nft_checkout_account_public_key', '');
$order->update_meta_data('_nft_checkout_account_unique_id', '');
$loader->sync_single_order_status($order);
check('T10j sync: order without an account is never confirmed', $order->get_status() === 'pending' && count($GLOBALS['http_log']) === 0);

echo "== NFT-04: default host\n";
check('T11 default API base is production app.rt.app', NFT_CHECKOUT_API_BASE_URL === 'https://app.rt.app/payment-api' && NFT_CHECKOUT_BASE_URL === 'https://app.rt.app');
check('T11b API base: dev address accepted', nft_checkout_clean_api_base('https://dev.rt.app/payment-api/') === 'https://dev.rt.app/payment-api');
check('T11c API base: http is forced to https', nft_checkout_clean_api_base('http://dev.rt.app/payment-api') === 'https://dev.rt.app/payment-api');
check('T11d API base: a host outside rt.app falls back to production', nft_checkout_clean_api_base('https://evil.example/payment-api') === 'https://app.rt.app/payment-api' && nft_checkout_clean_api_base('https://rt.app.evil.example') === 'https://app.rt.app/payment-api' && nft_checkout_clean_api_base('https://notrt.app/x') === 'https://app.rt.app/payment-api');
check('T11e API base: empty falls back to production', nft_checkout_clean_api_base('') === 'https://app.rt.app/payment-api');

echo "== NFT-05: account sync sends no secret\n";
reset_world(); $GLOBALS['http'] = function ($u, $a) { if (str_contains($u, 'sync-account-status')) $GLOBALS['sync_req'] = $a; return ['response' => ['code' => 200], 'body' => json_encode(['data' => [['mode' => 'live', 'public_key' => PUB_LIVE, 'status' => 'active']]])]; };
$loader->handle_cron_event();
$body = $GLOBALS['sync_req']['body'];
check('T12 sync-account-status body has no secret_key', !str_contains($body, 'secret_key') && !str_contains($body, SEC_LIVE) && str_contains($body, '"signature"'));
check('T12b telemetry not sent without consent', count(array_filter($GLOBALS['http_log'], fn($r) => str_contains($r[1], 'plugin/check'))) === 0);

echo "== pay id helpers\n";
check('pay ids match across base64/plain spellings', nft_checkout_pay_ids_match(UUID, base64_encode(UUID)) && nft_checkout_pay_ids_match(rtrim(base64_encode(UUID), '='), UUID) && !nft_checkout_pay_ids_match(UUID, '11111111-2222-4333-8444-555555555555'));

echo "== Retry payment (review NFT-01): fail on link A, pay on link B\n";
const UUID_B = '7c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f';
$issue_voucher = function ($order, $reference, $voucher_id) {
	// What the gateway records when RT confirms a payment email was sent.
	$order->update_meta_data('_nft_checkout_voucher_reference', $reference);
	$order->update_meta_data('_nft_checkout_voucher_id', $voucher_id);
	nft_checkout_remember_voucher($order, $reference, $voucher_id, PUB_LIVE);
	$order->update_meta_data('_nft_checkout_state', 'pending');
	if ($order->get_status() !== 'pending') { $order->update_status('pending'); }
};
$send = function (array $over) use ($good) { $raw = json_encode(array_merge($good, $over)); return webhook([], signed_headers($raw, SEC_LIVE), $raw); };

$order = reset_world();
$issue_voucher($order, '123-1', 'v_1');
[$code] = $send(['order_status' => 'failed', 'payment_status' => 'failed']);
check('R1 first payment fails on link A -> order failed, A remembered', $code === 200 && $order->get_status() === 'failed' && nft_checkout_pay_ids_match($order->get_meta('_nft_checkout_pay_id'), UUID), "http $code status=" . $order->get_status());
$issue_voucher($order, '123-2', 'v_2');
[$code, $data] = $send(['pay_id' => base64_encode(UUID_B), 'sent_at' => 'b']);
check('R2 shopper pays on new link B -> order processing + paid', $code === 200 && $order->get_status() === 'processing' && $order->is_paid(), "http $code " . ($data['message'] ?? ''));
check('R2 link B is now the newest known payment', nft_checkout_pay_ids_match($order->get_meta('_nft_checkout_latest_pay_id'), UUID_B) && $order->get_meta('_nft_checkout_voucher_awaiting_pay_id') === '');

$order = reset_world();
$issue_voucher($order, '123-1', 'v_1');
$send(['order_status' => 'failed', 'payment_status' => 'failed']);
$issue_voucher($order, '123-2', 'v_2');
[$code, $data] = $send(['order_status' => 'expired', 'payment_status' => 'expired', 'sent_at' => 'late']);
check('R3 old link A expires after a new email was sent -> order stays pending', $code === 200 && $order->get_status() === 'pending' && ($data['engine_reason'] ?? '') === 'superseded_link_ignored', "http $code status=" . $order->get_status());
[$code] = $send(['pay_id' => base64_encode(UUID_B), 'amount' => '1.00']);
check('R4 new link with the wrong amount is still refused', $code === 400 && !$order->is_paid(), "http $code");
[$code] = $send(['pay_id' => base64_encode(UUID_B), 'reference' => '999-1']);
check('R4b new link naming a voucher this order never sent is refused', $code === 400 && !$order->is_paid(), "http $code");
$rawb = json_encode(array_merge($good, ['pay_id' => base64_encode(UUID_B)]));
[$code] = webhook([], signed_headers($rawb, SEC_OTHER), $rawb);
check('R4c new link signed by another account is refused', $code === 401 && !$order->is_paid(), "http $code");
[$code] = $send(['pay_id' => base64_encode(UUID_B), 'reference' => '123-2', 'sent_at' => 'ok']);
check('R5 new link naming this order\'s voucher is accepted', $code === 200 && $order->is_paid(), "http $code");
$mails_before = count($GLOBALS['mails']);
[$code, $data] = $send(['sent_at' => 'second']);
check('R6 old link A paid as well (known id) -> order unchanged, still paid once', $code === 200 && $order->get_status() === 'processing', ($data['engine_reason'] ?? ''));

$order = reset_world();
$issue_voucher($order, '123-1', 'v_1');
$send([]);
const UUID_C = '9d8c7b6a-5f4e-4d3c-8b2a-1f0e9d8c7b6a';
[$code, $data] = $send(['pay_id' => base64_encode(UUID_C), 'sent_at' => 'dup']);
check('R7 second payment under an unknown id on a paid order -> 200, note + email, paid once', $code === 200 && ($data['engine_reason'] ?? '') === 'duplicate_payment_flagged' && $order->get_status() === 'processing' && count($GLOBALS['mails']) === 1, "http $code mails=" . count($GLOBALS['mails']));

echo "== Retry payment: status lookup asks about the newest payment\n";
$order = reset_world(); $GLOBALS["http"] = $sync_http;
$issue_voucher($order, '123-1', 'v_1');
$override = ['payment_status' => 'failed', 'transaction_status' => 'failed', 'settled' => false];
$loader->sync_single_order_status($order);
check('R8 lookup learns link A and its failure', $order->get_status() === 'failed' && nft_checkout_pay_ids_match($order->get_meta('_nft_checkout_pay_id'), UUID));
$issue_voucher($order, '123-2', 'v_2');
$loader->sync_single_order_status($order);
check('R8b lookup right after the new email names no old id', !str_contains($GLOBALS['last_req']['body'], 'payment_token'), $GLOBALS['last_req']['body']);
check('R8c RT still answering about failed link A does not fail the order again', $order->get_status() === 'pending', 'status=' . $order->get_status());
$override = ['pay_id' => base64_encode(UUID_B)];
$state = $loader->sync_single_order_status($order);
check('R9 lookup finds link B paid -> processing + paid', $state === 'success' && $order->is_paid() && $order->get_status() === 'processing', 'state=' . var_export($state, true));

$failed = array_filter($results, fn($r) => !$r[1]);
echo "\n" . count($results) . " checks, " . count($failed) . " failed\n";
exit($failed ? 1 : 0);
