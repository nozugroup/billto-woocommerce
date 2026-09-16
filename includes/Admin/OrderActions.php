<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Admin;

use BillTo\WooCommerce\Api\ApiException;
use BillTo\WooCommerce\Api\Client;
use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\OrderData;
use BillTo\WooCommerce\Support\PdfStorage;
use BillTo\WooCommerce\Sync\OrderSync;
use BillTo\WooCommerce\Sync\RetryableFailure;
use WC_Order;

/**
 * Manual actions from the order screen (admin-post.php) and the permission-checked PDF endpoint,
 * which also serves customers from "My account".
 */
final class OrderActions
{
    private const ACTION = 'billto_wc_order_action';

    /** @var callable(): Client */
    private $client;

    public function __construct(
        private OrderSync $orderSync,
        private PdfStorage $pdfStorage,
        callable $client,
    ) {
        $this->client = $client;
    }

    public function register(): void
    {
        add_action('admin_post_'.self::ACTION, [$this, 'handle']);
        add_action('admin_post_nopriv_'.self::ACTION, [$this, 'handle']);
        add_action('admin_notices', [$this, 'notice']);
    }

    public static function url(string $action, WC_Order $order): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => self::ACTION, 'do' => $action, 'order_id' => $order->get_id()], admin_url('admin-post.php')),
            self::ACTION.'-'.$order->get_id(),
        );
    }

    public function handle(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below with check_admin_referer.
        $orderId = absint($_GET['order_id'] ?? 0);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $do = sanitize_key(wp_unslash((string) ($_GET['do'] ?? '')));

        check_admin_referer(self::ACTION.'-'.$orderId);

        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            wp_die(esc_html__('Zamówienie nie istnieje.', 'billto-woocommerce'));
        }

        if ($do === 'pdf') {
            $this->servePdf($order);
        }

        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'billto-woocommerce'), '', ['response' => 403]);
        }

        $message = $this->run($do, $order);

        wp_safe_redirect(add_query_arg('billto_notice', rawurlencode($message), $order->get_edit_order_url()));
        exit;
    }

    private function run(string $do, WC_Order $order): string
    {
        try {
            switch ($do) {
                case 'sync':
                    $id = $this->orderSync->createOrUpdateBillToOrder($order->get_id());

                    return $id !== null ? __('Zamówienie zsynchronizowane z BillTo.', 'billto-woocommerce') : $this->lastError($order);

                case 'invoice':
                    $this->orderSync->invoiceOrder($order->get_id(), true);

                    return OrderData::invoiceId($order) !== null || OrderData::invoiceId(wc_get_order($order->get_id()) ?: $order) !== null
                        ? __('Faktura wystawiona.', 'billto-woocommerce')
                        : $this->lastError($order);

                case 'ksef':
                    $data = $this->orderSync->sendToKsef($order);

                    return $data !== null ? __('Faktura przekazana do KSeF - numer nadawany jest asynchronicznie.', 'billto-woocommerce') : __('Wysyłka do KSeF nie powiodła się - szczegóły w notatkach zamówienia.', 'billto-woocommerce');

                case 'ksef-status':
                    $data = $this->orderSync->refreshKsefStatus($order);

                    return $data !== null ? __('Status KSeF odświeżony.', 'billto-woocommerce') : __('Nie udało się pobrać statusu KSeF.', 'billto-woocommerce');

                case 'send-email':
                    return $this->sendEmail($order);

                case 'refresh-pdf':
                    $invoiceId = OrderData::invoiceId($order);

                    return $invoiceId !== null && $this->orderSync->fetchPdf($order, $invoiceId) !== null
                        ? __('PDF pobrany ponownie.', 'billto-woocommerce')
                        : __('Nie udało się pobrać PDF.', 'billto-woocommerce');

                default:
                    return __('Nieznana akcja.', 'billto-woocommerce');
            }
        } catch (RetryableFailure $e) {
            return sprintf(__('BillTo chwilowo niedostępne: %s. Operacja zostanie powtórzona automatycznie.', 'billto-woocommerce'), $e->getMessage());
        }
    }

    private function sendEmail(WC_Order $order): string
    {
        $invoiceId = OrderData::invoiceId($order);

        if ($invoiceId === null) {
            return __('Brak faktury do wysłania.', 'billto-woocommerce');
        }

        try {
            $response = ($this->client)()->post('invoices/'.$invoiceId.'/send-email', ['email' => $order->get_billing_email()]);
            $order->add_order_note(sprintf(__('BillTo: faktura wysłana e-mailem na %s.', 'billto-woocommerce'), $response['data']['sent_to'] ?? $order->get_billing_email()));

            return __('Faktura wysłana e-mailem.', 'billto-woocommerce');
        } catch (ApiException $e) {
            return sprintf(__('Wysyłka nie powiodła się: %s', 'billto-woocommerce'), $e->getMessage());
        }
    }

    /**
     * Streams the stored PDF to the shop staff or to the customer who owns the order.
     */
    private function servePdf(WC_Order $order): void
    {
        $allowed = current_user_can('manage_woocommerce')
            || (is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id());

        if (! $allowed) {
            wp_die(esc_html__('Brak uprawnień.', 'billto-woocommerce'), '', ['response' => 403]);
        }

        $path = (string) $order->get_meta(Meta::INVOICE_PDF_PATH);

        if (! $this->pdfStorage->exists($path)) {
            $invoiceId = OrderData::invoiceId($order);
            $path = $invoiceId !== null ? (string) $this->orderSync->fetchPdf($order, $invoiceId) : '';
        }

        if (! $this->pdfStorage->exists($path)) {
            wp_die(esc_html__('PDF faktury jest niedostępny.', 'billto-woocommerce'), '', ['response' => 404]);
        }

        $number = (string) $order->get_meta(Meta::INVOICE_NUMBER);
        $filename = sanitize_file_name(($number !== '' ? str_replace(['/', '\\'], '-', $number) : 'faktura-'.$order->get_id()).'.pdf');

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.$filename.'"');
        header('Content-Length: '.(string) filesize($path));
        readfile($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    private function lastError(WC_Order $order): string
    {
        $fresh = wc_get_order($order->get_id());
        $error = $fresh instanceof WC_Order ? (string) $fresh->get_meta(Meta::LAST_ERROR) : '';

        return $error !== '' ? $error : __('Operacja nie powiodła się - szczegóły w notatkach zamówienia i logach WooCommerce.', 'billto-woocommerce');
    }

    public function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $message = isset($_GET['billto_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['billto_notice'])) : '';

        if ($message === '') {
            return;
        }

        echo '<div class="notice notice-info is-dismissible"><p><strong>BillTo:</strong> '.esc_html($message).'</p></div>';
    }
}
