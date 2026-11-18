import {test} from 'node:test';
import assert from 'node:assert/strict';
import {browser} from './browser.mjs';
const flush = () => new Promise(resolve => setImmediate(resolve));

test('Magento adapter forwards a custom HideGuard URL to the shared SDK runtime', async () => {
  const app = browser({endpointUrl: 'https://magento.hideguard.example:8443/'});
  await flush();
  assert.equal(app.requests[0].body.form_key, 'form-key');
  assert.equal(app.scripts[0].src, 'https://magento.hideguard.example:8443/sdk/v1.js');
  assert.equal(app.scripts[0].dataset.endpoint, 'https://magento.hideguard.example:8443');
});

test('gate is locked synchronously before the first network response', async () => {
  const app = browser();
  assert.equal(app.locked, true);
  assert.equal(app.bodyLockedBeforeInit, true);
  assert.equal(app.lockedBeforeInit, true);
  assert.equal(app.document.body.hasAttribute('inert'), false);
  assert.equal(app.document.body.hasAttribute('data-hachitech-hideguard-inert'), false);
  assert.equal(app.gate.getAttribute('data-state'), 'pending');
  assert.equal(app.content.hasAttribute('inert'), true);
  assert.equal(app.heading.textContent, 'Verifying access');
  await flush();
  assert.equal(app.scripts.length, 1);
  assert.equal(app.requests[0].body.form_key, 'form-key');
  assert.equal(app.requests[0].credentials, 'same-origin');
});

test('missing endpoint does not load an SDK from a fallback host', async () => {
  const app = browser({endpointUrl: null});
  await flush();
  assert.equal(app.scripts.length, 0);
  assert.equal(app.gate.getAttribute('data-state'), 'error');
});

test('interaction lock is an AMD dependency initialized before access requests', () => {
  const app = browser();
  assert.ok(app.accessDependencies.includes('./runtime'));
  assert.ok(app.accessDependencies.includes('domReady!'));
  assert.equal(app.lockedBeforeInit, true);
  assert.equal(app.requests.length, 0);
  assert.equal('HachitechsHideguardLock' in app.window, false);
});

test('product-only protection never checks unselected home or CMS pages, including passed users', async () => {
  for (const page of ['', 'cms']) {
    for (const preflight of [{status: 'verification_required'},
      {status: 'allowed', allowed: true, passDuration: 0, expiresAt: Date.now() + 300000},
      {status: 'blocked', blocked: true, blockedUntil: Date.now() + 60000}]) {
      const app = browser({page, pageProtected: false, points: ['product'], preflight});
      await flush();
      assert.equal(app.requests.length, 0);
      assert.equal(app.scripts.length, 0);
      assert.equal(app.locked, false);
      assert.equal(app.timers.filter(timer => timer.active).length, 0);
      app.verdict('allow');
      await flush();
      assert.equal(app.requests.length, 0);
    }
  }
});

test('selected product pages always load SDK even for a previously passed user', async () => {
  for (const preflight of [{status: 'verification_required'},
    {status: 'allowed', allowed: true, passDuration: 0, expiresAt: Date.now() + 300000}]) {
    const app = browser({page: 'product', pageProtected: true, points: ['product'], preflight});
    await flush();
    assert.equal(app.requests.length, 1);
    assert.equal(app.scripts.length, 1);
    assert.equal(app.locked, true);
  }
});

test('all-pages protection still checks the home page', async () => {
  const app = browser({page: '', pageProtected: true, points: ['all_pages']});
  await flush();
  assert.equal(app.requests.length, 1);
  assert.equal(app.scripts.length, 1);
  assert.equal(app.locked, true);
});

test('the PHP page decision is authoritative even when page metadata or points disagree', async () => {
  for (const [page, points] of [['product', ['product']], ['', ['all_pages']]]) {
    const app = browser({page, pageProtected: false, points});
    await flush();
    assert.equal(app.requests.length, 0);
    assert.equal(app.scripts.length, 0);
    assert.equal(app.locked, false);
  }
});

