<?php

declare(strict_types=1);

use BillTo\WooCommerce\Support\Vies;
use Brain\Monkey\Functions;

it('rejects malformed ids without calling the service', function () {
    Functions\expect('wp_remote_get')->never();

    expect(Vies::check('12345')['status'])->toBe(Vies::INVALID);
});

it('parses the VIES REST response', function (array $body, int $code, string $expected) {
    $encoded = json_encode($body);
    Functions\when('wp_remote_get')->justReturn(['response' => ['code' => $code], 'body' => $encoded]);
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
    Functions\when('wp_remote_retrieve_body')->justReturn($encoded);

    $result = Vies::check('DE123456789');

    expect($result['status'])->toBe($expected);
})->with([
    'valid' => [['isValid' => true, 'name' => 'Beispiel GmbH', 'userError' => 'VALID'], 200, Vies::VALID],
    'invalid' => [['isValid' => false, 'userError' => 'INVALID'], 200, Vies::INVALID],
    'member state down' => [['isValid' => false, 'userError' => 'MS_UNAVAILABLE'], 200, Vies::UNAVAILABLE],
    'http 503' => [[], 503, Vies::UNAVAILABLE],
]);

it('maps Greece to the EL prefix used by VIES', function () {
    $urls = [];
    Functions\when('wp_remote_get')->alias(static function (string $url) use (&$urls) {
        $urls[] = $url;

        return ['response' => ['code' => 200], 'body' => '{"isValid":true}'];
    });
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
    Functions\when('wp_remote_retrieve_body')->justReturn('{"isValid":true}');

    Vies::check('GR123456789');

    expect($urls[0])->toContain('/ms/EL/vat/123456789');
});

it('lets a site override the lookup through a filter', function () {
    Functions\when('apply_filters')->alias(static fn (string $hook, $value, ...$args) => $hook === 'billto_wc_vies_result' ? ['status' => Vies::VALID, 'name' => 'X'] : $value);

    expect(Vies::check('DE123456789'))->toMatchArray(['status' => Vies::VALID, 'name' => 'X']);
});
