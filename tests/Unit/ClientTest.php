<?php

declare(strict_types=1);

use BillTo\WooCommerce\Api\ApiException;
use BillTo\WooCommerce\Api\Client;
use BillTo\WooCommerce\Support\Logger;
use BillTo\WooCommerce\Tests\Support\FakeHttp;
use Brain\Monkey\Functions;

/**
 * @param  list<array{code: int, body: string, headers?: array<string, string>}|object>  $responses
 */
function clientWithResponses(array $responses): Client
{
    FakeHttp::queue($responses);

    Functions\when('is_wp_error')->alias(static fn ($thing) => $thing instanceof WP_Error);
    Functions\when('wp_remote_retrieve_response_code')->alias(static fn ($r) => $r['response']['code']);
    Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['body']);
    Functions\when('wp_remote_retrieve_header')->alias(static fn ($r, $h) => $r['headers'][strtolower($h)] ?? '');
    Functions\when('wc_get_logger')->justReturn(new class
    {
        public function log(): void {}
    });

    return new class('tok', 'https://api.test/api/v1', new Logger) extends Client
    {
        /** @var list<int> */
        public array $pauses = [];

        protected function pause(int $seconds): void
        {
            $this->pauses[] = $seconds;
        }
    };
}

it('sends bearer auth, JSON body and an idempotency key on POST', function () {
    $client = clientWithResponses([['code' => 201, 'body' => '{"data":{"id":"o1"}}']]);

    $result = $client->post('orders', ['external_id' => '42'], 'key-42');
    $sent = FakeHttp::$sent;

    expect($result['data']['id'])->toBe('o1')
        ->and($sent[0]['url'])->toBe('https://api.test/api/v1/orders')
        ->and($sent[0]['args']['method'])->toBe('POST')
        ->and($sent[0]['args']['headers']['Authorization'])->toBe('Bearer tok')
        ->and($sent[0]['args']['headers']['Idempotency-Key'])->toBe('key-42')
        ->and($sent[0]['args']['headers']['Content-Type'])->toBe('application/json')
        ->and($sent[0]['args']['body'])->toBe('{"external_id":"42"}');
});

it('generates an idempotency key when none is given and omits it on GET', function () {
    $client = clientWithResponses([
        ['code' => 201, 'body' => '{"data":{}}'],
        ['code' => 200, 'body' => '{"data":[]}'],
    ]);

    $client->post('orders', []);
    $client->get('invoice-series', ['type' => 'VAT', 'active_only' => false, 'skip' => null]);
    $sent = FakeHttp::$sent;

    expect($sent[0]['args']['headers']['Idempotency-Key'])->toBe('00000000-0000-4000-8000-000000000000')
        ->and(isset($sent[1]['args']['headers']['Idempotency-Key']))->toBeFalse()
        ->and($sent[1]['url'])->toBe('https://api.test/api/v1/invoice-series?type=VAT&active_only=0');
});

it('maps error responses to ApiException with the API message', function () {
    $client = clientWithResponses([['code' => 422, 'body' => '{"message":"Podane dane sa nieprawidlowe.","errors":{"items":["req"]}}']]);

    try {
        $client->post('orders', []);
        $this->fail('expected exception');
    } catch (ApiException $e) {
        expect($e->status())->toBe(422)
            ->and($e->getMessage())->toBe('Podane dane sa nieprawidlowe.')
            ->and($e->validationMessages())->toBe(['items: req']);
    }
});

it('retries once on a transient failure, honouring Retry-After, and gives up after that', function () {
    $client = clientWithResponses([
        ['code' => 503, 'body' => '', 'headers' => ['retry-after' => '3']],
        ['code' => 200, 'body' => '{"data":{"ok":true}}'],
    ]);

    expect($client->get('orders/1')['data']['ok'])->toBeTrue()
        ->and(FakeHttp::$sent)->toHaveCount(2)
        ->and($client->pauses)->toBe([3]);

    $client = clientWithResponses([
        ['code' => 503, 'body' => ''],
        ['code' => 503, 'body' => ''],
    ]);

    expect(fn () => $client->get('orders/1'))->toThrow(ApiException::class);
    expect(FakeHttp::$sent)->toHaveCount(2);
});

it('does not retry a plan-limit 429 or a 4xx', function () {
    $client = clientWithResponses([['code' => 429, 'body' => '{"message":"limit","usage":{"used":5,"limit":5}}']]);
    expect(fn () => $client->post('orders/1/mark-paid'))->toThrow(ApiException::class, 'limit');
    expect(FakeHttp::$sent)->toHaveCount(1);

    $client = clientWithResponses([['code' => 409, 'body' => '{"message":"already"}']]);
    expect(fn () => $client->post('orders/1/mark-paid'))->toThrow(ApiException::class, 'already');
    expect(FakeHttp::$sent)->toHaveCount(1);
});

it('wraps network errors as status 0', function () {
    $error = Mockery::mock('WP_Error');
    $error->shouldReceive('get_error_message')->andReturn('timeout');
    $client = clientWithResponses([$error, $error]);

    try {
        $client->get('orders');
        $this->fail('expected exception');
    } catch (ApiException $e) {
        expect($e->isTransport())->toBeTrue()->and($e->getMessage())->toBe('timeout')->and(FakeHttp::$sent)->toHaveCount(2);
    }
});

it('derives stable per-site operation keys', function () {
    expect(Client::operationKey('order-1-mark-paid'))->toBe(Client::operationKey('order-1-mark-paid'))
        ->and(Client::operationKey('order-1-mark-paid'))->not->toBe(Client::operationKey('order-2-mark-paid'));
});

// ── API identification header ────────────────────────────────────────────────

it('identifies the plugin, not the WordPress runtime', function () {
    $header = \BillTo\WooCommerce\Api\Client::userAgent();

    // The product segment must name THIS plugin: BillTo uses it to reach the vendor before a
    // breaking change, and "WordPress/6.7" is shared by thousands of unrelated integrations.
    expect($header)->toStartWith('billto-woocommerce/'.BILLTO_WC_VERSION.' (+');
});

it('carries a contact and a compat declaration', function () {
    $header = \BillTo\WooCommerce\Api\Client::userAgent();

    // Without a contact the header names a product nobody can be notified about - the API
    // treats such a header as unidentified.
    expect($header)->toContain('(+https://billto.pl/integracje/woocommerce')
        ->and($header)->toMatch('/compat=\d{4}-\d{2}-\d{2}\)/');
});

it('appends platform versions as diagnostic tokens after the metadata group', function () {
    $header = \BillTo\WooCommerce\Api\Client::userAgent();

    $metaEnd = strpos($header, ')');

    expect($header)->toContain('WordPress/6.7')
        ->and(strpos($header, 'WordPress/'))->toBeGreaterThan($metaEnd);
});

it('sends the identification header on every request', function () {
    $captured = null;
    Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$captured) {
        $captured = $args['headers']['User-Agent'] ?? null;

        return ['response' => ['code' => 200], 'body' => '{"data":[]}'];
    });

    (new \BillTo\WooCommerce\Api\Client('token', 'https://billto.test/api/v1', new \BillTo\WooCommerce\Support\Logger))
        ->get('/invoices');

    expect($captured)->toBe(\BillTo\WooCommerce\Api\Client::userAgent());
});