test('unprotected pages defer selected action checks until the protected interaction', async () => {
  for (const [point, href, selector, type] of [
    ['cart', '/checkout/cart/add/product/1', '', 'click'],
    ['cart', '/checkout/Cart/Add/product/1', '', 'click'],
    ['checkout', '/checkout/', '', 'click'],
    ['checkout', '/checkout/Index/Index', '', 'click'],
    ['complete_order', '', '.action.checkout', 'submit']
  ]) {
    const allowed = {status: 'allowed', allowed: true, passDuration: 0, expiresAt: Date.now() + 300000};
    const app = browser({page: '', pageProtected: false, points: [point], report: () => allowed});
    await flush();
    assert.equal(app.requests.length, 0);
    assert.equal(app.scripts.length, 0);
    const target = {closest: name => name === 'form' ? null : target,
      getAttribute: name => name === 'href' ? href : null,
      matches: selectors => selectors.split(',').includes(selector)};
    assert.deepEqual(app.interaction(type, target), {prevented: true, stopped: true});
    assert.equal(app.locked, true);
    await flush();
    assert.equal(app.requests.length, 1);
    assert.equal(app.scripts.length, 1);
    app.verdict('allow');
    await flush();
    assert.equal(app.locked, false);
    assert.deepEqual(app.interaction(type, target), {prevented: false, stopped: false});
  }
});

test('cart actions require verification even when only product pages are protected', async () => {
  const app = browser({page: '', pageProtected: false, points: ['product']});
  const target = {closest: name => name === 'form' ? null : target,
    getAttribute: name => name === 'href' ? '/checkout/cart/add/product/1' : null,
    matches: () => false};
  assert.deepEqual(app.interaction('click', target), {prevented: true, stopped: true});
  await flush();
  assert.equal(app.requests.length, 1);
  assert.equal(app.scripts.length, 1);
  assert.equal(app.locked, true);
});

test('Magento action denial locks the page and redirects to the common block gate', async () => {
  const app = browser({page: '', pageProtected: false, points: []});
  await flush();
  assert.equal(app.requests.length, 0);
  app.ajaxComplete('/rest/default/V1/carts/mine/payment-information', 401, 'denied');
  assert.equal(app.document.body.hasAttribute('inert'), true);
  assert.deepEqual(app.redirects, ['/blocked']);
});

test('successful order response preserves the native redirect against a late mini-cart denial', async () => {
  const allowed = {status: 'allowed', allowed: true, passDuration: 0, expiresAt: Date.now() + 300000};
  const app = browser({page: '', pageProtected: true, report: () => allowed});
  await flush();
  app.verdict('allow');
  await flush();
  const finishMiniCart = app.ajaxStart('/customer/section/load/?sections=cart,captcha,messages');
  const callbacks = [];
  const request = {done(callback) { callbacks.push(callback); return this; }};
  const owner = {};
  const placeOrder = app.placeOrder(function (...args) {
    assert.equal(this, owner);
    assert.deepEqual(args, ['/payment-information', {cartId: 6}]);
    return request;
  });
  assert.equal(placeOrder.call(owner, '/payment-information', {cartId: 6}), request);
  request.done(() => app.window.location.replace('/checkout/onepage/success/'));
  callbacks.forEach(callback => callback(6));
  finishMiniCart(403, 'denied', {status: 'verification_required'});
  assert.deepEqual(app.redirects, ['/checkout/onepage/success/']);
  assert.equal(app.document.body.hasAttribute('inert'), false);
});

test('an unsuccessful order cannot suppress a mini-cart verification denial', () => {
  for (const response of [undefined, null, 0, {responseType: 'error'}]) {
    const app = browser({page: '', pageProtected: false});
    const callbacks = [];
    const request = {done(callback) { callbacks.push(callback); return this; }};
    app.placeOrder(() => request)();
    callbacks.forEach(callback => callback(response));
    app.ajaxComplete('/customer/section/load/', 403, 'denied', {status: 'verification_required'});
    assert.deepEqual(app.redirects, ['/blocked']);
  }
});

test('success page does not restart Always verification or redirect on a mini-cart refresh', async () => {
  const app = browser({page: 'checkout_success', pageProtected: false});
  await flush();
  assert.equal(app.requests.length, 0);
  assert.equal(app.scripts.length, 0);
  assert.equal(app.locked, false);
  app.ajaxComplete('/customer/section/load/', 403, 'denied', {status: 'verification_required'});
  assert.deepEqual(app.redirects, []);
  assert.equal(app.document.body.hasAttribute('inert'), false);
});

test('order completion never suppresses real blocks, errors or business action denials', () => {
  for (const [url, status, data, destination] of [
    ['/customer/section/load/', 401, {status: 'blocked'}, '/blocked'],
    ['/customer/section/load/', 503, {status: 'error'}, '/failed'],
    ['/rest/default/V1/carts/mine/payment-information', 403, {status: 'verification_required'}, '/blocked'],
    ['/checkout/cart/add/', 403, {status: 'verification_required'}, '/blocked']
  ]) {
    const app = browser({page: 'checkout_success', pageProtected: false});
    app.ajaxComplete(url, status, 'denied', data);
    assert.deepEqual(app.redirects, [destination]);
  }
});

