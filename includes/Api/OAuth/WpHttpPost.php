<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Api\OAuth;

use BillTo\Shop\OAuth\HttpPostInterface;

/**
 * The shared core's HTTP POST, implemented on the WordPress HTTP API.
 *
 * Same reasoning as the rest of this plugin: WordPress hosts run their own, version-dependent
 * stack and load many plugins that would clash on a bundled Guzzle. `wp_remote_post` also
 * respects whatever proxy or TLS configuration the host has set, which a bundled client
 * would quietly ignore.
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
