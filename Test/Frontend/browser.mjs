import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// Exercise the AMD files shipped by the module, without the core package's development fixtures.
const runtimeSource = readFileSync(new URL('../../view/frontend/web/js/runtime.js', import.meta.url), 'utf8');
const adapterSource = readFileSync(new URL('../../view/frontend/web/js/access.js', import.meta.url), 'utf8');

export function browser({preflight = {status: 'verification_required'}, report, points = ['all_pages'],
  page = 'gate', pageProtected = true, initialState = 'pending', responseCode = 200,
  endpointUrl, fetchResponse} = {}) {
  const listeners = new Map(), elements = new Map(), scripts = [], requests = [], timers = [], redirects = [];
  const mutations = [], heading = {}, message = {}, ajaxHandlers = [];
  function element(id) {
    const attributes = new Map();
    return {id, children: [], hidden: false,
      setAttribute: (name, value) => attributes.set(name, value),
      getAttribute: name => attributes.get(name), hasAttribute: name => attributes.has(name),
      removeAttribute: name => attributes.delete(name),
      contains(target) { return this === target || this.children.some(child => child.contains(target)); }};
  }
  const retry = element('retry');
  retry.closest = selector => ['[data-action="retry"]', 'a,button,input,form'].includes(selector) ? retry : null;
  retry.matches = () => false;
  const gate = element('hachitech-ab-gate'), content = element('page-content');
  gate.children.push(retry);
  gate.querySelector = selector => selector === 'h2' ? heading : message;
  gate.setAttribute('data-state', initialState);
  const children = [content];
  if (pageProtected || page === 'gate') { children.unshift(gate); elements.set(gate.id, gate); }
  let locked = false, reloads = 0, initialize, runtime, accessDependencies;
  const document = {
    getElementById: id => elements.get(id),
    createElement: tag => tag === 'script' ? {dataset: {}} : gate,
    body: element('page-body'),
    head: {appendChild: script => { scripts.push(script); elements.set(script.id, script); }},
    documentElement: {classList: {toggle: (name, enabled) => { locked = enabled; }}},
    addEventListener: (name, callback) => {
      const key = 'document:' + name;
      listeners.set(key, [...(listeners.get(key) || []), callback]);
    }
  };
  document.body.children = children;
  document.body.appendChild = element => {
    children.push(element); elements.set(element.id, element); mutations.forEach(callback => callback());
  };
  if (pageProtected || page === 'gate') {
    document.body.setAttribute('inert', '');
    document.body.setAttribute('data-hachitech-ab-inert', '');
  }
  const bodyLockedBeforeInit = document.body.hasAttribute('inert');
  const window = {
    addEventListener: (name, callback) => listeners.set(name, callback),
    dispatchEvent() {},
    location: {href: 'https://store.test/antidetectbrowser/gate/index',
      reload: () => { reloads++; }, replace: url => redirects.push(url)},
    fetch: async (url, options = {}) => {
      const body = Object.fromEntries(new URLSearchParams(options.body));
      if (fetchResponse && url !== '/access' && url !== '/verdict') {
        return fetchResponse;
      }
      requests.push({url, body, credentials: options.credentials});
      const result = url === '/access' ? (typeof preflight === 'function' ? await preflight(body) : preflight)
        : await report(body);
      return {ok: responseCode < 400, status: responseCode, json: async () => result};
    }
  };
  window.history = Object.fromEntries(['pushState', 'replaceState'].map(method => [method,
    (state, title, url) => { if (url !== undefined && url !== null) window.location.href = new URL(url, window.location.href).href; }]));
  const jquery = {ajaxPrefilter: callback => ajaxHandlers.push(callback)};
  jquery.mage = {cookies: {get: () => 'form-key'}};
  const context = vm.createContext({
    window, document, URL, URLSearchParams, AbortController, Promise, Date,
    MutationObserver: class { constructor(callback) { mutations.push(callback); } observe() {} },
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    setTimeout: (callback, ms) => { timers.push({callback, ms, active: true}); return timers.length; },
    clearTimeout: id => { if (timers[id - 1]) timers[id - 1].active = false; },
    define: (dependencies, factory) => {
      if (dependencies.length === 0) {
        runtime = factory();
      } else {
        accessDependencies = dependencies;
        initialize = factory(jquery, {}, value => value, runtime);
      }
    }
  });
  vm.runInContext(runtimeSource, context);
  vm.runInContext(adapterSource, context);
  initialize({enabled: true, points, page, pageProtected, initialState, endpointUrl, publicKey: 'public-sdk-key',
    accessUrl: '/access', verdictUrl: '/verdict', returnUrl: '/protected-page',
    gateUrl: '/blocked', deniedUrl: '/failed'});
  const lockedBeforeInit = locked;
  function ajaxStart(url) {
    const callbacks = [];
    const xhr = {status: 0, responseJSON: null,
      getResponseHeader: name => name === 'X-Hachitech-Antidetect' ? xhr.marker : null,
      always: callback => callbacks.push(callback)};
    ajaxHandlers.forEach(callback => callback({url, global: false}, {}, xhr));
    return (code, marker, responseJSON) => {
      Object.assign(xhr, {status: code, marker, responseJSON});
      callbacks.forEach(callback => callback());
    };
  }
  return {window, listeners, requests, scripts, gate, heading, message, timers, redirects, document, content,
    lockedBeforeInit, bodyLockedBeforeInit, accessDependencies,
    ajaxStart,
    ajaxComplete: (url, code, marker, responseJSON) => ajaxStart(url)(code, marker, responseJSON),
    interaction: (type, target = content) => {
      let prevented = false, stopped = false;
      const event = {target, preventDefault() { prevented = true; }, stopImmediatePropagation() { stopped = true; }};
      for (const listener of listeners.get('document:' + type) || []) {
        listener(event);
        if (stopped) break;
      }
      return {prevented, stopped};
    },
    get locked() { return locked; }, get reloads() { return reloads; },
    verdict: (verdict, visitorId = 'visitor-a') => listeners.get('fpc:verdict')({detail: {verdict, visitorId}})};
}
