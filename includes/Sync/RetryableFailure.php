<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Sync;

/**
 * Thrown by sync steps when the failure is transient (network, 5xx, throttling) and the job
 * should be re-queued with backoff. Permanent failures (validation, conflicts) are recorded on
 * the order as notes and do not throw.
 */
final class RetryableFailure extends \RuntimeException {}
