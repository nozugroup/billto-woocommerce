<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Api;

use BillTo\WooCommerce\Support\Logger;

/**
 * Thin BillTo API v1 client on top of the WordPress HTTP API.
 *
 * Deliberately not the billto/billto-php SDK: that package needs PHP 8.2 and a bundled PSR-18
 * client, while WordPress hosts run PHP 8.0/8.1 and load many plugins that would clash on a
 * shared Guzzle. The behaviours worth keeping are re-implemented here: Bearer auth, an
 * Idempotency-Key on every mutation, one retry for transient failures, typed errors.
 */
class Client
{
    private const TIMEOUT = 30;

    /**
     * BillTo API version this plugin release was tested against, sent as the `compat` segment
     * of the User-Agent. Informational: it targets notifications about upcoming API changes and
     * never selects API behaviour. Bump it when a release is verified against a newer API.
     */
    private const API_COMPAT = '2026-09-15';

    public function __construct(
        private string $token,
        private string $baseUrl,
        private Logger $logger,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->token) !== '';
    }

    /**
     * Identification required by the BillTo API.
     *
     * The plugin names ITSELF here, not WordPress: BillTo uses this header to reach the vendor
     * of the software before a backwards-incompatible change, and `WordPress/6.5` identifies the
     * runtime that thousands of unrelated integrations share. The shop's platform versions are
     * appended as diagnostic tokens - the API ignores them when matching, but they shorten
     * support conversations.
     *
     * The contact must stay reachable: BillTo may suspend access when notifications bounce.
     */
    public static function userAgent(): string
    {
        return \BillTo\Shop\UserAgent::build(
            'billto-woocommerce',
            BILLTO_WC_VERSION,
            'https://billto.pl/integracje/woocommerce',
            self::API_COMPAT,
            [
                'WordPress/'.get_bloginfo('version'),
                'WooCommerce/'.(defined('WC_VERSION') ? WC_VERSION : '?'),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    public function post(string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $path, [], $body ?? [], $idempotencyKey ?? self::randomKey());
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body, ?string $idempotencyKey = null): array
    {
        return $this->request('PUT', $path, [], $body, $idempotencyKey ?? self::randomKey());
    }

    /**
     * Raw binary download (PDF / XML). Returns the body string.
     */
    public function download(string $path): string
    {
        $response = $this->send('GET', $path, [], null, null, '*/*');
        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status >= 400) {
            throw $this->exceptionFromResponse($response, $status);
        }

        return (string) wp_remote_retrieve_body($response);
    }

    /**
     * Deterministic idempotency key for a business operation (e.g. "order-42-mark-paid"),
     * namespaced per site so two shops sharing one BillTo team never collide.
     */
    public static function operationKey(string $operation): string
    {
        return hash('sha256', get_site_url().'|billto-wc|'.$operation);
    }

    public static function randomKey(): string
    {
        return wp_generate_uuid4();
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null, ?string $idempotencyKey = null): array
    {
        $attempt = 0;

        while (true) {
            $response = $this->send($method, $path, $query, $body, $idempotencyKey);

            if (is_wp_error($response)) {
                $exception = new ApiException($response->get_error_message(), 0);
            } else {
                $status = (int) wp_remote_retrieve_response_code($response);

                if ($status < 400) {
                    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

                    return is_array($decoded) ? $decoded : [];
                }

                $exception = $this->exceptionFromResponse($response, $status);
            }

            // Only idempotent requests are retried: every mutation carries an Idempotency-Key, GETs are safe.
            if ($attempt === 0 && $exception->isRetryable()) {
                $attempt++;
                $retryAfter = is_wp_error($response) ? 1 : (int) wp_remote_retrieve_header($response, 'retry-after');
                $this->pause(max(1, min($retryAfter, 5)));

                continue;
            }

            $this->logger->error(sprintf('%s %s failed: HTTP %d %s', $method, $path, $exception->status(), $exception->getMessage()));

            throw $exception;
        }
    }

    /** Wait before a retry. Overridable so tests do not sleep. */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|\WP_Error
     */
    private function send(string $method, string $path, array $query, ?array $body, ?string $idempotencyKey, string $accept = 'application/json')
    {
        $url = rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
        $query = array_filter($query, static fn ($value) => $value !== null);

        if ($query !== []) {
            $url = add_query_arg(array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $query), $url);
        }

        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => $accept,
            'User-Agent' => self::userAgent(),
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $args = [
            'method' => $method,
            'timeout' => self::TIMEOUT,
            'headers' => $headers,
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return wp_remote_request($url, $args);
    }

    /**
     * @param  array<string, mixed>|\WP_Error  $response
     */
    private function exceptionFromResponse($response, int $status): ApiException
    {
        if (is_wp_error($response)) {
            return new ApiException($response->get_error_message(), 0);
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($decoded) ? $decoded : [];
        $message = isset($body['message']) && is_string($body['message']) ? $body['message'] : self::defaultMessage($status);

        return new ApiException($message, $status, $body);
    }

    private static function defaultMessage(int $status): string
    {
        return match (true) {
            $status === 401 => __('Nieprawidłowy lub nieaktywny token API BillTo.', 'billto-woocommerce'),
            $status === 403 => __('Token API nie ma wymaganych uprawnień.', 'billto-woocommerce'),
            $status === 404 => __('Zasób nie istnieje w BillTo.', 'billto-woocommerce'),
            $status === 429 => __('Przekroczono limit zapytań do API BillTo.', 'billto-woocommerce'),
            $status >= 500 => __('Błąd serwera BillTo.', 'billto-woocommerce'),
            default => sprintf(__('Błąd API BillTo (HTTP %d).', 'billto-woocommerce'), $status),
        };
    }
}
