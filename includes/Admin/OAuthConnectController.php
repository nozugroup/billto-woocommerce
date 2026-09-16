<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Admin;

use BillTo\Shop\OAuth\OAuthClient;
use BillTo\Shop\OAuth\Pkce;
use BillTo\WooCommerce\Api\OAuth\Connection;
use BillTo\WooCommerce\Support\Options;

/**
 * Przepływ "Połącz z BillTo" w panelu sklepu.
 *
 * WYBÓR PRZEPŁYWU - Authorization Code z PKCE, poprzedzony Dynamic Client Registration:
 *
 *  - **DCR**, bo wtyczka jest ROZPOWSZECHNIANA. Jeden sekret wspólny dla wszystkich instalacji
 *    znaczyłby, że wyciek u jednego sklepikarza otwiera konta pozostałych. Każda instalacja
 *    rejestruje własną parę, a wszystkie wskazują na jednego dostawcę w rejestrze BillTo.
 *    Prawo do rejestracji daje KOD INSTALACYJNY wygenerowany przez sklepikarza w BillTo -
 *    w paczce wtyczki nie ma niczego, co pozwalałoby cokolwiek zarejestrować.
 *  - **Authorization Code**, bo sklepikarz siedzi przy przeglądarce w panelu i to ON decyduje,
 *    do której swojej firmy w BillTo wpuszcza sklep. Wybór firmy jest częścią ekranu zgody.
 *  - **PKCE**, mimo że instalacja ma sekret: kod autoryzacyjny wraca przez przeglądarkę
 *    sklepikarza. Sklepy stoją za proxy, bywają wewnętrznie na HTTP i mają rozgadane nagłówki
 *    referrer - kod podebrany po drodze jest bez weryfikatora bezużyteczny, a weryfikator nie
 *    opuszcza serwera sklepu.
 *
 * Czego NIE używamy i dlaczego:
 *  - **client_credentials** - nie ma użytkownika, więc nie ma z czego wywieść firmy; BillTo
 *    dopuszcza ten grant wyłącznie do danych referencyjnych (`/v1/public/*`).
 *  - **Device flow** - jest przeglądarka, więc przepisywanie kodu z ekranu byłoby utrudnianiem
 *    życia bez powodu.
 */
final class OAuthConnectController
{
    public const ACTION_CONNECT = 'billto_oauth_connect';

    public const ACTION_CALLBACK = 'billto_oauth_callback';

    public const ACTION_DISCONNECT = 'billto_oauth_disconnect';

    /** Zgody, o które wtyczka prosi. Węziej się nie da - to minimum dla fakturowania zamówień. */
    private const SCOPES = [
        'orders:read', 'orders:write',
        'invoices:read', 'invoices:write',
        'contractors:read', 'contractors:write',
        'products:read',
    ];

    private const TRANSIENT_FLOW = 'billto_wc_oauth_flow';

    /**
     * Software statement ID tej wtyczki - identyfikator wydany dostawcy przez BillTo po
     * uzasadnionej prośbie o DCR. Wypełniany przy wydaniu paczki.
     *
     * Jest JAWNY i tak ma być: paczkę pobiera każdy. Sam z siebie nie rejestruje niczego - bez
     * dwuminutowego kodu od sklepikarza BillTo odrzuci żądanie. Bez niego z kolei nie wiadomo,
     * kto się rejestruje, więc rejestracja też nie przejdzie.
     */
    public const SOFTWARE_STATEMENT_ID = '';

    public function __construct(private Options $options, private Connection $connection) {}

    public function register(): void
    {
        add_action('admin_post_'.self::ACTION_CONNECT, [$this, 'connect']);
        add_action('admin_post_'.self::ACTION_CALLBACK, [$this, 'callback']);
        add_action('admin_post_'.self::ACTION_DISCONNECT, [$this, 'disconnect']);
    }