test('a late fetch mini-cart denial also preserves the success page without locking it', async () => {
  const response = {status: 403, headers: {get: () => 'denied'},
    clone: () => ({json: async () => ({status: 'verification_required'})})};
  const app = browser({page: 'checkout_success', pageProtected: false, fetchResponse: response});
  assert.equal(await app.window.fetch('/customer/section/load/?sections=cart'), response);
  assert.deepEqual(app.redirects, []);
  assert.equal(app.document.body.hasAttribute('inert'), false);
});

test('unavailable action verification uses the failure gate and ignores unrelated or remote HTTP errors', () => {
  const app = browser({page: '', pageProtected: false});
  app.ajaxComplete('/customer/ajax/login', 401, null);
  app.ajaxComplete('https://third-party.test/payment', 401, 'denied');
  assert.deepEqual(app.redirects, []);
  app.ajaxComplete('/customer/ajax/login', 503, 'denied');
  assert.deepEqual(app.redirects, ['/failed']);
});

test('a first popup login without a visitor goes to the pending gate to run SDK verification', () => {
  const app = browser({page: '', pageProtected: false});
  app.ajaxComplete('/customer/ajax/login', 403, 'denied', {status: 'verification_required'});
  assert.deepEqual(app.redirects, ['/blocked']);
});

test('login and registration actions start verification on unprotected pages', async () => {
  for (const path of ['/customer/account/loginPost', '/customer/account/createPost', '/customer/ajax/login',
    '/sociallogin/popup/create', '/sociallogin/social/login', '/sociallogin/social/callback', '/sociallogin/social/email']) {
    const app = browser({page: '', pageProtected: false, points: []});
    const target = {closest: name => name === 'form' ? null : target,
      getAttribute: name => name === 'href' ? path : null, matches: () => false};
    assert.deepEqual(app.interaction('submit', target), {prevented: true, stopped: true});
    await flush();
    assert.equal(app.requests.length, 1);
  }
});

test('standalone gate stays visible until navigation after a backend-confirmed pass', async () => {
  const allowed = {status: 'allowed', allowed: true, passDuration: 300, expiresAt: Date.now() + 300000};
  const app = browser({initialState: 'blocked', report: () => allowed});
  await flush();
  assert.equal(app.gate.getAttribute('data-page'), 'gate');
  assert.equal(app.gate.getAttribute('data-state'), 'pending');
  app.verdict('allow');
  await flush();
  assert.equal(app.window.HachitechsHideguard.getState().status, 'allowed');
  assert.deepEqual(app.redirects, ['/protected-page']);
  assert.equal(app.gate.hidden, false);
  assert.equal(app.locked, true);
  assert.equal(app.content.hasAttribute('inert'), true);
});


test('SPA path or query changes synchronously lock and request Magento policy through a full navigation', () => {
  for (const method of ['pushState', 'replaceState']) {
    const app = browser({page: '', pageProtected: false});
    app.window.history[method]({}, '', '#details');
    assert.equal(app.reloads, 0);
    app.window.history[method]({}, '', '/another-page?product=2');
    assert.equal(app.document.body.hasAttribute('inert'), true);
    assert.equal(app.reloads, 1);
  }
  const app = browser({page: '', pageProtected: false});
  app.window.location.href = 'https://store.test/product.html';
  app.listeners.get('popstate')({});
  assert.equal(app.reloads, 1);
});

test('custom fetch denials use the shared gate without consuming the caller response', async () => {
  for (const [status, data, destination] of [[401, {status: 'blocked'}, '/blocked'],
    [403, {status: 'verification_required'}, '/blocked'], [503, {status: 'error'}, '/failed']]) {
    let cloned = 0;
    const response = {status, headers: {get: () => 'denied'},
      clone: () => { cloned++; return {json: async () => data}; }};
    const app = browser({page: '', pageProtected: false, fetchResponse: response});
    assert.equal(await app.window.fetch('/rest/default/V1/carts/mine/payment-information'), response);
    assert.equal(cloned, 1);
    assert.equal(app.document.body.hasAttribute('inert'), true);
    assert.deepEqual(app.redirects, [destination]);
  }
});

test('fetch ignores remote denials and unrelated same-origin HTTP failures', async () => {
  for (const [url, marker] of [['https://remote.test/payment', 'denied'], ['/cart', null]]) {
    const response = {status: 401, headers: {get: () => marker}, clone: () => assert.fail('Unexpected body read')};
    const app = browser({page: '', pageProtected: false, fetchResponse: response});
    assert.equal(await app.window.fetch(url), response);
    assert.deepEqual(app.redirects, []);
  }
});

