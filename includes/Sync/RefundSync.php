<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Sync;

use BillTo\Shop\MarkPaidPayload;
use BillTo\Shop\RefundLines;
use BillTo\WooCommerce\Api\ApiException;
use BillTo\WooCommerce\Api\Client;
use BillTo\WooCommerce\Support\Logger;
use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\Options;
use BillTo\WooCommerce\Support\OrderData;
use BillTo\WooCommerce\Support\PdfStorage;
use WC_Order;
use WC_Order_Refund;

/**
 * WooCommerce refund -> BillTo correcting order (step 1) + KOR invoice (step 2).
 *
 * Line refunds send the quantity remaining AFTER the refund (cumulative across refunds);
 * amount-only refunds become a proportional price reduction or a note, per settings. The
 * arithmetic lives in the shared core (RefundLines); this class only reads WooCommerce.
 */
final class RefundSync
{
    /** @var callable(): Client */
    private $client;

    public function __construct(
        private Options $options,
        private Logger $logger,
        private PdfStorage $pdfStorage,
        callable $client,
    ) {
        $this->client = $client;
    }

    public function registerHooks(): void
    {
        add_action('woocommerce_order_refunded', [$this, 'onRefunded'], 20, 2);
    }

    public function onRefunded(int $orderId, int $refundId): void
    {
        if (! $this->options->refundCorrections()) {
            return;
        }

        $order = wc_get_order($orderId);

        if ($order instanceof WC_Order && OrderData::invoiceId($order) !== null) {
            Jobs::enqueue(Jobs::HOOK_REFUND, [$orderId, $refundId]);
        }
    }

    /**
     * @throws RetryableFailure
     */
    public function correctForRefund(int $orderId, int $refundId): void
    {
        $order = wc_get_order($orderId);
        $refund = wc_get_order($refundId);

        if (! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund) {
            return;
        }

        $corrections = $this->corrections($order);

        if (isset($corrections[(string) $refundId])) {
            return; // already corrected
        }

        $billtoOrderId = OrderData::billtoOrderId($order);

        if ($billtoOrderId === null) {
            return;
        }

        $lines = $this->linesForRefund($order, $refund);

        if ($lines === [] && $this->options->amountRefundMode() === Options::REFUND_PROPORTIONAL) {
            $lines = $this->proportionalLines($order, (float) $refund->get_amount());
        }

        if ($lines === []) {
            $order->add_order_note(sprintf(
                __('BillTo: zwrot #%1$d na kwotę %2$s nie ma pozycji - wystaw fakturę korygującą ręcznie w BillTo.', 'billto-woocommerce'),
                $refundId,
                wc_price((float) $refund->get_amount(), ['currency' => $order->get_currency()]),
            ));

            return;
        }

        $client = ($this->client)();
        $reason = trim((string) $refund->get_reason()) ?: sprintf(__('Zwrot WooCommerce #%d', 'billto-woocommerce'), $refundId);

        try {
            $step1 = $client->post('orders/'.$billtoOrderId.'/issue-correction', [
                'lines' => $lines,
                'reason' => $reason,
            ], Client::operationKey('order-'.$orderId.'-refund-'.$refundId.'-correction'));

            $correctingId = (string) ($step1['data']['id'] ?? '');

            if ($correctingId === '') {
                throw new ApiException(__('Brak identyfikatora zamówienia korygującego w odpowiedzi.', 'billto-woocommerce'), 500);
            }

            $step2 = $client->post(
                'orders/'.$correctingId.'/issue-kor',
                MarkPaidPayload::issueKor($this->options->toSettings()),
                Client::operationKey('order-'.$orderId.'-refund-'.$refundId.'-kor'),
            );
        } catch (ApiException $e) {
            if ($e->isRetryable()) {
                OrderData::rememberError($order, $e->getMessage());

                throw new RetryableFailure($e->getMessage(), 0, $e);
            }

            $details = $e->isValidation() ? ' ('.implode('; ', $e->validationMessages()).')' : '';
            $order->add_order_note(sprintf(__('BillTo: korekta dla zwrotu #%1$d nie powiodła się: %2$s%3$s', 'billto-woocommerce'), $refundId, $e->getMessage(), $details));
            $this->logger->error(sprintf('Order #%d refund #%d correction failed: %s', $orderId, $refundId, $e->getMessage()));

            return;
        }

        $kor = $step2['data'] ?? [];
        $korNumber = (string) ($kor['invoice_number'] ?? $kor['id'] ?? '');
        $corrections[(string) $refundId] = ['invoice_id' => $kor['id'] ?? null, 'invoice_number' => $korNumber];

        $order->update_meta_data(Meta::CORRECTIONS, wp_json_encode($corrections));
        $order->save_meta_data();
        $order->add_order_note(sprintf(__('BillTo: wystawiono fakturę korygującą %1$s dla zwrotu #%2$d.', 'billto-woocommerce'), $korNumber, $refundId));

        if (! empty($kor['id'])) {
            $this->storeKorPdf($order, (string) $kor['id'], (string) $refundId);
        }
    }

