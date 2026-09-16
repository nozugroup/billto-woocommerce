<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Support;

/**
 * Typed access to plugin settings (stored as individual `billto_wc_*` options by the WC settings tab).
 */
final class Options
{
    public const PREFIX = 'billto_wc_';

    public const ENV_PRODUCTION = 'production';

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_CUSTOM = 'custom';

    public const MODE_ALL = 'all';

    public const MODE_NIP_ONLY = 'nip_only';

    public const DELIVERY_BILLTO_EMAIL = 'billto_email';

    public const DELIVERY_WC_ATTACHMENT = 'wc_attachment';

    public const DELIVERY_LINK_ONLY = 'link_only';

    public const CREATE_ON_CHECKOUT = 'checkout';

    public const CREATE_ON_PAID = 'paid';

    public const URL_PRODUCTION = 'https://billto.pl/api/v1';

    public const URL_SANDBOX = 'https://sandbox.billto.pl/api/v1';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            // No 'token' key: the shop connects through OAuth only.
            'environment' => self::ENV_PRODUCTION,
            'custom_url' => '',
            'invoice_mode' => self::MODE_ALL,
            'delivery_mode' => self::DELIVERY_BILLTO_EMAIL,
            'create_on' => self::CREATE_ON_CHECKOUT,
            'paid_statuses' => ['processing', 'completed'],
            'ksef_auto' => 'no',
            'vat_zero' => '0 KR',
            'vat_no_tax' => 'zw',
            'nip_required' => 'no',
            'checkbox_label' => 'Chcę otrzymać fakturę VAT',
            'refund_corrections' => 'yes',
            'series_id' => '',
            'kor_series_id' => '',
            'oss_mode' => self::OSS_PL_VAT,
            'oss_series_id' => '',
            'eu_b2b_enabled' => 'yes',
            'non_eu_enabled' => 'yes',
            'amount_mode' => self::AMOUNT_AUTO,
            'unpaid_gateways' => ['cod', 'bacs'],
            'settle_status' => 'completed',
            'negative_lines' => self::NEGATIVE_DISTRIBUTE,
            'amount_refund_mode' => self::REFUND_PROPORTIONAL,
            'vies_check' => self::VIES_BLOCK,
            'eu_consumer_no_vat' => self::EU_CONSUMER_NO_VAT_BLOCK,
            'untaxed_extras_follow_goods' => 'yes',
            'foreign_taxed_follows_shop' => 'yes',
            'resync_on_edit' => 'yes',
        ];
    }

    /** Foreign buyer taxed by the shop anyway: invoice with Polish rates (what was paid) instead of 0% / np. */
    public function foreignTaxedFollowsShop(): bool
    {
        return $this->get('foreign_taxed_follows_shop') !== 'no';
    }

    /** Untaxed shipping / fees on an order with taxed goods take the highest product rate. */
    public function untaxedExtrasFollowGoods(): bool
    {
        return $this->get('untaxed_extras_follow_goods') !== 'no';
    }

    /** EU consumer without VAT in the shop (Polish-rates mode): do not invoice, add a note. */
    public const EU_CONSUMER_NO_VAT_BLOCK = 'block';

    /** EU consumer without VAT in the shop: map like a domestic sale (VAT-exempt seller). */
    public const EU_CONSUMER_NO_VAT_MAP = 'map';

    public function euConsumerNoVat(): string
    {
        return (string) $this->get('eu_consumer_no_vat') === self::EU_CONSUMER_NO_VAT_MAP ? self::EU_CONSUMER_NO_VAT_MAP : self::EU_CONSUMER_NO_VAT_BLOCK;
    }

    /** Amount entry mode: follow the WooCommerce "prices include tax" setting. */
    public const AMOUNT_AUTO = 'auto';

    public const AMOUNT_NET = 'net';

    public const AMOUNT_GROSS = 'gross';

    /** Negative lines (discount fees, gift cards): spread over the positive product lines. */
    public const NEGATIVE_DISTRIBUTE = 'distribute';

    /** Negative lines: do not send the order, add an order note. */
    public const NEGATIVE_SKIP = 'skip';

    /** Amount-only refund: correction lowering every line price proportionally. */
    public const REFUND_PROPORTIONAL = 'proportional';

    /** Amount-only refund: order note asking for a manual correction. */
    public const REFUND_NOTE = 'note';

    public const VIES_OFF = 'off';

    /** Invalid or unknown EU VAT id: do not invoice, add a note. */
    public const VIES_BLOCK = 'block';

    /** Invalid EU VAT id: invoice as a domestic sale (Polish rates) instead of 0% WDT. */
    public const VIES_DOMESTIC = 'domestic';

    /**
     * Resolved amount entry mode for the BillTo order: `net` or `gross`.
     */
    public function amountEntryMode(): string
    {
        $mode = (string) $this->get('amount_mode');

        if ($mode === self::AMOUNT_NET || $mode === self::AMOUNT_GROSS) {
            return $mode;
        }

        return function_exists('wc_prices_include_tax') && wc_prices_include_tax() ? self::AMOUNT_GROSS : self::AMOUNT_NET;
    }

    /** @return list<string> Payment gateway ids whose orders are invoiced as UNPAID. */
    public function unpaidGateways(): array
    {
        $gateways = $this->get('unpaid_gateways');

        return array_values(array_map('strval', is_array($gateways) ? $gateways : []));
    }

    public function isUnpaidGateway(?string $gatewayId): bool
    {
        return $gatewayId !== null && $gatewayId !== '' && in_array($gatewayId, $this->unpaidGateways(), true);
    }

    /** WooCommerce status (without wc-) at which an unpaid invoice gets its payment recorded. */
    public function settleStatus(): string
    {
        return str_replace('wc-', '', (string) $this->get('settle_status')) ?: 'completed';
    }

    public function negativeLinesMode(): string
    {
        return (string) $this->get('negative_lines') === self::NEGATIVE_SKIP ? self::NEGATIVE_SKIP : self::NEGATIVE_DISTRIBUTE;
    }

    public function amountRefundMode(): string
    {
        return (string) $this->get('amount_refund_mode') === self::REFUND_NOTE ? self::REFUND_NOTE : self::REFUND_PROPORTIONAL;
    }

    public function viesCheck(): string
    {
        $mode = (string) $this->get('vies_check');

        return in_array($mode, [self::VIES_OFF, self::VIES_BLOCK, self::VIES_DOMESTIC], true) ? $mode : self::VIES_BLOCK;
    }

    public function resyncOnEdit(): bool
    {
        return $this->get('resync_on_edit') === 'yes';
    }

    /**
     * Settings object for the shared core (billto/shop-integration-core).
     */
    public function toSettings(): \BillTo\Shop\Settings
    {
        $settings = new \BillTo\Shop\Settings;
        $settings->source = 'woocommerce';
        $settings->orderNoteLabel = __('Zamówienie WooCommerce', 'billto-woocommerce');
        $settings->shippingLabel = __('Dostawa', 'billto-woocommerce');
        $settings->discountSuffix = __('(z rabatem)', 'billto-woocommerce');
        $settings->amountMode = $this->amountEntryMode();
        $settings->vatTypeForZeroRate = $this->vatTypeForZeroRate();
        $settings->euConsumerNoVat = $this->euConsumerNoVat();
        $settings->untaxedExtrasFollowGoods = $this->untaxedExtrasFollowGoods();
        $settings->foreignTaxedFollowsShop = $this->foreignTaxedFollowsShop();
        $settings->vatTypeForNoTax = $this->vatTypeForNoTax();
        $settings->ossMode = $this->ossMode();
        $settings->euB2bEnabled = $this->euB2bEnabled();
        $settings->nonEuEnabled = $this->nonEuEnabled();
        $settings->viesCheck = $this->viesCheck();
        $settings->negativeLines = $this->negativeLinesMode();
        $settings->amountRefundMode = $this->amountRefundMode();
        $settings->unpaidGateways = $this->unpaidGateways();
        $settings->billtoSendsEmail = $this->billtoSendsEmail();
        $settings->seriesId = $this->seriesId();
        $settings->ossSeriesId = $this->ossSeriesId();
        $settings->korSeriesId = $this->korSeriesId();
        $settings->unknownRateMapper = static function (float $percent): ?string {
            /** Unknown percentage (e.g. a foreign rate on a domestic-mode order): sites can map it. */
            $mapped = apply_filters('billto_wc_unknown_vat_rate', null, $percent);

            return is_string($mapped) && $mapped !== '' ? $mapped : null;
        };

        return $settings;
    }

    /** EU consumers: no invoice at all (order is not synced). */
    public const OSS_OFF = 'off';

    /** EU consumers: regular VAT invoice with Polish rates (below the EUR 10,000 OSS threshold). */
    public const OSS_PL_VAT = 'pl_vat';

    /** EU consumers: OSS invoice at the consumption country's rate (team registered for OSS). */
    public const OSS_INVOICE = 'oss';

    public function ossMode(): string
    {
        $mode = (string) $this->get('oss_mode');

        return in_array($mode, [self::OSS_OFF, self::OSS_PL_VAT, self::OSS_INVOICE], true) ? $mode : self::OSS_PL_VAT;
    }

    public function ossSeriesId(): ?string
    {
        return trim((string) $this->get('oss_series_id')) ?: null;
    }

    /** Invoices for EU companies (0% WDT for goods, "np I" for services). */
    public function euB2bEnabled(): bool
    {
        return $this->get('eu_b2b_enabled') === 'yes';
    }

    /** Invoices for buyers outside the EU (0% export for goods, "np II" for services). */
    public function nonEuEnabled(): bool
    {
        return $this->get('non_eu_enabled') === 'yes';
    }

    /** BillTo VAT series id used for shop invoices; null = the team's default series. */
    public function seriesId(): ?string
    {
        return trim((string) $this->get('series_id')) ?: null;
    }

    /** BillTo KOR series id for refund corrections; null = the team's default KOR series. */
    public function korSeriesId(): ?string
    {
        return trim((string) $this->get('kor_series_id')) ?: null;
    }

    public function get(string $key): mixed
    {
        $defaults = self::defaults();

        return get_option(self::PREFIX.$key, $defaults[$key] ?? null);
    }

    public function seedDefaults(): void
    {
        foreach (self::defaults() as $key => $value) {
            add_option(self::PREFIX.$key, $value);
        }
    }

    public function baseUrl(): string
    {
        return match ((string) $this->get('environment')) {
            self::ENV_SANDBOX => self::URL_SANDBOX,
            self::ENV_CUSTOM => rtrim((string) $this->get('custom_url'), '/') ?: self::URL_PRODUCTION,
            default => self::URL_PRODUCTION,
        };
    }

    /**
     * Root of the BillTo site, without the API prefix.
     *
     * The OAuth endpoints (`/oauth/authorize`, `/oauth/token`, `/oauth/register`) live at the
     * site root, not under `/api/v1` - they are a browser-facing flow, not an API resource.
     * Deriving it here keeps the sandbox/custom switch in one place instead of every caller
     * doing its own string surgery on `baseUrl()`.
     */
    public function oauthBaseUrl(): string
    {
        $base = $this->baseUrl();
        $position = strpos($base, '/api/');

        return $position === false ? rtrim($base, '/') : rtrim(substr($base, 0, $position), '/');
    }

    public function isSandbox(): bool
    {
        return (string) $this->get('environment') === self::ENV_SANDBOX;
    }

    public function invoiceMode(): string
    {
        return (string) $this->get('invoice_mode');
    }

    public function invoicesOnlyOnRequest(): bool
    {
        return $this->invoiceMode() === self::MODE_NIP_ONLY;
    }

    public function deliveryMode(): string
    {
        return (string) $this->get('delivery_mode');
    }

    /** BillTo e-mails the invoice itself only in the "billto_email" mode; otherwise the buyer e-mail is withheld from BillTo. */
    public function billtoSendsEmail(): bool
    {
        return $this->deliveryMode() === self::DELIVERY_BILLTO_EMAIL;
    }

    public function attachPdfToWcEmail(): bool
    {
        return $this->deliveryMode() === self::DELIVERY_WC_ATTACHMENT;
    }

    public function createOnCheckout(): bool
    {
        return (string) $this->get('create_on') === self::CREATE_ON_CHECKOUT;
    }

    /** @return list<string> WooCommerce statuses (without the wc- prefix) that mean "paid". */
    public function paidStatuses(): array
    {
        $statuses = $this->get('paid_statuses');

        return array_values(array_map(
            static fn ($status) => str_replace('wc-', '', (string) $status),
            is_array($statuses) ? $statuses : ['processing', 'completed'],
        ));
    }

    public function ksefAuto(): bool
    {
        return $this->get('ksef_auto') === 'yes';
    }

    public function vatTypeForZeroRate(): string
    {
        return (string) $this->get('vat_zero');
    }

    public function vatTypeForNoTax(): string
    {
        return (string) $this->get('vat_no_tax');
    }

    /**
     * "Invoices for companies only": a customer who asks for an invoice must enter a NIP / VAT id.
     * Off by default - consumers without a tax id get an invoice as a natural person.
     */
    public function nipRequired(): bool
    {
        return $this->get('nip_required') === 'yes';
    }

    public function checkboxLabel(): string
    {
        return (string) $this->get('checkbox_label') ?: self::defaults()['checkbox_label'];
    }

    public function refundCorrections(): bool
    {
        return $this->get('refund_corrections') === 'yes';
    }
}
