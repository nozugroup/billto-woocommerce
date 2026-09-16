<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Frontend;

use BillTo\WooCommerce\Admin\OrderActions;
use BillTo\WooCommerce\Support\Meta;
use WC_Order;

/**
 * Invoice link in "My account": orders table action + order details page.
 */
final class MyAccount
{
    public function register(): void
    {
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'addOrderAction'], 10, 2);
        add_action('woocommerce_order_details_after_order_table', [$this, 'renderOnOrderPage']);
    }

    /**
     * @param  array<string, array{url: string, name: string}>  $actions
     * @return array<string, array{url: string, name: string}>
     */
    public function addOrderAction(array $actions, WC_Order $order): array
    {
        if ((string) $order->get_meta(Meta::INVOICE_ID) === '') {
            return $actions;
        }

        $actions['billto_invoice'] = [
            'url' => OrderActions::url('pdf', $order),
            'name' => __('Faktura', 'billto-woocommerce'),
        ];

        return $actions;
    }

    public function renderOnOrderPage(WC_Order $order): void
    {
        if ((string) $order->get_meta(Meta::INVOICE_ID) === '' || ! is_user_logged_in() || (int) $order->get_customer_id() !== get_current_user_id()) {
            return;
        }

        $number = (string) $order->get_meta(Meta::INVOICE_NUMBER);
        $publicUrl = \BillTo\WooCommerce\Plugin::instance()->orderSync()->ensurePublicUrl($order);

        echo '<section class="woocommerce-order-invoice billto-wc-invoice">';
        echo '<h2 class="woocommerce-order-details__title">'.esc_html__('Faktura', 'billto-woocommerce').'</h2>';
        echo '<p>'.esc_html($number !== '' ? $number : __('Faktura VAT', 'billto-woocommerce')).': ';
        echo '<a href="'.esc_url(OrderActions::url('pdf', $order)).'" target="_blank">'.esc_html__('Pobierz PDF', 'billto-woocommerce').'</a>';

        if ($publicUrl !== '') {
            echo ' | <a href="'.esc_url($publicUrl).'" target="_blank" rel="noopener">'.esc_html__('Dane do przelewu', 'billto-woocommerce').'</a>';
        }

        echo '</p></section>';
    }
}
