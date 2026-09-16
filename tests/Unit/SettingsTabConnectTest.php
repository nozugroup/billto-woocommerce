<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use BillTo\Shop\OAuth\HttpPostInterface;
use BillTo\Shop\OAuth\OAuthClient;
use BillTo\WooCommerce\Admin\OAuthConnectController;
use BillTo\WooCommerce\Admin\SettingsTab;
use BillTo\WooCommerce\Api\Client;
use BillTo\WooCommerce\Api\OAuth\Connection;
use BillTo\WooCommerce\Support\Logger;
use BillTo\WooCommerce\Support\Options;

/**
 * The connect section renders INSIDE WooCommerce's own settings form, and WooCommerce pipes
 * section descriptions through wp_kses_post. Both constraints bite: a nested <form> is invalid
 * HTML the browser drops, and kses strips <input>, <form> and the `form` attribute outright.
 */
final class NullPost implements HttpPostInterface
{
    public function post(string $url, array $fields, array $headers = []): ?array
    {
        return null;
    }
}

function connectTab(array $options = []): SettingsTab
{
    $store = $options;

    Functions\when('get_option')->alias(static fn (string $key, $default = false) => $store[$key] ?? $default);
    Functions\when('update_option')->justReturn(true);
    Functions\when('delete_option')->justReturn(true);
    Functions\when('wc_get_logger')->justReturn(new class
    {
        public function log(string $level, string $message, array $context = []): void {}
    });

    $connection = new Connection(new Logger, new OAuthClient('https://billto.test', new NullPost));

    // Tokenless client: `fields()` asks for the series list, but a disconnected client makes
    // no request.
    return new SettingsTab(new Options, static fn () => new Client('', 'https://billto.test', new Logger), $connection);
}

function renderConnectField(SettingsTab $tab): string
{
    ob_start();
    $tab->renderConnectField();

    return (string) ob_get_clean();
}

beforeEach(function () {
    Functions\when('admin_url')->alias(static fn (string $path = '') => 'https://shop.test/wp-admin/'.$path);
    Functions\when('wp_nonce_field')->alias(static function (): void {
        echo '<input type="hidden" name="_wpnonce" value="n">';
    });
    Functions\when('wp_nonce_url')->returnArg();
});

it('keeps form controls out of the section description', function () {
    $description = (new ReflectionClass(SettingsTab::class))->getMethod('connectionDescription');
    $description->setAccessible(true);

    $text = (string) $description->invoke(connectTab());

    // This string goes through wp_kses_post, which strips form markup; the dedicated field
    // below is where the controls live.
    expect($text)->not->toContain('<form')
        ->and($text)->not->toContain('<input')
        ->and($text)->not->toContain('<button');
});

it('binds the code field and the button to the standalone form, not to the settings form', function () {
    $html = renderConnectField(connectTab());

    // Two controls, both pointing at the footer form: without the attribute they belong to
    // WooCommerce's settings form and "Connect" merely saves settings.
    expect($html)->toContain('name="billto_registration_code"')
        ->and(substr_count($html, 'form="billto-oauth-connect"'))->toBe(2)
        ->and($html)->not->toContain('<form');
});

it('renders the connect form once, outside the settings form', function () {
    $_GET = ['page' => 'wc-settings', 'tab' => 'billto'];

    ob_start();
    connectTab()->renderConnectForm();
    $html = (string) ob_get_clean();

    // The registration code travels by POST, never in a URL: it is a live credential for the
    // minutes it exists and has no business in browser history or a server log.
    expect($html)->toContain('id="billto-oauth-connect"')
        ->toContain('method="post"')
        ->toContain('admin-post.php')
        ->toContain('value="billto_oauth_connect"');
});

it('does not render the connect form on other settings tabs', function () {
    $_GET = ['page' => 'wc-settings', 'tab' => 'products'];

    ob_start();
    connectTab()->renderConnectForm();

    expect((string) ob_get_clean())->toBe('');
});

it('drops the code field once the shop is connected', function () {
    $p = Options::PREFIX;

    $connected = connectTab([
        $p.'oauth_access_token' => 'at',
        $p.'oauth_refresh_token' => 'rt',
        $p.'oauth_expires_at' => time() + 3600,
    ]);

    expect(renderConnectField($connected))->toBe('');
});

it('no longer offers a pasted API token', function () {
    // A pasted token is a long-lived credential for the merchant's WHOLE company, sitting in
    // the shop's database with no way to narrow or withdraw it short of deleting a token that
    // something else may be using.
    Functions\when('WC')->justReturn(null);
    Functions\when('wc_get_order_statuses')->justReturn(['wc-processing' => 'Processing', 'wc-completed' => 'Completed']);
    Functions\when('get_transient')->justReturn(false);

    $fields = (new ReflectionClass(SettingsTab::class))->getMethod('fields');
    $fields->setAccessible(true);

    $ids = array_column($fields->invoke(connectTab()), 'id');

    expect($ids)->not->toContain(Options::PREFIX.'token')
        ->and(Options::defaults())->not->toHaveKey('token');
});

it('forgets the installation credentials, not just the tokens, when asked', function () {
    // Production and sandbox are separate BillTo instances with separate client registries, so
    // a client_id issued in one is not valid in the other.
    $p = Options::PREFIX;
    $deleted = [];

    Functions\when('get_option')->alias(static fn (string $key, $default = false) => $default);
    Functions\when('delete_option')->alias(static function (string $key) use (&$deleted): bool {
        $deleted[] = $key;

        return true;
    });
    Functions\when('wc_get_logger')->justReturn(new class
    {
        public function log(string $level, string $message, array $context = []): void {}
    });

    (new Connection(new Logger, new OAuthClient('https://billto.test', new NullPost)))->forget();

    expect($deleted)->toContain($p.'oauth_access_token')
        ->toContain($p.'oauth_refresh_token')
        ->toContain($p.'oauth_client_id')
        ->toContain($p.'oauth_client_secret');
});

it('renders a message for the result of the last connect attempt', function () {
    Functions\when('sanitize_key')->alias(static fn ($v) => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)));
    Functions\when('wp_unslash')->returnArg();

    $controller = new OAuthConnectController(new Options, new Connection(new Logger, new OAuthClient('https://billto.test', new NullPost)));

    $render = static function (string $result) use ($controller): string {
        $_GET['billto_oauth'] = $result;
        ob_start();
        $controller->renderResultNotice();

        return (string) ob_get_clean();
    };

    // Bez tego niepowodzenie kończyło się cichym powrotem na zakładkę ustawień: parametr
    // `billto_oauth` był ustawiany, ale nikt go nie czytał.
    expect($render('statement_unavailable'))
        ->toContain('notice-error')
        ->toContain('Nie udało się pobrać danych rejestracji')
        ->and($render('connected'))->toContain('notice-success')
        ->and($render('nieznany_kod'))->toBe('');

    unset($_GET['billto_oauth']);
});
