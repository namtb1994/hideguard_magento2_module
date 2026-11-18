define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    return function (config, element) {
        var button = $(element),
            message = button.next('.hachitech-hideguard-reset-status');

        button.on('click', function () {
            button.prop('disabled', true);
            message.text($t('Resetting blocks…'));
            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {form_key: window.FORM_KEY, store: config.store, website: config.website}
            }).done(function () {
                message.text($t('Block list reset. Reload blocked storefront pages.'));
            }).fail(function (response) {
                message.text(
                    response.responseJSON && response.responseJSON.error || $t('Unable to reset blocks. Please retry.')
                );
            }).always(function () {
                button.prop('disabled', false);
            });
        });
    };
});
