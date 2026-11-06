# Frontend tests

Run the dependency-free runtime/adapter regression tests from the Magento root:

```sh
node --test app/code/Hachitechs/Hideguard/Test/Frontend/access.test.mjs
```

The request barrier tests run Magento's own `lib/web/jquery.js` with jsdom's XHR
against a temporary HTTP server bound only to `127.0.0.1`. Install the test-only
dependency outside the checkout; no storefront dependency is added:

```sh
npm install --prefix /tmp/hideguard-frontend-tests --no-audit --no-fund jsdom@26.1.0
NODE_PATH=/tmp/hideguard-frontend-tests/node_modules node --test app/code/Hachitechs/Hideguard/Test/Frontend/request-gate.test.cjs
```

Node 22 is used for these checks. Fetch is a native Request/AbortSignal plus a
recording transport; XHR uses jsdom's real HTTP implementation. The shared runtime
test emits an SDK verdict but waits for a separately simulated backend decision.
These tests do not call HideGuard or change the shop's policy, sessions or orders.

Native Magento rendering, XSD, routing and scope checks use temporary database
tables and process-local configuration:

```sh
docker exec troyclancy-php php app/code/Hachitechs/Hideguard/Test/Integration/local.php --request-gate-only
```
