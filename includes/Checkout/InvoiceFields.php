<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Checkout;

use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\Nip;
use BillTo\WooCommerce\Support\Options;
use WC_Order;

/**
 * "I want an invoice" checkbox and NIP field, for both the classic (shortcode) checkout and
 * the Blocks checkout (additional checkout fields API, WooCommerce 8.9+).
 */
final class InvoiceFields
{
    private const CLASSIC_CHECKBOX = 'billto_wants_invoice';

    private const CLASSIC_NIP = 'billing_nip';

    private const BLOCKS_CHECKBOX = 'billto/wants-invoice';

    private const BLOCKS_NIP = 'billto/nip';

    public function __construct(private Options $options) {}

    public function register(): void
    {
        // Classic checkout.
        add_filter('woocommerce_billing_fields', [$this, 'addClassicFields'], 20);
        add_action('woocommerce_after_checkout_validation', [$this, 'validateClassic'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'saveClassic'], 10, 2);
        add_filter('woocommerce_admin_billing_fields', [$this, 'addAdminBillingField']);
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'renderAdminValues']);

        // Blocks checkout.
        add_action('woocommerce_init', [$this, 'registerBlocksFields']);
        add_action('woocommerce_blocks_validate_location_contact_fields', [$this, 'validateBlocks'], 10, 2);
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    public function addClassicFields(array $fields): array
    {
        $fields[self::CLASSIC_CHECKBOX] = [
            'type' => 'checkbox',
            'label' => $this->options->checkboxLabel(),
            'required' => false,
            'class' => ['form-row-wide', 'billto-wants-invoice'],
            'priority' => 25,
        ];

        $fields[self::CLASSIC_NIP] = [
            'type' => 'text',
            'label' => $this->options->nipRequired() ? __('NIP (do faktury)', 'billto-woocommerce') : __('NIP (opcjonalnie, faktura na firmę)', 'billto-woocommerce'),
            'placeholder' => __('Zostaw puste, jeśli faktura ma być na osobę prywatną', 'billto-woocommerce'),
            'required' => false,
            'class' => ['form-row-wide', 'billto-nip'],
            'priority' => 26,
            'autocomplete' => 'off',
        ];

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function validateClassic(array $data, \WP_Error $errors): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce already.
        $wants = ! empty($_POST[self::CLASSIC_CHECKBOX]);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $nip = sanitize_text_field(wp_unslash((string) ($_POST[self::CLASSIC_NIP] ?? '')));
        $country = (string) ($data['billing_country'] ?? 'PL');

        $error = $this->validateNip($wants, $nip, $country);

        if ($error !== null) {
            $errors->add('billto_nip', $error);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveClassic(WC_Order $order, array $data): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $wants = ! empty($_POST[self::CLASSIC_CHECKBOX]);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $nip = sanitize_text_field(wp_unslash((string) ($_POST[self::CLASSIC_NIP] ?? '')));

        $order->update_meta_data(Meta::WANTS_INVOICE, $wants ? 'yes' : 'no');

        if ($nip !== '') {
            $order->update_meta_data(Meta::NIP, $nip);
        }
    }

    /**
     * Lets the shop staff edit the NIP on the order screen.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    public function addAdminBillingField(array $fields): array
    {
        $fields['nip'] = [
            'label' => __('NIP', 'billto-woocommerce'),
            'show' => false,
        ];

        return $fields;
    }

    public function renderAdminValues(WC_Order $order): void
    {
        $nip = \BillTo\WooCommerce\Support\OrderData::nip($order);
        $wants = \BillTo\WooCommerce\Support\OrderData::wantsInvoice($order);

        if ($nip === '' && ! $wants) {
            return;
        }

        echo '<p><strong>'.esc_html__('Faktura', 'billto-woocommerce').':</strong> '
            .esc_html($wants ? __('klient prosił o fakturę', 'billto-woocommerce') : __('bez prośby o fakturę', 'billto-woocommerce'))
            .($nip !== '' ? ', NIP '.esc_html($nip) : '')
            .'</p>';
    }

    public function registerBlocksFields(): void
    {
        if (! function_exists('woocommerce_register_additional_checkout_field')) {
            return;
        }

        woocommerce_register_additional_checkout_field([
            'id' => self::BLOCKS_CHECKBOX,
            'label' => $this->options->checkboxLabel(),
            'location' => 'contact',
            'type' => 'checkbox',
            'required' => false,
        ]);

        woocommerce_register_additional_checkout_field([
            'id' => self::BLOCKS_NIP,
            'label' => $this->options->nipRequired() ? __('NIP (do faktury)', 'billto-woocommerce') : __('NIP (opcjonalnie, faktura na firmę)', 'billto-woocommerce'),
            'location' => 'contact',
            'type' => 'text',
            'required' => false,
            'sanitize_callback' => static fn ($value): string => sanitize_text_field((string) $value),
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function validateBlocks(\WP_Error $errors, array $fields): void
    {
        $wants = ! empty($fields[self::BLOCKS_CHECKBOX]);
        $nip = (string) ($fields[self::BLOCKS_NIP] ?? '');
        $customer = function_exists('WC') ? WC()->customer : null;
        $country = $customer instanceof \WC_Customer ? (string) $customer->get_billing_country() : 'PL';

        $error = $this->validateNip($wants, $nip, $country);

        if ($error !== null) {
            $errors->add('billto_nip', $error);
        }
    }

    /**
     * Shared validation: NIP required when requested (setting), Polish checksum for PL buyers,
     * EU VAT shape for other EU buyers. Returns the error message or null.
     */
    public function validateNip(bool $wantsInvoice, string $nip, string $country): ?string
    {
        $nip = trim($nip);

        if ($nip === '') {
            if ($wantsInvoice && $this->options->nipRequired()) {
                return __('Ten sklep wystawia faktury tylko dla firm - podaj NIP, aby otrzymać fakturę VAT.', 'billto-woocommerce');
            }

            return null;
        }

        if (strtoupper($country) === 'PL' || $country === '') {
            return Nip::isValid($nip) ? null : __('Podany NIP jest nieprawidłowy (błędna suma kontrolna).', 'billto-woocommerce');
        }

        if (in_array(strtoupper($country), self::EU_COUNTRIES, true) && Nip::normalizeEuVat($nip) === null && Nip::normalizeEuVat($country.$nip) === null) {
            return __('Podaj numer VAT UE w formacie z prefiksem kraju, np. DE123456789.', 'billto-woocommerce');
        }

        return null;
    }

    public const EU_COUNTRIES = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];
}
