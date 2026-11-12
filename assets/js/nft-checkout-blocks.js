/**
 * WooCommerce Blocks payment method for NFT Checkout.
 *
 * NFT-11: nothing here intercepts Place Order. WooCommerce Blocks submits the
 * checkout through the Store API, which runs every validation (required
 * fields, terms, stock, captcha / anti-fraud hooks, this gateway's validators)
 * and then calls process_payment(). RT e-mails the voucher and the shopper is
 * sent to the order-received page.
 *
 * The only data this component contributes is the consent checkbox (when the
 * merchant enabled it), passed to the gateway as payment method data so
 * validate_fields() sees it exactly as in the classic checkout.
 */
(function () {
    const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
    const { createElement, RawHTML, useState, useEffect, Fragment } = window.wp?.element || {};
    const { getSetting } = window.wc?.wcSettings || {};

    if (typeof registerPaymentMethod !== 'function' || typeof createElement !== 'function') {
        return;
    }

    const PAYMENT_METHOD = 'nft-checkout';

    const settings =
        (window.wc?.wcSettings?.getPaymentMethodData?.(PAYMENT_METHOD)) ||
        (typeof getSetting === 'function' ? getSetting(PAYMENT_METHOD + '_data', {}) : {}) ||
        {};

    const label = settings.title || 'Pay by card';
    const description = settings.description || '';
    const showConsent = settings.show_consent_checkbox === true;

    /**
     * Content shown when the method is selected: the description, the e-mail
     * notice and, if enabled, the consent checkbox whose value is handed to
     * the gateway on payment setup.
     */
    const Content = function (props) {
        const { eventRegistration, emitResponse } = props;
        const { onPaymentSetup } = eventRegistration;
        const [consent, setConsent] = useState(false);

        useEffect(
            function () {
                const unsubscribe = onPaymentSetup(function () {
                    if (showConsent && !consent) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message:
                                'You must consent to the collection of your data to process this payment.',
                            messageContext: emitResponse.noticeContexts.PAYMENTS,
                        };
                    }
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                nft_checkout_consent: consent ? 'on' : '',
                                nft_checkout_nonce: settings.consent_nonce || '',
                            },
                        },
                    };
                });
                return unsubscribe;
            },
            [onPaymentSetup, consent, emitResponse]
        );

        const children = [];

        if (description) {
            children.push(
                createElement(RawHTML, { key: 'description', className: 'nft-checkout-description' }, description)
            );
        }

        if (settings.email_notice) {
            children.push(
                createElement('p', { key: 'notice', className: 'nft-checkout-email-notice' }, settings.email_notice)
            );
        }

        if (showConsent) {
            children.push(
                createElement(
                    'p',
                    { key: 'consent', className: 'form-row form-row-wide nft-checkout-consent' },
                    createElement(
                        'label',
                        { htmlFor: 'nft_checkout_consent' },
                        createElement('input', {
                            type: 'checkbox',
                            id: 'nft_checkout_consent',
                            name: 'nft_checkout_consent',
                            checked: consent,
                            onChange: function (e) {
                                setConsent(!!e.target.checked);
                            },
                        }),
                        ' ' + (settings.consent_label || 'I consent to the collection of my data to process this payment')
                    )
                )
            );
        }

        return createElement(Fragment || 'div', {}, children);
    };

    const methodConfig = {
        name: settings.id || PAYMENT_METHOD,
        label,
        ariaLabel: label,

        content: createElement(Content, {}),

        edit: createElement('div', { className: 'nft-checkout-edit' }, label),

        canMakePayment: function () {
            return settings.can_pay !== false;
        },

        supports: {
            features: settings.supports || ['products'],
        },
    };

    if (settings.title && settings.isActive) {
        registerPaymentMethod(methodConfig);
    }
})();
