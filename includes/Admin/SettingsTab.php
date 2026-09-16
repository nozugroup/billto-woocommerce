<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Admin;

use BillTo\WooCommerce\Api\ApiException;
use BillTo\WooCommerce\Api\Client;
use BillTo\WooCommerce\Api\OAuth\Connection;
use BillTo\WooCommerce\Support\Options;

/**
 * WooCommerce -> Settings -> BillTo tab, plus the AJAX "test connection" button.
 */
final class SettingsTab
{
    private const TAB = 'billto';

    /** Id of the connection form printed in the footer (see {@see renderConnectForm()}). */
    private const CONNECT_FORM_ID = 'billto-oauth-connect';

    /** @var callable(): Client */
    private $client;

    public function __construct(private Options $options, callable $client, private ?Connection $connection = null)
    {
        $this->client = $client;
    }

    /**
     * Header of the connection section: state plus the connect button, which starts the
     * Authorization Code flow with PKCE preceded by registration of this installation.
     */
    private function connectionDescription(): string
    {
        $connection = $this->connection;

        if ($connection !== null && $connection->isConnected()) {
            $company = $connection->companyName();

            $disconnect = wp_nonce_url(
                admin_url('admin-post.php?action='.OAuthConnectController::ACTION_DISCONNECT),
                OAuthConnectController::ACTION_DISCONNECT
            );

            return sprintf(
                '<strong>%s</strong>%s &nbsp; <a class="button" href="%s">%s</a>',
                esc_html__('Sklep jest połączony z BillTo.', 'billto-woocommerce'),
                $company !== '' ? ' '.esc_html(sprintf(__('Firma: %s.', 'billto-woocommerce'), $company)) : '',
                esc_url($disconnect),
                esc_html__('Odłącz', 'billto-woocommerce')
            );
        }

        return esc_html__('Wygeneruj kod instalacyjny w BillTo (Ustawienia → Integracje → Autoryzowane aplikacje), wklej go poniżej i podłącz sklep. Firmę i zakres uprawnień wskażesz w BillTo - token nie jest przenoszony do sklepu.', 'billto-woocommerce');
    }

