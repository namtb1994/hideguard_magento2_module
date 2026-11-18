const {test, before, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {JSDOM} = require('jsdom');

const modulePath = path.resolve(__dirname, '../..');
const gateSource = fs.readFileSync(path.join(modulePath, 'view/frontend/web/js/request-gate.js'), 'utf8');
const mixinSource = fs.readFileSync(path.join(modulePath, 'view/frontend/web/js/jquery-request-gate.js'), 'utf8');
const jquerySource = fs.readFileSync(path.resolve(modulePath, '../../../../lib/web/jquery.js'), 'utf8');
let server, origin, hits;

before(async () => {
    hits = [];
    server = http.createServer((request, response) => {
        let body = '';
        request.on('data', chunk => { body += chunk; });
        request.on('end', () => {
            hits.push({url: request.url, headers: request.headers, body});
            const finish = () => {
                response.setHeader('Content-Type', 'application/json');
                response.end(JSON.stringify({ok: true}));
            };
            if (request.url.includes('slow')) setTimeout(finish, 120);
            else finish();
        });
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
});

after(() => new Promise(resolve => server.close(resolve)));

function fixture(t, jquery = false, fetchResponse) {
    const dom = new JSDOM('<!doctype html><body></body>', {url: origin + '/', runScripts: 'outside-only'});
    const window = dom.window;
    const observers = [];
    const NativeObserver = window.MutationObserver;
    window.MutationObserver = class extends NativeObserver {
        constructor(callback) {
            super(callback);
            observers.push(this);
        }
    };
    t.after(() => {
        observers.forEach(observer => observer.disconnect());
        window.close();
    });
    const sent = [], fetched = [], response = {nativeResponse: true};
    const nativeSend = window.XMLHttpRequest.prototype.send;
    window.XMLHttpRequest.prototype.send = function (body) {
        sent.push({xhr: this, body});
        return nativeSend.call(this, body);
    };
    window.Request = class extends Request {
        constructor(input, init) {
            super(typeof input === 'string' ? new URL(input, origin) : input, init);
        }
    };
    window.fetch = request => {
        fetched.push(request);
        return Promise.resolve(fetchResponse ? fetchResponse(request) : response);
    };
    window.HachitechsHideguardRequestConfig = {
        assetUrls: [origin + '/static/', origin + '/media/'],
        verificationUrls: ['check', 'verdict', 'fail'].map(action => origin + '/hideguard/access/' + action + '/')
    };
    window.eval(gateSource);
    if (jquery) {
        window.eval(jquerySource);
        window.define = (dependencies, factory) => factory()(window.jQuery);
        window.eval(mixinSource);
        delete window.define;
    }
    return {
        window, sent, fetched, response,
        transition(status) {
            window.dispatchEvent(new window.CustomEvent('hachitech:access-state', {detail: {status}}));
        },
        xhr(url, body, method = 'GET') {
            const xhr = new window.XMLHttpRequest();
            xhr.open(method, url, true);
            const done = new Promise(resolve => xhr.addEventListener('loadend', resolve, {once: true}));
            xhr.send(body);
            return {xhr, done};
        }
    };
}

for (const verdict of ['allowed', 'blocked', 'verification_failed']) {
    test(`shared runtime releases transports only on backend ${verdict}`, async t => {
        const routes = [];
        const app = fixture(t, true, input => {
            const url = typeof input === 'string' ? input : input.url;
            routes.push(url);
            const payload = url.includes('/check/')
                ? {status: 'verification_required', verificationId: 'fixture-attempt'}
                : {status: verdict, allowed: verdict === 'allowed', blocked: verdict === 'blocked',
                    expiresAt: Date.now() + 300000, blockedUntil: Date.now() + 60000};
            return {ok: true, status: 200, json: () => Promise.resolve(payload)};
        });
        let runtime;
        app.window.define = (dependencies, factory) => { runtime = factory(); };
        app.window.eval(fs.readFileSync(path.join(modulePath, 'view/frontend/web/js/runtime.js'), 'utf8'));
        const waiting = app.xhr('/runtime-widget');
        const control = action => origin + '/hideguard/access/' + action + '/';
        const api = runtime.initialize({enabled: true, points: ['all_pages'], page: '', pageProtected: true,
            initialState: 'pending', publicKey: 'fixture', accessUrl: control('check'),
            verdictUrl: control('verdict'), failUrl: control('fail'), endpointUrl: 'https://hideguard.example',
            gateUrl: origin + '/#gate', deniedUrl: origin + '/#failed'}, {translate: value => value});
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(routes.length, 1, 'preflight bypasses the barrier');
        assert.equal(app.sent.length, 0);
        app.window.dispatchEvent(new app.window.CustomEvent('fpc:verdict', {
            detail: {verdict: 'allow', visitorId: 'fixture-visitor'}
        }));
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(routes.length, 2, 'verdict report bypasses the barrier');
        assert.equal(api.getState().status, verdict === 'verification_failed' ? 'error' : verdict);
        assert.equal(app.sent.length, verdict === 'allowed' ? 1 : 0);
        await waiting.done;
    });
}

test('missing verifier cancels queued transports at a bounded deadline', async t => {
    const app = fixture(t);
    const timers = [];
    const nativeTimer = app.window.setTimeout;
    app.window.setTimeout = (callback, delay) => {
        if (delay === 45000) {
            timers.push(callback);
            return 0;
        }
        return nativeTimer(callback, delay);
    };
    const xhr = app.xhr('/no-verifier');
    const pending = app.window.fetch('/no-verifier-fetch');
    timers.forEach(callback => callback());
    await assert.rejects(pending, {name: 'AbortError'});
    await xhr.done;
    app.transition('allowed');
    assert.equal(app.sent.length, 0);
    assert.equal(app.fetched.length, 0);
});

test('a throwing widget callback cannot prevent the rest of the queue from settling', async t => {
    const app = fixture(t);
    let reported = 0;
    app.window.addEventListener('error', event => {
        reported++;
        event.preventDefault();
    });
    app.window.HachitechsHideguardRequests.wait(() => {}, () => { throw new Error('widget failure'); });
    const pending = app.window.fetch('/other-widget');
    app.transition('blocked');
    await assert.rejects(pending, {name: 'AbortError'});
    await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(reported, 1);
    assert.equal(app.fetched.length, 0);
});

test('arbitrary application XHR/fetch wait and are sent once after backend Allow', async t => {
    const app = fixture(t);
    const a = app.xhr('/widget-one');
    const b = app.xhr('/customer/section/load');
    const fetched = app.window.fetch('/another-app/data.json');
    assert.equal(app.sent.length, 0);
    assert.equal(app.fetched.length, 0);
    app.transition('pending');
    assert.equal(app.sent.length, 0);
    app.transition('allowed');
    app.transition('allowed');
    assert.equal(app.sent.length, 2);
    assert.equal(await fetched, app.response);
    assert.equal(app.fetched.length, 1);
    await Promise.all([a.done, b.done]);
    assert.equal(a.xhr.status, 200);
    assert.equal(b.xhr.status, 200);
    const c = app.xhr('/later-widget');
    assert.equal(app.sent.length, 3);
    await c.done;
});

test('static/media/templates, cross-origin traffic and verification bypass pending', async t => {
    const app = fixture(t);
    const urls = ['/static/version1/theme/template.html', '/media/catalog/item.json',
        '/hideguard/access/check/?retry=1', '/hideguard/access/verdict/',
        '/hideguard/access/fail/'];
    const requests = urls.map(url => app.xhr(url));
    await app.window.fetch('https://hideguard.example/sdk/v1.js');
    assert.equal(app.sent.length, urls.length);
    assert.equal(app.fetched.length, 1);
    assert.equal(app.window.HachitechsHideguardRequests.holds('/static-not-assets/foo.js', 'GET'), true);
    assert.equal(app.window.HachitechsHideguardRequests.holds('/static/file', 'POST'), true);
    assert.equal(app.window.HachitechsHideguardRequests.holds('/hideguard/access/check/extra', 'GET'), true);
    await Promise.all(requests.map(request => request.done));
});

test('fetch snapshots native Request options and preserves the native response', async t => {
    const app = fixture(t);
    const headers = {'X-Test': 'original'};
    const pending = app.window.fetch('/save', {method: 'POST', body: 'a=1', headers, credentials: 'include'});
    headers['X-Test'] = 'changed';
    app.transition('allowed');
    assert.equal(await pending, app.response);
    const request = app.fetched[0];
    assert.equal(request.method, 'POST');
    assert.equal(request.credentials, 'include');
    assert.equal(request.headers.get('X-Test'), 'original');
    assert.equal(await request.text(), 'a=1');
});

test('queued POST snapshots FormData and freezes headers until native send', async t => {
    const app = fixture(t);
    const body = new app.window.FormData();
    body.append('value', 'original');
    const request = app.xhr('/form-body', body, 'POST');
    body.set('value', 'changed');
    assert.throws(() => request.xhr.setRequestHeader('X-Late', 'value'), {name: 'InvalidStateError'});
    app.transition('allowed');
    await request.done;
    const hit = hits.find(item => item.url === '/form-body');
    assert.match(hit.body, /original/);
    assert.doesNotMatch(hit.body, /changed/);
});

test('native XHR timeout counts backend time after Allow', async t => {
    const app = fixture(t);
    const request = app.xhr('/slow-native');
    request.xhr.timeout = 20;
    let timeouts = 0;
    request.xhr.ontimeout = () => { timeouts++; };
    await new Promise(resolve => setTimeout(resolve, 40));
    assert.equal(timeouts, 0);
    app.transition('allowed');
    await request.done;
    assert.equal(timeouts, 1);
});

test('AbortSignal cancels fetch before Allow without sending', async t => {
    const app = fixture(t);
    const controller = new AbortController();
    const pending = app.window.fetch('/never-fetch', {signal: controller.signal});
    controller.abort();
    await assert.rejects(pending, {name: 'AbortError'});
    app.transition('allowed');
    assert.equal(app.fetched.length, 0);
});

for (const status of ['blocked', 'error', 'pagehide']) {
    test(`${status} cancels waiting XHR/fetch and never replays them`, async t => {
        const app = fixture(t);
        const request = app.xhr('/never-send');
        const pending = app.window.fetch('/never-fetch');
        if (status === 'pagehide') app.window.dispatchEvent(new app.window.Event('pagehide'));
        else app.transition(status);
        await assert.rejects(pending, {name: 'AbortError'});
        await request.done;
        assert.equal(request.xhr.status, 0);
        app.transition('allowed');
        assert.equal(app.sent.length, 0);
        assert.equal(app.fetched.length, 0);
    });
}

test('queued XHR abort events settle once; reopen works and double send fails', async t => {
    const app = fixture(t);
    const request = app.xhr('/cancel-me');
    const events = [];
    for (const name of ['readystatechange', 'abort', 'loadend']) {
        request.xhr.addEventListener(name, () => events.push([name, request.xhr.readyState]));
    }
    assert.throws(() => request.xhr.send(), {name: 'InvalidStateError'});
    request.xhr.abort();
    await request.done;
    assert.deepEqual(events, [['readystatechange', 4], ['abort', 4], ['loadend', 4]]);
    assert.equal(request.xhr.readyState, 0);
    assert.throws(() => request.xhr.send(), {name: 'InvalidStateError'});
    request.xhr.open('POST', '/reopened', true);
    request.xhr.setRequestHeader('X-Queue-Test', 'preserved');
    request.xhr.send('payload');
    app.transition('allowed');
    await new Promise(resolve => request.xhr.addEventListener('loadend', resolve, {once: true}));
    assert.equal(app.sent.length, 1);
    assert.equal(request.xhr.status, 200);
    assert.ok(hits.some(hit => hit.url === '/reopened' && hit.body === 'payload'
        && hit.headers['x-queue-test'] === 'preserved'));
});

test('opening a queued XHR again removes the old send', async t => {
    const app = fixture(t);
    const {xhr} = app.xhr('/old');
    xhr.open('GET', '/new');
    xhr.send();
    app.transition('allowed');
    await new Promise(resolve => xhr.addEventListener('loadend', resolve, {once: true}));
    assert.equal(app.sent.length, 1);
});

test('abort handler can reopen and send without the old cancellation resetting it', async t => {
    const app = fixture(t);
    const request = app.xhr('/abort-reentrant');
    request.xhr.onabort = () => {
        request.xhr.open('GET', '/replacement');
        request.xhr.send();
    };
    request.xhr.abort();
    app.transition('allowed');
    await new Promise(resolve => request.xhr.addEventListener('load', resolve, {once: true}));
    assert.equal(app.sent.length, 1);
    assert.equal(request.xhr.readyState, 4);
    assert.equal(request.xhr.status, 200);
});

test('synchronous application XHR fails closed while pending', t => {
    const app = fixture(t);
    const xhr = new app.window.XMLHttpRequest();
    xhr.open('GET', '/sync', false);
    assert.throws(() => xhr.send(), {name: 'InvalidAccessError'});
    assert.equal(app.sent.length, 0);
});

test('real Magento jQuery preserves Deferred and starts timeout after Allow', async t => {
    const app = fixture(t, true);
    const $ = app.window.jQuery;
    let success = 0, failure = 0, complete = 0;
    const xhr = $.ajax({url: '/jquery-data', dataType: 'json', timeout: 50,
        success() { success++; }, error() { failure++; }, complete() { complete++; }});
    const done = new Promise(resolve => xhr.always(resolve));
    assert.equal(typeof xhr.done, 'function');
    await new Promise(resolve => setTimeout(resolve, 90));
    assert.equal(failure, 0);
    assert.equal(app.sent.length, 0);
    app.transition('allowed');
    await done;
    assert.equal(app.sent.length, 1);
    assert.equal(success, 1);
    assert.equal(failure, 0);
    assert.equal(complete, 1);
});

test('real jQuery timeout still aborts a slow backend after Allow', async t => {
    const app = fixture(t, true);
    const xhr = app.window.jQuery.ajax({url: '/slow', timeout: 20});
    const done = new Promise(resolve => xhr.fail((request, status) => resolve(status)));
    app.transition('allowed');
    assert.equal(await done, 'timeout');
    assert.equal(app.sent.length, 1);
});

for (const action of ['abort', 'blocked', 'error']) {
    test(`real jQuery ${action} settles Deferred once without sending`, async t => {
        const app = fixture(t, true);
        let failed = 0, completed = 0;
        const xhr = app.window.jQuery.ajax({url: '/jquery-never', timeout: 100,
            error() { failed++; }, complete() { completed++; }});
        if (action === 'abort') xhr.abort();
        else app.transition(action);
        app.transition('allowed');
        assert.equal(failed, 1);
        assert.equal(completed, 1);
        assert.equal(app.sent.length, 0);
    });
}
