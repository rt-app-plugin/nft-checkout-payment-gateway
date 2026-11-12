/**
 * Classic (shortcode) checkout flow for NFT Checkout.
 *
 * Place Order is submitted to WooCommerce's own checkout endpoint, so every
 * WooCommerce validation and hook runs server-side. On success RT has
 * e-mailed the voucher, and this script replaces the checkout form with an
 * "order received" panel built from the gateway's response.
 *
 * The block checkout does not use this file: it goes through the Store API
 * (NFT-11, see nft-checkout-blocks.js).
 *
 * NFT-10: this script never rewrites billing fields. Validation of names,
 * addresses and postcodes is the server's job.
 */
(function ($, window, document) {
    'use strict';

    if (window.NFTCheckoutInitialized) {
        return;
    }

    window.NFTCheckoutInitialized = true;

    const CHECK_ICON =
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M5 12.5l4.5 4.5L19 7.5"></path>' +
        '</svg>';

    const EXTERNAL_ICON =
        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M14 5h5v5"></path>' +
        '<path d="M19 5l-9 9"></path>' +
        '<path d="M18 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4"></path>' +
        '</svg>';

    const NFTCheckout = {

        PAYMENT_METHOD: nft_checkout_params.payment_method,

        state: {
            submitting: false,
            orderId: null,
            button: null,
            buttonText: ''
        },

        /* =========================================================
         * INIT
         * ========================================================= */

        init: function () {

            this.bindClassicCheckout();
        },

        /* =========================================================
         * CLASSIC CHECKOUT
         * ========================================================= */

        bindClassicCheckout: function () {

            const self = this;

            $('form.checkout')
                .off('checkout_place_order_' + self.PAYMENT_METHOD)
                .on(
                    'checkout_place_order_' + self.PAYMENT_METHOD,
                    function () {

                        const $form = $(this);

                        if (self.state.submitting) {
                            return false;
                        }

                        self.clearCheckoutErrors();

                        // Start custom flow
                        self.handleClassicCheckout($form);

                        // STOP WooCommerce default flow
                        return false;
                    }
                );
        },

        handleClassicCheckout: function ($form) {

            const self = this;

            self.state.submitting = true;

            self.state.button = $form
                .find('button[name="woocommerce_checkout_place_order"]');

            self.state.buttonText = self.state.button.text();

            self.state.button
                .prop('disabled', true)
                .addClass('loading')
                .text('Processing...');

            $.ajax({

                type: 'POST',

                url: wc_checkout_params.checkout_url,

                data: $form.serialize(),

                dataType: 'json',

                success: function (response) {

                    self.handleResponse(response);
                },

                error: function () {

                    self.showCheckoutError(
                        'There was an error processing your order.'
                    );

                    self.reset();
                }
            });
        },

        /* =========================================================
         * RESPONSE HANDLER
         * ========================================================= */

        handleResponse: function (response) {

            const self = this;

            try {

                if (typeof response === 'string') {

                    try {
                        response = JSON.parse(response);
                    } catch (e) {

                        self.showCheckoutError('Invalid server response.');

                        self.reset();

                        return;
                    }
                }

                const success =
                    response?.result === 'success' ||
                    response?.success === true;

                const redirect =
                    response.redirect ||
                    response.data?.redirect ||
                    null;

                const errorMessage =
                    response?.message ||
                    response?.messages ||
                    response?.data?.message ||
                    response?.data?.messages ||
                    response?.data?.error ||
                    response?.error ||
                    'Your order could not be placed. Please try again.';

                self.state.orderId =
                    response.order_id ||
                    response.data?.order_id ||
                    null;

                // =====================================================
                // FAILURE
                // =====================================================
                if (!success) {

                    setTimeout(function () {

                        self.showCheckoutError(errorMessage);

                        // force scroll after Woo rerender
                        const $notice = $('.woocommerce-notices-wrapper');

                        if ($notice.length) {
                            $('html, body').animate({
                                scrollTop: $notice.offset().top - 80
                            }, 300);
                        }

                    }, 50);

                    setTimeout(function () {
                        self.reset();
                    }, 400);

                    return;
                }

                // =====================================================
                // SUCCESS — the voucher email is on its way
                // =====================================================
                const orderReceived = response.data?.order_received;

                if (orderReceived) {

                    self.showOrderReceived(orderReceived);

                    self.reset();

                    return;
                }

                if (redirect && typeof redirect === 'string' && redirect.length > 5) {

                    window.location.href = redirect;

                    self.reset(true);

                    return;
                }

                self.showCheckoutError('Your order could not be placed. Please try again.');

                self.reset();

            } catch (e) {

                self.showCheckoutError('Unexpected checkout error.');

                self.reset();
            }
        },

        /* =========================================================
         * ORDER RECEIVED
         * ========================================================= */

        showOrderReceived: function (details) {

            const text = function (value) {
                return document.createTextNode(value);
            };

            const $summary = $('<div>', { 'class': 'nft-checkout-order-received__summary' });

            const row = function (label, value, modifier) {
                return $('<div>', {
                    'class': 'nft-checkout-order-received__row' + (modifier ? ' ' + modifier : '')
                }).append(
                    $('<span>').text(label),
                    $('<span>').text(value)
                );
            };

            // Customer-supplied values are only ever inserted as text.
            $summary.append(row('Order', '#' + (details.order_number || '')));

            // The reference the voucher email prints under the amount. Skipped
            // when it is just the order number again, which is the row above.
            if (details.reference && String(details.reference) !== String(details.order_number || '')) {
                $summary.append(row('Reference', details.reference));
            }

            (details.items || []).forEach(function (item) {
                $summary.append(row(item.label, item.value));
            });

            $summary.append(
                row('Amount due', details.amount_due || '', 'nft-checkout-order-received__row--total')
            );

            const $panel = $('<div>', {
                'class': 'nft-checkout-order-received',
                role: 'status',
                tabindex: '-1'
            }).append(

                $('<div>', {
                    'class': 'nft-checkout-order-received__icon',
                    'aria-hidden': 'true'
                }).html(CHECK_ICON),

                $('<h2>', { 'class': 'nft-checkout-order-received__title' })
                    .text('Order received'),

                $('<p>', { 'class': 'nft-checkout-order-received__lead' }).append(
                    text('Almost done — check your email ('),
                    $('<strong>').text(details.email || ''),
                    text(') and open the link to pay by card. Your order is saved until you finish.')
                ),

                $summary,

                $('<p>', { 'class': 'nft-checkout-order-received__foot' }).append(
                    text('We’ll send your receipt to '),
                    $('<strong>').text(details.email || ''),
                    text(' once you’ve paid.')
                ),

                this.buildPaymentLinkSection(details)
            );

            // Replace the checkout with the panel.
            const $target = $('form.checkout').first();

            this.clearCheckoutErrors();

            $('.nft-checkout-order-received').remove();

            $('.woocommerce-form-coupon-toggle, .woocommerce-form-login-toggle, form.checkout_coupon, form.woocommerce-form-login').hide();

            if ($target.length) {
                $panel.insertBefore($target);
                $target.hide();
            } else {
                $('body').prepend($panel);
            }

            $('html, body').animate({
                scrollTop: Math.max($panel.offset().top - 80, 0)
            }, 300);

            $panel[0].focus({ preventScroll: true });
        },

        /* =========================================================
         * PAYMENT LINK ("Not receiving the email?")
         * ========================================================= */

        /**
         * The voucher email's own button, for a customer whose email has not
         * arrived: a plain link to the same RT page, opened in a new tab.
         */
        buildPaymentLinkSection: function (details) {

            let link = this.safeLink(details.purchase_url);

            // Unsigned /voucher/redeem/{uuid} links are rejected by the server.
            if (link && link.indexOf('/voucher/redeem/') !== -1 && link.indexOf('signature=') === -1) {
                link = '';
            }

            if (!link) {
                return '';
            }

            const text = function (value) {
                return document.createTextNode(value);
            };

            return $('<p>', { 'class': 'nft-checkout-order-received__link' }).append(
                text('Not receiving the email? '),
                $('<a>', {
                    'class': 'nft-checkout-order-received__link-anchor',
                    href: link,
                    target: '_blank',
                    rel: 'noopener noreferrer'
                }).append(
                    text('Click here'),
                    $('<span>', { 'class': 'nft-checkout-order-received__sr-only' })
                        .text(' (opens in a new tab)'),
                    $('<span>', {
                        'class': 'nft-checkout-order-received__link-icon',
                        'aria-hidden': 'true'
                    }).html(EXTERNAL_ICON)
                )
            );
        },

        /**
         * A payment link fit to show and open: https only, or nothing.
         */
        safeLink: function (value) {

            if (typeof value !== 'string' || !value) {
                return '';
            }

            try {
                const url = new URL(value);

                return url.protocol === 'https:' ? url.href : '';
            } catch (e) {
                return '';
            }
        },

        /* =========================================================
         * UI
         * ========================================================= */

        showCheckoutError: function (message, fields = []) {

            // Clear previous notices first
            $('.woocommerce-notices-wrapper').remove();

            const $wrapper = $('<div>', { 'class': 'woocommerce-notices-wrapper nft-checkout-error-wrap' });
            const $box = $('<div>', { 'class': 'woocommerce-error nft-checkout-error-box', 'role': 'alert' });
            const $header = $('<div>', { 'class': 'nft-checkout-error-header' });
            const $strong = $('<strong>').text(message || '');

            $header.append($strong);
            $box.append($header);

            if (fields && fields.length) {
                const $ul = $('<ul>', { 'class': 'nft-checkout-error-fields' });
                fields.forEach(function (field) {
                    $ul.append($('<li>').text(field));
                });
                $box.append($ul);
            }

            $wrapper.append($box);

            const classicTarget = $('form.checkout');

            if (classicTarget.length) {
                classicTarget.prepend($wrapper);
            } else {
                $('body').prepend($wrapper);
            }

            // Scroll to top notice
            const $notice = $('.woocommerce-notices-wrapper');

            if ($notice.length) {

                $('html, body').animate({
                    scrollTop: $notice.offset().top - 80
                }, 300);
            }
        },

        clearCheckoutErrors: function () {

            $('.woocommerce-notices-wrapper').remove();

            $('.woocommerce-error').remove();

            $('.woocommerce-message').remove();

            $('.woocommerce-info').remove();
        },

        reset: function (keepDisabled = false) {

            this.state.submitting = false;

            const $button = $(
                'button[name="woocommerce_checkout_place_order"]'
            );

            if (!$button.length) {
                return;
            }

            if (keepDisabled) {

                $button
                    .prop('disabled', true)
                    .addClass('loading')
                    .text('Processing...');

                return;
            }

            $button
                .prop('disabled', false)
                .removeClass('loading')
                .text(
                    this.state.buttonText || 'Place order'
                );
        }
    };

    $(document).ready(function () {

        NFTCheckout.init();
    });

})(jQuery, window, document);
