define([], function () {
    'use strict';

    return function ($) {
        $.ajaxPrefilter(function (settings, original, xhr) {
            var gate = window.HachitechsHideguardRequests,
                timeout = settings.timeout,
                timer,
                remove;

            function expired() {
                xhr.abort('timeout');
            }

            if (!gate || settings.async === false || !timeout || !gate.holds(settings.url, settings.type)) {
                return;
            }
            // jQuery starts its own timer before native send; start it when access is granted instead.
            settings.timeout = 0;
            remove = gate.wait(function () {
                timer = window.setTimeout(expired, timeout);
            }, function () {
                xhr.abort('abort');
            });
            xhr.always(function () {
                remove();
                window.clearTimeout(timer);
            });
        });
        return $;
    };
});
