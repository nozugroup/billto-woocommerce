<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Sync;

use BillTo\Shop\BuyerScenario as CoreScenario;
use BillTo\WooCommerce\Support\OrderData;
use WC_Order;

/**
 * WooCommerce-facing helpers around the core buyer scenarios: classification from an order and
 * Polish labels for notes and the admin box. The rules live in billto/shop-integration-core.
 */
final class BuyerScenario
{
    public const PL_B2C = CoreScenario::PL_B2C;

    public const PL_B2B = CoreScenario::PL_B2B;

    public const EU_B2C = CoreScenario::EU_B2C;

    public const EU_B2B = CoreScenario::EU_B2B;

    public const NON_EU = CoreScenario::NON_EU;

    public static function classify(string $country, bool $hasTaxId): string
    {
        return CoreScenario::classify($country, $hasTaxId);
    }

    public static function forOrder(WC_Order $order): string
    {
        return CoreScenario::classify((string) $order->get_billing_country(), trim(OrderData::nip($order)) !== '');
    }

    public static function isDomestic(string $scenario): bool
    {
        return CoreScenario::isDomestic($scenario);
    }

    /** Polish message for a core warning code (Settings::WARNING_*). */
    public static function warningLabel(string $code): string
    {
        return match ($code) {
            \BillTo\Shop\Settings::WARNING_FOREIGN_TAXED => __('Sklep naliczył polski VAT nabywcy zagranicznemu, więc faktura ma stawki polskie zamiast 0% WDT / eksportu. Jeśli to sprzedaż 0%, dodaj stawkę 0% dla tego kraju w podatkach WooCommerce.', 'billto-woocommerce'),
            \BillTo\Shop\Settings::WARNING_UNTAXED_EXTRAS => __('Dostawa lub opłata bez podatku w WooCommerce dostała stawkę towarów. Sprawdź, czy stawka VAT ma zaznaczone "Wysyłka".', 'billto-woocommerce'),
            \BillTo\Shop\Settings::WARNING_VIES_INVALID_DOMESTIC => __('Numer VAT UE nabywcy jest nieaktywny w VIES; faktura ze stawkami polskimi zamiast 0% WDT.', 'billto-woocommerce'),
            default => $code,
        };
    }

    public static function label(string $scenario): string
    {
        return match ($scenario) {
            CoreScenario::PL_B2C => __('osoba fizyczna (PL)', 'billto-woocommerce'),
            CoreScenario::PL_B2B => __('firma (PL)', 'billto-woocommerce'),
            CoreScenario::EU_B2C => __('konsument z UE (OSS)', 'billto-woocommerce'),
            CoreScenario::EU_B2B => __('firma z UE (WDT / np)', 'billto-woocommerce'),
            CoreScenario::EU_B2B_DOMESTIC => __('firma z UE ze stawkami polskimi (sklep naliczył VAT lub numer VAT nieaktywny)', 'billto-woocommerce'),
            CoreScenario::NON_EU => __('nabywca spoza UE (eksport / np)', 'billto-woocommerce'),
            CoreScenario::NON_EU_DOMESTIC => __('nabywca spoza UE ze stawkami polskimi (sklep naliczył VAT)', 'billto-woocommerce'),
            default => $scenario,
        };
    }
}
