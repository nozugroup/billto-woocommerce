# Changelog

## [1.0.0] - 2026-09-16

First stable release, and the baseline every later version builds on.

### Added

- Orders mirrored to BillTo (`source=woocommerce`, `external_id` = WooCommerce order id); the invoice
  is issued when the order reaches a status configured as paid.
- Deferred-payment gateways (cash on delivery, bank transfer): invoice issued unpaid, payment recorded
  when the order reaches the configured status.
- Gross amount mode (the default for shops with prices including tax): the invoice equals the amount
  paid, to the cent.
- Buyer scenarios: PL consumer / PL company / EU consumer (OSS invoice, Polish VAT, or no invoice) /
  EU company (goods `0 WDT`, services `np I`) / outside the EU (goods `0 EX`, services `np II`), each
  one switchable in the settings.
- VIES check of EU VAT ids before 0% WDT invoices: block, invoice as domestic, or off.
- Refunds with line items turned into correcting orders and KOR invoices; amount-only refunds turned
  into proportional price-reduction corrections.
- Negative lines (discount fees, gift cards) spread over product lines, or skipped with a note.
- Settings tab (WooCommerce -> Settings -> BillTo) with environment, invoice and delivery modes, paid
  statuses, KSeF submission, refund corrections and VAT fallbacks - plus a status panel showing the
  connection, amount mode, queued and failed jobs and recent order errors.
- Checkout fields ("I want an invoice" + NIP) for the classic and the Blocks checkout, with NIP
  checksum validation.
- Order screen box with invoice number, PDF, public page, KSeF status and manual actions; a "Faktura"
  column on the orders list; the invoice link in "My account" and a PDF endpoint behind a permission
  check.
- KSeF status polling after submission (1 min, 5 min, 30 min, 2 h) instead of manual refresh only.
- Re-sync of the BillTo order when staff edit an uninvoiced order in the admin.
- Series selection for VAT, KOR and OSS invoices, and a "Rodzaj dla faktur BillTo" (goods / service /
  auto) setting on the product edit screen.
- Background processing with Action Scheduler and retry backoff; WooCommerce log source `billto-wc`.
- Update channel: the plugin declares an `Update URI` and checks BillTo for new releases, so updates
  appear in the WordPress plugin screen like any other. The header also stops WordPress from offering
  an unrelated wordpress.org plugin that happens to share the slug.
- OAuth 2.0 as the only way to connect: a token scoped to a single company, with permissions the owner
  approves on a consent screen and can withdraw in BillTo without touching the shop. There is no pasted
  API token. Changing the environment disconnects the shop rather than keeping credentials that answer
  401 to every request.
