<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Sync;

use BillTo\WooCommerce\Api\ApiException;
use BillTo\WooCommerce\Api\Client;
use BillTo\WooCommerce\Support\Logger;
use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\Options;
use BillTo\WooCommerce\Support\OrderData;
use BillTo\WooCommerce\Support\PdfStorage;
use BillTo\WooCommerce\Support\Vies;
use WC_Order;

/**
 * WooCommerce order lifecycle -> BillTo: create/update the order, invoice it when paid (or as
 * unpaid for cash-on-delivery gateways and settle later), cancel it when cancelled, fetch the
 * PDF, optionally submit to KSeF and poll for the number.
 */
final class OrderSync
{
    /** @var callable(): Client */
    private $client;

    public function __construct(
        private Options $options,
        private Logger $logger,
        private OrderMapper $mapper,
        private PdfStorage $pdfStorage,
        callable $client,
    ) {
        $this->client = $client;
    }

    public function registerHooks(): void
    {
        // Classic checkout + Store API (Blocks) checkout.
        add_action('woocommerce_checkout_order_processed', [$this, 'onOrderPlaced'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'onOrderPlacedObject'], 20, 1);

        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 20, 4);
        add_action('woocommerce_payment_complete', [$this, 'onPaymentComplete'], 20, 1);

