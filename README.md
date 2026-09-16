# BillTo for WooCommerce

[![CI](https://github.com/nozugroup/billto-woocommerce/actions/workflows/ci.yml/badge.svg)](https://github.com/nozugroup/billto-woocommerce/actions/workflows/ci.yml)

WooCommerce plugin that issues VAT invoices in [BillTo](https://billto.pl) (Polish invoicing SaaS with KSeF)
for shop orders. WordPress 6.4+, WooCommerce 8.2+, PHP 8.0+. HPOS and Blocks checkout compatible.

The user-facing strings are Polish (the plugin targets Polish merchants); code and docs are English.

## How it works

1. **Order placed** -> the plugin creates a BillTo order (`source=woocommerce`, `external_id` = WC order id).
   Repeated attempts are deduplicated by BillTo, so no duplicates on retries.
2. **Order paid** (status in the configured "paid statuses", default `processing`/`completed`) ->
   `POST /orders/{id}/mark-paid` issues the VAT invoice from the default VAT series. Keyed with a stable
   `Idempotency-Key`, so a redelivered payment webhook never invoices twice.
3. **Invoice issued** -> PDF stored under `uploads/billto-invoices/` (direct access blocked), shown in the
   order screen and in "My account"; optional automatic KSeF submission; delivery per settings.
4. **Refund with line items** -> `issue-correction` + `issue-kor` produce a correction invoice (KOR).
5. **Order cancelled before invoicing** -> BillTo order cancelled.

All API calls run in the background through Action Scheduler with retry backoff for transient failures.
Permanent failures land in order notes and in WooCommerce -> Status -> Logs (`billto-wc`).

## Settings (WooCommerce -> Settings -> BillTo)

| Setting | Options |
|---|---|
| Token / environment | production, sandbox, custom URL; connection test button |
| Invoice mode | all orders, or only when the customer ticks "I want an invoice" |
| Order creation | at checkout, or only together with the invoice when paid |
| Paid statuses | multi-select of WooCommerce statuses |
| Amounts | auto (follow "prices include tax") / gross / net; gross makes the invoice equal the amount paid |
| Deferred payments | gateways invoiced as unpaid (default `cod`, `bacs`) + the status that records the payment (default `completed`) |
| Negative lines | spread discount fees / gift cards over product lines, or skip the order with a note |
| Amount-only refunds | proportional price-reduction correction, or a note |
| VIES | check EU VAT ids: block 0% WDT on an inactive id, invoice as domestic, or off |
| Order edits | re-sync the BillTo order when staff edit an uninvoiced order |
| KSeF | auto-submit after issuing (`ksef:send` scope) |
| Refunds | corrections on/off |
| Delivery | BillTo e-mail / PDF attached to the WooCommerce "Customer invoice" e-mail / link only |
| Checkout | checkbox label; "companies only" (NIP required when an invoice is requested, off by default so consumers get an invoice as a natural person) |
| VAT | fallback `vat_type` for 0% and for untaxed lines |

Required token scopes: `orders:read`, `orders:write`, `invoices:read`, `invoices:write`, plus `ksef:send` for KSeF.

## Delivery modes and e-mail

`mark-paid` and `issue-kor` are called with `send_email` matching the delivery mode, so BillTo e-mails the
buyer only in the "BillTo e-mail" mode. In the "attachment" mode the plugin triggers the standard WooCommerce
"Customer invoice" e-mail once the PDF is available and attaches it (also to later processing/completed e-mails).

## Hooks

- `billto_wc_order_payload` (filter) - adjust the BillTo order payload before sending.
- `billto_wc_should_sync_order` (filter) - exclude orders (e.g. by payment gateway).
- `billto_wc_unknown_vat_rate` (filter) - map a non-Polish tax percentage to a `vat_type`.
- `billto_wc_invoice_issued` (action) - fired with the WC order and the BillTo invoice payload.

## Buyer scenarios

The buyer is classified from the billing country and the NIP / EU VAT id field (`BuyerScenario`):

| Buyer | Document | Line `vat_type` |
|---|---|---|
| PL consumer (no NIP) | VAT invoice, `tax_type=none` | shop tax rate mapped to 23 / 8 / 5 / 0 |
| PL company (NIP) | VAT invoice, `tax_type=local` | shop tax rate |
| EU consumer | per setting: VAT invoice with Polish rates (below the EUR 10k threshold), **OSS invoice** (`invoice_type=oss`, rate = the tax WooCommerce charged, e.g. 19), or no invoice | shop rate / consumption-country rate |
| EU company (EU VAT id) | VAT invoice, `tax_type=eu` | goods `0 WDT`, services `np I` |
| Outside the EU | VAT invoice, `tax_type=noneu` / `none` | goods `0 EX`, services `np II` |

Goods vs. service: the "Rodzaj dla faktur BillTo" select on the product (General tab); by default virtual products
are services. Shipping follows the goods. EU B2B and non-EU invoicing can be switched off in settings (such orders
are then not sent to BillTo). VIES validation of EU VAT ids is the merchant's responsibility.

OSS mode needs: OSS registration declared in BillTo, an OSS series, EU country tax rates configured in WooCommerce
(the invoice takes the single rate charged on the order; mixed rates fall back to the buyer country's standard rate
on the BillTo side), and BillTo API with `invoice_type` support.

## Mapping notes

- Buyer: company name (or first + last name), billing address, NIP -> `tax_type` `local` (PL), `eu` (EU VAT id), `noneu`; no NIP -> `none`.
- Lines: net unit price after discounts (4 decimals), shipping and fees as separate lines, `vat_type` per the table above.
- Refund lines send the quantity **remaining after** the correction, cumulative across refunds.

- Gross mode sends `amount_entry_mode=gross` + `unit_price_gross`; net mode sends the net unit price after discounts (4 decimals).
- Negative lines are distributed over positive **product** lines (shipping/fees untouched); the last line absorbs rounding.

Requires BillTo API with `send_email`, `mark_paid`, `series_id`, `invoice_type`, `amount_entry_mode` on the orders
endpoints. Limitations: orders edited in WooCommerce **after** invoicing are not re-synced (use refunds ->
corrections); a full amount-only refund should be entered as a line refund; the BillTo *order* keeps the Polish
fallback rate for OSS orders while the OSS *invoice* carries the foreign rate.

## Development

```bash
composer install
composer check   # phpcs (security subset), phpstan, pest
```

The business rules are shared with the other BillTo shop plugins through
[billto/shop-integration-core](https://github.com/nozugroup/billto-shop-integration-core), vendored in
`lib/shop-integration-core/` (see `DEVELOPMENT.md`).

Unit tests use Brain Monkey + WooCommerce stubs and cover the API client, order mapping, refund lines,
checkout validation and settings. There is no automated end-to-end test against a running WordPress yet.

Build a distribution ZIP: `rsync -a --exclude-from=.distignore ./ build/billto-woocommerce/ && (cd build && zip -r billto-woocommerce.zip billto-woocommerce)`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
