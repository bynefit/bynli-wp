(function () {
    'use strict';

    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
        return;
    }

    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = (window.wc.wcSettings && window.wc.wcSettings.getSetting) || function () { return {}; };
    var createElement = window.wp.element.createElement;
    var decodeEntities = (window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities) || function (s) { return s; };

    var settings = getSetting('bynefit_data', {}) || {};
    var title = decodeEntities(settings.title || 'Pay with Bynefit');
    var description = decodeEntities(settings.description || '');

    function Label(props) {
        var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
        if (PaymentMethodLabel) {
            return createElement(PaymentMethodLabel, { text: title });
        }
        return createElement('span', null, title);
    }

    function Content() {
        return description ? createElement('p', null, description) : null;
    }

    registerPaymentMethod({
        name: 'bynefit',
        label: createElement(Label, null),
        ariaLabel: title,
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: function () { return true; },
        supports: {
            features: settings.supports || ['products']
        }
    });
})();
