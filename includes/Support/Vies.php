<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Support;

use BillTo\Shop\Vies\HttpGetInterface;
use BillTo\Shop\Vies\Vies as CoreVies;

/**
 * VIES check through the shared core, with the WordPress HTTP API as transport.
 */
final class Vies implements HttpGetInterface
{
    public const VALID = CoreVies::VALID;

    public const INVALID = CoreVies::INVALID;

    public const UNAVAILABLE = CoreVies::UNAVAILABLE;

    /**
     * @return array{status: string, name: string|null, checked_at: string}
     */
    public static function check(string $vatId): array
    {
        /** Lets tests and sites replace the lookup (e.g. a cached or mocked result). */
        $override = apply_filters('billto_wc_vies_result', null, $vatId);

        if (is_array($override) && isset($override['status'])) {
            return ['status' => (string) $override['status'], 'name' => $override['name'] ?? null, 'checked_at' => gmdate('c')];
        }

        return CoreVies::check($vatId, new self);
    }

    /**
     * @return array{status: int, body: string}|null
     */
    public function get(string $url): ?array
    {
        $response = wp_remote_get($url, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response)) {
            return null;
        }

        return ['status' => (int) wp_remote_retrieve_response_code($response), 'body' => (string) wp_remote_retrieve_body($response)];
    }
}
