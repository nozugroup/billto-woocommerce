<?php

declare(strict_types=1);

namespace BillTo\WooCommerce;

use BillTo\Shop\OAuth\OAuthClient;
use BillTo\WooCommerce\Admin\OAuthConnectController;
use BillTo\WooCommerce\Api\OAuth\Connection;
use BillTo\WooCommerce\Api\OAuth\WpHttpPost;
use BillTo\WooCommerce\Admin\OrderActions;
use BillTo\WooCommerce\Admin\OrderMetaBox;
use BillTo\WooCommerce\Admin\OrdersListColumn;
use BillTo\WooCommerce\Admin\ProductKindField;
use BillTo\WooCommerce\Admin\SettingsTab;
use BillTo\WooCommerce\Api\Client;
use BillTo\WooCommerce\Checkout\InvoiceFields;
use BillTo\WooCommerce\Email\InvoiceAttachment;
use BillTo\WooCommerce\Frontend\MyAccount;
use BillTo\WooCommerce\Support\Logger;
use BillTo\WooCommerce\Support\Options;
use BillTo\WooCommerce\Support\PdfStorage;
use BillTo\WooCommerce\Support\UpdateChecker;
use BillTo\WooCommerce\Sync\Jobs;
use BillTo\WooCommerce\Sync\OrderMapper;
use BillTo\WooCommerce\Sync\OrderSync;
use BillTo\WooCommerce\Sync\RefundSync;

/**
 * Composition root: builds the services and registers every hook.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private Options $options;

    private Logger $logger;

    private ?Client $client = null;

    private OrderSync $orderSync;

    private RefundSync $refundSync;

    private PdfStorage $pdfStorage;

    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    private function __construct()
    {
        $this->options = new Options;
        $this->logger = new Logger;
        $this->pdfStorage = new PdfStorage;
        $mapper = new OrderMapper($this->options);
        $this->orderSync = new OrderSync($this->options, $this->logger, $mapper, $this->pdfStorage, fn (): Client => $this->client());
        $this->refundSync = new RefundSync($this->options, $this->logger, $this->pdfStorage, fn (): Client => $this->client());
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        load_plugin_textdomain('billto-woocommerce', false, dirname(BILLTO_WC_BASENAME).'/languages');

        (new SettingsTab($this->options, fn (): Client => $this->client(), $this->connection()))->register();
        (new OAuthConnectController($this->options, $this->connection()))->register();
        (new InvoiceFields($this->options))->register();
        (new Jobs($this->orderSync, $this->refundSync, $this->logger))->register();
        $this->orderSync->registerHooks();
        $this->refundSync->registerHooks();
        (new OrderMetaBox($this->options))->register();
        (new OrderActions($this->orderSync, $this->pdfStorage, fn (): Client => $this->client()))->register();
        (new OrdersListColumn)->register();
        (new ProductKindField)->register();
        (new MyAccount)->register();
        (new InvoiceAttachment($this->options, $this->pdfStorage))->register();
        (new UpdateChecker)->register();

        add_filter('plugin_action_links_'.BILLTO_WC_BASENAME, static function (array $links): array {
            $url = admin_url('admin.php?page=wc-settings&tab=billto');
            array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Ustawienia', 'billto-woocommerce').'</a>');

            return $links;
        });
    }

    private ?Connection $connection = null;

    /**
     * Lazily built API client - settings may change between requests, and most page loads never call the API.
     */
    public function client(): Client
    {
        // WYLACZNIE OAuth. Wklejanie tokenu API do wtyczki znaczylo oddanie sklepowi
        // dlugowiecznego poswiadczenia CALEJ firmy, bez zakresu wezszego niz uprawnienia tokenu
        // i bez mozliwosci odebrania dostepu inaczej niz przez skasowanie tokenu uzywanego byc
        // moze przez cos jeszcze. Po polaczeniu OAuth sklep dostaje token jednej firmy,
        // o zakresie, ktory wlasciciel widzi na ekranie zgody i moze cofnac po stronie BillTo.
        return $this->client ??= new Client(
            (string) $this->connection()->accessToken(),
            $this->options->baseUrl(),
            $this->logger
        );
    }

    /** Stan polaczenia OAuth tego sklepu - budowany leniwie, jak klient API. */
    public function connection(): Connection
    {
        return $this->connection ??= new Connection(
            $this->logger,
            new OAuthClient($this->options->oauthBaseUrl(), new WpHttpPost)
        );
    }

    public function options(): Options
    {
        return $this->options;
    }

    public function orderSync(): OrderSync
    {
        return $this->orderSync;
    }

    public static function activate(): void
    {
        (new PdfStorage)->ensureDirectory();
        (new Options)->seedDefaults();
    }
}
