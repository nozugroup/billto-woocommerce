<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Api\OAuth;

use BillTo\Shop\OAuth\OAuthClient;
use BillTo\Shop\OAuth\TokenSet;
use BillTo\WooCommerce\Support\Logger;
use BillTo\WooCommerce\Support\Options;

/**
 * Stan połączenia OAuth tego sklepu z BillTo: własne poświadczenia instalacji i para tokenów.
 *
 * DLACZEGO TEN SKLEP MA WŁASNE POŚWIADCZENIA: wtyczka jest rozpowszechniana, więc jeden sekret
 * wspólny dla wszystkich instalacji oznaczałby, że wyciek u jednego sklepikarza otwiera konta
 * wszystkich pozostałych. Dynamic Client Registration daje każdej instalacji własną parę,
 * a wszystkie wywodzą się z jednego oświadczenia wydanego dostawcy - dzięki czemu BillTo wciąż
 * wie, z kim rozmawiać przed zmianą niezgodną wstecz.
 *
 * ODŚWIEŻANIE jest tutaj, a nie w kliencie API: token trzeba wymienić ZANIM poleci żądanie,
 * inaczej każde wygaśnięcie kosztuje jedno nieudane wystawienie faktury - a sklep zdążył już
 * powiedzieć klientowi, że się nie udało.
 */
final class Connection
{
    private const OPT_CLIENT_ID = 'oauth_client_id';

    private const OPT_CLIENT_SECRET = 'oauth_client_secret';

    private const OPT_ACCESS_TOKEN = 'oauth_access_token';

    private const OPT_REFRESH_TOKEN = 'oauth_refresh_token';

    private const OPT_EXPIRES_AT = 'oauth_expires_at';

    private const OPT_COMPANY = 'oauth_company';

    /**
     * Klucze opcji czytamy przez stałą `Options::PREFIX`, więc sam obiekt ustawień nie jest tu
     * potrzebny - adres BillTo rozstrzyga się przy budowaniu klienta OAuth, w kompozycji.
     */
    public function __construct(
        private Logger $logger,
        private OAuthClient $oauth,
    ) {}

    public function isConnected(): bool
    {
        return $this->tokens() !== null;
    }

    /** Nazwa firmy, na którą sklep dostał zgodę - pokazywana w ustawieniach. */
    public function companyName(): string
    {
        return (string) get_option(Options::PREFIX.self::OPT_COMPANY, '');
    }

    public function clientId(): string
    {
        return (string) get_option(Options::PREFIX.self::OPT_CLIENT_ID, '');
    }

    public function clientSecret(): string
    {
        return (string) get_option(Options::PREFIX.self::OPT_CLIENT_SECRET, '');
    }

    /**
     * Rejestruje tę instalację w BillTo, jeżeli jeszcze nie ma własnych poświadczeń.
     *
     * Idempotentne: ponowne wywołanie nie tworzy drugiego klienta. Gdyby tworzyło, każde
     * kliknięcie "Połącz" zostawiałoby w rejestrze BillTo osierocony wpis.
     */
    public function ensureRegistered(string $softwareStatementId, string $registrationCode, string $redirectUri): bool
    {
        if ($this->clientId() !== '' && $this->clientSecret() !== '') {
            return true;
        }

        // Identyfikator mówi, KTO się rejestruje (wydany dostawcy przez BillTo, jedzie w paczce
        // i nie jest sekretem). Kod mówi, NA CZYJE dane (generuje go sklepikarz, żyje 2 minuty).
        // Żadne z osobna nie wystarcza.
        $credentials = $this->oauth->register(
            $softwareStatementId,
            $registrationCode,
            $this->installationName(),
            $redirectUri
        );

        if ($credentials === null) {
            $this->logger->error('[BillTo OAuth] rejestracja instalacji odrzucona przez BillTo');

            return false;
        }

        update_option(Options::PREFIX.self::OPT_CLIENT_ID, $credentials['client_id'], false);
        update_option(Options::PREFIX.self::OPT_CLIENT_SECRET, $credentials['client_secret'], false);

        return true;
    }

