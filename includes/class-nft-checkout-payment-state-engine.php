<?php
if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'config.php';
require_once plugin_dir_path(__FILE__) . 'nft-checkout-payment-gateway-utils.php';

/**
 * The order's payment state machine.
 *
 * States: pending (voucher sent, nothing paid), processing (a payment is in
 * flight), success (paid: WooCommerce processing / completed), failed,
 * cancelled, expired.
 *
 * NFT-07: success is final. Nothing - no webhook, no sync, no return link -
 * moves a paid order down again. An order paid another way (its WooCommerce
 * status is processing or completed) counts as success too. A "returned"
 * notice from RT on a paid order is recorded and the merchant told; the
 * status is left for a human.
 *
 * NFT-08: every event is deduplicated, whatever its source.
 */
class NFT_Checkout_Payment_State_Engine
{
    const LOCK_TTL  = 12;
    const EVENT_TTL = 86400;

    /* =========================================================
     * ENTRY POINT
     * ========================================================= */
    public static function handle_event($order_id, $event_type, $payload = [])
    {
        $order = wc_get_order($order_id);
        if (!$order) return false;

        if (!nft_checkout_is_gateway_order($order)) {
            NFT_Checkout_Payment_Gateway_Logger::warning(
                "Refused state transition for non-nft-checkout order #{$order_id} (payment method: " . $order->get_payment_method() . ')',
                ['source' => 'nft-checkout-payment-gateway']
            );
            return false;
        }

        $event_id = self::generate_event_id($event_type, $payload);

        // NFT-08: a repeat of an event that already reached the order is ignored,
        // whichever path delivered it.
        if (self::is_duplicate_event($order_id, $event_id)) {
            return self::safe_response($order, 'duplicate_event_ignored', self::get_state($order));
        }

        $lock_key = "rt_nft_checkout_lock_{$order_id}";
        $lock_acquired = add_option($lock_key, time() + self::LOCK_TTL, '', 'no');
        if (!$lock_acquired) {
            $lock_time = (int) get_option($lock_key);
            if ($lock_time > 0 && $lock_time < time()) {
                update_option($lock_key, time() + self::LOCK_TTL, false);
            } else {
                // Not marked as seen: the sender may retry once the lock is gone.
                return self::safe_response($order, 'locked_skip');
            }
        }

        try {

            $current_state = self::normalize_state(self::get_state($order));
            $new_state     = self::normalize_state(self::resolve_state($payload));

            if (!$new_state) {
                return self::safe_response($order, 'no_state', $current_state);
            }

            if ($current_state === 'success') {
                if ($new_state === 'returned' && in_array($event_type, ['webhook_update'], true)) {
                    // Money that arrived was sent back. Recorded for the merchant;
                    // the order status is never changed automatically (NFT-07).
                    self::record_return($order, $payload, $event_type);
                    self::mark_event($order_id, $event_id);
                    return self::safe_response($order, 'return_recorded', 'success');
                }
                return self::safe_response($order, 'final_success_locked', 'success');
            }

            if ($new_state === 'returned') {
                // A return only makes sense for money that arrived.
                return self::safe_response($order, 'invalid_transition_blocked', $current_state);
            }

            $can_transition = self::can_transition($current_state, $new_state);

            /**
             * A verified payment must always be able to settle an order that is
             * still waiting for money, whatever closed attempt the engine last
             * recorded.
             */
            if ($new_state === 'success') {
                $can_transition = in_array(
                    $current_state,
                    ['pending', 'on-hold', 'checkout-draft', 'processing', 'failed', 'cancelled', 'expired'],
                    true
                );
                if (!$can_transition) {
                    NFT_Checkout_Payment_Gateway_Logger::warning(
                        "Refused state transition to success for order #{$order_id} from state '{$current_state}'",
                        ['source' => 'nft-checkout-payment-gateway']
                    );
                }
            }

            if (!$can_transition) {
                NFT_Checkout_Payment_Gateway_Logger::warning(
                    "Invalid transition blocked for order #{$order_id}: {$current_state} -> {$new_state}",
                    ['source' => 'nft-checkout-payment-gateway']
                );
                return self::safe_response(
                    $order,
                    'invalid_transition_blocked',
                    $current_state
                );
            }

            self::apply($order, $new_state, $event_type, $payload);
            self::mark_event($order_id, $event_id);

            if ($new_state === 'failed') {

                $existing = self::timeline_has_state_for_token(
                    $order,
                    'failed',
                    $payload['payment_token'] ?? ''
                );

                if (!$existing) {
                    self::push_timeline_event($order, $new_state, $event_type, $payload);
                }

            } elseif ($new_state === 'pending' && $current_state === 'pending') {
                // Nothing happened; keep the timeline readable.
            } else {
                self::push_timeline_event($order, $new_state, $event_type, $payload);
            }

            // SINGLE SOURCE OF TRUTH FOR NOTES
            self::sync_order_notes($order);

            return self::safe_response($order, 'updated', $new_state);

        } finally {
            delete_option($lock_key);
        }
    }

