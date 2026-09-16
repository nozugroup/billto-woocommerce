<?php

declare(strict_types=1);

namespace BillTo\WooCommerce\Sync;

use BillTo\Shop\BuyerScenario;
use BillTo\Shop\MarkPaidPayload;
use BillTo\Shop\OrderPayloadBuilder;
use BillTo\Shop\VatMapper;
use BillTo\Shop\Vies\Vies;
use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\Options;
use WC_Order;

/**
 * WooCommerce -> BillTo payloads, delegating every business decision to the shared core
 * (billto/shop-integration-core): buyer scenario, VAT mapping, gross/net amounts, negative
 * lines, OSS rate, mark-paid flags. This class only knows WooCommerce.
 */
class OrderMapper
{
    public const SOURCE = 'woocommerce';

    public function __construct(private Options $options, private ?OrderAdapter $adapter = null)
    {
        $this->adapter ??= new OrderAdapter;
    }

    /**
     * Body of POST /orders + line map + flags.
     *
     * @return array{payload: array<string, mixed>, lineMap: array<string, int>, hasNegativeLines: bool, scenario: string}
     */
    public function build(WC_Order $order, bool $confirmed = true): array
    {
        $core = $this->adapter->toCoreOrder($order);
        $result = (new OrderPayloadBuilder($this->options->toSettings()))->build($core, $this->viesStatus($order), $confirmed);

        /**
         * Lets a site tweak the payload (e.g. rename shipping lines) before it is sent.
         *
         * @param array<string, mixed> $payload
         * @param WC_Order $order
         */
        $result['payload'] = (array) apply_filters('billto_wc_order_payload', $result['payload'], $order);

        return $result;
    }

    /**
     * Body of POST /orders/{id}/mark-paid.
     *
     * @return array<string, mixed>
     */
    public function markPaidPayload(WC_Order $order): array
    {
        return MarkPaidPayload::build($this->adapter->toCoreOrder($order), $this->options->toSettings());
    }

    /**
     * Body of POST /orders/{correcting}/issue-kor.
     *
     * @return array<string, mixed>
     */
    public function issueKorPayload(): array
    {
        return MarkPaidPayload::issueKor($this->options->toSettings());
    }

    /** Buyer scenario used for rate mapping (VIES fallback included). */
    public function effectiveScenario(WC_Order $order): string
    {
        return BuyerScenario::effective($this->adapter->buyer($order), $this->options->toSettings(), $this->viesStatus($order));
    }

    /** Raw buyer scenario (no VIES fallback). */
    public function scenario(WC_Order $order): string
    {
        return BuyerScenario::forBuyer($this->adapter->buyer($order));
    }

    public function ossVatType(WC_Order $order): ?string
    {
        return VatMapper::ossRate($this->adapter->toCoreOrder($order));
    }

    public function adapter(): OrderAdapter
    {
        return $this->adapter;
    }

    private function viesStatus(WC_Order $order): ?string
    {
        $vies = json_decode((string) $order->get_meta(Meta::VIES), true);

        if (is_array($vies) && in_array($vies['status'] ?? null, [Vies::VALID, Vies::INVALID, Vies::UNAVAILABLE], true)) {
            return (string) $vies['status'];
        }

        return null;
    }
}
