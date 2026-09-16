<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Admin;

use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\Options;
use BillTo\WooCommerce\Support\OrderData;
use BillTo\WooCommerce\Sync\BuyerScenario;
use WC_Order;

/**
 * "BillTo" box on the order edit screen: mapping, invoice, KSeF, manual actions.
 */
final class OrderMetaBox
{
    public function __construct(private Options $options) {}

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add']);
    }

    public function add(): void
    {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';

        add_meta_box('billto-wc', __('BillTo', 'billto-woocommerce'), [$this, 'render'], $screen, 'side', 'high');
    }

    /**
     * @param  \WP_Post|WC_Order  $object
     */
    public function render($object): void
    {
        $order = $object instanceof WC_Order ? $object : wc_get_order($object->ID);

        if (! $order instanceof WC_Order) {
            return;
        }

        $billtoOrderId = OrderData::billtoOrderId($order);
        $invoiceId = OrderData::invoiceId($order);
        $invoiceNumber = (string) $order->get_meta(Meta::INVOICE_NUMBER);
        $ksefStatus = (string) $order->get_meta(Meta::KSEF_STATUS);
        $ksefNumber = (string) $order->get_meta(Meta::KSEF_NUMBER);
        $lastError = (string) $order->get_meta(Meta::LAST_ERROR);
        $corrections = json_decode((string) $order->get_meta(Meta::CORRECTIONS), true);

        echo '<div class="billto-wc-box" style="font-size:13px;line-height:1.6">';

        if ($this->options->isSandbox()) {
            echo '<p style="color:#996800;margin:0 0 6px"><strong>'.esc_html__('Sandbox', 'billto-woocommerce').'</strong></p>';
        }

        echo '<p style="margin:0"><strong>'.esc_html__('Zamówienie', 'billto-woocommerce').':</strong> ';
        echo $billtoOrderId !== null
            ? esc_html((string) $order->get_meta(Meta::ORDER_NUMBER) ?: __('utworzone', 'billto-woocommerce'))
            : '<span style="color:#787c82">'.esc_html__('nie wysłano', 'billto-woocommerce').'</span>';
        echo '</p>';

        echo '<p style="margin:0"><strong>'.esc_html__('Faktura', 'billto-woocommerce').':</strong> ';

        if ($invoiceId !== null) {
            echo esc_html($invoiceNumber !== '' ? $invoiceNumber : $invoiceId);
            echo ' <a href="'.esc_url(OrderActions::url('pdf', $order)).'" target="_blank">PDF</a>';

            $publicUrl = \BillTo\WooCommerce\Plugin::instance()->orderSync()->ensurePublicUrl($order);

            if ($publicUrl !== '') {
                echo ' | <a href="'.esc_url($publicUrl).'" target="_blank" rel="noopener">'.esc_html__('strona publiczna', 'billto-woocommerce').'</a>';
            }
        } else {
            echo '<span style="color:#787c82">'.esc_html__('brak', 'billto-woocommerce').'</span>';
        }

        echo '</p>';

        if ($invoiceId !== null) {
            echo '<p style="margin:0"><strong>KSeF:</strong> '.esc_html($this->ksefLabel($ksefStatus, $ksefNumber)).'</p>';
        }

        if (is_array($corrections) && $corrections !== []) {
            echo '<p style="margin:0"><strong>'.esc_html__('Korekty', 'billto-woocommerce').':</strong> ';
            echo esc_html(implode(', ', array_map(static fn ($c) => (string) ($c['invoice_number'] ?? ''), $corrections)));
            echo '</p>';
        }

        $warnings = json_decode((string) $order->get_meta(Meta::WARNINGS), true);

        if (is_array($warnings) && $warnings !== []) {
            foreach ($warnings as $code) {
                echo '<p style="margin:6px 0 0;color:#996800"><strong>'.esc_html__('Ostrzeżenie', 'billto-woocommerce').':</strong> '.esc_html(BuyerScenario::warningLabel((string) $code)).'</p>';
            }
        }

        if ($lastError !== '') {
            echo '<p style="margin:6px 0 0;color:#d63638"><strong>'.esc_html__('Ostatni błąd', 'billto-woocommerce').':</strong> '.esc_html($lastError).'</p>';
        }

        echo '<p style="margin:10px 0 0;display:flex;flex-wrap:wrap;gap:6px">';

        if ($invoiceId === null) {
            if ($billtoOrderId === null) {
                $this->button('sync', $order, __('Wyślij zamówienie', 'billto-woocommerce'));
            } else {
                $this->button('sync', $order, __('Synchronizuj', 'billto-woocommerce'));
            }

            $this->button('invoice', $order, __('Wystaw fakturę', 'billto-woocommerce'), 'button-primary');
        } else {
            if ($ksefStatus !== 'assigned') {
                $this->button('ksef', $order, __('Wyślij do KSeF', 'billto-woocommerce'));
            }

            $this->button('ksef-status', $order, __('Odśwież status KSeF', 'billto-woocommerce'));
            $this->button('send-email', $order, __('Wyślij e-mailem', 'billto-woocommerce'));
            $this->button('refresh-pdf', $order, __('Pobierz PDF ponownie', 'billto-woocommerce'));
        }

        echo '</p></div>';
    }

    private function button(string $action, WC_Order $order, string $label, string $class = 'button'): void
    {
        echo '<a class="'.esc_attr($class).' button-small" href="'.esc_url(OrderActions::url($action, $order)).'">'.esc_html($label).'</a>';
    }

    private function ksefLabel(string $status, string $number): string
    {
        return match ($status) {
            'assigned' => $number,
            'pending' => __('w trakcie nadawania numeru', 'billto-woocommerce'),
            'error' => __('błąd - sprawdź w BillTo', 'billto-woocommerce'),
            default => __('nie wysłano', 'billto-woocommerce'),
        };
    }
}
