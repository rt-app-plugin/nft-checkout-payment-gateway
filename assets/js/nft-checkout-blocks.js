(function () {
    const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
    const { createElement, useState, useEffect, useRef } = window.wp?.element || {};

    if (typeof registerPaymentMethod !== 'function' || typeof createElement !== 'function') {
        return;
    }

    const settings =
        window.wc?.wcSettings?.getPaymentMethodData?.('nft-checkout') || {};

    const label = settings.title || 'Pay by card';
    const description = settings.description || 'Pay securely by card. Your payment is processed by RT.';

    const NftCheckoutContent = function (props) {
        const { eventRegistration, emitResponse } = props || {};
        const { onPaymentSetup } = eventRegistration || {};
        const [consent, setConsent] = useState(false);
        const consentRef = useRef(consent);
        consentRef.current = consent;

        useEffect(function () {
            if (typeof onPaymentSetup !== 'function') {
                return undefined;
            }
            const unsubscribe = onPaymentSetup(function () {
                const isConsentChecked = !!consentRef.current;
                if (settings.show_consent_checkbox && !isConsentChecked) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'You must consent to the collection of your data to process this payment.',
                    };
                }
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            nft_checkout_nonce: settings.nonce || '',
                            nft_checkout_consent: settings.show_consent_checkbox
                                ? (isConsentChecked ? 'on' : '')
                                : 'on',
                        },
                    },
                };
            });
            return unsubscribe;
        }, [onPaymentSetup, emitResponse]);

        const children = [
            createElement('p', { key: 'desc', className: 'nft-checkout-description-text' }, description),
        ];

        if (settings.show_consent_checkbox) {
            children.push(
                createElement(
                    'label',
                    {
                        key: 'consent',
                        className: 'nft-checkout-blocks-consent',
                        style: { display: 'block', marginTop: '8px' },
                    },
                    createElement('input', {
                        type: 'checkbox',
                        checked: consent,
                        onChange: function (e) {
                            setConsent(!!e.target.checked);
                        },
                        style: { marginRight: '6px' },
                    }),
                    settings.consent_label || 'I consent to the collection of my data to process this payment'
                )
            );
        }

        return createElement('div', { className: 'nft-checkout-description' }, children);
    };

    const methodConfig = {
        name: settings.id || 'nft-checkout',
        label: label,
        ariaLabel: label,

        content: createElement(NftCheckoutContent, {}),

        edit: createElement(
            'div',
            { className: 'nft-checkout-edit' },
            label
        ),

        canMakePayment: async () => {
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

// Call this function whenever you want to refresh payment methods in the block checkout
function refreshBlockPaymentMethods() {
    if (window.wc && window.wc.blocksCheckout) {
        document.body.dispatchEvent(new CustomEvent('wc-blocks_checkout_update_payment_methods'));
    } else if (window.jQuery) {
        jQuery(document.body).trigger('update_checkout');
    }
}