    /**
     * `lines[]` (line_number + remaining quantity) from the refund's line items.
     *
     * @return list<array<string, mixed>>
     */
    public function linesForRefund(WC_Order $order, WC_Order_Refund $refund): array
    {
        $refunded = [];

        foreach ($refund->get_items(['line_item', 'shipping', 'fee']) as $refundItem) {
            $originalItemId = (int) $refundItem->get_meta('_refunded_item_id');

            if ($originalItemId === 0) {
                continue;
            }

            $original = $order->get_item($originalItemId);

            if (! $original) {
                continue;
            }

            $isProduct = $original->get_type() === 'line_item';

            if ($isProduct) {
                $refundedTotal = abs((float) $order->get_qty_refunded_for_item($originalItemId));
                $original = $original instanceof \WC_Order_Item_Product ? $original : null;
                $originalQty = $original ? (float) $original->get_quantity() : 0.0;
            } else {
                $refundedTotal = method_exists($refundItem, 'get_total') && abs((float) $refundItem->get_total()) > 0 ? 1.0 : 0.0;
                $originalQty = 1.0;
            }

            $refunded[] = ['id' => (string) $originalItemId, 'originalQuantity' => $originalQty, 'refundedQuantity' => $refundedTotal, 'isProduct' => $isProduct];
        }

        return RefundLines::remaining(OrderData::lineMap($order), $refunded);
    }

    /**
     * Amount-only refund: lower the unit price of every invoiced line by the same percentage.
     *
     * @return list<array<string, mixed>>
     */
    public function proportionalLines(WC_Order $order, float $refundAmount): array
    {
        $lines = [];

        foreach (OrderData::lineMap($order) as $itemId => $lineNumber) {
            $item = $order->get_item((int) $itemId);

            if (! $item) {
                continue;
            }

            if ($item instanceof \WC_Order_Item_Product) {
                $lines[] = ['id' => (string) $itemId, 'quantity' => (float) $item->get_quantity(), 'netTotal' => (float) $order->get_line_total($item, false, false)];
            } else {
                $lines[] = ['id' => (string) $itemId, 'quantity' => 1.0, 'netTotal' => method_exists($item, 'get_total') ? (float) $item->get_total() : 0.0];
            }
        }

        return RefundLines::proportional(OrderData::lineMap($order), $lines, $refundAmount, (float) $order->get_total());
    }

    /** @return array<string, array<string, mixed>> */
    private function corrections(WC_Order $order): array
    {
        $decoded = json_decode((string) $order->get_meta(Meta::CORRECTIONS), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function storeKorPdf(WC_Order $order, string $invoiceId, string $refundId): void
    {
        try {
            $content = ($this->client)()->download('invoices/'.$invoiceId.'/pdf');
        } catch (ApiException $e) {
            $this->logger->warning(sprintf('KOR PDF download failed for %s: %s', $invoiceId, $e->getMessage()));

            return;
        }

        if (str_starts_with($content, '%PDF')) {
            $this->pdfStorage->store($order->get_id(), 'kor-'.$refundId, $content);
        }
    }
}
