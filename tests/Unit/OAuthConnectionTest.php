<?php

declare(strict_types=1);

use BillTo\Shop\OAuth\HttpPostInterface;
use BillTo\Shop\OAuth\OAuthClient;
use BillTo\WooCommerce\Api\OAuth\Connection;
use BillTo\WooCommerce\Support\Logger;
use BillTo\WooCommerce\Support\Options;
use Brain\Monkey\Functions;

/** Zwraca zaplanowane odpowiedzi po kolei i zapamiętuje, o co pytano. */
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
 * Opcje WordPressa w pamięci - `update_option` i `get_option` muszą widzieć te same dane,
 * inaczej test odświeżania nigdy nie zobaczyłby zapisanej nowej pary tokenów.
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
    // Logger sięga po wc_get_logger(); bez atrapy z metodą log() ścieżki błędów wywracają się
    // na "call to a member function on null" i test mierzyłby brak atrapy, nie zachowanie.
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

it('rejestruje instalację tylko raz', function () use ($p) {
    // Gdyby rejestrowała za każdym razem, każde kliknięcie "Połącz" zostawiałoby w rejestrze
    // BillTo osierocony wpis, a sklep gubiłby wcześniejsze poświadczenia.
    [$store] = stubOptionStore();

    $http = new QueuedPost([
        ['status' => 201, 'body' => json_encode(['client_id' => 'cid', 'client_secret' => 'sec'])],
    ]);

    $connection = connectionWith($http);

    expect($connection->ensureRegistered('3b8cacc7-eeec', 'blti_KOD', 'https://sklep.test/cb'))->toBeTrue()
        ->and($store[$p.'oauth_client_id'])->toBe('cid')
        // Druga próba nie dotyka sieci - kolejka ma tylko jedną odpowiedź.
        ->and($connection->ensureRegistered('3b8cacc7-eeec', 'blti_KOD', 'https://sklep.test/cb'))->toBeTrue()
        ->and($http->calls)->toHaveCount(1)
        // Identyfikator mówi KTO, kod mówi NA CZYJE dane. Żadne z osobna nie wystarcza.
        ->and($http->calls[0]['fields']['software_statement_id'])->toBe('3b8cacc7-eeec')
        ->and($http->calls[0]['fields']['code'])->toBe('blti_KOD');
});

it('nie zapisuje poświadczeń, gdy BillTo odrzuci rejestrację', function () use ($p) {
    [$store] = stubOptionStore();

    // 403 = zły, zużyty albo wygasły kod - albo dostawca, któremu nie nadaliśmy DCR.
    $connection = connectionWith(new QueuedPost([['status' => 403, 'body' => '{"error":"invalid_code"}']]));

    expect($connection->ensureRegistered('3b8cacc7-eeec', 'zle', 'https://sklep.test/cb'))->toBeFalse()
        ->and(isset($store[$p.'oauth_client_id']))->toBeFalse();
});

it('oddaje ważny token bez odpytywania BillTo', function () use ($p) {
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

it('odświeża token ZANIM wygaśnie i zapisuje nową parę', function () use ($p) {
    // Odświeżanie dopiero po 401 kosztuje jedno nieudane żądanie na każde wygaśnięcie -
    // a jeśli to było wystawienie faktury, sklep zdążył już powiedzieć klientowi, że się nie udało.
    [$store] = stubOptionStore([
        $p.'oauth_client_id' => 'cid',
        $p.'oauth_client_secret' => 'sec',
        $p.'oauth_access_token' => 'stary',
        $p.'oauth_refresh_token' => 'stary-rt',
        // Wygasa za 2 minuty - jeszcze ważny, ale w marginesie odświeżania.
        $p.'oauth_expires_at' => time() + 120,
    ]);

    $http = new QueuedPost([
        ['status' => 200, 'body' => json_encode(['access_token' => 'nowy', 'refresh_token' => 'nowy-rt', 'expires_in' => 43200])],
    ]);

    expect(connectionWith($http)->accessToken())->toBe('nowy')
        ->and($http->calls[0]['url'])->toBe('https://billto.test/oauth/token')
        ->and($http->calls[0]['fields']['grant_type'])->toBe('refresh_token')
        // Token odświeżania jest jednorazowy - bez zapisania nowej pary integracja zablokowałaby
        // się przy kolejnym żądaniu.
        ->and($store[$p.'oauth_access_token'])->toBe('nowy')
        ->and($store[$p.'oauth_refresh_token'])->toBe('nowy-rt');
});

it('zgłasza brak dostępu, gdy odświeżenie zostanie odrzucone', function () use ($p) {
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

it('zgłasza brak dostępu, gdy nie ma czym odświeżyć', function () use ($p) {
    stubOptionStore([
        $p.'oauth_access_token' => 'stary',
        $p.'oauth_refresh_token' => '',
        $p.'oauth_expires_at' => time() - 10,
    ]);

    expect(connectionWith(new QueuedPost([]))->accessToken())->toBeNull();
});

it('sklep bez połączenia nie udaje połączonego', function () {
    stubOptionStore();

    $connection = connectionWith(new QueuedPost([]));

    expect($connection->isConnected())->toBeFalse()
        ->and($connection->accessToken())->toBeNull();
});

it('odłączenie kasuje tokeny, ale zostawia poświadczenia instalacji', function () use ($p) {
    // Odłączenie to cofnięcie zgody na firmę, nie wyrejestrowanie sklepu - ponowne połączenie
    // ma pominąć rejestrację, zamiast zakładać w BillTo drugi wpis.
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
