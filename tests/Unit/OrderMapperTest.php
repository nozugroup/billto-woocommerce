<?php

declare(strict_types=1);

use BillTo\Shop\BuyerScenario as CoreScenario;
use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\Options;
use BillTo\WooCommerce\Sync\OrderAdapter;
use BillTo\WooCommerce\Sync\OrderMapper;

/**
 * Adapter with the WooCommerce tax lookup replaced by a fixed percentage per item id.
 */
final class TestableAdapter extends OrderAdapter
{
    /** @var array<int, float|null> */
    public array $percents = [];

    protected function taxPercent(WC_Order $order, WC_Order_Item $item): ?float
    {
        return $this->percents[$item->get_id()] ?? null;
    }
}

/**
 * @param  array<string, mixed>  $meta
 */
function fakeOrder(array $meta = [], string $country = 'PL', string $company = 'ACME sp. z o.o.', string $gateway = 'cheque'): Mockery\MockInterface
{
    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('get_id')->andReturn(42);
    $order->shouldReceive('get_order_number')->andReturn('42');
    $order->shouldReceive('get_currency')->andReturn('PLN');
    $created = Mockery::mock('WC_DateTime');
    $created->shouldReceive('date')->with('Y-m-d')->andReturn('2026-09-14');
    $order->shouldReceive('get_date_created')->andReturn($created);
    $order->shouldReceive('get_billing_country')->andReturn($country);
    $order->shouldReceive('get_billing_company')->andReturn($company);
    $order->shouldReceive('get_billing_first_name')->andReturn('Jan');
    $order->shouldReceive('get_billing_last_name')->andReturn('Kowalski');
    $order->shouldReceive('get_billing_address_1')->andReturn('Marszałkowska 1');
    $order->shouldReceive('get_billing_address_2')->andReturn('lok. 2');
    $order->shouldReceive('get_billing_postcode')->andReturn('00-001');
    $order->shouldReceive('get_billing_city')->andReturn('Warszawa');
    $order->shouldReceive('get_billing_phone')->andReturn('+48 600 000 000');
    $order->shouldReceive('get_billing_email')->andReturn('jan@acme.pl');
    $order->shouldReceive('get_payment_method')->andReturn($gateway);
    $order->shouldReceive('get_payment_method_title')->andReturn('Przelewy24');
    $order->shouldReceive('get_customer_note')->andReturn('');
    $order->shouldReceive('get_meta')->andReturnUsing(static fn (string $key) => $meta[$key] ?? '');

    return $order;
}

function fakeLineItem(int $id, string $name, float $qty, ?string $sku = null, string $kind = ''): Mockery\MockInterface
{
    $product = Mockery::mock('WC_Product');
    $product->shouldReceive('get_sku')->andReturn($sku ?? '');
    $product->shouldReceive('get_meta')->with(\BillTo\WooCommerce\Support\ProductKind::META)->andReturn($kind);
    $product->shouldReceive('is_virtual')->andReturn(false);

    $item = Mockery::mock('WC_Order_Item_Product');
    $item->shouldReceive('get_id')->andReturn($id);
    $item->shouldReceive('get_name')->andReturn($name);
    $item->shouldReceive('get_quantity')->andReturn($qty);
    $item->shouldReceive('get_product')->andReturn($product);

    return $item;
}

function fakeShipping(int $id, string $name, float $total, float $tax = 0.0): Mockery\MockInterface
{
    $item = Mockery::mock('WC_Order_Item_Shipping');
    $item->shouldReceive('get_id')->andReturn($id);
    $item->shouldReceive('get_name')->andReturn($name);
    $item->shouldReceive('get_method_title')->andReturn($name);
    $item->shouldReceive('get_total')->andReturn((string) $total);
    $item->shouldReceive('get_total_tax')->andReturn((string) $tax);

    return $item;
}

/**
 * @param  array<int, array{0: float, 1: float}>  $lineTotals  item id => [net, gross]
 */
