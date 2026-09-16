<?php

declare(strict_types=1);

use BillTo\WooCommerce\Support\UpdateChecker;
use Brain\Monkey\Functions;

/**
 * @param  array{code: int, body: string}  $response
 */
function updateCheckerWith(array $response, mixed $cached = false): UpdateChecker
{
    $GLOBALS['billto_transients'] = ['value' => $cached, 'written' => []];

    Functions\when('get_site_transient')->alias(static fn () => $GLOBALS['billto_transients']['value']);
    Functions\when('set_site_transient')->alias(static function ($key, $value, $ttl) {
        $GLOBALS['billto_transients']['written'][] = ['key' => $key, 'value' => $value, 'ttl' => $ttl];

        return true;
    });
    Functions\when('wp_remote_get')->justReturn(['response' => ['code' => $response['code']], 'body' => $response['body']]);
    Functions\when('is_wp_error')->justReturn(false);
    Functions\when('wp_remote_retrieve_response_code')->alias(static fn ($r) => $r['response']['code']);
    Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['body']);

    return new UpdateChecker;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function releaseBody(array $overrides = []): string
{
    return (string) json_encode(array_merge([
        'platform' => 'woocommerce',
        'slug' => 'billto-woocommerce',
        'version' => '9.9.9',
        'download_url' => 'https://github.com/nozugroup/billto-woocommerce/releases/download/v9.9.9/billto-woocommerce.zip',
        'changelog_url' => 'https://github.com/nozugroup/billto-woocommerce/releases/tag/v9.9.9',
        'homepage' => 'https://billto.pl/integracje/woocommerce',
        'requirements' => ['platform_min' => '6.4', 'platform_max' => '6.7', 'php_min' => '8.0'],
    ], $overrides));
}

it('offers the update when BillTo publishes a newer version', function () {
    $checker = updateCheckerWith(['code' => 200, 'body' => releaseBody()]);

    $update = $checker->check(false, [], BILLTO_WC_BASENAME);

    expect($update)->toBeArray()
        ->and($update['version'])->toBe('9.9.9')
        ->and($update['plugin'])->toBe(BILLTO_WC_BASENAME)
        ->and($update['package'])->toContain('billto-woocommerce.zip')
        ->and($update['requires_php'])->toBe('8.0')
        ->and($update['tested'])->toBe('6.7');
});

it('offers nothing when the published version is not newer than the installed one', function () {
    $checker = updateCheckerWith(['code' => 200, 'body' => releaseBody(['version' => BILLTO_WC_VERSION])]);

    expect($checker->check(false, [], BILLTO_WC_BASENAME))->toBeFalse();
});

it('offers nothing when the release carries no installable ZIP', function () {
    // A GitHub release without the attached ZIP: installing the source archive would create a
    // directory named after the commit, so the update must be skipped rather than guessed.
    $checker = updateCheckerWith(['code' => 200, 'body' => releaseBody(['download_url' => null])]);

    expect($checker->check(false, [], BILLTO_WC_BASENAME))->toBeFalse();
});

it('offers nothing when no release has been published yet', function () {
    $checker = updateCheckerWith(['code' => 200, 'body' => releaseBody(['version' => null])]);

    expect($checker->check(false, [], BILLTO_WC_BASENAME))->toBeFalse();
});

it('caches a failed lookup briefly instead of asking on every check', function () {
    $checker = updateCheckerWith(['code' => 503, 'body' => '']);

    expect($checker->check(false, [], BILLTO_WC_BASENAME))->toBeFalse();

    $written = $GLOBALS['billto_transients']['written'];

    expect($written)->toHaveCount(1)
        ->and($written[0]['value'])->toBe('none')
        ->and($written[0]['ttl'])->toBeLessThan(3600);
});

it('does not touch updates of other plugins', function () {
    $checker = updateCheckerWith(['code' => 200, 'body' => releaseBody()]);

    expect($checker->check(false, [], 'other-plugin/other-plugin.php'))->toBeFalse();
});

it('serves the cached payload without another request', function () {
    $checker = updateCheckerWith(['code' => 500, 'body' => ''], cached: json_decode(releaseBody(), true));

    $update = $checker->check(false, [], BILLTO_WC_BASENAME);

    expect($update['version'])->toBe('9.9.9')
        ->and($GLOBALS['billto_transients']['written'])->toBeEmpty();
});
