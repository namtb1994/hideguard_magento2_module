define([], function () {
    'use strict';

    return function (placeOrder) {
        return function () {
            var request = placeOrder.apply(this, arguments);

            // Registered before the payment component's success redirect and customer-data reload.
            request.done(function (orderId) {
                if (Number.isInteger(orderId) && orderId > 0) {
                    window.dispatchEvent(new CustomEvent('hachitech:order-placed'));
                }
            });
            return request;
        };
    };
});
