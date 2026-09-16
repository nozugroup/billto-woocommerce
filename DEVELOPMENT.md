# Development

How to work on the plugin. Nothing here is specific to one machine; keep your own local details
(paths, credentials, tokens) in `DEVELOPMENT.local.md`, which is gitignored.

## Requirements

- PHP 8.2+ for the dev toolchain (the plugin itself runs on PHP 8.0+), Composer 2.
- A local WordPress (6.4+) with WooCommerce (8.2+) for manual testing - any stack works
  (Laravel Herd, Local, wp-env, Docker). No WordPress is needed for the unit tests.
- A BillTo account with API access; use the **sandbox** environment for development
  (Settings -> API tokens, scopes `orders:*`, `invoices:*`, and `ksef:send` if you test KSeF).

## Setup

```bash
git clone git@github.com:nozugroup/billto-woocommerce.git
cd billto-woocommerce
composer install
composer check        # phpcs (security subset), phpstan, pest
```

Link the checkout into your WordPress so edits are live:

```bash
ln -s /path/to/billto-woocommerce /path/to/wordpress/wp-content/plugins/billto-woocommerce
```

On Windows use a junction instead of a symlink:

```powershell
New-Item -ItemType Junction -Path wp-content\plugins\billto-woocommerce -Target C:\path\to\billto-woocommerce
```

Activate the plugin, then configure it under WooCommerce -> Settings -> BillTo (environment: sandbox).

## Shared core (billto/shop-integration-core)

The business rules (buyer scenarios, VAT mapping, gross/net amounts, negative lines, mark-paid flags,
refund lines, VIES) live in [billto/shop-integration-core](https://github.com/nozugroup/billto-shop-integration-core)
and are **vendored** into `lib/shop-integration-core/` (tracked in git, no Composer on the merchant's host).
The plugin's autoloader maps `BillTo\Shop\` to that directory. Change rules there, then refresh the copy:

```bash
composer sync-core            # copies ../billto-shop-integration-core/src, writes lib/shop-integration-core/VERSION
composer sync-core -- /path   # from another checkout
```

`lib/shop-integration-core/VERSION` holds the copied commit hash, so drift is visible in review.
WooCommerce-specific code stays in `includes/`: `Sync\OrderAdapter` maps `WC_Order` onto the core model,
`Sync\OrderMapper` calls the core builders.

## Tests and static analysis

```bash
composer test           # Pest unit tests (Brain Monkey + WordPress/WooCommerce stubs)
composer analyse        # PHPStan level 6 with the WordPress extension
composer lint           # phpcs, errors only (security, DB, i18n, capabilities)
composer lint:warnings  # phpcs including warnings
```

Unit tests do not boot WordPress: WordPress and WooCommerce functions are stubbed with Brain Monkey,
classes come from `php-stubs/*`. Two things the bootstrap must do, or the suite fails in confusing ways:

- load `vendor/antecedent/patchwork/Patchwork.php` **before** the stub files (otherwise
  `DefinedTooEarly`), and
- create the Patchwork cache directory named in `patchwork.json` (it is gitignored).

Run Pest with at least `memory_limit=1G` (preprocessing the stubs), which `composer test` does.

## Recommended local store configuration

To exercise every code path set the shop up like this:

- Country PL, currency PLN, **prices entered including tax**, tax based on the billing address.
- Tax rates: PL 23% (standard), 8% (`reduced-rate`), 5% (custom class), 0% (`zero-rate`); a 0% rate for
  one EU country (e.g. DE) and one non-EU country (e.g. US) on the standard class.
- Products on each rate, one virtual product (treated as a service), a flat-rate taxable shipping method.
- Payment gateways: bank transfer (order stays on-hold = unpaid), cash on delivery, and the built-in
  "Check payments" gateway, whose orders go to `processing` immediately - a convenient "paid" simulator.
- Allowed countries: PL plus at least one EU and one non-EU country.
- Both checkouts: the default Blocks checkout page and a page with the `[woocommerce_checkout]` shortcode.

## Running background jobs locally

The plugin talks to the API through Action Scheduler jobs, which on a quiet local site only run when
WP-Cron fires. Trigger them explicitly:

```bash
wp action-scheduler run --group=billto-wc
wp action-scheduler list --status=pending --group=billto-wc
```

Failures are logged to WooCommerce -> Status -> Logs (source `billto-wc`) and to the order notes;
the "Stan integracji" panel at the top of the settings tab summarises queued/failed jobs and recent errors.

## Scenario checklist

| Case | How to trigger | Expected in BillTo |
|---|---|---|
| PL consumer | order without a NIP, paid gateway | VAT invoice, `tax_type=none`, shop rates, gross totals equal |
| PL company | valid NIP (e.g. `5261040828`) | VAT invoice, `tax_type=local` |
| Cash on delivery | COD gateway, status `processing` then `completed` | invoice issued unpaid, payment recorded at `completed` |
| Negative fee | add a negative fee in the admin order editor | product lines reduced "(z rabatem)", totals equal |
| EU company | billing DE + `DE123456789` | VIES note; `0 WDT` goods / `np I` services (or blocked) |
| EU consumer | billing DE, no NIP; pick the EU-consumer mode in settings | Polish VAT invoice / OSS invoice / not synced |
| Outside EU | billing US | `0 EX` / `np II` |
| Line refund | refund one product line | correcting order + KOR |
| Amount refund | refund an amount without items | proportional price-reduction KOR |

## Releasing

1. Bump the version in `billto-woocommerce.php` (header and `BILLTO_WC_VERSION`) and `readme.txt` (`Stable tag`), update `CHANGELOG.md`. All three must match the tag - the release workflow refuses to publish otherwise.
2. Push to `main`; CI builds `billto-woocommerce.zip` from the tracked files minus `.distignore`.
3. Tag `vX.Y.Z` and push the tag. `.github/workflows/release.yml` rebuilds the ZIP from the tag and publishes it as a release asset.

## How installed shops get the update

The plugin is not on wordpress.org, so WordPress would never offer an update on its own. Two pieces
make it work, and both must stay in place:

- The `Update URI:` header in `billto-woocommerce.php` tells WordPress to stop asking wordpress.org
  about this slug (which also closes a real hole: a wordpress.org plugin using the slug
  `billto-woocommerce` would otherwise be offered to our users as an update) and to call the
  `update_plugins_billto.pl` filter instead.
- `Support\UpdateChecker` answers that filter from `https://billto.pl/api/v1/plugins/woocommerce/latest`,
  which relays the latest **published GitHub release** (BillTo side: `config/shop_plugins.php`,
  `App\Services\ShopPlugins\PluginReleaseService`). The answer is cached in a site transient for 12 h.

Consequences worth remembering: a release with no ZIP attached reaches nobody (the checker refuses
to hand WordPress a source archive, whose directory name would be wrong), and updates always come
from production `billto.pl` even when the shop's API environment is set to sandbox.