    private static function timeline_has_state_for_token($order, $state, $payment_token)
    {
        if (empty($payment_token)) {
            return false;
        }

        foreach (self::get_timeline($order) as $event) {
            if (
                ($event['type'] ?? '') === $state &&
                ($event['payment_token'] ?? '') === $payment_token
            ) {
                return true;
            }
        }

        return false;
    }

    /* =========================================================
     * TIMELINE ENGINE (SOURCE OF TRUTH)
     * ========================================================= */
    private static function push_timeline_event($order, $state, $event_type, $payload)
    {
        $timeline = self::get_timeline($order);

        $timeline[] = [
            'type'          => $state,
            'event_type'    => $event_type,
            'time'          => current_time('mysql'),
            'payment_token' => $payload['payment_token'] ?? null,
        ];

        $order->update_meta_data('_nft_checkout_timeline', $timeline);
        $order->save();
    }

    /* =========================================================
     * APPLY STATE (WooCommerce sync only)
     * ========================================================= */
    private static function apply($order, $state, $event_type, $payload)
    {
        if (self::get_state($order) === 'success') {
            return;
        }

        $order_id = $order->get_id();
        $payment_token = $payload['payment_token'] ?? '';

        // stable idempotency key (VERY IMPORTANT)
        $state_lock_key = md5($order_id . '|' . $state . '|' . $payment_token);

        $last_lock = $order->get_meta('_nft_checkout_state_lock');

        // The lock only short-circuits a repeat of an event that already reached
        // WooCommerce. A 'success' lock on an order that is still unpaid means the
        // earlier attempt never completed, so it must be allowed through again.
        if (
            $last_lock === $state_lock_key &&
            !($state === 'success' && $order->has_status(['pending', 'on-hold', 'failed', 'cancelled', 'checkout-draft']))
        ) {
            return;
        }

        $order->update_meta_data('_nft_checkout_state_lock', $state_lock_key);

        $wc_status = match ($state) {
            'success'    => self::get_success_wc_status(),
            'failed'     => 'failed',
            'cancelled'  => 'cancelled',
            'expired'    => 'failed',
            default      => null
        };

        if ($wc_status) {
            if ($state === 'success') {
                // payment_complete() records the transaction id and paid date,
                // reduces stock and moves the order to processing/completed the
                // WooCommerce way. Then honour the configured success status.
                $order->payment_complete($payment_token);
                if ($order->get_status() !== $wc_status) {
                    $order->update_status($wc_status, '');
                }
            } else {
                $order->update_status($wc_status, '');
            }
        }

        $order->update_meta_data('_nft_checkout_state', $state);
        $order->update_meta_data('_nft_checkout_last_event', $event_type);
        $order->update_meta_data('_nft_checkout_last_event_time', current_time('mysql'));

        if (!empty($payment_token)) {
            $order->update_meta_data('_nft_checkout_pay_id', $payment_token);
        }

        if ($state === 'success') {
            $order->update_meta_data('_nft_checkout_payment_success', 'yes');
            $order->update_meta_data('_nft_checkout_paid_amount', (float) $order->get_total());
        }

        $order->save();
    }

    /**
     * RT reports that money which had arrived was sent back (a return, a
     * reversal, a chargeback). The order stays as it is (NFT-07); the merchant
     * gets a note and an e-mail and decides.
     */
    private static function record_return($order, $payload, $event_type)
    {
        $return_code = sanitize_text_field((string) ($payload['return_code'] ?? ''));
        $reason      = sanitize_text_field((string) ($payload['failure_reason'] ?? ''));
        $token       = sanitize_text_field((string) ($payload['payment_token'] ?? ''));

        if ($order->get_meta('_nft_checkout_payment_returned') === 'yes') {
            return;
        }

        /* translators: %s: reference code for the reversal */
        $detail = $return_code !== '' ? sprintf(__(' (reference %s)', 'nft-checkout-payment-gateway'), $return_code) : '';
        $note   = sprintf(
            /* translators: %1$s: bank or card reference in parentheses, or empty */
            __('This card payment was reversed after it had been received%1$s. The money is no longer in your RT account. The order was not changed: check it before shipping.', 'nft-checkout-payment-gateway'),
            $detail
        );
        if ($reason !== '') {
            $note .= ' ' . $reason;
        }

        $order->update_meta_data('_nft_checkout_payment_returned', 'yes');
        $order->update_meta_data('_nft_checkout_return_code', $return_code);
        $order->update_meta_data('_nft_checkout_returned_at', current_time('mysql'));
        $order->add_order_note('<strong>NFT Checkout</strong><br><br>' . esc_html($note));
        $order->save();

        $to = apply_filters('rt_nft_checkout_alert_email', get_option('admin_email'), $order);
        if (!empty($to) && is_email($to)) {
            wp_mail(
                $to,
                sprintf(
                    /* translators: 1: site name, 2: order number */
                    __('[%1$s] Payment returned for order #%2$s', 'nft-checkout-payment-gateway'),
                    wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                    $order->get_order_number()
                ),
                $note . "\n\n" . $order->get_edit_order_url()
            );
        }

        NFT_Checkout_Payment_Gateway_Logger::warning(
            "Payment returned reported for paid order #{$order->get_id()} (not applied automatically).",
            ['event_type' => $event_type, 'return_code' => $return_code]
        );
    }

