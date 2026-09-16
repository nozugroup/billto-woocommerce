<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Support;

/**
 * WooCommerce logger wrapper (WooCommerce -> Status -> Logs, source "billto-wc").
 */
final class Logger
{
    private const SOURCE = 'billto-wc';

    public function info(string $message): void
    {
        $this->log('info', $message);
    }

    public function warning(string $message): void
    {
        $this->log('warning', $message);
    }

    public function error(string $message): void
    {
        $this->log('error', $message);
    }

    private function log(string $level, string $message): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->log($level, $message, ['source' => self::SOURCE]);
    }
}
