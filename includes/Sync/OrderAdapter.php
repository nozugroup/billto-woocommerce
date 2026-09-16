<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Sync;

use BillTo\Shop\Model\Buyer;
use BillTo\Shop\Model\Line;
use BillTo\Shop\Model\Order;
use BillTo\WooCommerce\Support\OrderData;
use BillTo\WooCommerce\Support\ProductKind;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

/**
 * Maps a WooCommerce order onto the platform-neutral order model of the shared core.
 */
class OrderAdapter
{
    public function toCoreOrder(WC_Order $order): Order
    {
        $created = $order->get_date_created();

        return new Order(
            (string) $order->get_id(),
            (string) $order->get_order_number(),
            (string) $order->get_currency(),
            $created ? $created->date('Y-m-d') : gmdate('Y-m-d'),
            $this->buyer($order),
            $this->lines($order),
            (string) $order->get_payment_method(),
            (string) $order->get_payment_method_title(),
            (string) $order->get_customer_note(),
        );
    }

    public function buyer(WC_Order $order): Buyer
    {
        $company = trim($order->get_billing_company());
        $person = trim($order->get_billing_first_name().' '.$order->get_billing_last_name());

        return new Buyer(
            $company !== '' ? $company : $person,
            (string) $order->get_billing_country(),
            OrderData::nip($order),
            trim($order->get_billing_address_1().' '.$order->get_billing_address_2()),
            trim($order->get_billing_postcode().' '.$order->get_billing_city()),
            (string) $order->get_billing_email(),
            trim($order->get_billing_phone()),
        );
    }

    /**
     * @return list<Line>
     */
    public function lines(WC_Order $order): array
    {
        $lines = [];

        foreach ($order->get_items('line_item') as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }

            $lines[] = new Line(
                (string) $item->get_id(),
                Line::TYPE_PRODUCT,
                $this->lineName($item),
                (float) $item->get_quantity(),
                (float) $order->get_line_total($item, false, false),
                (float) $order->get_line_total($item, true, false),
                $this->taxPercent($order, $item),
                ProductKind::isService($item->get_product() ?: null),
            );
        }

        foreach ($order->get_items('shipping') as $shipping) {
            if (! $shipping instanceof WC_Order_Item_Shipping) {
                continue;
            }

            $lines[] = new Line(
                (string) $shipping->get_id(),
                Line::TYPE_SHIPPING,
                $shipping->get_name() ?: $shipping->get_method_title(),
                1.0,
                (float) $shipping->get_total(),
                (float) $shipping->get_total() + (float) $shipping->get_total_tax(),
                $this->taxPercent($order, $shipping),
            );
        }

        foreach ($order->get_items('fee') as $fee) {
            if (! $fee instanceof WC_Order_Item_Fee) {
                continue;
            }

            $lines[] = new Line(
                (string) $fee->get_id(),
                Line::TYPE_FEE,
                $fee->get_name(),
                1.0,
                (float) $fee->get_total(),
                (float) $fee->get_total() + (float) $fee->get_total_tax(),
                $this->taxPercent($order, $fee),
            );
        }

        return $lines;
    }

    private function lineName(WC_Order_Item_Product $item): string
    {
        $name = $item->get_name();
        $product = $item->get_product();
        $sku = $product ? $product->get_sku() : '';

        if ($sku !== '' && ! str_contains($name, $sku)) {
            $name .= ' ['.$sku.']';
        }

        return $name;
    }

    /**
     * Percentage of the first tax rate applied to the item, or null when no tax was applied.
     * Protected so tests can replace the WooCommerce tax-table lookup.
     */
    protected function taxPercent(WC_Order $order, WC_Order_Item $item): ?float
    {
        if (! method_exists($item, 'get_taxes')) {
            return null;
        }

        /** @var array{total?: array<int|string, string|null>} $taxes */
        $taxes = $item->get_taxes();
        $totals = $taxes['total'] ?? [];

        if (! is_array($totals) || $totals === []) {
            return null;
        }

        foreach ($totals as $rateId => $amount) {
            if ($amount === '' || $amount === null) {
                continue;
            }

            $percent = \WC_Tax::get_rate_percent_value((int) $rateId);

            return is_numeric($percent) ? (float) $percent : null;
        }

        return null;
    }
}