    private static function get_timeline($order)
    {
        $data = $order->get_meta('_nft_checkout_timeline', true);

        if (empty($data) || !is_array($data)) {
            return [];
        }

        // sanitize each entry
        return array_values(array_filter($data, function ($item) {
            return is_array($item) && isset($item['type']);
        }));
    }

    /* =========================================================
     * TIMELINE → ORDER NOTES SYNC
     * ========================================================= */
    private static function sync_order_notes($order)
    {
        $timeline = self::get_timeline($order);

        if (empty($timeline)) {
            return;
        }

        $failed_events = [];
        $success_event = null;
        $cancel_event  = null;
        $seen_failed   = [];

        foreach ($timeline as $event) {

            $type  = $event['type'] ?? '';
            $token = trim((string) ($event['payment_token'] ?? ''));

            if ($type === 'failed') {
                if (!empty($token) && !isset($seen_failed[$token])) {
                    $seen_failed[$token] = true;
                    $failed_events[] = $event;
                }
            }

            if ($type === 'success' && !$success_event) {
                $success_event = $event;
            }

            if ($type === 'cancelled' && !$cancel_event) {
                $cancel_event = $event;
            }
        }

        $already_synced = (int) $order->get_meta('_nft_checkout_failed_note_count');

        $actual_failed_count = count($failed_events);

        if ($actual_failed_count > $already_synced) {

            for ($i = $already_synced; $i < $actual_failed_count; $i++) {

                $event = $failed_events[$i];

                $attempt_number = $i + 1;

                $order->add_order_note(
                    self::build_order_note(
                        "Payment Failed (Attempt {$attempt_number})",
                        $event['payment_token'] ?? '',
                        $event['event_type'] ?? ''
                    )
                );
            }

            $order->update_meta_data('_nft_checkout_failed_note_count', $actual_failed_count);
        }

        if ($success_event && !$order->get_meta('_nft_checkout_success_note_added')) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment completed successfully.',
                    $success_event['payment_token'] ?? '',
                    $success_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data('_nft_checkout_success_note_added', 'yes');
        }

