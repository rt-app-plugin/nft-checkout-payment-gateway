<?php
if (!defined('ABSPATH')) exit;

class NFT_Checkout_Payment_State_Engine
{
    const LOCK_TTL  = 12;
    const EVENT_TTL = 86400;

    /**
     * Tracks whether the current transition originated from remote sync (webhook/API sync)
     * so outbound cancel-order-link hooks do not bounce back to RT.
     *
     * @var bool
     */
    private static $applying_remote_sync = false;

    /**
     * Check whether the state engine is currently applying a remote status update.
     *
     * @return bool
     */
    public static function is_applying_remote_sync()
    {
        return self::$applying_remote_sync;
    }

    /* =========================================================
     * ENTRY POINT
     * ========================================================= */
    public static function handle_event($order_id, $event_type, $payload = [])
    {
        global $wpdb;

        $order_id = absint($order_id);
        if ($order_id <= 0) {
            return false;
        }

        $precheck_order = wc_get_order($order_id);
        if (!$precheck_order) {
            return false;
        }

        $payment_method = $precheck_order->get_payment_method();
        if ($payment_method !== 'nft-checkout' && $payment_method !== 'nft_checkout') {
            NFT_Checkout_Payment_Gateway_Logger::warning(
                "Refused state transition for non-nft-checkout order #{$order_id} (payment method: {$payment_method})",
                ['source' => 'nft-checkout-payment-gateway']
            );
            return false;
        }

        $mysql_lock_name = "rt_nft_checkout_state_{$order_id}";
        $mysql_locked    = false;
        if (isset($wpdb) && $wpdb instanceof wpdb) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $mysql_locked = ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $mysql_lock_name)) === '1');
            if (!$mysql_locked) {
                return self::safe_response($precheck_order, 'locked_skip');
            }
        }

        $lock_key      = "rt_nft_checkout_lock_{$order_id}";
        $lock_acquired = add_option($lock_key, time() + self::LOCK_TTL, '', 'no');
        if (!$lock_acquired) {
            $lock_time = (int) get_option($lock_key);
            if ($lock_time > 0 && $lock_time < time()) {
                update_option($lock_key, time() + self::LOCK_TTL, false);
            } else {
                if ($mysql_locked && isset($wpdb) && $wpdb instanceof wpdb) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $mysql_lock_name));
                }
                return self::safe_response($precheck_order, 'locked_skip');
            }
        }

        try {
            // Re-read fresh order inside the lock to prevent TOCTOU races.
            $order = wc_get_order($order_id);
            if (!$order) {
                return false;
            }

            $current_state = self::normalize_state(self::get_state($order));
            $new_state     = self::normalize_state(self::resolve_state($payload));

            if (!$new_state) {
                return self::safe_response($order, 'no_state', $current_state);
            }

            if ($current_state === 'success' || $current_state === 'refunded') {
                return self::safe_response($order, 'final_success_locked', $current_state);
            }

            if ($order->is_paid() || $order->has_status(['processing', 'completed', 'refunded'])) {
                return self::safe_response($order, 'final_success_locked', 'success');
            }

            $event_id = self::generate_event_id($new_state, $payload);

            if (
                in_array($event_type, ['webhook_update', 'api_sync', 'api_update'], true) &&
                self::is_duplicate_event($order, $event_id)
            ) {
                return self::safe_response($order, 'duplicate_event_ignored', $current_state);
            }

            // ONLY apply state change (NO NOTES HERE)
            $can_transition = self::can_transition($current_state, $new_state);

            /**
             * NFT-09: Allow success only from pending, failed, expired, in-flight processing, on-hold or checkout-draft.
             */
            if ($new_state === 'success') {
                if (in_array($current_state, ['pending', 'failed', 'expired', 'processing', 'on-hold', 'checkout-draft'], true)) {
                    $can_transition = true;
                } else {
                    $can_transition = false;
                    NFT_Checkout_Payment_Gateway_Logger::warning(
                        "Refused state transition to success for order #{$order_id} from state '{$current_state}'",
                        ['source' => 'nft-checkout-payment-gateway']
                    );
                }
            }

            /**
             * BLOCK invalid transitions
             */
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

            self::mark_event($order, $event_id);

            /**
             * APPLY STATE
             */
            self::apply($order, $new_state, $event_type, $payload);

            /**
             * PUSH TIMELINE
             */
            if ($new_state === 'failed') {

                $existing = self::timeline_has_state_for_token(
                    $order,
                    'failed',
                    $payload['payment_token'] ?? ''
                );

                if (!$existing) {
                    self::push_timeline_event(
                        $order,
                        $new_state,
                        $event_type,
                        $payload
                    );
                }

            } else {

                self::push_timeline_event(
                    $order,
                    $new_state,
                    $event_type,
                    $payload
                );
            }

            // SINGLE SOURCE OF TRUTH FOR NOTES
            self::sync_order_notes($order);

            return self::safe_response($order, 'updated', $new_state);

        } finally {
            delete_option($lock_key);
            if ($mysql_locked && isset($wpdb) && $wpdb instanceof wpdb) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $mysql_lock_name));
            }
        }
    }

    private static function timeline_has_state_for_token($order, $state, $payment_token)
    {
        if (empty($payment_token)) {
            return false;
        }

        $timeline = self::get_timeline($order);

        foreach ($timeline as $event) {

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
        if (
            self::get_state($order) === 'success' ||
            $order->is_paid() ||
            $order->has_status(['processing', 'completed', 'refunded'])
        ) {
            return;
        }

        $order_id = $order->get_id();
        $payment_token = $payload['payment_token'] ?? '';

        // stable idempotency key (VERY IMPORTANT)
        $state_lock_key = md5($order_id . '|' . $state . '|' . $payment_token);

        $last_lock = $order->get_meta('_nft_checkout_state_lock');

        if ($last_lock === $state_lock_key && !($state === 'success' && $order->has_status(['pending', 'on-hold', 'failed']))) {
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

        if ($wc_status) {
            $prev_remote_flag = self::$applying_remote_sync;
            self::$applying_remote_sync = in_array($event_type, ['webhook_update', 'api_sync', 'api_update'], true);
            try {
                if ($state === 'success') {
                    $order->payment_complete($payment_token);
                    if ($wc_status && $order->get_status() !== $wc_status) {
                        $order->update_status($wc_status, '');
                    }
                } else {
                    $order->update_status($wc_status, '');
                }
            } finally {
                self::$applying_remote_sync = $prev_remote_flag;
            }
        }

        $order->save();
    }

    private static function get_timeline($order)
    {
        $data = $order->get_meta('_nft_checkout_timeline', true);
        if (empty($data)) {
            $data = $order->get_meta('_nft_checkout_timeline', true);
        }

        if (empty($data)) {
            return [];
        }

        // If corrupted string
        if (!is_array($data)) {
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

        /**
         * SINGLE PASS TIMELINE PARSE
         */
        foreach ($timeline as $event) {

            $type  = $event['type'] ?? '';
            $token = trim((string) ($event['payment_token'] ?? ''));

            /**
             * FAILED EVENTS
             */
            if ($type === 'failed') {

                if (
                    !empty($token) &&
                    !isset($seen_failed[$token])
                ) {
                    $seen_failed[$token] = true;

                    $failed_events[] = $event;
                }
            }

            /**
             * SUCCESS EVENT
             */
            if (
                $type === 'success' &&
                !$success_event
            ) {
                $success_event = $event;
            }

            /**
             * CANCEL EVENT
             */
            if (
                $type === 'cancelled' &&
                !$cancel_event
            ) {
                $cancel_event = $event;
            }
        }

        /**
         * FAILED NOTES
         */
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

            $order->update_meta_data(
                '_nft_checkout_failed_note_count',
                $actual_failed_count
            );
        }

        /**
         * SUCCESS NOTE
         */
        if (
            $success_event &&
            !$order->get_meta('_nft_checkout_success_note_added')
        ) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment completed successfully.',
                    $success_event['payment_token'] ?? '',
                    $success_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data(
                '_nft_checkout_success_note_added',
                'yes'
            );
        }

        /**
         * CANCEL NOTE
         */
        if (
            $cancel_event &&
            !$order->get_meta('_nft_checkout_cancel_note_added')
        ) {

            $order->add_order_note(
                self::build_order_note(
                    'Payment was cancelled.',
                    $cancel_event['payment_token'] ?? '',
                    $cancel_event['event_type'] ?? ''
                )
            );

            $order->update_meta_data(
                '_nft_checkout_cancel_note_added',
                'yes'
            );
        }

        $order->save();
    }

    private static function build_order_note(
        $title,
        $payment_token,
        $event_type
    ) {

        $source = match ($event_type) {
            'popup_close'    => 'Customer Return from Payment Page',
            'redirect'       => 'Customer Redirect',
            'webhook_update' => 'Webhook',
            'api_sync'       => 'API Sync',
            default          => 'System'
        };

        // Decode Base64 payment token if possible
        $decoded_payment_id = base64_decode($payment_token, true);

        if (empty($decoded_payment_id)) {
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
    private static function resolve_state($payload)
    {
        $status = $payload['status']
            ?? $payload['payment_status']
            ?? $payload['transaction_status']
            ?? $payload['order_status']
            ?? null;

        $status = $status ? strtolower((string)$status) : null;

        return match ($status) {
            'success', 'paid', 'completed'  => 'success',
            'failed'                        => 'failed',
            'cancelled', 'canceled'        => 'cancelled',
            'expired'                      => 'expired',
            'pending', 'processing'        => 'processing',
            default                        => null
        };
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
        if ($from === 'success' || $from === 'refunded') {
            return false;
        }
        if ($from === $to) {
            return true;
        }

        $map = [
            'pending'        => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold'],
            'on-hold'        => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold'],
            'checkout-draft' => ['pending', 'cancelled', 'processing', 'success', 'failed', 'expired', 'on-hold'],
            'processing'     => ['processing', 'success', 'failed', 'cancelled', 'expired'],
            'failed'         => ['failed', 'success', 'cancelled', 'pending'],
            'cancelled'      => ['cancelled'],
            'expired'        => ['expired', 'failed', 'cancelled', 'success', 'pending'],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }

    /* =========================================================
     * CURRENT STATE
     * ========================================================= */
    private static function get_state($order)
    {
        $wc_status = $order->get_status();

        // Always respect WooCommerce's authoritative paid/refunded/terminal statuses first.
        if ($wc_status === 'refunded') {
            return 'refunded';
        }
        if ($order->is_paid() || in_array($wc_status, ['processing', 'completed'], true)) {
            return 'success';
        }
        if ($wc_status === 'cancelled') {
            return 'cancelled';
        }

        $meta_state = $order->get_meta('_nft_checkout_state');
        if ($wc_status === 'failed') {
            return ($meta_state === 'expired') ? 'expired' : 'failed';
        }

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
        $settings = function_exists('nft_checkout_get_settings')
            ? nft_checkout_get_settings()
            : get_option('woocommerce_nft-checkout_settings', []);
        $status = $settings['order_status'] ?? 'processing';

        return in_array($status, ['processing', 'completed'], true)
            ? $status
            : 'processing';
    }

    /* =========================================================
     * EVENT ID (webhook & API sync deduplication)
     * ========================================================= */
    private static function generate_event_id($state, $payload)
    {
        return hash('sha256', implode('|', [
            (string) $state,
            (string) ($payload['payment_token'] ?? '')
        ]));
    }

    private static function is_duplicate_event($order, $event_id)
    {
        $order_id = $order->get_id();
        if (get_transient("rt_nft_checkout_event_{$order_id}_{$event_id}") !== false) {
            return true;
        }

        $processed = $order->get_meta('_nft_checkout_processed_events', true);
        if (is_array($processed) && in_array($event_id, $processed, true)) {
            return true;
        }

        return false;
    }

    private static function mark_event($order, $event_id)
    {
        $order_id = $order->get_id();
        set_transient("rt_nft_checkout_event_{$order_id}_{$event_id}", 1, self::EVENT_TTL);

        $processed = $order->get_meta('_nft_checkout_processed_events', true);
        if (!is_array($processed)) {
            $processed = [];
        }
        if (!in_array($event_id, $processed, true)) {
            $processed[] = $event_id;
            if (count($processed) > 50) {
                $processed = array_slice($processed, -50);
            }
            $order->update_meta_data('_nft_checkout_processed_events', $processed);
            $order->save();
        }
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
        $state = self::get_state($order);

        if (!empty($state) && in_array($state, ['pending','processing','success','failed','cancelled','expired','refunded'], true)) {
            return $state;
        }

        // 2. SAFETY: API status (current response)
        if (!empty($api_status)) {
            $mapped = match (strtolower((string)$api_status)) {
                'success', 'paid', 'completed' => 'success',
                'failed' => 'failed',
                'cancelled', 'canceled' => 'cancelled',
                'processing', 'pending' => 'processing',
                default => null
            };

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
