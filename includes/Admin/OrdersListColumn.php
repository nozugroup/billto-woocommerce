<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Admin;

use BillTo\WooCommerce\Support\Meta;
use WC_Order;

/**
 * "Faktura" column on the orders list (both HPOS and legacy post tables).
 */
final class OrdersListColumn
{
    private const KEY = 'billto_invoice';

    public function register(): void
    {
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addColumn'], 20);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('manage_edit-shop_order_columns', [$this, 'addColumn'], 20);
        add_action('manage_shop_order_posts_custom_column', [$this, 'renderLegacyColumn'], 10, 2);
    }

    /**
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    public function addColumn(array $columns): array
    {
        $result = [];

        foreach ($columns as $key => $label) {
            $result[$key] = $label;

            if ($key === 'order_total') {
                $result[self::KEY] = __('Faktura', 'billto-woocommerce');
            }
        }

        if (! isset($result[self::KEY])) {
            $result[self::KEY] = __('Faktura', 'billto-woocommerce');
        }

        return $result;
    }

    /**
     * @param  WC_Order|mixed  $order
     */
    public function renderColumn(string $column, $order): void
    {
        if ($column !== self::KEY || ! $order instanceof WC_Order) {
            return;
        }

        $number = (string) $order->get_meta(Meta::INVOICE_NUMBER);

        if ($number === '') {
            $error = (string) $order->get_meta(Meta::LAST_ERROR);
            echo $error !== '' ? '<span style="color:#d63638" title="'.esc_attr($error).'">&#9888;</span>' : '<span style="color:#787c82">&ndash;</span>';

            return;
        }

        echo '<a href="'.esc_url(OrderActions::url('pdf', $order)).'" target="_blank">'.esc_html($number).'</a>';

        $ksef = (string) $order->get_meta(Meta::KSEF_STATUS);

        if ($ksef === 'assigned') {
            echo ' <span title="KSeF" style="color:#00a32a">&#10003;</span>';
        }
    }

    public function renderLegacyColumn(string $column, int $postId): void
    {
        $order = wc_get_order($postId);

        if ($order instanceof WC_Order) {
            $this->renderColumn($column, $order);
        }
    }
}