function withItems(Mockery\MockInterface $order, array $products, array $shipping, array $fees, array $lineTotals): void
{
    $order->shouldReceive('get_items')->with('line_item')->andReturn($products);
    $order->shouldReceive('get_items')->with('shipping')->andReturn($shipping);
    $order->shouldReceive('get_items')->with('fee')->andReturn($fees);
    $order->shouldReceive('get_line_total')->andReturnUsing(static function ($item, bool $inclusive) use ($lineTotals): float {
        return $lineTotals[$item->get_id()][$inclusive ? 1 : 0] ?? 0.0;
    });
}

function mapper(array $settings = [], array $percents = []): OrderMapper
{
    stubSettings($settings);
    $adapter = new TestableAdapter;
    $adapter->percents = $percents;

    return new OrderMapper(new Options, $adapter);
}

it('maps a domestic B2B order with shipping into a confirmed BillTo order (net mode)', function () {
    $order = fakeOrder([Meta::NIP => '526-104-08-28']);
    withItems($order, [fakeLineItem(10, 'Kubek', 2, 'KUB-1')], [fakeShipping(20, 'Kurier', 12.20, 2.81)], [], [10 => [162.60, 200.0]]);

    $built = mapper(['amount_mode' => Options::AMOUNT_NET], [10 => 23.0, 20 => 23.0])->build($order);
    $payload = $built['payload'];

    expect($built['scenario'])->toBe(CoreScenario::PL_B2B)
        ->and($built['lineMap'])->toBe(['10' => 1, '20' => 2])
        ->and($payload)->toMatchArray(['external_id' => '42', 'source' => 'woocommerce', 'status' => 'confirmed', 'currency' => 'PLN', 'order_date' => '2026-09-14', 'amount_entry_mode' => 'net'])
        ->and($payload['buyer'])->toMatchArray(['name' => 'ACME sp. z o.o.', 'tax_type' => 'local', 'tax_number' => '5261040828', 'country_code' => 'PL', 'address_line_1' => 'Marszałkowska 1 lok. 2', 'address_line_2' => '00-001 Warszawa', 'email' => 'jan@acme.pl'])
        ->and($payload['items'])->toBe([
            ['name' => 'Kubek [KUB-1]', 'quantity' => 2.0, 'units' => 'szt.', 'unit_price' => 81.3, 'vat_type' => '23'],
            ['name' => 'Dostawa: Kurier', 'quantity' => 1.0, 'units' => 'usł.', 'unit_price' => 12.2, 'vat_type' => '23'],
        ])
        ->and($payload['notes'])->toContain('Zamówienie WooCommerce #42')->toContain('Przelewy24');
});

it('sends gross unit prices in gross mode', function () {
    $order = fakeOrder([Meta::NIP => '5261040828']);
    withItems($order, [fakeLineItem(1, 'Kubek', 2)], [fakeShipping(2, 'Kurier', 9.92, 2.28)], [], [1 => [80.0, 98.40]]);

    $payload = mapper(['amount_mode' => Options::AMOUNT_GROSS], [1 => 23.0, 2 => 23.0])->build($order)['payload'];

    expect($payload['amount_entry_mode'])->toBe('gross')
        ->and($payload['items'][0])->toMatchArray(['unit_price' => 40.0, 'unit_price_gross' => 49.2])
        ->and($payload['items'][1])->toMatchArray(['unit_price' => 9.92, 'unit_price_gross' => 12.2]);
});

it('maps a private buyer without NIP as tax_type none with the person name', function () {
    $order = fakeOrder([], 'PL', '');
    withItems($order, [fakeLineItem(1, 'Książka', 1)], [], [], [1 => [50.0, 52.5]]);

    $m = mapper(['amount_mode' => Options::AMOUNT_NET], [1 => 5.0]);
    $built = $m->build($order);

    expect($built['payload']['buyer']['name'])->toBe('Jan Kowalski')
        ->and($built['payload']['buyer']['tax_type'])->toBe('none')
        ->and($built['payload']['buyer'])->not->toHaveKey('tax_number')
        ->and($built['payload']['items'][0]['vat_type'])->toBe('5')
        ->and($m->scenario($order))->toBe(CoreScenario::PL_B2C);
});