test('contact form starts verification before submitting on an unprotected page', async () => {
  const app = browser({page: '', pageProtected: false, points: []});
  const target = {closest: name => name === 'form' ? null : target,
    getAttribute: name => name === 'href' ? '/contact/index/post' : null, matches: () => false};
  assert.deepEqual(app.interaction('submit', target), {prevented: true, stopped: true});
  await flush();
  assert.equal(app.requests.length, 1);
});


test('blocked gate ignores background AJAX denials and keeps its runtime expiry timer', async () => {
  const blocked = {status: 'blocked', blocked: true, blockedUntil: Date.now() + 3600000};
  let preflight = blocked;
  const app = browser({page: 'gate', pageProtected: false, initialState: 'blocked', preflight: () => preflight});
  await flush();
  const requests = app.requests.length;
  const expiryTimer = app.timers.find(timer => timer.active && timer.ms > 3500000);
  assert.ok(expiryTimer);
  for (const url of ['/customer/section/load/?sections=messages', '/fbe/pixel/userData/', '/fbe/pixel/tracker/']) {
    app.ajaxComplete(url, 401, 'denied', blocked);
  }
  assert.deepEqual(app.redirects, []);
  assert.equal(app.reloads, 0);
  assert.equal(app.gate.getAttribute('data-state'), 'blocked');
  assert.equal(app.document.body.hasAttribute('inert'), false);
  assert.equal(expiryTimer.active, true);
  assert.equal(app.requests.length, requests);
  assert.equal(app.scripts.length, 0);
  preflight = {status: 'allowed', allowed: true, passDuration: 300, expiresAt: Date.now() + 300000};
  expiryTimer.callback();
  await flush();
  assert.deepEqual(app.redirects, ['/protected-page']);
});

test('background fetch denials on the gate do not navigate or make the retry UI inert', async () => {
  const blocked = {status: 'blocked', blocked: true, blockedUntil: Date.now() + 3600000};
  const response = {ok: false, status: 401, headers: {get: () => 'denied'}, json: async () => blocked,
    clone: () => ({json: async () => blocked})};
  const app = browser({page: 'gate', pageProtected: false, initialState: 'blocked', preflight: blocked, fetchResponse: response});
  await flush();
  assert.equal(await app.window.fetch('/customer/section/load/?sections=messages'), response);
  assert.deepEqual(app.redirects, []);
  assert.equal(app.reloads, 0);
  assert.equal(app.document.body.hasAttribute('inert'), false);
  assert.equal(app.gate.getAttribute('data-state'), 'blocked');
});


test('background denials leave the failure gate retry controls available', async () => {
  const failure = {status: 'verification_failed'};
  const response = {status: 503, headers: {get: () => 'denied'}, clone: () => ({json: async () => failure})};
  const app = browser({page: 'gate', pageProtected: false, initialState: 'error', preflight: failure,
    responseCode: 403, fetchResponse: response});
  await flush();
  app.ajaxComplete('/customer/section/load/', 503, 'denied', failure);
  assert.equal(await app.window.fetch('/fbe/pixel/userData/'), response);
  assert.deepEqual(app.redirects, []);
  assert.equal(app.document.body.hasAttribute('inert'), false);
  assert.equal(app.gate.getAttribute('data-state'), 'error');
});


test('All pages exempts gate controls so Retry starts verification and still waits for backend Allow', async () => {
  const pending = {status: 'verification_required', verificationId: 'retry-attempt', verificationUntil: Date.now() + 35000};
  const allowed = {status: 'allowed', allowed: true, passDuration: 0, expiresAt: Date.now() + 300000};
  const app = browser({page: 'gate', pageProtected: false, points: ['all_pages'], initialState: 'error',
    preflight: pending, report: () => allowed});
  await flush();
  assert.equal(app.requests.length, 0);
  assert.deepEqual(app.interaction('click', app.gate.children[0]), {prevented: true, stopped: false});
  await flush();
  assert.equal(app.requests.length, 1);
  assert.equal(app.requests[0].body.retry, '1');
  assert.equal(app.scripts.length, 1);
  assert.equal(app.gate.getAttribute('data-state'), 'pending');
  assert.equal(app.locked, true);
  assert.deepEqual(app.redirects, []);
  app.verdict('allow');
  await flush();
  assert.equal(app.requests.length, 2);
  assert.deepEqual(app.redirects, ['/protected-page']);
});


