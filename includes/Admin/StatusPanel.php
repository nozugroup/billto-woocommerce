<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Admin;

use BillTo\WooCommerce\Api\Client;
use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\Options;
use BillTo\WooCommerce\Sync\Jobs;

/**
 * "Stan integracji" box at the top of the settings tab: what is configured, what is queued,
 * what failed recently - so problems are visible without reading logs.
 */
final class StatusPanel
{
    /** @var callable(): Client */
    private $client;

    public function __construct(private Options $options, callable $client)
    {
        $this->client = $client;
    }

    public function render(): void
    {
        $rows = [];
        $client = ($this->client)();

        // Shows the API address in use, not just the environment name.
        $rows[] = [__('Połączenie', 'billto-woocommerce'), $client->isConfigured()
            ? $this->ok(sprintf(__('połączony przez OAuth: %s', 'billto-woocommerce'), $this->options->baseUrl()))
            : $this->bad(__('brak - użyj „Połącz z BillTo" poniżej', 'billto-woocommerce'))];

        $rows[] = [__('Kwoty', 'billto-woocommerce'), esc_html($this->options->amountEntryMode() === Options::AMOUNT_GROSS
            ? __('brutto (ceny sklepu zawierają podatek)', 'billto-woocommerce')
            : __('netto', 'billto-woocommerce'))];

        if (function_exists('as_get_scheduled_actions')) {
            $pending = count(as_get_scheduled_actions(['group' => Jobs::GROUP, 'status' => 'pending', 'per_page' => 50], 'ids'));
            $failed = count(as_get_scheduled_actions(['group' => Jobs::GROUP, 'status' => 'failed', 'per_page' => 50, 'date' => gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS), 'date_compare' => '>='], 'ids'));
            $rows[] = [__('Zadania w tle', 'billto-woocommerce'), sprintf(
                '%s %s, %s %s',
                esc_html((string) $pending), esc_html__('oczekujących', 'billto-woocommerce'),
                $failed > 0 ? $this->bad((string) $failed) : esc_html((string) $failed), esc_html__('nieudanych w 7 dni', 'billto-woocommerce'),
            ).' <a href="'.esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler&s=billto_wc')).'">'.esc_html__('pokaż', 'billto-woocommerce').'</a>'];
        }

        $errors = wc_get_orders([
            'limit' => 5,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [['key' => Meta::LAST_ERROR, 'compare' => 'EXISTS']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'return' => 'objects',
        ]);

        $errorHtml = $errors === []
            ? $this->ok(__('brak', 'billto-woocommerce'))
            : '<ul style="margin:0">'.implode('', array_map(static fn (\WC_Order $o) => '<li><a href="'.esc_url($o->get_edit_order_url()).'">#'.esc_html($o->get_order_number()).'</a>: '.esc_html((string) $o->get_meta(Meta::LAST_ERROR)).'</li>', $errors)).'</ul>';
        $rows[] = [__('Ostatnie błędy zamówień', 'billto-woocommerce'), $errorHtml];

        echo '<h2>'.esc_html__('Stan integracji', 'billto-woocommerce').'</h2>';
        echo '<table class="form-table billto-wc-status" style="max-width:900px"><tbody>';

        foreach ($rows as [$label, $html]) {
            echo '<tr><th scope="row" style="width:220px">'.esc_html($label).'</th><td>'.$html.'</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above
        }

        echo '</tbody></table>';
    }

    private function ok(string $text): string
    {
        return '<span style="color:#00a32a">&#10003; '.esc_html($text).'</span>';
    }

    private function bad(string $text): string
    {
        return '<span style="color:#d63638">&#9888; '.esc_html($text).'</span>';
    }
}
