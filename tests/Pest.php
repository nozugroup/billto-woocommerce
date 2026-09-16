<?php

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;

uses()
    ->beforeEach(function (): void {
        Monkey\setUp();
        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();
        Functions\when('wp_json_encode')->alias(static fn ($data, $flags = 0) => json_encode($data, $flags));
        Functions\when('is_email')->alias(static fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_file_name')->alias(static fn ($name) => preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $name));
        Functions\when('wp_strip_all_tags')->alias(static fn ($text) => strip_tags((string) $text));
        Functions\when('get_site_url')->justReturn('https://shop.test');
        Functions\when('get_bloginfo')->justReturn('6.7');
        Functions\when('wp_generate_uuid4')->justReturn('00000000-0000-4000-8000-000000000000');
        Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
            return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($args);
        });
        // The WordPress stubs define the hook functions with empty bodies, so Brain Monkey's own
        // implementations are not loaded; pass-through behaviour is enough for these tests.
        Functions\when('apply_filters')->alias(static fn (string $hook, $value = null) => $value);
        Functions\when('do_action')->justReturn(null);
        Functions\when('wp_remote_request')->alias([\BillTo\WooCommerce\Tests\Support\FakeHttp::class, 'handle']);
        \BillTo\WooCommerce\Tests\Support\FakeHttp::reset();
    })
    ->afterEach(function (): void {
        Monkey\tearDown();
        Mockery::close();
    })
    ->in('Unit');

/**
 * Stub get_option() with a fixed settings map (keys without the billto_wc_ prefix).
 *
 * @param  array<string, mixed>  $settings
 */
function stubSettings(array $settings = []): void
{
    $defaults = \BillTo\WooCommerce\Support\Options::defaults();
    $merged = array_merge($defaults, $settings);

    Functions\when('get_option')->alias(static function (string $name, $default = false) use ($merged) {
        $key = str_replace(\BillTo\WooCommerce\Support\Options::PREFIX, '', $name);

        return array_key_exists($key, $merged) ? $merged[$key] : $default;
    });
}