    public function connect(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'billto-woocommerce'));
        }

        // Sprawdzenie nonce stoi TUTAJ, a nie w pomocniczej metodzie: phpcs rozpoznaje je tylko
        // w tej samej funkcji, w której czytamy $_POST, a wyciszanie sniffa w miejscu obsługi
        // danych z formularza to ostatnia rzecz, jaką warto wyciszać.
        check_admin_referer(self::ACTION_CONNECT);

        // KOD INSTALACYJNY, a nie wartość osadzona w paczce. Paczka wtyczki to zip, który każdy
        // pobiera - cokolwiek w niej siedzi, trzeba traktować jak publiczne, a to pozwoliłoby
        // rejestrować klientów pod marką zweryfikowanego dostawcy. Kod generuje sklepikarz
        // u siebie w BillTo; jest krótkotrwały i jednorazowy.
        $code = isset($_POST['billto_registration_code'])
            ? sanitize_text_field(wp_unslash((string) $_POST['billto_registration_code']))
            : '';

        if ($code === '' && $this->connection->clientId() === '') {
            $this->redirectBack('missing_code');
        }

        if (! $this->connection->ensureRegistered(self::SOFTWARE_STATEMENT_ID, $code, $this->redirectUri())) {
            $this->redirectBack('registration_failed');
        }

        $verifier = Pkce::verifier();
        $state = Pkce::state();

        // Weryfikator i stan żyją po stronie serwera, nie w adresie powrotnym. 15 minut
        // wystarcza na zalogowanie się do BillTo i wybór firmy, a nie zostawia otwartego
        // okna na później.
        set_transient(self::TRANSIENT_FLOW, ['verifier' => $verifier, 'state' => $state], 15 * MINUTE_IN_SECONDS);

        wp_redirect($this->oauth()->authorizationUrl(
            $this->connection->clientId(),
            $this->redirectUri(),
            self::SCOPES,
            $state,
            $verifier
        ));
        exit;
    }

    public function callback(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'billto-woocommerce'));
        }

        $flow = get_transient(self::TRANSIENT_FLOW);
        delete_transient(self::TRANSIENT_FLOW);

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash((string) $_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : '';

        // Porównanie stanu jest jedyną rzeczą odróżniającą powrót z NASZEGO żądania od
        // podrzuconego adresu - bez niego ktoś mógłby podłączyć sklep do swojej firmy.
        if (! is_array($flow) || ! hash_equals((string) ($flow['state'] ?? ''), $state)) {
            $this->redirectBack('state_mismatch');
        }

        if ($code === '') {
            // Użytkownik kliknął "Odmawiam" albo BillTo odrzuciło żądanie - to nie jest awaria.
            $this->redirectBack('denied');
        }

        $tokens = $this->oauth()->exchangeCode(
            $this->connection->clientId(),
            $this->connection->clientSecret(),
            $this->redirectUri(),
            $code,
            (string) $flow['verifier']
        );

        if ($tokens === null) {
            $this->redirectBack('exchange_failed');
        }

        $this->connection->store($tokens);

        $this->redirectBack('connected');
    }

    public function disconnect(): void
    {
        $this->authorizeRequest(self::ACTION_DISCONNECT);

        $this->connection->disconnect();

        $this->redirectBack('disconnected');
    }

    private function oauth(): OAuthClient
    {
        return new OAuthClient($this->options->oauthBaseUrl(), new \BillTo\WooCommerce\Api\OAuth\WpHttpPost);
    }

    /**
     * Adres powrotny musi być STAŁY i zgodny z zarejestrowanym - BillTo porównuje go przy
     * wymianie kodu, więc każda zmiana adresu sklepu wymaga ponownej rejestracji instalacji.
     */
    private function redirectUri(): string
    {
        return admin_url('admin-post.php?action='.self::ACTION_CALLBACK);
    }

    private function authorizeRequest(string $action): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'billto-woocommerce'));
        }

        check_admin_referer($action);
    }

    private function redirectBack(string $result): void
    {
        wp_redirect(add_query_arg(
            'billto_oauth',
            $result,
            admin_url('admin.php?page=wc-settings&tab=billto')
        ));
        exit;
    }
}