it('maps foreign buyers by goods/service and zero-rate settings', function () {
    $lines = [fakeLineItem(1, 'Widget', 1), fakeLineItem(2, 'Consulting', 1, null, 'service')];
    $totals = [1 => [100.0, 100.0], 2 => [100.0, 100.0]];

    $eu = fakeOrder([Meta::NIP => 'DE123456789'], 'DE');
    withItems($eu, $lines, [fakeShipping(3, 'DHL', 20.0)], [], $totals);
    $built = mapper([], [1 => 0.0, 2 => 0.0, 3 => 0.0])->build($eu);
    expect($built['payload']['buyer'])->toMatchArray(['tax_type' => 'eu', 'tax_number' => 'DE123456789', 'tax_country' => 'DE'])
        ->and(array_column($built['payload']['items'], 'vat_type'))->toBe(['0 WDT', 'np I', '0 WDT']);

    $us = fakeOrder([Meta::NIP => '12-3456789'], 'US');
    withItems($us, $lines, [], [], $totals);
    $built = mapper([], [1 => 0.0, 2 => 0.0])->build($us);
    expect($built['payload']['buyer']['tax_type'])->toBe('noneu')
        ->and(array_column($built['payload']['items'], 'vat_type'))->toBe(['0 EX', 'np II']);

    $pl = fakeOrder([], 'PL');
    withItems($pl, [fakeLineItem(1, 'Widget', 1)], [], [], $totals);
    expect(mapper(['vat_zero' => 'zw', 'vat_no_tax' => 'np I'], [1 => 0.0])->build($pl)['payload']['items'][0]['vat_type'])->toBe('zw')
        ->and(mapper(['vat_zero' => 'zw', 'vat_no_tax' => 'np I'], [1 => null])->build($pl)['payload']['items'][0]['vat_type'])->toBe('np I');
});

it('spreads a negative fee over the product lines or flags it, per settings', function () {
    $fee = Mockery::mock('WC_Order_Item_Fee');
    $fee->shouldReceive('get_id')->andReturn(4);
    $fee->shouldReceive('get_name')->andReturn('Rabat');
    $fee->shouldReceive('get_total')->andReturn('-30');
    $fee->shouldReceive('get_total_tax')->andReturn('-6.9');
    $totals = [1 => [100.0, 123.0], 2 => [100.0, 123.0]];

    $order = fakeOrder();
    withItems($order, [fakeLineItem(1, 'A', 2), fakeLineItem(2, 'B', 1)], [fakeShipping(3, 'Kurier', 10.0, 2.3)], [$fee], $totals);
    $built = mapper(['amount_mode' => Options::AMOUNT_GROSS], [1 => 23.0, 2 => 23.0, 3 => 23.0, 4 => 23.0])->build($order);
    expect($built['hasNegativeLines'])->toBeTrue()
        ->and($built['payload']['items'])->toHaveCount(3)
        ->and($built['payload']['items'][0])->toMatchArray(['unit_price' => 42.5, 'unit_price_gross' => 52.275])
        ->and($built['payload']['items'][0]['name'])->toContain('(z rabatem)')
        ->and($built['lineMap'])->toBe(['1' => 1, '2' => 2, '3' => 3]);

    $order = fakeOrder();
    withItems($order, [fakeLineItem(1, 'A', 2), fakeLineItem(2, 'B', 1)], [fakeShipping(3, 'Kurier', 10.0, 2.3)], [$fee], $totals);
    $skipped = mapper(['negative_lines' => Options::NEGATIVE_SKIP], [1 => 23.0, 2 => 23.0, 3 => 23.0, 4 => 23.0])->build($order);
    expect($skipped['hasNegativeLines'])->toBeTrue()->and($skipped['payload']['items'])->toHaveCount(4);
});

