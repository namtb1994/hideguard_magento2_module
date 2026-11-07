# Hachitechs_Hideguard

Magento storefront and business action protection backed by the framework-independent
`hachitechs/hideguard-php` library. Protection is disabled by default. When enabled,
all storefront pages and the existing business action hooks are protected; customer
login is not required.

The Composer package name is `hachitechs/hideguard-m2`; the Magento module name
and PHP namespace are `Hachitechs_Hideguard` and `Hachitechs\Hideguard`.

## Requirements and package version

The manifest requires PHP 8.2+, Magento framework `^103.0.7`, the native Magento
modules listed in `composer.json`, cURL, PDO MySQL and Sodium. Use the PHP version
supported by your Magento release. The current local verification environment is
Magento 2.4.9 / PHP 8.5; this is not a claim that every older supported combination
has been tested.

**This source requires `hachitechs/hideguard-php:^1.0`.** Version `1.0.0` is the
package's first stable distribution and contains the endpoint defaults and
traffic/storage contracts used by this module. Publish the matching `v1.0.0`
package tag before running the release installation commands below. Package
versioning is derived from Git tags; preparing source files does not publish them.

Repositories and authentication belong in the **Magento root** Composer project.
Composer does not load repository declarations from a dependency's manifest. Add
this entry to the root `repositories` list, preserving the Magento repository:

```json
{
    "type": "vcs",
    "url": "https://github.com/namtb1994/hachitechs-hideguard-php.git",
    "only": ["hachitechs/hideguard-php"]
}
```

Provide private repository credentials through your Composer/Git authentication
configuration or CI secrets. Do not commit credentials.

## Installation

### Existing app/code checkout

Keep this directory at `app/code/Hachitechs/Hideguard`. Magento's root autoloader
finds its classes and `registration.php`. The module manifest records dependencies
and supports Composer distribution, but Composer does **not** recursively install
requirements from arbitrary manifests under `app/code`.

For this installation method, require the library from the Magento root after its
matching release is available:

```sh
composer require hachitechs/hideguard-php:^1.0
bin/magento module:enable Hachitechs_Hideguard
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f en_AU
bin/magento cache:clean config full_page block_html
```

Use the locale/theme appropriate to your deployment. Commit the root manifest,
lockfile and module source. Subsequent `composer install` uses the lockfile.
Do not run `composer install` inside the module directory to create a second vendor
folder; Magento loads dependencies from its root project.

### Module installed through Composer

To have Composer automatically install the library through this module's `require`,
publish this module in its own repository or use a local path repository whose
source is **outside** `app/code`. Configure that repository in the Magento root,
then require `hachitechs/hideguard-m2` at the module version you publish.
The module manifest supplies PSR-4 autoloading and `registration.php` automatically.

For local module development, a root repository entry can be:

```json
{
    "type": "path",
    "url": "../module-hideguard",
    "options": {
        "symlink": true,
        "versions": {"hachitechs/hideguard-m2": "dev-main"}
    }
}
```

Then run `composer require 'hachitechs/hideguard-m2:dev-main'` from the Magento
root, followed by the Magento setup commands above. The development version mapping
is local metadata, not a published module tag. If the PHP package is also unreleased,
use its local path repository and development alias as described in its README.

Choose one module installation location. Do not keep an independent `app/code` copy
alongside a Composer-installed copy of the same module; Magento must register
`Hachitechs_Hideguard` only once. This change does not alter the application's root
manifest/lockfile or install a second copy of the module.

## Admin configuration

Open **Stores > Configuration > Hachitechs > HideGuard > Protection**. Select the
mode, enter that mode's credentials, then enable protection and save. Settings and
credentials retain Magento Default / Website / Store View inheritance.

| Setting | Behavior |
| --- | --- |
| Enable | Enables protection for the effective store scope. Default: No. |
| Environment | Testing (`development`) uses `https://dev.hideguard.io`; Production uses `https://hideguard.io`. The Admin description shows both hosts. |
| Public Key | SDK site key. Only the active profile's public key is exposed to the storefront. |
| API Key | Encrypted server-only credential for private verdict requests. Required for protected business actions. |
| Signature Public Key | Site-specific Ed25519 PEM public key, required when enabling either mode. Never enter a private signing key. |
| Additional Protected Paths | Existing additive wildcard rules, at most 50 rules / 4096 bytes. Rules are not exclusions; all storefront pages are already protected. |
| Block Duration | 15 minutes, 1 hour, 6 hours or 24 hours. Repeated reports do not extend an active block. |
| HideGuard Recheck Interval | Always, 1 minute, 5 minutes, 15 minutes or 1 hour. Default: 5 minutes. Business actions still fetch a fresh private verdict. |
| Debug Log | Store-scoped diagnostic logging, disabled by default. |
| Block List | Resets blocks in the selected scope. Default scope resets all store views. Requires the reset ACL and form key. |

Only the selected profile's three credential fields are shown. There is no
HideGuard URL field, Staging profile or Protected Access Points selector.
`Config::ACCESS_POINTS` fixes `all_pages` and `complete_order`; legacy URL/points
rows and posted fields cannot override the host or protection scope. The host
mapping lives in the PHP package and is shared by the SDK, CSP and backend client.