        // Staff edited the order in the admin (address, items) before it was invoiced.
        add_action('woocommerce_process_shop_order_meta', [$this, 'onAdminOrderSaved'], 60, 1);
    }

    public function onOrderPlaced(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if ($order instanceof WC_Order && $this->shouldHandle($order) && $this->options->createOnCheckout()) {
            Jobs::enqueue(Jobs::HOOK_SYNC_ORDER, [$orderId]);
        }
    }

    public function onOrderPlacedObject(WC_Order $order): void
    {
        $this->onOrderPlaced($order->get_id());
    }

    public function onPaymentComplete(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if ($order instanceof WC_Order && $this->shouldHandle($order) && in_array($order->get_status(), $this->options->paidStatuses(), true)) {
            Jobs::enqueue(Jobs::HOOK_INVOICE, [$orderId]);
        }
    }

    public function onStatusChanged(int $orderId, string $from, string $to, WC_Order $order): void
    {
        if (! $this->shouldHandle($order)) {
            return;
        }

        if (in_array($to, $this->options->paidStatuses(), true)) {
            Jobs::enqueue(Jobs::HOOK_INVOICE, [$orderId]);
        }

        // Cash on delivery: the invoice was issued unpaid; the money arrives with this status.
        if ($to === $this->options->settleStatus() && (string) $order->get_meta(Meta::INVOICE_PAID) === 'no') {
            Jobs::enqueue(Jobs::HOOK_SETTLE, [$orderId]);
        }

        if ($to === 'cancelled' && OrderData::billtoOrderId($order) !== null) {
            Jobs::enqueue(Jobs::HOOK_CANCEL, [$orderId]);
        }
    }

    public function onAdminOrderSaved(int $orderId): void
    {
        if (! $this->options->resyncOnEdit()) {
            return;
        }

        $order = wc_get_order($orderId);

        if ($order instanceof WC_Order && OrderData::billtoOrderId($order) !== null && OrderData::invoiceId($order) === null && $this->shouldHandle($order)) {
            Jobs::enqueue(Jobs::HOOK_SYNC_ORDER, [$orderId]);
        }
    }

    /**
     * Whether this order participates in the integration at all (token set, invoice mode, scenario).
     */
    public function shouldHandle(WC_Order $order): bool
    {
        if (! ($this->client)()->isConfigured()) {
            return false;
        }

        if ($order->get_parent_id() > 0) {
            return false; // refunds are WC_Order_Refund, never synced as orders
        }

        if ($this->options->invoicesOnlyOnRequest() && ! OrderData::wantsInvoice($order)) {
            return false;
        }

        if (! $this->scenarioEnabled(BuyerScenario::forOrder($order))) {
            return false;
        }

        /** Lets a site exclude orders (e.g. specific payment gateways). */
        return (bool) apply_filters('billto_wc_should_sync_order', true, $order);
    }

    /**
     * Whether the settings allow invoicing this buyer scenario at all.
     */
    public function scenarioEnabled(string $scenario): bool
    {
        return \BillTo\Shop\BuyerScenario::isEnabled($scenario, $this->options->toSettings());
    }

    /**
     * Body of POST /orders/{id}/mark-paid for this order (decided by the shared core).
     *
     * @return array<string, mixed>
     */
    public function markPaidPayload(WC_Order $order): array
    {
        return $this->mapper->markPaidPayload($order);
    }

    /**
     * Creates the BillTo order (or re-syncs it while still editable). Idempotent through
     * (source, external_id): a repeated POST returns the existing order.
     *
     * @throws RetryableFailure
     */
    public function createOrUpdateBillToOrder(int $orderId): ?string
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return null;
        }

        if (! $this->viesAllows($order)) {
            return null;
        }

        $client = ($this->client)();
        $built = $this->mapper->build($order);

        if (($built['blocker'] ?? null) === \BillTo\Shop\Settings::BLOCKER_EU_CONSUMER_NO_VAT) {
            $message = __('Konsument z UE, a sklep nie naliczył VAT (brak stawki dla tego kraju w WooCommerce?). W trybie "stawki polskie" faktura wymaga VAT - uzupełnij stawki podatkowe, przełącz tryb OSS albo zmień ustawienie "Konsument z UE bez VAT w sklepie".', 'billto-woocommerce');
            OrderData::rememberError($order, $message);
            $order->add_order_note('BillTo: '.$message);

            return null;
        }

        if ($built['hasNegativeLines'] && $this->options->negativeLinesMode() === Options::NEGATIVE_SKIP) {
            $message = __('Zamówienie zawiera pozycję ujemną (rabat jako opłata, karta podarunkowa) - wystaw fakturę ręcznie albo włącz rozdzielanie rabatu w ustawieniach BillTo.', 'billto-woocommerce');
            OrderData::rememberError($order, $message);
            $order->add_order_note('BillTo: '.$message);

            return null;
        }

        $payload = $built['payload'];
        $lineMap = $built['lineMap'];
        $hash = md5((string) wp_json_encode($payload));

        // Status changes and admin saves fire the update hook without changing anything relevant.
        if (OrderData::billtoOrderId($order) !== null && (string) $order->get_meta(Meta::PAYLOAD_HASH) === $hash) {
            return OrderData::billtoOrderId($order);
        }

        try {
            $existingId = OrderData::billtoOrderId($order);

            if ($existingId !== null) {
                unset($payload['status'], $payload['send_confirmation']);

                try {
                    $response = $client->put('orders/'.$existingId, $payload, Client::operationKey('order-'.$orderId.'-update-'.md5(wp_json_encode($payload) ?: '')));
                } catch (ApiException $e) {
                    if ($e->isConflict()) {
                        // Already invoiced / closed - nothing to update, keep the existing mapping.
                        return $existingId;
                    }

                    throw $e;
                }
            } else {
                $response = $client->post('orders', $payload, Client::operationKey('order-'.$orderId.'-create'));
            }
        } catch (ApiException $e) {
            $this->handleFailure($order, $e, __('Nie udało się utworzyć zamówienia w BillTo', 'billto-woocommerce'));

            return null;
        }

        $data = $response['data'] ?? [];
        $billtoId = isset($data['id']) ? (string) $data['id'] : null;

        if ($billtoId === null) {
            OrderData::rememberError($order, __('Odpowiedź BillTo bez identyfikatora zamówienia.', 'billto-woocommerce'));

            return null;
        }

        $isNew = OrderData::billtoOrderId($order) === null;

        $order->update_meta_data(Meta::ORDER_ID, $billtoId);
        // display_number: BillTo series number, or the shop reference when the team does not number orders.
        $order->update_meta_data(Meta::ORDER_NUMBER, (string) ($data['display_number'] ?? $data['order_number'] ?? ''));
        $order->update_meta_data(Meta::LINE_MAP, wp_json_encode($lineMap));
        $order->update_meta_data(Meta::PAYLOAD_HASH, $hash);

        // Consistent with what the shop charged, but not what the buyer scenario normally produces.
        $warnings = $built['warnings'] ?? [];
        $previous = json_decode((string) $order->get_meta(Meta::WARNINGS), true);

        if ($warnings !== []) {
            $order->update_meta_data(Meta::WARNINGS, wp_json_encode($warnings));
        } else {
            $order->delete_meta_data(Meta::WARNINGS);
        }

        $order->save_meta_data();
        OrderData::clearError($order);

        foreach (array_diff($warnings, is_array($previous) ? $previous : []) as $code) {
            $order->add_order_note(__('BillTo, ostrzeżenie: ', 'billto-woocommerce').BuyerScenario::warningLabel((string) $code));
        }

        if ($isNew) {
            $order->add_order_note(sprintf(
                __('BillTo: zamówienie %1$s (nabywca: %2$s).', 'billto-woocommerce'),
                $data['order_number'] ?: $billtoId,
                BuyerScenario::label($this->mapper->effectiveScenario($order)),
            ));
        } else {
            $order->add_order_note(__('BillTo: zamówienie zaktualizowane po edycji.', 'billto-woocommerce'));
        }

        $this->logger->info(sprintf('Order #%d synced to BillTo order %s', $orderId, $billtoId));

        return $billtoId;
    }

    /**
     * EU company: check the VAT id in VIES per settings. Returns false when invoicing must stop
     * (invalid id in "block" mode). An unavailable VIES service re-queues the job.
     *
     * @throws RetryableFailure
     */
    private function viesAllows(WC_Order $order): bool
    {
        if (BuyerScenario::forOrder($order) !== BuyerScenario::EU_B2B || $this->options->viesCheck() === Options::VIES_OFF) {
            return true;
        }

        $cached = json_decode((string) $order->get_meta(Meta::VIES), true);
        $result = is_array($cached) && in_array($cached['status'] ?? '', [Vies::VALID, Vies::INVALID], true)
            ? $cached
            : Vies::check(OrderData::nip($order));

        if ($result['status'] === Vies::UNAVAILABLE) {
            OrderData::rememberError($order, __('VIES niedostępny - weryfikacja numeru VAT UE zostanie powtórzona.', 'billto-woocommerce'));

            throw new RetryableFailure('VIES unavailable');
        }

        if (! is_array($cached) || ($cached['status'] ?? null) !== $result['status']) {
            $order->update_meta_data(Meta::VIES, wp_json_encode($result));
            $order->save_meta_data();
            $order->add_order_note(sprintf(
                __('BillTo VIES: numer %1$s %2$s%3$s', 'billto-woocommerce'),
                OrderData::nip($order),
                $result['status'] === Vies::VALID ? __('aktywny', 'billto-woocommerce') : __('NIEAKTYWNY', 'billto-woocommerce'),
                ! empty($result['name']) ? ' ('.$result['name'].')' : '',
            ));
        }

        if ($result['status'] === Vies::INVALID && $this->options->viesCheck() === Options::VIES_BLOCK) {
            $message = __('Numer VAT UE nabywcy jest nieaktywny w VIES - faktura 0% WDT nie zostanie wystawiona. Popraw numer i użyj „Synchronizuj", albo zmień zachowanie w ustawieniach BillTo.', 'billto-woocommerce');
            OrderData::rememberError($order, $message);
            $order->add_order_note('BillTo: '.$message);

            return false;
        }

        return true;
    }

    /**
     * Issues the invoice through POST /orders/{id}/mark-paid, stores the result, fetches the PDF,
     * optionally sends to KSeF and triggers WooCommerce e-mail delivery.
     *
     * @throws RetryableFailure
     */
    public function invoiceOrder(int $orderId, bool $force = false): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return;
        }

        if (OrderData::invoiceId($order) !== null && ! $force) {
            return; // already invoiced
        }

        if (! $force && ! $this->shouldHandle($order)) {
            return;
        }

        $billtoId = OrderData::billtoOrderId($order) ?? $this->createOrUpdateBillToOrder($orderId);

        if ($billtoId === null) {
            return; // failure already recorded / re-queued
        }

        $client = ($this->client)();

        try {
            $response = $client->post(
                'orders/'.$billtoId.'/mark-paid',
                $this->markPaidPayload($order),
                Client::operationKey('order-'.$orderId.'-mark-paid'),
            );
        } catch (ApiException $e) {
            if ($e->isConflict()) {
                // Invoiced through another channel (BillTo UI, retry race). Recover the invoice from the order.
                $this->recoverInvoiceFromOrder($order, $billtoId);

                return;
            }

            $this->handleFailure($order, $e, __('Nie udało się wystawić faktury w BillTo', 'billto-woocommerce'));

            return;
        }

        $this->storeInvoice($order, $response['data'] ?? []);
    }

    /**
     * Public page URL of the invoice, fetched on demand: BillTo generates the public token lazily,
     * so the URL may be missing right after issuance and appear on a later read.
     */
    public function ensurePublicUrl(WC_Order $order): string
    {
        $url = (string) $order->get_meta(Meta::INVOICE_PUBLIC_URL);
        $invoiceId = OrderData::invoiceId($order);

        if ($url !== '' || $invoiceId === null) {
            return $url;
        }

        try {
            $url = (string) (($this->client)()->get('invoices/'.$invoiceId)['data']['public_url'] ?? '');
        } catch (ApiException $e) {
            return '';
        }

        if ($url !== '') {
            $order->update_meta_data(Meta::INVOICE_PUBLIC_URL, $url);
            $order->save_meta_data();
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    public function storeInvoice(WC_Order $order, array $invoice): void
    {
        if (empty($invoice['id'])) {
            return;
        }

        $order->update_meta_data(Meta::INVOICE_ID, (string) $invoice['id']);
        $order->update_meta_data(Meta::INVOICE_NUMBER, (string) ($invoice['invoice_number'] ?? ''));
        $order->update_meta_data(Meta::INVOICE_PAID, ! empty($invoice['paid_at']) ? 'yes' : 'no');

        if (empty($invoice['public_url'])) {
            // The mark-paid response carries a compact invoice; the public page URL lives on the full resource.
            try {
                $full = ($this->client)()->get('invoices/'.$invoice['id']);
                $invoice['public_url'] = $full['data']['public_url'] ?? '';
            } catch (ApiException $e) {
                $this->logger->warning(sprintf('Invoice %s details failed: %s', $invoice['id'], $e->getMessage()));
            }
        }

        if (! empty($invoice['public_url'])) {
            $order->update_meta_data(Meta::INVOICE_PUBLIC_URL, (string) $invoice['public_url']);
        }

        $order->save_meta_data();
        OrderData::clearError($order);
        $order->add_order_note(sprintf(
            empty($invoice['paid_at'])
                ? __('BillTo: wystawiono fakturę %s (nieopłacona - wpłata zostanie dopisana po odbiorze).', 'billto-woocommerce')
                : __('BillTo: wystawiono fakturę %s.', 'billto-woocommerce'),
            $invoice['invoice_number'] ?? $invoice['id'],
        ));

        $this->fetchPdf($order, (string) $invoice['id']);

        if ($this->options->ksefAuto() && ($invoice['type'] ?? 'VAT') !== 'OSS') {
            $this->sendToKsef($order);
        }

        // Cash on delivery paid at the same time (e.g. status jumped straight to the settle status).
        if (empty($invoice['paid_at']) && $order->get_status() === $this->options->settleStatus()) {
            Jobs::enqueue(Jobs::HOOK_SETTLE, [$order->get_id()]);
        }

        /**
         * Fires once the invoice for a WooCommerce order exists in BillTo.
         *
         * @param WC_Order $order
         * @param array<string, mixed> $invoice
         */
        do_action('billto_wc_invoice_issued', $order, $invoice);
    }

    /**
     * Records the payment on an invoice issued as unpaid (POST /invoices/{id}/mark-paid).
     *
     * @throws RetryableFailure
     */
    public function settleInvoice(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return;
        }

        $invoiceId = OrderData::invoiceId($order);

        if ($invoiceId === null || (string) $order->get_meta(Meta::INVOICE_PAID) !== 'no') {
            return;
        }

        try {
            ($this->client)()->post('invoices/'.$invoiceId.'/mark-paid', ['paid_at' => gmdate('Y-m-d')], Client::operationKey('order-'.$orderId.'-settle'));
        } catch (ApiException $e) {
            if ($e->isValidation()) {
                // Already paid in BillTo (idempotent on the API side) or a draft - record and stop.
                $order->add_order_note(sprintf(__('BillTo: wpłata nie została dopisana: %s', 'billto-woocommerce'), $e->getMessage()));

                return;
            }

            $this->handleFailure($order, $e, __('Nie udało się dopisać wpłaty do faktury w BillTo', 'billto-woocommerce'));

            return;
        }

        $order->update_meta_data(Meta::INVOICE_PAID, 'yes');
        $order->save_meta_data();
        $order->add_order_note(__('BillTo: faktura oznaczona jako zapłacona.', 'billto-woocommerce'));
    }

    /**
     * Downloads and stores the PDF; failures are logged, never fatal.
     */
    public function fetchPdf(WC_Order $order, string $invoiceId): ?string
    {
        try {
            $content = ($this->client)()->download('invoices/'.$invoiceId.'/pdf');
        } catch (ApiException $e) {
            $this->logger->warning(sprintf('PDF download failed for invoice %s: %s', $invoiceId, $e->getMessage()));

            return null;
        }

        if (! str_starts_with($content, '%PDF')) {
            $this->logger->warning(sprintf('Invoice %s: response is not a PDF', $invoiceId));

            return null;
        }

        $path = $this->pdfStorage->store($order->get_id(), 'invoice', $content);
        $order->update_meta_data(Meta::INVOICE_PDF_PATH, $path);
        $order->save_meta_data();

        return $path;
    }

    /**
     * Submits the order's invoice to KSeF, stores the snapshot and schedules status polling.
     *
     * @return array<string, mixed>|null
     */
    public function sendToKsef(WC_Order $order): ?array
    {
        $invoiceId = OrderData::invoiceId($order);

        if ($invoiceId === null) {
            return null;
        }

        try {
            $response = ($this->client)()->post('invoices/'.$invoiceId.'/ksef', null, Client::operationKey('invoice-'.$invoiceId.'-ksef'));
        } catch (ApiException $e) {
            if ($e->isConflict() || $e->isValidation()) {
                // Already being sent, or rejected - both carry the status snapshot.
                $data = $e->body()['data'] ?? null;

                if (is_array($data)) {
                    $this->storeKsefStatus($order, $data);
                }
            }

            $order->add_order_note(sprintf(__('BillTo KSeF: %s', 'billto-woocommerce'), $e->getMessage()));

            return null;
        }

        $data = $response['data'] ?? [];
        $data = is_array($data) ? $data : [];
        $this->storeKsefStatus($order, $data);

        if (empty($data['ksef_number']) && empty($data['has_unresolved_errors'])) {
            Jobs::enqueue(Jobs::HOOK_KSEF_STATUS, [$order->get_id(), 0], Jobs::KSEF_POLL[0]);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function refreshKsefStatus(WC_Order $order): ?array
    {
        $invoiceId = OrderData::invoiceId($order);

        if ($invoiceId === null) {
            return null;
        }

        try {
            $response = ($this->client)()->get('invoices/'.$invoiceId.'/ksef');
        } catch (ApiException $e) {
            $this->logger->warning(sprintf('KSeF status failed for invoice %s: %s', $invoiceId, $e->getMessage()));

            return null;
        }

        $data = $response['data'] ?? [];
        $this->storeKsefStatus($order, is_array($data) ? $data : []);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeKsefStatus(WC_Order $order, array $data): void
    {
        $number = isset($data['ksef_number']) && is_string($data['ksef_number']) ? $data['ksef_number'] : '';
        $status = $number !== '' ? 'assigned' : (! empty($data['has_unresolved_errors']) ? 'error' : (! empty($data['sent']) ? 'pending' : 'none'));
        $previous = (string) $order->get_meta(Meta::KSEF_STATUS);

        $order->update_meta_data(Meta::KSEF_STATUS, $status);
        $order->update_meta_data(Meta::KSEF_NUMBER, $number);
        $order->save_meta_data();

        if ($status === 'assigned' && $previous !== 'assigned') {
            $order->add_order_note(sprintf(__('BillTo KSeF: nadano numer %s.', 'billto-woocommerce'), $number));
        } elseif ($status === 'error' && $previous !== 'error') {
            $first = $data['errors'][0]['status_description'] ?? null;
            $order->add_order_note(__('BillTo KSeF: wysyłka odrzucona - sprawdź szczegóły w BillTo.', 'billto-woocommerce').(is_string($first) ? ' '.$first : ''));
        }
    }

    /**
     * Cancels the BillTo order when the shop cancels an uninvoiced order. Invoiced orders need a
     * correction instead - handled by RefundSync when a refund is recorded.
     */
    public function cancelBillToOrder(int $orderId): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof WC_Order) {
            return;
        }

        $billtoId = OrderData::billtoOrderId($order);

        if ($billtoId === null) {
            return;
        }

        if (OrderData::invoiceId($order) !== null) {
            $order->add_order_note(__('BillTo: zamówienie ma wystawioną fakturę - anulowanie wymaga faktury korygującej (zarejestruj zwrot).', 'billto-woocommerce'));

            return;
        }

        try {
            ($this->client)()->post('orders/'.$billtoId.'/cancel', null, Client::operationKey('order-'.$orderId.'-cancel'));
            $order->add_order_note(__('BillTo: zamówienie anulowane.', 'billto-woocommerce'));
        } catch (ApiException $e) {
            if ($e->isConflict()) {
                return; // already cancelled/closed
            }

            $this->handleFailure($order, $e, __('Nie udało się anulować zamówienia w BillTo', 'billto-woocommerce'));
        }
    }

    /**
     * 409 on mark-paid: the order already has an invoice. Read it back so the shop shows it.
     */
    private function recoverInvoiceFromOrder(WC_Order $order, string $billtoId): void
    {
        try {
            $response = ($this->client)()->get('orders/'.$billtoId);
        } catch (ApiException $e) {
            $this->handleFailure($order, $e, __('Nie udało się odczytać zamówienia z BillTo', 'billto-woocommerce'));

            return;
        }

        foreach ($response['data']['invoices'] ?? [] as $invoice) {
            if (in_array($invoice['type'] ?? '', ['VAT', 'OSS'], true) && ($invoice['status'] ?? '') !== 'cancelled') {
            // Recovered from the BillTo order - the same document the shop would have issued.
                $this->storeInvoice($order, $invoice);

                return;
            }
        }

        $order->add_order_note(__('BillTo: zamówienie zgłoszone jako zafakturowane, ale nie znaleziono faktury - sprawdź w BillTo.', 'billto-woocommerce'));
    }

    /**
     * Transient errors re-queue the job; permanent ones are written to the order as a note and meta.
     *
     * @throws RetryableFailure
     */
    private function handleFailure(WC_Order $order, ApiException $e, string $context): void
    {
        if ($e->isRetryable()) {
            OrderData::rememberError($order, $context.': '.$e->getMessage());

            throw new RetryableFailure($e->getMessage(), 0, $e);
        }

        $details = $e->isValidation() ? implode('; ', $e->validationMessages()) : '';
        $message = $context.': '.$e->getMessage().($details !== '' ? ' ('.$details.')' : '');

        OrderData::rememberError($order, $message);
        $order->add_order_note('BillTo: '.$message);
        $this->logger->error(sprintf('Order #%d: %s', $order->get_id(), $message));
    }
}