test('pending homepage verification survives background AJAX denials and accepts Allow in place', async () => {
  const pending = {status: 'verification_required', verificationId: 'home-attempt', verificationUntil: Date.now() + 35000};
  const allowed = {status: 'allowed', allowed: true, passDuration: 0, expiresAt: Date.now() + 300000};
  const app = browser({page: '', pageProtected: true, points: ['all_pages'], preflight: pending, report: () => allowed});
  await flush();
  assert.equal(app.scripts.length, 1);
  for (const url of ['/fbe/pixel/userData/', '/fbe/pixel/tracker/', '/customer/section/load/']) {
    app.ajaxComplete(url, 403, 'denied', pending);
  }
  assert.deepEqual(app.redirects, []);
  assert.equal(app.locked, true);
  app.verdict('allow');
  await flush();
  assert.equal(app.window.HachitechsHideguard.getState().status, 'allowed');
  assert.equal(app.locked, false);
  assert.deepEqual(app.redirects, []);
});

test('pending homepage verification survives fetch denial without leaving the body inert after Allow', async () => {
  const pending = {status: 'verification_required', verificationId: 'home-attempt', verificationUntil: Date.now() + 35000};
  const allowed = {status: 'allowed', allowed: true, passDuration: 0, expiresAt: Date.now() + 300000};
  const response = {status: 403, headers: {get: () => 'denied'}, clone: () => ({json: async () => pending})};
  const app = browser({page: '', pageProtected: true, preflight: pending, report: () => allowed, fetchResponse: response});
  await flush();
  assert.equal(await app.window.fetch('/fbe/pixel/userData/'), response);
  assert.deepEqual(app.redirects, []);
  assert.equal(app.locked, true);
  assert.equal(app.document.body.hasAttribute('inert'), false);
  app.verdict('allow');
  await flush();
  assert.equal(app.locked, false);
  assert.equal(app.document.body.hasAttribute('inert'), false);
});


test('late background AJAX denial from the pending page cannot redirect a newly accepted Allow', async () => {
  const pending = {status: 'verification_required'};
  const allowed = {status: 'allowed', allowed: true, passDuration: 0, expiresAt: Date.now() + 300000};
  const app = browser({page: '', pageProtected: true, preflight: pending, report: () => allowed});
  await flush();
  const complete = app.ajaxStart('/fbe/pixel/userData/');
  app.verdict('allow');
  await flush();
  assert.equal(app.window.HachitechsHideguard.getState().status, 'allowed');
  complete(403, 'denied', pending);
  assert.deepEqual(app.redirects, []);
  assert.equal(app.document.body.hasAttribute('inert'), false);
  assert.equal(app.locked, false);
  // A request started after Allow still obeys a new backend denial.
  app.ajaxComplete('/customer/ajax/login', 403, 'denied', pending);
  assert.deepEqual(app.redirects, ['/blocked']);
});

test('late background fetch denial preserves a page unlocked by backend Allow', async () => {
  let complete;
  const pending = {status: 'verification_required'};
  const allowed = {status: 'allowed', allowed: true, passDuration: 0, expiresAt: Date.now() + 300000};
  const response = {status: 403, headers: {get: () => 'denied'},
    clone: () => ({json: () => new Promise(resolve => { complete = resolve; })})};
  const app = browser({page: '', pageProtected: true, preflight: pending, report: () => allowed, fetchResponse: response});
  await flush();
  const request = app.window.fetch('/fbe/pixel/userData/');
  await flush();
  app.verdict('allow');
  await flush();
  complete(pending);
  assert.equal(await request, response);
  assert.deepEqual(app.redirects, []);
  assert.equal(app.document.body.hasAttribute('inert'), false);
  assert.equal(app.locked, false);
});

test('real Block and verification failures still navigate during a pending protected page check', async () => {
  for (const kind of ['ajax', 'fetch']) {
    for (const [status, state, destination] of [[401, 'blocked', '/blocked'],
      [403, 'verification_failed', '/failed'], [503, 'error', '/failed']]) {
      const data = {status: state};
      const response = {status, headers: {get: () => 'denied'}, clone: () => ({json: async () => data})};
      const app = browser({page: '', pageProtected: true, fetchResponse: response});
      await flush();
      if (kind === 'ajax') app.ajaxComplete('/fbe/pixel/userData/', status, 'denied', data);
      else await app.window.fetch('/fbe/pixel/userData/');
      assert.deepEqual(app.redirects, [destination]);
      assert.equal(app.document.body.hasAttribute('inert'), true);
    }
  }
});