    /**
     * Zapisuje parę tokenów po wymianie kodu autoryzacyjnego.
     *
     * @param  array{access_token: string, refresh_token: ?string, expires_in: int}  $response
     */
    public function store(array $response, string $companyName = ''): void
    {
        $set = TokenSet::fromTokenResponse($response);

        update_option(Options::PREFIX.self::OPT_ACCESS_TOKEN, $set->accessToken, false);
        update_option(Options::PREFIX.self::OPT_REFRESH_TOKEN, (string) $set->refreshToken, false);
        update_option(Options::PREFIX.self::OPT_EXPIRES_AT, $set->expiresAt, false);

        if ($companyName !== '') {
            update_option(Options::PREFIX.self::OPT_COMPANY, $companyName, false);
        }
    }

    /**
     * Ważny token dostępowy albo null, gdy sklep nie jest połączony lub odświeżenie padło.
     *
     * Zwrócenie null jest ŚWIADOME zamiast rzucania: wywołujący ma wtedy szansę spróbować
     * starego tokenu z ustawień (sklepy sprzed przejścia na OAuth) zamiast zatrzymać wystawianie.
     */
    public function accessToken(): ?string
    {
        $set = $this->tokens();

        if ($set === null) {
            return null;
        }

        if (! $set->needsRefresh()) {
            return $set->accessToken;
        }

        if (! $set->canRefresh()) {
            // Bez tokenu odświeżania sklepikarz musi autoryzować ponownie ręcznie - i musi
            // się o tym dowiedzieć, a nie oglądać serię niewyjaśnionych 401.
            $this->logger->error('[BillTo OAuth] token wygasł i nie ma czym go odświeżyć - wymagana ponowna autoryzacja');

            return null;
        }

        $response = $this->oauth->refresh($this->clientId(), $this->clientSecret(), (string) $set->refreshToken);

        if ($response === null) {
            $this->logger->error('[BillTo OAuth] odświeżenie tokenu odrzucone - wymagana ponowna autoryzacja');

            return null;
        }

        // Token odświeżania jest JEDNORAZOWY - BillTo unieważnia go przy wymianie. Nową parę
        // trzeba zapisać, zanim poleci kolejne żądanie, inaczej integracja sama się zablokuje.
        $this->store($response);

        return $response['access_token'];
    }

    public function disconnect(): void
    {
        foreach ([self::OPT_ACCESS_TOKEN, self::OPT_REFRESH_TOKEN, self::OPT_EXPIRES_AT, self::OPT_COMPANY] as $key) {
            delete_option(Options::PREFIX.$key);
        }

        // Poświadczenia instalacji (client_id/secret) ZOSTAJĄ: odłączenie to cofnięcie zgody
        // na firmę, nie wyrejestrowanie sklepu. Ponowne połączenie ma pominąć rejestrację.
    }

    /**
     * Kasuje TAKŻE poświadczenia instalacji.
     *
     * Do zmiany środowiska: client_id i sekret wydane w produkcji nie znaczą nic w sandboksie
     * (to odrębne instancje z własnymi rejestrami), więc zostawienie ich dałoby sklep
     * „połączony", który przy każdym żądaniu dostaje 401.
     */
    public function forget(): void
    {
        $this->disconnect();

        foreach ([self::OPT_CLIENT_ID, self::OPT_CLIENT_SECRET] as $key) {
            delete_option(Options::PREFIX.$key);
        }
    }

    private function tokens(): ?TokenSet
    {
        return TokenSet::fromArray([
            'access_token' => (string) get_option(Options::PREFIX.self::OPT_ACCESS_TOKEN, ''),
            'refresh_token' => (string) get_option(Options::PREFIX.self::OPT_REFRESH_TOKEN, ''),
            'expires_at' => (int) get_option(Options::PREFIX.self::OPT_EXPIRES_AT, 0),
        ]);
    }

    /** Nazwa widoczna w BillTo - po niej sklepikarz pozna SWOJĄ instalację na liście zgód. */
    /**
     * Nazwa instalacji: unikatowa w skali BillTo i NIEZMIENNA po rejestracji.
     *
     * Stąd host sklepu + data: host odróżnia wdrożenia od siebie, a data domyka przypadek, w
     * którym ten sam sklep rejestruje się ponownie po odłączeniu (BillTo odrzuca duplikat nazwy
     * kodem 422, więc bez tego drugie podłączenie tego samego sklepu byłoby niemożliwe).
     * Skracamy do 50 znaków - to limit po stronie BillTo.
     */
    private function installationName(): string
    {
        $host = wp_parse_url((string) home_url(), PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'sklep';

        return substr('WooCommerce '.$host.' '.gmdate('Y-m-d H:i'), 0, 50);
    }
}
