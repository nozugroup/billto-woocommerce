<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Support;

use WC_Order;

/**
 * Reads checkout invoice data from an order regardless of which checkout (classic or Blocks) wrote it.
 */
final class OrderData
{
    public static function nip(WC_Order $order): string
    {
        $classic = (string) $order->get_meta(Meta::NIP);

        if ($classic !== '') {
            return $classic;
        }

        return (string) $order->get_meta(Meta::NIP_BLOCKS);
    }

    public static function wantsInvoice(WC_Order $order): bool
    {
        $classic = (string) $order->get_meta(Meta::WANTS_INVOICE);

        if ($classic !== '') {
            return $classic === 'yes';
        }

        $blocks = $order->get_meta(Meta::WANTS_INVOICE_BLOCKS);

        return $blocks === true || $blocks === 1 || $blocks === '1' || $blocks === 'yes';
    }

    public static function billtoOrderId(WC_Order $order): ?string
    {
        $id = (string) $order->get_meta(Meta::ORDER_ID);

        return $id !== '' ? $id : null;
    }

    public static function invoiceId(WC_Order $order): ?string
    {
        $id = (string) $order->get_meta(Meta::INVOICE_ID);

        return $id !== '' ? $id : null;
    }

    /** @return array<string, int> WooCommerce item id => BillTo line_number */
    public static function lineMap(WC_Order $order): array
    {
        $decoded = json_decode((string) $order->get_meta(Meta::LINE_MAP), true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    public static function rememberError(WC_Order $order, string $message): void
    {
        $order->update_meta_data(Meta::LAST_ERROR, wp_strip_all_tags($message));
        $order->save_meta_data();
    }

    public static function clearError(WC_Order $order): void
    {
        if ((string) $order->get_meta(Meta::LAST_ERROR) !== '') {
            $order->delete_meta_data(Meta::LAST_ERROR);
            $order->save_meta_data();
        }
    }
}
