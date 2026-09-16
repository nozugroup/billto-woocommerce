<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Api\OAuth;

use BillTo\Shop\OAuth\HttpPostInterface;

/**
 * The shared core's HTTP POST, implemented on the WordPress HTTP API.
 *
 * Uses `wp_remote_post`, so the request follows the proxy and TLS configuration of the host
 * and the plugin ships no HTTP client of its own.
 */
final class WpHttpPost implements HttpPostInterface
{
    private const TIMEOUT = 20;

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}|null
     */
    public function post(string $url, array $fields, array $headers = []): ?array
    {
        $response = wp_remote_post($url, [
            'timeout' => self::TIMEOUT,
            'headers' => array_merge(['User-Agent' => \BillTo\WooCommerce\Api\Client::userAgent()], $headers),
            'body' => $fields,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }
}
