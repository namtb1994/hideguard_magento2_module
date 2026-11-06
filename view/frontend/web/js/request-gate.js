/* Transport barrier: loaded synchronously before RequireJS and storefront widgets. */
(function (window) {
    'use strict';

    var config = window.HachitechsHideguardRequestConfig,
        state = 'pending',
        waiting = new Set(),
        nativeFetch = window.fetch,
        prototype = window.XMLHttpRequest.prototype,
        open = prototype.open,
        send = prototype.send,
        abort = prototype.abort,
        setRequestHeader = prototype.setRequestHeader,
        requests = new WeakMap();

    if (!config || window.HachitechsHideguardRequests) {
        return;
    }

    function urlOf(value) {
        return new URL(value, window.location.href);
    }

    function isApplication(url, method) {
        var target = urlOf(url);

        if (target.origin !== window.location.origin) {
            return false;
        }
        if (config.verificationUrls.some(function (value) {
            var endpoint = urlOf(value);

            return target.origin === endpoint.origin && target.pathname === endpoint.pathname;
        })) {
            return false;
        }
        return !(/^(GET|HEAD)$/i.test(method || 'GET') && config.assetUrls.some(function (value) {
            var asset = urlOf(value);

            return target.origin === asset.origin && target.pathname.indexOf(asset.pathname) === 0;
        }));
    }

    function denied() {
        return new DOMException('HideGuard access is not allowed.', 'AbortError');
    }

    // Each registration settles once; removing it also releases its timer and body references.
    function wait(start, cancel) {
        var entry, timer;

        function remove() {
            waiting.delete(entry);
            window.clearTimeout(timer);
        }

        entry = function (allowed) {
            remove();
            if (allowed) {
                start();
            } else {
                cancel();
            }
        };
        if (state === 'allowed') {
            start();
        } else if (state !== 'pending') {
            cancel();
        } else {
            waiting.add(entry);
            // The shared verifier has a shorter deadline. Fail closed if it never initializes.
            timer = window.setTimeout(function () {
                entry(false);
            }, 45000);
        }
        return remove;
    }

    function transition(next) {
        state = next;
        if (state !== 'pending') {
            Array.from(waiting).forEach(function (entry) {
                if (waiting.has(entry)) {
                    try {
                        entry(state === 'allowed');
                    } catch (error) {
                        // One widget's callback must not prevent other requests from settling.
                        window.setTimeout(function () {
                            throw error;
                        }, 0);
                    }
                }
            });
        }
    }

    window.HachitechsHideguardRequests = {
        holds: function (url, method) {
            try {
                return state !== 'allowed' && isApplication(url, method);
            } catch (error) {
                // Leave malformed URLs to the caller's native transport error handling.
                return false;
            }
        },
        wait: wait
    };
    window.addEventListener('hachitech:access-state', function (event) {
        if (['pending', 'allowed', 'blocked', 'error'].indexOf(event.detail.status) !== -1) {
            transition(event.detail.status);
        }
    });
    window.addEventListener('pagehide', function () {
        transition('error');
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    if (nativeFetch) {
        window.fetch = function (input, init) {
            var request;

            try {
                if (!window.HachitechsHideguardRequests.holds(
                    input && input.url || input, init && init.method || input && input.method
                )) {
                    return nativeFetch.apply(this, arguments);
                }
                // Snapshot body, headers, credentials and signal using the native Fetch contract.
                request = new Request(input, init);
            } catch (error) {
                return Promise.reject(error);
            }
            return new Promise(function (resolve, reject) {
                var remove = function () {};

                function cancel() {
                    remove();
                    request.signal.removeEventListener('abort', cancel);
                    reject(request.signal.aborted ? request.signal.reason : denied());
                }

                if (request.signal.aborted) {
                    cancel();
                    return;
                }
                request.signal.addEventListener('abort', cancel, {once: true});
                remove = wait(function () {
                    request.signal.removeEventListener('abort', cancel);
                    nativeFetch.call(window, request).then(resolve, reject);
                }, cancel);
            });
        };
    }

    prototype.open = function (method, url, async) {
        var previous = requests.get(this), result;

        if (previous && previous.remove) {
            previous.remove();
        }
        if (previous && previous.forcedState) {
            delete this.readyState;
        }
        requests.set(this, {
            url: String(url), method: method, async: arguments.length < 3 || Boolean(async), queued: false
        });
        // Keep native validation, credentials and OPENED event behavior.
        result = open.apply(this, arguments);
        return result;
    };

    function cancelXhr(xhr, metadata, type) {
        metadata.remove();
        metadata.queued = false;
        metadata.cancelled = true;
        abort.call(xhr);
        // Native send has not run, so native abort cannot deliver the completion events.
        Object.defineProperty(xhr, 'readyState', {configurable: true, value: 4});
        metadata.forcedState = true;
        xhr.dispatchEvent(new Event('readystatechange'));
        if (requests.get(xhr) === metadata && xhr.readyState === 4) {
            xhr.dispatchEvent(new ProgressEvent(type));
            xhr.dispatchEvent(new ProgressEvent('loadend'));
            if (type === 'abort' && requests.get(xhr) === metadata) {
                // A callback may have called open/send again; never reset that new request.
                Object.defineProperty(xhr, 'readyState', {configurable: true, value: 0});
            }
        }
    }

    function snapshotBody(body) {
        var copy;

        if (body instanceof window.FormData) {
            copy = new window.FormData();
            body.forEach(function (value, key) {
                copy.append(key, value);
            });
            return copy;
        }
        if (body instanceof window.URLSearchParams) {
            return new window.URLSearchParams(body);
        }
        if (body instanceof window.ArrayBuffer) {
            return body.slice(0);
        }
        if (window.ArrayBuffer.isView(body)) {
            return new window.Uint8Array(body.buffer, body.byteOffset, body.byteLength).slice();
        }
        return body && body.nodeType === 9 ? body.cloneNode(true) : body;
    }

    prototype.send = function (body) {
        var xhr = this, metadata = requests.get(xhr);

        if (metadata && metadata.queued) {
            throw new DOMException('The request has already been sent.', 'InvalidStateError');
        }
        if (metadata && metadata.cancelled) {
            throw new DOMException('The request was aborted. Call open before sending again.', 'InvalidStateError');
        }
        if (!metadata || xhr.readyState !== 1
            || !window.HachitechsHideguardRequests.holds(metadata.url, metadata.method)
        ) {
            return send.apply(xhr, arguments);
        }
        if (!metadata.async) {
            throw new DOMException('Synchronous requests cannot wait for HideGuard.', 'InvalidAccessError');
        }
        body = snapshotBody(body);
        metadata.queued = true;
        metadata.remove = function () {};
        metadata.remove = wait(function () {
            metadata.queued = false;
            try {
                send.call(xhr, body);
            } catch (error) {
                cancelXhr(xhr, metadata, 'error');
            }
        }, function () {
            cancelXhr(xhr, metadata, 'abort');
        });
    };

    prototype.setRequestHeader = function () {
        var metadata = requests.get(this);

        if (metadata && (metadata.queued || metadata.cancelled)) {
            throw new DOMException('Headers cannot be changed after send.', 'InvalidStateError');
        }
        return setRequestHeader.apply(this, arguments);
    };

    prototype.abort = function () {
        var metadata = requests.get(this);

        if (metadata && metadata.queued) {
            cancelXhr(this, metadata, 'abort');
            return;
        }
        return abort.apply(this, arguments);
    };
}(window));
