<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Support;

use BillTo\Shop\Nip as CoreNip;

/**
 * Thin alias over the shared core so existing call sites keep working.
 */
final class Nip
{
    public static function normalize(string $value): string
    {
        return CoreNip::normalize($value);
    }

    public static function isValid(string $value): bool
    {
        return CoreNip::isValid($value);
    }

    public static function normalizeEuVat(string $value): ?string
    {
        return CoreNip::normalizeEuVat($value);
    }
}