        if ($cancel_event && !$order->get_meta('_nft_checkout_cancel_note_added')) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment was cancelled.',
                    $cancel_event['payment_token'] ?? '',
                    $cancel_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data('_nft_checkout_cancel_note_added', 'yes');
        }

        $order->save();
    }

    private static function build_order_note($title, $payment_token, $event_type)
    {
        $source = match ($event_type) {
            'popup_close'    => 'Customer Return from Payment Page',
            'redirect'       => 'Customer Redirect',
            'webhook_update' => 'Webhook',
            'api_sync'       => 'Status Check',
            default          => 'System'
        };

        // Decode Base64 payment token if possible
        $decoded_payment_id = base64_decode((string) $payment_token, true);

        if (empty($decoded_payment_id) || !ctype_print($decoded_payment_id)) {
            $decoded_payment_id = $payment_token;
        }

        return sprintf(
            '<strong>NFT Checkout</strong><br><br>
            <strong>%s</strong><br><br>
            <strong>Payment ID:</strong> %s<br>
            <strong>Updated Via:</strong> %s<br>
            <strong>Recorded At:</strong> %s',
            esc_html($title),
            esc_html($decoded_payment_id),
            esc_html($source),
            esc_html(current_time('F j, Y \a\t g:i A'))
        );
    }

    /* =========================================================
     * STATE RESOLUTION
     * ========================================================= */

    /**
     * Map a status word RT uses to the engine vocabulary, or null.
     */
    public static function map_status($status)
    {
        $status = is_string($status) && $status !== '' ? strtolower(trim($status)) : null;

        return match ($status) {
            'success', 'paid', 'completed', 'settled', 'succeeded' => 'success',
            'failed', 'rejected', 'declined'                        => 'failed',
            'cancelled', 'canceled'                                 => 'cancelled',
            'expired'                                               => 'expired',
            'processing', 'in_flight', 'in-flight', 'authorized', 'authorised' => 'processing',
            'pending', 'initiated', 'created', 'open', 'awaiting'   => 'pending',
            'returned', 'reversed', 'reversal', 'chargeback', 'charged_back', 'disputed', 'refunded', 'refund' => 'returned',
            default                                                 => null
        };
    }

    private static function resolve_state($payload)
    {
        $status = $payload['status']
            ?? $payload['payment_status']
            ?? $payload['transaction_status']
            ?? $payload['order_status']
            ?? null;

        return self::map_status($status);
    }

    /* =========================================================
     * NORMALIZATION
     * ========================================================= */
    private static function normalize_state($state)
    {
        return match ($state) {
            'completed' => 'success',
            'canceled'  => 'cancelled',
            default     => $state
        };
    }

    /* =========================================================
     * TRANSITIONS
     * ========================================================= */
    private static function can_transition($from, $to)
    {
        if ($from === 'success') return false;
        if ($from === $to) return true;

        $map = [
            'pending'        => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold'],
            'on-hold'        => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold'],
            'checkout-draft' => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold'],
            'processing'     => ['processing', 'success', 'failed', 'cancelled', 'expired'],
            'failed'         => ['failed', 'success', 'processing', 'cancelled', 'expired', 'pending'],
            'cancelled'      => ['cancelled', 'failed', 'processing', 'success', 'pending'],
            'expired'        => ['expired', 'pending', 'failed', 'cancelled', 'processing', 'success'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /* =========================================================
     * CURRENT STATE
     * ========================================================= */
    private static function get_state($order)
    {
        $wc_status = $order->get_status();

        // NFT-07: a paid order is paid, whatever this plugin last recorded and
        // however it was paid. Nothing may move it down again.
        if (in_array($wc_status, ['processing', 'completed'], true) || $order->is_paid()) {
            return 'success';
        }

        if ($wc_status === 'cancelled') {
            return 'cancelled';
        }

        $meta_state = $order->get_meta('_nft_checkout_state');
        if (!empty($meta_state)) {
            return $meta_state;
        }

        return !empty($wc_status) ? $wc_status : 'pending';
    }

    /* =========================================================
     * SUCCESS STATUS
     * ========================================================= */
    private static function get_success_wc_status()
    {
        $settings = nft_checkout_get_settings();
        $status   = $settings['order_status'] ?? 'processing';

        return in_array($status, ['processing','completed'], true)
            ? $status
            : 'processing';
    }

    /* =========================================================
     * EVENT ID (dedupe for every event type)
     * ========================================================= */
    private static function generate_event_id($type, $payload)
    {
        return hash('sha256', implode('|', [
            $type,
            strtolower((string) ($payload['status'] ?? '')),
            $payload['payment_token'] ?? '',
            $payload['return_code'] ?? '',
        ]));
    }

    private static function is_duplicate_event($order_id, $event_id)
    {
        return get_transient("rt_nft_checkout_event_{$order_id}_{$event_id}") !== false;
    }

    private static function mark_event($order_id, $event_id)
    {
        set_transient("rt_nft_checkout_event_{$order_id}_{$event_id}", 1, self::EVENT_TTL);
    }

    /* =========================================================
     * RESPONSE
     * ========================================================= */
    private static function safe_response($order, $reason, $state = null)
    {
        return [
            'ok' => true,
            'reason' => $reason,
            'order_id' => $order->get_id(),
            'state' => $state ?? self::get_state($order)
        ];
    }

    public static function resolve_final_state($order, $api_status = null)
    {
        // 1. PRIMARY: engine state (validated)
        $state = self::normalize_state(self::get_state($order));

        if (!empty($state) && in_array($state, ['pending','processing','success','failed','cancelled','expired'], true)) {
            return $state;
        }

        // 2. SAFETY: API status (current response)
        if (!empty($api_status) && is_string($api_status)) {
            $mapped = self::map_status($api_status);
            if ($mapped) {
                return $mapped;
            }
        }

        // 3. BACKUP: WooCommerce status
        if ($order->has_status(['processing', 'completed'])) {
            return 'success';
        }

        if ($order->has_status(['failed'])) {
            return 'failed';
        }

        if ($order->has_status(['cancelled'])) {
            return 'cancelled';
        }

        return null;
    }
}