Before upgrading an older deployment using Staging, select Testing or Production
and configure credentials registered for that host. Magento rejects legacy Staging
as an active policy; the PHP library retains constructor compatibility for other
adapters. Existing development configurations previously pointing at production
must also use credentials valid for the new testing host. No credentials are
copied between profiles automatically.

## Page and checkout behavior

`PageAccess` runs before routing and Magento full-page cache. The backend checks
browser/customer identity, active blocks, grant expiry and policy fingerprint.
An unverified normal GET may render public HTML under a transparent interaction
lock. Protected operations remain denied until the backend confirms access.
Sensitive data must stay behind server-side authorization; this preview does not
hide HTML from a browser that disables JavaScript.

Preflight creates a server-owned verification attempt with a 35-second deadline.
Reloads cannot extend it. The shared runtime loads the SDK only when needed, sends
its response to the backend and unlocks after backend confirmation. Both Admin
modes use `Ed25519Verifier`; unsigned browser payloads cannot grant access.
Timed grants retain their original deadline. Always mode starts a new attempt on
the next protected page load; page visits and repeated Allow do not extend a pass.

Confirmed blocks redirect normal navigation to `/antidetectbrowser/gate/index`;
the gate returns HTTP 401 with `Retry-After`. Protected AJAX/API requests return a
denial response. Verification failures use the error gate and an explicit retry;
they do not create a timed ban. Gate/check/verdict/fail endpoints remain reachable
to prevent redirect loops. Static/media files, Admin, cron and CLI retain their
existing treatment outside storefront page enforcement.

Successful order placement keeps the native checkout success redirect. Success is
checked before Magento consumes its checkout session. A valid native success
session may pass a `verification_required` result after order validation without
another gate round trip; an actual block or verification error is still denied.
Late mini-cart refresh denials cannot replace the successful order navigation.
The success page does not restart Always verification.

## Backend verdicts and business actions

When enabled, the existing hooks request a fresh private verdict before checkout,
quote submission/place order, add-to-cart, login, registration and supported form
submissions, even if a page grant is still valid. `Observer/CheckAction::EVENTS`
contains the named route/event map; generic protected storefront POSTs use the
predispatch fallback. Native public services are covered by `QuoteAccess`,
`OrderAccess` and `AuthAccess`, including their REST/GraphQL entry points.

The visitor ID comes from server-owned browser/quote state. Headless clients must
use a verified, correctly owned binding; there is no endpoint that grants access
to an arbitrary client-selected quote or visitor. Missing credentials, upstream
failure, malformed responses and visitor mismatches deny the business action.

The private client sends `POST {mode origin}/api/v1/verdict` with the active
profile's API key, TLS verification, no redirects/retries, a 3-second connection
timeout and an 8-second total timeout. The package limits response bodies to
64 KiB. Private responses use `PrivateVerdictChecker` through the backend verifier;
browser reports still use Ed25519. Both paths apply the same Guard block/grant
state rather than maintaining separate block lists.

An allowed response may be shared only within the same business invocation and
matching action/store/browser/actor/resource. Nested hooks inspect current grant
and revocation state. A later invocation, another action or another quote fetches
a new verdict. Operation state is cleared in `finally` and between requests.

Payment protection runs before quote submission/order payment side effects. This
module does not independently hook later capture, invoice, settlement or payment
webhook operations. Magento's native cart mutex remains in charge of quote
serialization; this is not a claim of gateway-wide idempotency.

The shared runtime locks interactions during verification. An early request barrier
also queues same-origin asynchronous XHR/fetch until backend Allow, then sends each
request once. Abort, failure, Block, page exit or the fallback deadline cancel the
queue without replaying mutations. Magento's jQuery mixin starts its timeout when
the request is released. Static/media GET/HEAD, cross-origin traffic and verification
endpoints bypass the barrier. Synchronous XHR cannot wait and fails while pending;
JSONP, iframes, workers and WebSockets are outside this transport queue.

## Technical limits and persistence

Admin does not expose the following technical settings:

| Setting | Module value |
| --- | --- |
| Rate window | 60 seconds |
| Circuit breaker | Enabled; threshold 5, failure window 60 seconds, open 30 seconds, probe lease 15 seconds |
| Scope budget | 600 admissions per store/window |
| Identity budget | 60 admissions per browser/customer/window |
| Stored session capacity | 10,000 per store; this is not an online visitor count |
| Inactive session retention | 2,592,000 seconds (30 days) |

The first two rows are fixed constants in `Model/Config/RuntimeSettings.php`.
The four remaining limits may be overridden in root deployment configuration:

```php
'hideguard' => [
    // Preserve existing cache and other hideguard keys.
    'limits' => ['scope_requests' => 600, 'identity_requests' => 60,
        'session_capacity' => 10000, 'session_retention_seconds' => 2592000],
    'store_limits' => [2 => ['scope_requests' => 1200]],
],
```