    /**
     * Registration code input and the connect button, rendered as a custom field type because
     * WooCommerce passes section descriptions through `wp_kses_post`, which strips `<input>`,
     * `<form>` and the `form` attribute.
     *
     * The controls point with the `form` attribute at the form printed in the footer
     * ({@see renderConnectForm()}), since this section renders inside the settings form and
     * nested forms are not allowed in HTML. Submitted by POST, so the registration code does
     * not end up in the URL, browser history or server logs.
     */
    public function renderConnectField(): void
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            return;
        }

        printf(
            '<tr valign="top"><th scope="row" class="titledesc">%s</th><td class="forminp">'
                . '<input type="text" form="%s" name="billto_registration_code" placeholder="blti_..." '
                . 'style="min-width:320px;margin-right:8px" autocomplete="off">'
                . '<button type="submit" form="%s" class="button button-primary">%s</button></td></tr>',
            esc_html__('Kod instalacyjny', 'billto-woocommerce'),
            esc_attr(self::CONNECT_FORM_ID),
            esc_attr(self::CONNECT_FORM_ID),
            esc_html__('Połącz z BillTo', 'billto-woocommerce')
        );
    }

    /**
     * Empty connection form, printed outside the WooCommerce settings form. The controls target
     * it with the `form` attribute, which HTML5 allows without nesting forms.
     */
    public function renderConnectForm(): void
    {
        if (! $this->isOwnTab()) {
            return;
        }

        printf(
            '<form id="%s" method="post" action="%s">',
            esc_attr(self::CONNECT_FORM_ID),
            esc_url(admin_url('admin-post.php'))
        );

        // Printed on its own rather than interpolated: WordPress generates the field already
        // escaped.
        wp_nonce_field(OAuthConnectController::ACTION_CONNECT);

        printf(
            '<input type="hidden" name="action" value="%s"></form>',
            esc_attr(OAuthConnectController::ACTION_CONNECT)
        );
    }

    private function isOwnTab(): bool
    {
        // Read only to recognise the screen; no state change, so no nonce here.
        return isset($_GET['page'], $_GET['tab'])
            && $_GET['page'] === 'wc-settings'
            && $_GET['tab'] === self::TAB;
    }

    public function register(): void
    {
        add_filter('woocommerce_settings_tabs_array', [$this, 'addTab'], 50);
        add_action('woocommerce_settings_tabs_'.self::TAB, [$this, 'render']);
        add_action('woocommerce_update_options_'.self::TAB, [$this, 'save']);
        add_action('wp_ajax_billto_wc_test_connection', [$this, 'testConnection']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_footer', [$this, 'renderConnectForm']);
        add_action('woocommerce_admin_field_billto_connect', [$this, 'renderConnectField']);
    }

    /**
     * @param  array<string, string>  $tabs
     * @return array<string, string>
     */
    public function addTab(array $tabs): array
    {
        $tabs[self::TAB] = __('BillTo', 'billto-woocommerce');

        return $tabs;
    }

    public function render(): void
    {
        (new StatusPanel($this->options, $this->client))->render();
        woocommerce_admin_fields($this->fields());
        echo '<p><button type="button" class="button" id="billto-wc-test-connection">'
            .esc_html__('Testuj połączenie', 'billto-woocommerce')
            .'</button> <span id="billto-wc-test-result" style="margin-left:8px"></span></p>';
    }

    public function save(): void
    {
        $before = $this->options->baseUrl();

        woocommerce_update_options($this->fields());

        // Production, sandbox and a custom address are separate BillTo instances with separate
        // client registries, so credentials are dropped when the environment changes.
        if ($this->connection !== null && $this->options->baseUrl() !== $before && $this->connection->isConnected()) {
            $this->connection->forget();

            add_action('admin_notices', static function (): void {
                printf(
                    '<div class="notice notice-warning"><p>%s</p></div>',
                    esc_html__('Zmiana środowiska rozłączyła sklep z BillTo. Połącz go ponownie - poświadczenia z poprzedniego środowiska tam nie działają.', 'billto-woocommerce')
                );
            });
        }

        // Environment may have changed - drop cached series lists.
        foreach (['VAT', 'KOR', 'OSS'] as $type) {
            delete_transient($this->seriesCacheKey($type));
        }
    }

    private function seriesCacheKey(string $type): string
    {
        return 'billto_wc_series_'.$type.'_'.substr(md5($this->options->baseUrl()), 0, 12);
    }

    public function enqueueAssets(string $hook): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the current settings tab.
        $tab = sanitize_key(wp_unslash((string) ($_GET['tab'] ?? '')));

        if ($hook !== 'woocommerce_page_wc-settings' || $tab !== self::TAB) {
            return;
        }

        wp_enqueue_script('billto-wc-settings', BILLTO_WC_URL.'assets/js/settings.js', ['jquery'], BILLTO_WC_VERSION, true);
        wp_localize_script('billto-wc-settings', 'billtoWcSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('billto_wc_test_connection'),
            'testing' => __('Sprawdzanie...', 'billto-woocommerce'),
        ]);
    }

    /**
     * Tests the token currently saved (save the form first) by listing invoice series.
     */
    public function testConnection(): void
    {
        check_ajax_referer('billto_wc_test_connection', 'nonce');

        if (! current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Brak uprawnień.', 'billto-woocommerce')], 403);
        }

        $client = ($this->client)();

        if (! $client->isConfigured()) {
            wp_send_json_error(['message' => __('Zapisz ustawienia z tokenem API, potem przetestuj połączenie.', 'billto-woocommerce')]);
        }

        try {
            $series = $client->get('invoice-series', ['type' => 'VAT']);
            $default = null;

            foreach ($series['data'] ?? [] as $row) {
                if (! empty($row['is_default'])) {
                    $default = $row['name'] ?? $row['code'] ?? null;
                }
            }

            $message = $default !== null
                ? sprintf(__('Połączono. Domyślna seria VAT: %s.', 'billto-woocommerce'), $default)
                : __('Połączono, ale zespół nie ma domyślnej serii faktur VAT - ustaw ją w BillTo, inaczej mark-paid zwróci błąd.', 'billto-woocommerce');

            wp_send_json_success(['message' => $message, 'environment' => $this->options->isSandbox() ? 'sandbox' : 'production']);
        } catch (ApiException $e) {
            wp_send_json_error(['message' => sprintf(__('Błąd połączenia (HTTP %1$d): %2$s', 'billto-woocommerce'), $e->status(), $e->getMessage())]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function gatewayOptions(): array
    {
        $options = [];

        // WC() can be null before WooCommerce has booted; the guard keeps the settings screen
        // rendering with an empty gateway list.
        if (function_exists('WC') && WC() !== null && WC()->payment_gateways()) {
            foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
                $options[(string) $id] = $gateway->get_title() ?: (string) $id;
            }
        }

        // Keep saved ids selectable even when their gateway plugin is currently inactive.
        foreach ($this->options->unpaidGateways() as $saved) {
            $options[$saved] ??= $saved;
        }

        return $options;
    }

    /**
     * Select options for a series type: the team's default first, then the other active series.
     * Fetched from the API and cached briefly; on any failure only the "default" option is shown
     * (plus the currently saved id, so a saved choice is never silently dropped from the form).
     *
     * @return array<string, string>
     */
    private function seriesOptions(string $type): array
    {
        $options = ['' => __('Domyślna seria zespołu w BillTo', 'billto-woocommerce')];
        $client = ($this->client)();
        $saved = (string) $this->options->get(match ($type) {
            'KOR' => 'kor_series_id',
            'OSS' => 'oss_series_id',
            default => 'series_id',
        });

        if ($client->isConfigured()) {
            $cacheKey = $this->seriesCacheKey($type);
            $series = get_transient($cacheKey);

            if (! is_array($series) || $series === []) {
                try {
                    $series = $client->get('invoice-series', ['type' => $type])['data'] ?? [];

                    // Never cache an empty list: the merchant is typically adding series in BillTo
                    // right now and expects the next page load to show them.
                    if ($series !== []) {
                        set_transient($cacheKey, $series, 10 * MINUTE_IN_SECONDS);
                    }
                } catch (ApiException $e) {
                    $series = [];
                }
            }

            foreach ($series as $row) {
                if (empty($row['id'])) {
                    continue;
                }

                $label = (string) ($row['name'] ?? $row['code'] ?? $row['id']);
                $label .= ! empty($row['pattern']) ? ' ('.$row['pattern'].')' : '';
                $label .= ! empty($row['is_default']) ? ' - '.__('domyślna', 'billto-woocommerce') : '';
                $options[(string) $row['id']] = $label;
            }
        }

        if ($saved !== '' && ! isset($options[$saved])) {
            $options[$saved] = sprintf(__('Zapisana seria %s (niedostępna na liście)', 'billto-woocommerce'), $saved);
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        $statuses = [];

        foreach (wc_get_order_statuses() as $key => $label) {
            $statuses[str_replace('wc-', '', (string) $key)] = $label;
        }

        $vatTypes = [
            '0 KR' => __('0% krajowe (0 KR)', 'billto-woocommerce'),
            '23' => __('23%', 'billto-woocommerce'),
            '8' => __('8%', 'billto-woocommerce'),
            '5' => __('5%', 'billto-woocommerce'),
            '0 WDT' => __('0% WDT', 'billto-woocommerce'),
            '0 EX' => __('0% eksport (0 EX)', 'billto-woocommerce'),
            'zw' => __('zwolnione (zw)', 'billto-woocommerce'),
            'np I' => __('nie podlega (np I)', 'billto-woocommerce'),
            'np II' => __('nie podlega (np II)', 'billto-woocommerce'),
            'oo' => __('odwrotne obciążenie (oo)', 'billto-woocommerce'),
        ];

        $p = Options::PREFIX;

        return [
            ['type' => 'title', 'id' => $p.'section_connection', 'title' => __('Połączenie z BillTo', 'billto-woocommerce'),
                'desc' => $this->connectionDescription()],
            // Custom field type: form controls do not survive `wp_kses_post`, which WooCommerce
            // applies to section descriptions - see renderConnectField().
            ['type' => 'billto_connect', 'id' => $p.'connect'],
            // There is no API token field: the shop connects through OAuth and receives a token
            // scoped to a single company.
            ['type' => 'select', 'id' => $p.'environment', 'title' => __('Środowisko', 'billto-woocommerce'), 'default' => Options::ENV_PRODUCTION, 'options' => [
                Options::ENV_PRODUCTION => __('Produkcja (billto.pl)', 'billto-woocommerce'),
                Options::ENV_SANDBOX => __('Sandbox (sandbox.billto.pl) - dokumenty testowe', 'billto-woocommerce'),
                Options::ENV_CUSTOM => __('Własny adres API', 'billto-woocommerce'),
            ], 'desc' => __('Produkcja i sandbox to ODRĘBNE konta BillTo. Zmiana środowiska rozłącza sklep - połącz go ponownie w nowym środowisku.', 'billto-woocommerce'), 'desc_tip' => true],
            ['type' => 'text', 'id' => $p.'custom_url', 'title' => __('Własny adres API', 'billto-woocommerce'), 'placeholder' => 'https://.../api/v1', 'default' => '', 'css' => 'min-width:420px'],
            ['type' => 'sectionend', 'id' => $p.'section_connection'],

            ['type' => 'title', 'id' => $p.'section_flow', 'title' => __('Fakturowanie', 'billto-woocommerce'),
                'desc' => __('Zamówienie WooCommerce jest odwzorowywane jako zamówienie w BillTo. Gdy zamówienie przechodzi w status oznaczający zapłatę, BillTo wystawia fakturę VAT.', 'billto-woocommerce')],
            ['type' => 'select', 'id' => $p.'invoice_mode', 'title' => __('Dla których zamówień', 'billto-woocommerce'), 'default' => Options::MODE_ALL, 'options' => [
                Options::MODE_ALL => __('Wszystkie zamówienia', 'billto-woocommerce'),
                Options::MODE_NIP_ONLY => __('Tylko gdy klient zaznaczy „chcę fakturę" w koszyku', 'billto-woocommerce'),
            ], 'desc' => __('„Wszystkie" pasuje do sklepu bez kasy fiskalnej (faktura dokumentuje każdą sprzedaż). Sklep ewidencjonujący sprzedaż konsumencką na kasie powinien wybrać „tylko na życzenie", inaczej sprzedaż będzie udokumentowana podwójnie.', 'billto-woocommerce'), 'desc_tip' => false],
            ['type' => 'checkbox', 'id' => $p.'resync_on_edit', 'title' => __('Edycja zamówienia', 'billto-woocommerce'), 'default' => 'yes',
                'desc' => __('Gdy obsługa zmieni zamówienie w panelu przed wystawieniem faktury (adres, pozycje), zaktualizuj zamówienie w BillTo.', 'billto-woocommerce')],
            ['type' => 'select', 'id' => $p.'create_on', 'title' => __('Zamówienie w BillTo powstaje', 'billto-woocommerce'), 'default' => Options::CREATE_ON_CHECKOUT, 'options' => [
                Options::CREATE_ON_CHECKOUT => __('Od razu po złożeniu zamówienia', 'billto-woocommerce'),
                Options::CREATE_ON_PAID => __('Dopiero przy zapłacie (razem z fakturą)', 'billto-woocommerce'),
            ]],
            ['type' => 'select', 'id' => $p.'series_id', 'title' => __('Seria faktur VAT', 'billto-woocommerce'), 'default' => '', 'options' => $this->seriesOptions('VAT'),
                'desc' => __('Numeracja faktur ze sklepu. Lista pochodzi z BillTo (Ustawienia -> Numeracja); zapisz token i odśwież stronę, aby ją zobaczyć.', 'billto-woocommerce'), 'desc_tip' => false],
            ['type' => 'select', 'id' => $p.'kor_series_id', 'title' => __('Seria faktur korygujących', 'billto-woocommerce'), 'default' => '', 'options' => $this->seriesOptions('KOR'),
                'desc' => __('Numeracja korekt wystawianych przy zwrotach.', 'billto-woocommerce'), 'desc_tip' => true],
            ['type' => 'multiselect', 'id' => $p.'paid_statuses', 'title' => __('Statusy oznaczające zapłatę', 'billto-woocommerce'), 'class' => 'wc-enhanced-select', 'css' => 'min-width:320px', 'default' => ['processing', 'completed'], 'options' => $statuses,
                'desc' => __('Przejście zamówienia w jeden z tych statusów wystawia fakturę (raz na zamówienie).', 'billto-woocommerce'), 'desc_tip' => false],
            ['type' => 'checkbox', 'id' => $p.'ksef_auto', 'title' => __('KSeF', 'billto-woocommerce'), 'desc' => __('Wysyłaj wystawioną fakturę do KSeF automatycznie (token musi mieć uprawnienie ksef:send, a zespół skonfigurowany certyfikat).', 'billto-woocommerce'), 'default' => 'no'],
            ['type' => 'checkbox', 'id' => $p.'refund_corrections', 'title' => __('Zwroty', 'billto-woocommerce'), 'desc' => __('Zwrot w WooCommerce z pozycjami tworzy fakturę korygującą w BillTo (ilości po zwrocie).', 'billto-woocommerce'), 'default' => 'yes'],
            ['type' => 'select', 'id' => $p.'amount_refund_mode', 'title' => __('Zwrot samej kwoty (bez pozycji)', 'billto-woocommerce'), 'default' => Options::REFUND_PROPORTIONAL, 'options' => [
                Options::REFUND_PROPORTIONAL => __('Korekta obniżająca cenę każdej pozycji proporcjonalnie (rabat po sprzedaży)', 'billto-woocommerce'),
                Options::REFUND_NOTE => __('Tylko notatka - korektę wystawia obsługa w BillTo', 'billto-woocommerce'),
            ]],
            ['type' => 'sectionend', 'id' => $p.'section_flow'],

            ['type' => 'title', 'id' => $p.'section_amounts', 'title' => __('Kwoty i płatności', 'billto-woocommerce')],
            ['type' => 'select', 'id' => $p.'amount_mode', 'title' => __('Tryb kwot', 'billto-woocommerce'), 'default' => Options::AMOUNT_AUTO, 'options' => [
                Options::AMOUNT_AUTO => __('Jak w WooCommerce (ceny z podatkiem = brutto)', 'billto-woocommerce'),
                Options::AMOUNT_GROSS => __('Brutto - BillTo liczy netto od ceny z podatkiem', 'billto-woocommerce'),
                Options::AMOUNT_NET => __('Netto - BillTo liczy podatek od ceny netto', 'billto-woocommerce'),
            ], 'desc' => __('Brutto gwarantuje, że suma faktury równa się kwocie zapłaconej w sklepie. Netto tylko dla sklepów z cenami netto (B2B).', 'billto-woocommerce'), 'desc_tip' => false],
            ['type' => 'multiselect', 'id' => $p.'unpaid_gateways', 'title' => __('Płatności odroczone', 'billto-woocommerce'), 'class' => 'wc-enhanced-select', 'css' => 'min-width:320px', 'default' => ['cod', 'bacs'], 'options' => $this->gatewayOptions(),
                'desc' => __('Dla tych metod faktura jest wystawiana jako NIEopłacona (pieniądze jeszcze nie wpłynęły), a wpłata jest dopisywana po przejściu zamówienia w status poniżej.', 'billto-woocommerce'), 'desc_tip' => false],
            ['type' => 'select', 'id' => $p.'settle_status', 'title' => __('Status oznaczający odbiór płatności', 'billto-woocommerce'), 'default' => 'completed', 'options' => $statuses],
            ['type' => 'checkbox', 'id' => $p.'untaxed_extras_follow_goods', 'title' => __('Dostawa bez podatku', 'billto-woocommerce'), 'default' => 'yes',
                'desc' => __('Gdy WooCommerce nie naliczył podatku od dostawy lub opłaty, a towary mają VAT, dostawa dostaje stawkę towarów (najwyższą z zamówienia) zamiast "zw". Dostawa jest świadczeniem pomocniczym i dzieli stawkę towaru.', 'billto-woocommerce')],
            ['type' => 'select', 'id' => $p.'negative_lines', 'title' => __('Pozycje ujemne', 'billto-woocommerce'), 'default' => Options::NEGATIVE_DISTRIBUTE, 'options' => [
                Options::NEGATIVE_DISTRIBUTE => __('Rozdziel rabat proporcjonalnie na pozycje towarowe', 'billto-woocommerce'),
                Options::NEGATIVE_SKIP => __('Nie wysyłaj zamówienia, dodaj notatkę', 'billto-woocommerce'),
            ], 'desc' => __('Rabat jako ujemna opłata albo karta podarunkowa - faktura nie może mieć pozycji ujemnych.', 'billto-woocommerce'), 'desc_tip' => true],
            ['type' => 'sectionend', 'id' => $p.'section_amounts'],

            ['type' => 'title', 'id' => $p.'section_scenarios', 'title' => __('Rodzaje nabywców', 'billto-woocommerce'),
                'desc' => __('Nabywca jest rozpoznawany z adresu rozliczeniowego i pola NIP / VAT UE. Osoby fizyczne i firmy z Polski zawsze otrzymują fakturę VAT ze stawkami sklepu. Poniżej zachowanie dla pozostałych przypadków.', 'billto-woocommerce')],
            ['type' => 'select', 'id' => $p.'oss_mode', 'title' => __('Konsument z innego kraju UE', 'billto-woocommerce'), 'default' => Options::OSS_PL_VAT, 'options' => [
                Options::OSS_PL_VAT => __('Faktura VAT ze stawkami polskimi (sprzedaż poniżej progu 10 000 EUR)', 'billto-woocommerce'),
                Options::OSS_INVOICE => __('Faktura OSS ze stawką kraju konsumpcji (firma zarejestrowana do OSS)', 'billto-woocommerce'),
                Options::OSS_OFF => __('Nie wystawiaj faktury (zamówienie nie trafia do BillTo)', 'billto-woocommerce'),
            ], 'desc' => __('Tryb OSS wymaga zadeklarowanej rejestracji OSS w BillTo, serii faktur OSS oraz stawek VAT krajów UE w WooCommerce (stawka z zamówienia trafia na fakturę).', 'billto-woocommerce'), 'desc_tip' => false],
            ['type' => 'select', 'id' => $p.'oss_series_id', 'title' => __('Seria faktur OSS', 'billto-woocommerce'), 'default' => '', 'options' => $this->seriesOptions('OSS')],
            ['type' => 'select', 'id' => $p.'eu_consumer_no_vat', 'title' => __('Konsument z UE bez VAT w sklepie', 'billto-woocommerce'), 'default' => Options::EU_CONSUMER_NO_VAT_BLOCK, 'options' => [
                Options::EU_CONSUMER_NO_VAT_BLOCK => __('Nie wystawiaj faktury, dodaj notatkę (sklep powinien naliczyć VAT)', 'billto-woocommerce'),
                Options::EU_CONSUMER_NO_VAT_MAP => __('Mapuj jak sprzedaż krajową (sprzedawca zwolniony z VAT)', 'billto-woocommerce'),
            ], 'desc' => __('Dotyczy trybu "stawki polskie". Pozycja bez podatku dla konsumenta z UE zwykle oznacza brak stawki VAT dla tego kraju w WooCommerce (Podatki -> stawki); faktura zw / 0% ukryłaby ten błąd.', 'billto-woocommerce'), 'desc_tip' => true],
            ['type' => 'checkbox', 'id' => $p.'eu_b2b_enabled', 'title' => __('Firma z UE (numer VAT UE)', 'billto-woocommerce'), 'default' => 'yes',
                'desc' => __('Wystawiaj fakturę: towary ze stawką 0% WDT, usługi jako "np" (art. 28b, odwrotne obciążenie u nabywcy).', 'billto-woocommerce')],
            ['type' => 'select', 'id' => $p.'vies_check', 'title' => __('Weryfikacja VIES', 'billto-woocommerce'), 'default' => Options::VIES_BLOCK, 'options' => [
                Options::VIES_BLOCK => __('Sprawdzaj numer w VIES; nieaktywny = nie wystawiaj faktury (notatka)', 'billto-woocommerce'),
                Options::VIES_DOMESTIC => __('Sprawdzaj numer w VIES; nieaktywny = faktura ze stawkami polskimi', 'billto-woocommerce'),
                Options::VIES_OFF => __('Nie sprawdzaj (odpowiedzialność sklepu)', 'billto-woocommerce'),
            ], 'desc' => __('Stawka 0% WDT wymaga aktywnego numeru VAT UE nabywcy. Wynik zapisuje się przy zamówieniu.', 'billto-woocommerce'), 'desc_tip' => true],
            ['type' => 'checkbox', 'id' => $p.'non_eu_enabled', 'title' => __('Nabywca spoza UE', 'billto-woocommerce'), 'default' => 'yes',
                'desc' => __('Wystawiaj fakturę: towary ze stawką 0% eksport, usługi jako "np" (poza UE). Dotyczy firm i konsumentów.', 'billto-woocommerce')],
            ['type' => 'checkbox', 'id' => $p.'foreign_taxed_follows_shop', 'title' => __('Zagraniczny nabywca z naliczonym VAT', 'billto-woocommerce'), 'default' => 'yes',
                'desc' => __('Gdy WooCommerce naliczył polski VAT firmie z UE lub nabywcy spoza UE (brak stawki 0% dla tego kraju), faktura dostaje stawki polskie, czyli to, co klient zapłacił. Wyłączone: 0% WDT / eksport mimo pobranego VAT, faktura niższa od płatności.', 'billto-woocommerce')],
            ['type' => 'sectionend', 'id' => $p.'section_scenarios'],

            ['type' => 'title', 'id' => $p.'section_delivery', 'title' => __('Doręczenie faktury', 'billto-woocommerce')],
            ['type' => 'select', 'id' => $p.'delivery_mode', 'title' => __('Jak klient otrzymuje fakturę', 'billto-woocommerce'), 'default' => Options::DELIVERY_BILLTO_EMAIL, 'options' => [
                Options::DELIVERY_BILLTO_EMAIL => __('E-mail z BillTo (PDF + strona publiczna z QR do przelewu)', 'billto-woocommerce'),
                Options::DELIVERY_WC_ATTACHMENT => __('PDF jako załącznik e-maila WooCommerce „Faktura / Zamówienie zrealizowane"', 'billto-woocommerce'),
                Options::DELIVERY_LINK_ONLY => __('Tylko link do PDF w koncie klienta i panelu sklepu', 'billto-woocommerce'),
            ]],
            ['type' => 'sectionend', 'id' => $p.'section_delivery'],

            ['type' => 'title', 'id' => $p.'section_checkout', 'title' => __('Koszyk', 'billto-woocommerce')],
            ['type' => 'text', 'id' => $p.'checkbox_label', 'title' => __('Etykieta pola „chcę fakturę"', 'billto-woocommerce'), 'default' => 'Chcę otrzymać fakturę VAT', 'css' => 'min-width:420px'],
            ['type' => 'checkbox', 'id' => $p.'nip_required', 'title' => __('Faktury tylko dla firm', 'billto-woocommerce'),
                'desc' => __('Wymagaj NIP / numeru VAT UE, gdy klient zaznaczy „chcę fakturę". Domyślnie wyłączone: klient bez NIP otrzymuje fakturę jako osoba fizyczna, a podany NIP jest sprawdzany (suma kontrolna dla Polski, format dla UE).', 'billto-woocommerce'), 'default' => 'no'],
            ['type' => 'sectionend', 'id' => $p.'section_checkout'],

            ['type' => 'title', 'id' => $p.'section_vat', 'title' => __('Stawki VAT', 'billto-woocommerce'),
                'desc' => __('Stawki procentowe (23, 8, 5) są mapowane automatycznie. Poniżej stawki dla pozycji bez podatku.', 'billto-woocommerce')],
            ['type' => 'select', 'id' => $p.'vat_zero', 'title' => __('Stawka 0% w WooCommerce', 'billto-woocommerce'), 'default' => '0 KR', 'options' => $vatTypes],
            ['type' => 'select', 'id' => $p.'vat_no_tax', 'title' => __('Pozycja bez podatku', 'billto-woocommerce'), 'default' => 'zw', 'options' => $vatTypes,
                'desc' => __('Gdy sklep nie nalicza podatku (np. zwolnienie podmiotowe z VAT).', 'billto-woocommerce'), 'desc_tip' => true],
            ['type' => 'sectionend', 'id' => $p.'section_vat'],
        ];
    }
}
