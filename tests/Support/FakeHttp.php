<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Tests\Support;

/**
 * Backs the stubbed wp_remote_request(): queue of canned responses, log of sent requests.
 */
final class FakeHttp
{
    /** @var list<array{code: int, body: string, headers?: array<string, string>}|object> */
    public static array $queue = [];

    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $sent = [];

    public static function reset(): void
    {
        self::$queue = [];
        self::$sent = [];
    }

    /**
     * @param  list<array{code: int, body: string, headers?: array<string, string>}|object>  $responses
     */
    public static function queue(array $responses): void
    {
        self::$queue = $responses;
        self::$sent = [];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|object
     */
    public static function handle(string $url, array $args)
    {
        self::$sent[] = ['url' => $url, 'args' => $args];
        $next = array_shift(self::$queue);

        if ($next === null) {
            throw new \LogicException('FakeHttp: no queued response for '.$url);
        }

        if (is_object($next)) {
            return $next; // WP_Error mock
        }

        return ['response' => ['code' => $next['code']], 'body' => $next['body'], 'headers' => $next['headers'] ?? []];
    }
}