Precedence is defaults, global limits, then numeric store-ID overrides. Partial
overrides inherit the remaining values. Only those four keys are accepted;
values must be positive integers/digit strings up to 2147483647. Store override
IDs must be canonical integers from 1 to 65535. Invalid configuration fails clearly.
Legacy technical database values no longer apply; migrate required effective
website/store values into deployment overrides before rollout. Keep old rows for
audit/rollback. These defaults are not a production capacity guarantee.

`StateRepository` maps Magento state to the core contracts. `StoreLock` delegates
to PDO-bound `MySqlCoordinator` fencing on the dedicated Guard connection, with
browser/resource partitioning and conservative fallback for other adapters. Guard,
circuit and business PDO connections are separate. Security mutations are committed
on domain denials and rolled back on infrastructure failures; unsupported external
or nested transactions are rejected.

Declarative schema includes `hachitech_antidetect_session`, `_block`, `_rate`,
`_control` and `_circuit`. Upgrade through Magento `setup:upgrade`; do not run the
standalone PDO schema initializer against Magento tables. Session counters are
transactional. Use `bin/magento hideguard:sessions:reconcile --help` for explicit
counter reconciliation. Cron cleanup runs hourly, at most ten batches of 500 rows
per store/run; authorization checks expiry without waiting for cron. Reused allowed
sessions coalesce last-seen writes for `min(60, retention)` seconds while retaining
quota accounting and immediate security-state persistence.

Keep the configuration section `hachitech_antidetectbrowser`, route prefix
`/antidetectbrowser/*`, encryption key and existing security records when upgrading.
Policy changes invalidate incompatible grants through fingerprints; active blocks
retain their deadlines. Do not erase state to make an upgrade or rollback pass.

## Cache and assets

Unmanaged mode conservatively emits Varnish `return (pass)` for protected store
HTML and the verification endpoints. With the current fixed all-pages scope, it
must not retain shared HTML HITs for enabled stores. Disabled stores keep native
cache behavior; static/media and PURGE retain native handling.

Generate and deploy VCL after changing enabled stores, store URLs or policy, then
purge previously cached HTML before serving the new policy:

```sh
bin/magento varnish:vcl:generate --export-version=6 --output-file=var/hideguard.vcl
```

Managed snapshot mode is opt-in and requires a coherent shared directory, matching
proxy policy, purge/acknowledgment and explicit activation. It can preserve public
HTML caching only when a policy has public routes; the current all-pages policy
has no ordinary public storefront pages. Keep conservative bypass until the
managed topology is ready. The module does not load VCL into proxies or configure
a CDN automatically. Gate/verification and protected HTML remain private/no-store.

The CSP collector uses the selected mode's origin for `script-src` and `connect-src`.
The package supplies prebuilt AMD/LESS assets; Magento publishes its local copies
through normal static content deployment. When changing shared runtime source,
run the package build/publish steps and verify the published copies before release.
No Node build is needed to serve existing prebuilt assets.

## Debug logging

Enable Debug Log only when needed. It writes private verdict request/response
summaries and transport failures to `var/log/hachitechs_hideguard_debug.log`.
API keys, signature keys and raw visitor IDs are excluded; visitor IDs are hashed.
It does not log form/payment payloads or SDK traffic sent directly by the browser.
Turning logging off stops new records; logging failures do not change verdicts.
Production rotation must be configured separately. No production logrotate job,
sampling policy or automatic debug shutdown is installed by this module.

## Verification and limitations

No signed browser/actor/resource nonce exists in the current collect response;
verification IDs correlate attempts but do not provide complete cross-browser replay
prevention. Deleting anonymous browser state may lose an anonymous block binding;
customer blocks continue to apply to that customer. Already delivered public HTML
cannot be withdrawn.

Run module tests with a PHPUnit 9.6 runner and Magento's unit bootstrap. The Magento
2.4.9 root dev dependency may install a newer PHPUnit, so do not assume its binary
is compatible with this suite. In this checkout a separate test runner is staged
under `var/hideguard-package-work`:

```sh
docker compose exec -T php php var/hideguard-package-work/phpunit.phar --no-configuration --bootstrap dev/tests/unit/framework/bootstrap.php app/code/Hachitechs/Hideguard/Test/Unit
node --test app/code/Hachitechs/Hideguard/Test/Frontend/access.test.mjs
docker compose exec -T php php app/code/Hachitechs/Hideguard/Test/Integration/phase-two.php
docker compose exec -T php php app/code/Hachitechs/Hideguard/Test/Integration/m1-di.php
docker compose exec -T php php app/code/Hachitechs/Hideguard/Test/Integration/local.php --request-gate-only
```

Those paths assume the app/code installation. For a Composer module installation,
use the corresponding module path under `vendor`. The Docker service is `php`;
other deployments should use their own PHP/container command. Tests use isolated
fixtures and do not create real purchases or call a payment gateway. The full
legacy `local.php` smoke currently stops at its native-theme CSS assertion; passing
config/DI/unit tests does not claim a complete browser payment E2E run. Frontend
transport tests and their temporary jsdom dependency are described in
[Test/Frontend/README.md](Test/Frontend/README.md).

Historical implementation plans, runbooks and evidence files may remain beside
this README for local use. They are not required for runtime installation; current
installation and behavior are described here without depending on those files.
