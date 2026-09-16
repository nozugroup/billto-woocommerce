<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Sync;

use BillTo\WooCommerce\Support\Logger;

/**
 * Background execution through Action Scheduler (bundled with WooCommerce), with a retry
 * schedule for transient API failures. Falls back to inline execution when AS is unavailable.
 */
final class Jobs
{
    public const GROUP = 'billto-wc';

    public const HOOK_SYNC_ORDER = 'billto_wc_sync_order';

    public const HOOK_INVOICE = 'billto_wc_invoice_order';

    public const HOOK_REFUND = 'billto_wc_refund';

    public const HOOK_CANCEL = 'billto_wc_cancel_order';

    /** Records the payment on an invoice issued as unpaid (cash on delivery). */
    public const HOOK_SETTLE = 'billto_wc_settle_invoice';

    /** Polls the KSeF status until a number is assigned or an error is reported. */
    public const HOOK_KSEF_STATUS = 'billto_wc_ksef_status';

    /** Retry delays in seconds: 1 min, 5 min, 30 min, 2 h, 12 h. */
    private const BACKOFF = [60, 300, 1800, 7200, 43200];

    /** KSeF poll delays in seconds: 1 min, 5 min, 30 min, 2 h. */
    public const KSEF_POLL = [60, 300, 1800, 7200];

    public function __construct(
        private OrderSync $orderSync,
        private RefundSync $refundSync,
        private Logger $logger,
    ) {}

    public function register(): void
    {
        add_action(self::HOOK_SYNC_ORDER, [$this, 'runSyncOrder'], 10, 2);
        add_action(self::HOOK_INVOICE, [$this, 'runInvoice'], 10, 2);
        add_action(self::HOOK_REFUND, [$this, 'runRefund'], 10, 3);
        add_action(self::HOOK_CANCEL, [$this, 'runCancel'], 10, 2);
        add_action(self::HOOK_SETTLE, [$this, 'runSettle'], 10, 2);
        add_action(self::HOOK_KSEF_STATUS, [$this, 'runKsefStatus'], 10, 2);
    }

    /**
     * Queues a job; deduplicates identical pending jobs.
     *
     * @param  array<int, mixed>  $args
     */
    public static function enqueue(string $hook, array $args, int $delaySeconds = 0): void
    {
        if (! function_exists('as_schedule_single_action')) {
            do_action_ref_array($hook, $args);

            return;
        }

        if ($delaySeconds === 0 && function_exists('as_has_scheduled_action') && as_has_scheduled_action($hook, $args, self::GROUP)) {
            return;
        }

        as_schedule_single_action(time() + $delaySeconds, $hook, $args, self::GROUP);
    }

    public function runSyncOrder(int $orderId, int $attempt = 0): void
    {
        $this->attempt(self::HOOK_SYNC_ORDER, [$orderId], $attempt, fn () => $this->orderSync->createOrUpdateBillToOrder($orderId));
    }

    public function runInvoice(int $orderId, int $attempt = 0): void
    {
        $this->attempt(self::HOOK_INVOICE, [$orderId], $attempt, fn () => $this->orderSync->invoiceOrder($orderId));
    }

    public function runRefund(int $orderId, int $refundId, int $attempt = 0): void
    {
        $this->attempt(self::HOOK_REFUND, [$orderId, $refundId], $attempt, fn () => $this->refundSync->correctForRefund($orderId, $refundId));
    }

    public function runCancel(int $orderId, int $attempt = 0): void
    {
        $this->attempt(self::HOOK_CANCEL, [$orderId], $attempt, fn () => $this->orderSync->cancelBillToOrder($orderId));
    }

    public function runSettle(int $orderId, int $attempt = 0): void
    {
        $this->attempt(self::HOOK_SETTLE, [$orderId], $attempt, fn () => $this->orderSync->settleInvoice($orderId));
    }

    /**
     * KSeF polling is not a retry loop but a schedule: re-queued while the submission is pending.
     */
    public function runKsefStatus(int $orderId, int $poll = 0): void
    {
        $order = wc_get_order($orderId);

        if (! $order instanceof \WC_Order) {
            return;
        }

        $status = $this->orderSync->refreshKsefStatus($order);
        $pending = $status !== null && empty($status['ksef_number']) && empty($status['has_unresolved_errors']) && ! empty($status['sent']);

        if ($pending && isset(self::KSEF_POLL[$poll + 1])) {
            self::enqueue(self::HOOK_KSEF_STATUS, [$orderId, $poll + 1], self::KSEF_POLL[$poll + 1]);
        }
    }

    /**
     * @param  array<int, mixed>  $args
     * @param  callable(): mixed  $work
     */
    private function attempt(string $hook, array $args, int $attempt, callable $work): void
    {
        try {
            $work();
        } catch (RetryableFailure $e) {
            if (! isset(self::BACKOFF[$attempt])) {
                $this->logger->error(sprintf('%s %s: giving up after %d attempts (%s)', $hook, wp_json_encode($args), $attempt + 1, $e->getMessage()));

                return;
            }

            $this->logger->warning(sprintf('%s %s: attempt %d failed (%s), retry in %ds', $hook, wp_json_encode($args), $attempt + 1, $e->getMessage(), self::BACKOFF[$attempt]));
            self::enqueue($hook, [...$args, $attempt + 1], self::BACKOFF[$attempt]);
        }
    }
}
