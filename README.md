# HideGuard Bot & Checkout Guard for Magento 2

[HideGuard Bot & Checkout Guard](https://hideguard.io/) is a Magento 2 bot protection
and checkout security extension. It combines browser verification with server-side
access checks to protect storefront pages, shopping carts, checkout, customer
accounts and supported form submissions for both guests and logged-in customers.

## Magento 2 security features

- **Storefront browser verification:** verify visitors across storefront pages
  before allowing protected interactions.
- **Cart and checkout protection:** request fresh server-side verdicts before
  add-to-cart, quote submission and order placement.
- **Customer account protection:** check customer login and registration actions,
  along with supported storefront form submissions.
- **REST and GraphQL enforcement:** apply checks to supported Magento cart, order
  and account services used by API integrations.
- **Store-scoped controls:** manage Testing and Production credentials,
  verification intervals and timed blocks through Magento Admin.

## How HideGuard protection works

When enabled, HideGuard verifies storefront visitors and restricts interactions
while the backend confirms access. Protected business actions require fresh
server-side verdicts, even when a storefront verification is still valid. Missing
credentials or failed verification deny those actions.

Protection applies to all storefront pages and supported business actions.
Customer login is not required, and protection is disabled until you enable it.
Browser verification does not make delivered HTML confidential or replace Magento
authorization. Payment protection applies before order placement; later capture,
settlement and payment webhook processing are outside the module's scope.

## System requirements

| Requirement | Details |
| --- | --- |
| Magento | Magento 2 with framework `^103.0.7` and the modules required by [composer.json](composer.json). |
| PHP | `^8.2`, using a version supported by your Magento release. |
| PHP extensions | cURL, PDO, PDO MySQL and Sodium. |
| HideGuard library | `hachitechs/hideguard-php:^1.0`. |
| HideGuard credentials | Public Key, API Key and Signature Public Key for the selected environment. |
| Connectivity | Server and storefront browser access to HideGuard over HTTPS. |
| Maintenance | Magento cron configured and running. |

The complete package dependency constraints are defined in `composer.json`.

## Installation

Choose either a source download or Composer installation through GitHub. Run all
commands below from the Magento project root. Configure repository authentication
if access is private, and keep credentials out of source control.

### Option 1: Download to app/code

#### Download and copy the module

Download the source ZIP from the
[HideGuard Magento 2 repository](https://github.com/namtb1994/hideguard_magento2_module)
using **Code > Download ZIP**, then extract it and copy the module contents into:

```text
app/code/Hachitechs/Hideguard/
```

Create the directory if needed. Files such as `registration.php`, `composer.json`
and the `etc/` directory must be directly inside `Hideguard/`, without an extra
archive directory level. Preserve the directory name capitalization shown above.

#### Install the PHP library

Configure the library repository in the Magento root Composer project, then
install the required version:

```sh
composer config repositories.hideguard-php vcs https://github.com/namtb1994/hachitechs-hideguard-php.git
composer require hachitechs/hideguard-php:^1.0
```

If the library repository is already configured, skip the `composer config` step.

Magento does not automatically install dependencies declared by modules copied
into `app/code`. Install the library from the Magento root; do not run Composer
inside the module directory.

### Option 2: Install with Composer through GitHub

Add both GitHub repositories to the Magento root Composer project, skipping any
repository entries already configured:

```sh
composer config repositories.hideguard-m2 vcs https://github.com/namtb1994/hideguard_magento2_module.git
composer config repositories.hideguard-php vcs https://github.com/namtb1994/hachitechs-hideguard-php.git
composer require 'hachitechs/hideguard-m2:dev-master'
```

The `dev-master` constraint selects the module's `master` development branch.
Composer installs the module under `vendor/hachitechs/hideguard-m2` and
automatically resolves and installs `hachitechs/hideguard-php:^1.0` from the
module's `require` declarations. No separate library `composer require` command
is needed for this method.

Both repositories must be configured in the Magento root: Composer does not load
repository declarations from dependencies. Commit the root `composer.json` and
`composer.lock` so subsequent `composer install` runs use the resolved revisions.
Do not keep another copy of the module in `app/code/Hachitechs/Hideguard` when
using this installation method; Magento must register the module only once.

### Enable and deploy the module

After completing either installation method, enable the module:

```sh
bin/magento module:enable Hachitechs_Hideguard
bin/magento setup:upgrade
```

For a production deployment, compile dependencies, deploy static assets and refresh
caches using your normal Magento release process:

```sh
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:clean config full_page block_html
```

Static assets use your configured themes and locales. Explicit static deployment
in developer or default mode requires Magento's `-f` option.

## Configure HideGuard in Magento Admin

Open **Stores > Configuration > Hachitechs > HideGuard > Protection**.
Select the store scope and environment, enter the matching credentials, then enable
protection and save the configuration. Refresh the Magento configuration cache.

| Setting | Purpose |
| --- | --- |
| Enable | Activate protection for the selected scope. |
| Environment | Select Testing or Production with its own credentials. |
| Public Key | Site key used by the browser SDK. |
| API Key | Encrypted server-only credential for private verdict requests. |
| Signature Public Key | Site-specific Ed25519 PEM public key for signature verification. |
| HideGuard Recheck Interval | Control when storefront verification is repeated. |
| Block Duration | Set the duration of confirmed visitor blocks. |
| Block List | Reset blocks for the selected scope; Default scope covers all store views. |
| Debug Log | Enable diagnostic logging when troubleshooting. |

Settings follow Magento Default, Website and Store View inheritance. Never enter
a private signing key in Signature Public Key. Protected business actions continue
to request fresh verdicts regardless of the storefront recheck interval.

## Integration and deployment notes

- **Headless storefronts:** supported REST and GraphQL actions require verified
  browser and resource bindings. Account for verification in your integration.
- **Varnish and CDN:** keep protected HTML and verification responses out of shared
  caches. Regenerate and deploy Varnish configuration and purge affected cached
  HTML after protection settings or store URL changes. External proxies must be
  configured through your deployment process.
- **Maintenance:** keep Magento cron running for cleanup. Preserve configuration,
  encryption keys and security records when upgrading, and apply schema updates
  through Magento setup. Manage diagnostic log rotation with your logging policy.

Learn more about [HideGuard bot protection and checkout security](https://hideguard.io/).
