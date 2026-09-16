<?php

declare(strict_types=1);

use BillTo\Shop\OAuth\HttpPostInterface;
use BillTo\Shop\OAuth\OAuthClient;
use BillTo\WooCommerce\Api\OAuth\Connection;
use BillTo\WooCommerce\Support\Logger;
use BillTo\WooCommerce\Support\Options;
use Brain\Monkey\Functions;

/** Returns queued responses in order and records what was asked for. */
final class QueuedPost implements HttpPostInterface
{
    /** @var array<int, array{status: int, body: string}|null> */
    private array $queue;

    /** @var array<int, array{url: string, fields: array<string, string>}> */
    public array $calls = [];

    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function post(string $url, array $fields, array $headers = []): ?array
    {
        $this->calls[] = ['url' => $url, 'fields' => $fields];

        return array_shift($this->queue);
    }
}

/**
 * In-memory WordPress options, so `update_option` and `get_option` share one store.
 */
function stubOptionStore(array $initial = []): array
{
    $store = new ArrayObject($initial);

    Functions\when('get_option')->alias(static fn (string $key, $default = false) => $store[$key] ?? $default);
    Functions\when('update_option')->alias(static function (string $key, $value) use ($store): bool {
        $store[$key] = $value;

        return true;
    });
    Functions\when('delete_option')->alias(static function (string $key) use ($store): bool {
        unset($store[$key]);

        return true;
    });
    // The logger calls wc_get_logger(); the stub keeps the error paths from fataling.
    Functions\when('wc_get_logger')->justReturn(new class
    {
        public function log(string $level, string $message, array $context = []): void {}
    });

    Functions\when('home_url')->justReturn('https://sklep.test');
    Functions\when('wp_parse_url')->alias(static fn (string $url, int $component = -1) => parse_url($url, $component));

    return [$store];
}

function connectionWith(HttpPostInterface $http): Connection
{
    return new Connection(new Logger, new OAuthClient('https://billto.test', $http));
}

$p = Options::PREFIX;

it('registers the installation only once', function () use ($p) {
    [$store] = stubOptionStore();

    $http = new QueuedPost([
        ['status' => 201, 'body' => json_encode(['client_id' => 'cid', 'client_secret' => 'sec'])],
    ]);

    $connection = connectionWith($http);

    expect($connection->ensureRegistered('3b8cacc7-eeec', 'blti_KOD', 'https://sklep.test/cb'))->toBeTrue()
        ->and($store[$p.'oauth_client_id'])->toBe('cid')
        // The second attempt makes no request: the queue holds a single response.
        ->and($connection->ensureRegistered('3b8cacc7-eeec', 'blti_KOD', 'https://sklep.test/cb'))->toBeTrue()
        ->and($http->calls)->toHaveCount(1)
        // Registration sends both the software statement id and the merchant's code.
        ->and($http->calls[0]['fields']['software_statement_id'])->toBe('3b8cacc7-eeec')
        ->and($http->calls[0]['fields']['code'])->toBe('blti_KOD');
});

it('stores no credentials when BillTo rejects the registration', function () use ($p) {
    [$store] = stubOptionStore();

    // 403 covers a wrong, used or expired code, and a vendor without DCR access.
    $connection = connectionWith(new QueuedPost([['status' => 403, 'body' => '{"error":"invalid_code"}']]));

    expect($connection->ensureRegistered('3b8cacc7-eeec', 'zle', 'https://sklep.test/cb'))->toBeFalse()
        ->and(isset($store[$p.'oauth_client_id']))->toBeFalse();
});

it('returns a valid token without calling BillTo', function () use ($p) {
    stubOptionStore([
        $p.'oauth_client_id' => 'cid',
        $p.'oauth_client_secret' => 'sec',
        $p.'oauth_access_token' => 'wazny',
        $p.'oauth_refresh_token' => 'rt',
        $p.'oauth_expires_at' => time() + 3600,
    ]);

    $http = new QueuedPost([]);

    expect(connectionWith($http)->accessToken())->toBe('wazny')
        ->and($http->calls)->toHaveCount(0);
});

it('refreshes the token before it expires and stores the new pair', function () use ($p) {
    [$store] = stubOptionStore([
        $p.'oauth_client_id' => 'cid',
        $p.'oauth_client_secret' => 'sec',
        $p.'oauth_access_token' => 'stary',
        $p.'oauth_refresh_token' => 'stary-rt',
        // Expires in two minutes: still valid, but inside the refresh margin.
        $p.'oauth_expires_at' => time() + 120,
    ]);

    $http = new QueuedPost([
        ['status' => 200, 'body' => json_encode(['access_token' => 'nowy', 'refresh_token' => 'nowy-rt', 'expires_in' => 43200])],
    ]);

    expect(connectionWith($http)->accessToken())->toBe('nowy')
        ->and($http->calls[0]['url'])->toBe('https://billto.test/oauth/token')
        ->and($http->calls[0]['fields']['grant_type'])->toBe('refresh_token')
        // The refresh token is single use, so the new pair has to be stored.
        ->and($store[$p.'oauth_access_token'])->toBe('nowy')
        ->and($store[$p.'oauth_refresh_token'])->toBe('nowy-rt');
});

it('reports no access when the refresh is rejected', function () use ($p) {
    stubOptionStore([
        $p.'oauth_client_id' => 'cid',
        $p.'oauth_client_secret' => 'sec',
        $p.'oauth_access_token' => 'stary',
        $p.'oauth_refresh_token' => 'uniewazniony',
        $p.'oauth_expires_at' => time() - 10,
    ]);

    expect(connectionWith(new QueuedPost([['status' => 400, 'body' => '{"error":"invalid_grant"}']]))->accessToken())
        ->toBeNull();
});

it('reports no access when there is no refresh token', function () use ($p) {
    stubOptionStore([
        $p.'oauth_access_token' => 'stary',
        $p.'oauth_refresh_token' => '',
        $p.'oauth_expires_at' => time() - 10,
    ]);

    expect(connectionWith(new QueuedPost([]))->accessToken())->toBeNull();
});

it('does not report a disconnected shop as connected', function () {
    stubOptionStore();

    $connection = connectionWith(new QueuedPost([]));

    expect($connection->isConnected())->toBeFalse()
        ->and($connection->accessToken())->toBeNull();
});

it('clears the tokens on disconnect but keeps the installation credentials', function () use ($p) {
    [$store] = stubOptionStore([
        $p.'oauth_client_id' => 'cid',
        $p.'oauth_client_secret' => 'sec',
        $p.'oauth_access_token' => 'at',
        $p.'oauth_refresh_token' => 'rt',
        $p.'oauth_expires_at' => time() + 3600,
    ]);

    $connection = connectionWith(new QueuedPost([]));
    $connection->disconnect();

    expect($connection->isConnected())->toBeFalse()
        ->and($store[$p.'oauth_client_id'])->toBe('cid')
        ->and($store[$p.'oauth_client_secret'])->toBe('sec');
});