it('uses domestic rates for an EU company whose VAT id failed VIES when the fallback is on', function () {
    $vies = json_encode(['status' => 'invalid', 'name' => null, 'checked_at' => 'now']);

    // The shop applied 0% (reverse charge configured); the failed VIES check alone decides the mapping.
    $order = fakeOrder([Meta::NIP => 'DE123456789', Meta::VIES => $vies], 'DE');
    withItems($order, [fakeLineItem(1, 'Widget', 1)], [], [], [1 => [100.0, 100.0]]);
    $domestic = mapper(['vies_check' => Options::VIES_DOMESTIC], [1 => 0.0]);
    $built = $domestic->build($order);
    expect($domestic->effectiveScenario($order))->toBe(CoreScenario::EU_B2B_DOMESTIC)
        ->and($built['payload']['items'][0]['vat_type'])->toBe('23')
        ->and($built['warnings'])->toBe([\BillTo\Shop\Settings::WARNING_VIES_INVALID_DOMESTIC]);

    $order = fakeOrder([Meta::NIP => 'DE123456789', Meta::VIES => $vies], 'DE');
    withItems($order, [fakeLineItem(1, 'Widget', 1)], [], [], [1 => [100.0, 100.0]]);
    $block = mapper(['vies_check' => Options::VIES_BLOCK], [1 => 0.0]);
    expect($block->effectiveScenario($order))->toBe(CoreScenario::EU_B2B)
        ->and($block->build($order)['payload']['items'][0]['vat_type'])->toBe('0 WDT');
});

it('invoices a foreign buyer taxed by the shop with Polish rates and reports a warning', function () {
    $order = fakeOrder([Meta::NIP => 'DE123456789'], 'DE');
    withItems($order, [fakeLineItem(1, 'Widget', 1)], [], [], [1 => [100.0, 123.0]]);
    $built = mapper([], [1 => 23.0])->build($order);

    expect($built['scenario'])->toBe(CoreScenario::EU_B2B_DOMESTIC)
        ->and($built['payload']['items'][0]['vat_type'])->toBe('23')
        ->and($built['warnings'])->toBe([\BillTo\Shop\Settings::WARNING_FOREIGN_TAXED]);

    $kept = mapper(['foreign_taxed_follows_shop' => 'no'], [1 => 23.0])->build($order);
    expect($kept['scenario'])->toBe(CoreScenario::EU_B2B)->and($kept['payload']['items'][0]['vat_type'])->toBe('0 WDT');
});

it('builds the mark-paid body: unpaid gateways, series, OSS', function () {
    $cod = fakeOrder([Meta::NIP => '5261040828'], 'PL', 'ACME', 'cod');
    withItems($cod, [fakeLineItem(1, 'W', 1)], [], [], [1 => [100.0, 123.0]]);
    expect(mapper(['delivery_mode' => Options::DELIVERY_LINK_ONLY, 'series_id' => 'ser-1'], [1 => 23.0])->markPaidPayload($cod))
        ->toBe(['send_email' => false, 'mark_paid' => false, 'series_id' => 'ser-1']);

    $de = fakeOrder([], 'DE', '');
    withItems($de, [fakeLineItem(1, 'W', 1)], [fakeShipping(2, 'DHL', 10.0, 1.9)], [], [1 => [100.0, 119.0]]);
    $m = mapper(['oss_mode' => Options::OSS_INVOICE, 'oss_series_id' => 'oss-1'], [1 => 19.0, 2 => 19.0]);
    expect($m->markPaidPayload($de))->toBe(['send_email' => true, 'invoice_type' => 'oss', 'oss_vat_type' => '19', 'series_id' => 'oss-1'])
        ->and($m->ossVatType($de))->toBe('19');

    $de = fakeOrder([], 'DE', '');
    withItems($de, [fakeLineItem(1, 'W', 1)], [], [], [1 => [100.0, 100.0]]);
    expect(mapper(['oss_mode' => Options::OSS_INVOICE], [1 => 0.0])->markPaidPayload($de))->toBe(['send_email' => true, 'invoice_type' => 'oss']);

    expect(mapper(['kor_series_id' => 'kor-1', 'delivery_mode' => Options::DELIVERY_WC_ATTACHMENT])->issueKorPayload())->toBe(['send_email' => false, 'series_id' => 'kor-1']);
});
