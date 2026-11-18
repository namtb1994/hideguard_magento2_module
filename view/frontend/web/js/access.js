define(['jquery', 'mage/cookies', 'mage/translate', './runtime', 'domReady!'], function ($, cookies, $t, runtime) {
    'use strict';

    return function (config) {
        var points = config.points || [],
            orderPlaced = config.page === 'checkout_success',
            api,
            fetch,
            route = window.location.href.split('#')[0];

        api = runtime.initialize(config, {
            translate: $t,
            prepareBody: function (data) {
                data.set('form_key', $.mage.cookies.get('form_key') || window.FORM_KEY || '');
            },
            isProtectedAction: function (target, path) {
                var cart = /\/checkout\/cart\/add(?:\/|$)/i.test(path) ||
                        target && target.matches('[data-action="add-to-cart"],.action.tocart'),
                    checkout = /\/(?:checkout|onestepcheckout)(?:\/|$)/i.test(path) &&
                        !/\/checkout\/cart(?:\/|$)/i.test(path),
                    order = target && target.matches('.action.checkout,[data-role="review-save"]');

                if (config.page === 'gate') {
                    return false;
                }

                return points.indexOf('all_pages') !== -1 || cart || checkout || order ||
                    /\/customer\/(?:account\/(?:loginPost|createPost)|ajax\/login)(?:\/|$)/i.test(path) ||
                    /\/sociallogin\/(?:popup\/create|social\/(?:login|callback|email))(?:\/|$)/i.test(path) ||
                    /\/contact\/index\/post(?:\/|$)/i.test(path);
            }
        });
        if (api) {
            window.HachitechsHideguard = api;
            window.addEventListener('hachitech:order-placed', function () {
                orderPlaced = true;
            });

            function deny(status, data, pendingPageCheck, url) {
                // Consuming the order grant may deny the mini-cart refresh. Keep the success navigation.
                // Actual blocks/errors and business-action denials still use the normal gate.
                if (orderPlaced && data && data.status === 'verification_required'
                    && /\/customer\/section\/load(?:\/|$)/i.test(url.pathname)
                ) {
                    return;
                }
                // Background requests denied during this page's check must not interrupt its SDK verdict.
                if (pendingPageCheck && data && data.status === 'verification_required') {
                    return;
                }
                document.body.setAttribute('inert', '');
                window.location.replace(status === 401 || data && data.status === 'verification_required'
                    ? config.gateUrl : config.deniedUrl);
            }

            // A route change must receive Magento's current page policy before it can unlock.
            function routeChanged() {
                var next = window.location.href.split('#')[0];

                if (next !== route) {
                    route = next;
                    document.body.setAttribute('inert', '');
                    window.location.reload();
                }
            }

            if (window.history) {
                ['pushState', 'replaceState'].forEach(function (method) {
                    var original = window.history[method];

                    window.history[method] = function () {
                        var result = original.apply(this, arguments);

                        routeChanged();
                        return result;
                    };
                });
                window.addEventListener('popstate', routeChanged);
            }
            if (window.fetch) {
                fetch = window.fetch;

                window.fetch = function (input) {
                    var url,
                        pendingPageCheck = config.pageProtected && api.getState().status === 'pending';

                    try {
                        url = new URL(typeof input === 'string' || input instanceof URL ? input : input.url,
                            window.location.href);
                    } catch (error) {
                        return fetch.apply(this, arguments);
                    }
                    return fetch.apply(this, arguments).then(function (response) {
                        if (config.page !== 'gate' && url.origin === new URL(window.location.href).origin &&
                            response.headers &&
                            response.headers.get('X-Hachitech-HideGuard') === 'denied') {
                            return response.clone().json().catch(function () {
                                return {};
                            }).then(function (data) {
                                deny(response.status, data, pendingPageCheck, url);
                                return response;
                            });
                        }
                        return response;
                    });
                };
            }
            // Observe Magento/mage/storage requests, including popup login with global:false.
            $.ajaxPrefilter(function (settings, originalSettings, xhr) {
                var url,
                    pendingPageCheck = config.pageProtected && api.getState().status === 'pending';

                try {
                    url = new URL(settings.url, window.location.href);
                } catch (error) {
                    return;
                }
                // The standalone gate's runtime owns its blocked state, expiry and retry controls.
                if (config.page !== 'gate' && url.origin === new URL(window.location.href).origin) {
                    xhr.always(function () {
                        if (xhr.getResponseHeader('X-Hachitech-HideGuard') === 'denied') {
                            deny(xhr.status, xhr.responseJSON, pendingPageCheck, url);
                        }
                    });
                }
            });
        }
    };
});
