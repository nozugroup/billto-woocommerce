<?php

declare(strict_types=1);

use BillTo\WooCommerce\Api\Client;
use BillTo\WooCommerce\Support\Logger;
use BillTo\WooCommerce\Support\Meta;
use BillTo\WooCommerce\Support\Options;
use BillTo\WooCommerce\Support\PdfStorage;
use BillTo\WooCommerce\Sync\RefundSync;

function refundItem(int $originalId, float $qty, float $total = -10.0): Mockery\MockInterface
{
    $item = Mockery::mock('WC_Order_Item_Product');
    $item->shouldReceive('get_meta')->with('_refunded_item_id')->andReturn($originalId);
    $item->shouldReceive('get_quantity')->andReturn(-$qty);
    $item->shouldReceive('get_total')->andReturn((string) $total);

    return $item;
}

function originalItem(float $qty, string $type = 'line_item'): Mockery\MockInterface
{
    $item = Mockery::mock('WC_Order_Item_Product');
    $item->shouldReceive('get_quantity')->andReturn($qty);
    $item->shouldReceive('get_type')->andReturn($type);

    return $item;
}

it('builds correction lines with the remaining quantity, cumulative across refunds', function () {
    stubSettings();
    $sync = new RefundSync(new Options, new Logger, new PdfStorage, static fn () => Mockery::mock(Client::class));

    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('get_meta')->with(Meta::LINE_MAP)->andReturn(json_encode([10 => 1, 20 => 2, 30 => 3]));
    $order->shouldReceive('get_item')->with(10)->andReturn(originalItem(5));
    $order->shouldReceive('get_item')->with(20)->andReturn(originalItem(1, 'shipping'));
    $order->shouldReceive('get_item')->with(99)->andReturn(false);
    // Item 10: total refunded so far is 3 (1 earlier + 2 now) -> remaining 5 - 1 - 2 = 2.
    $order->shouldReceive('get_qty_refunded_for_item')->with(10)->andReturn(-3);
    $order->shouldReceive('get_qty_refunded_for_item')->with(20)->andReturn(-1);

    $refund = Mockery::mock('WC_Order_Refund');
    $refund->shouldReceive('get_items')->andReturn([
        refundItem(10, 2),
        refundItem(20, 1, -12.20),
        refundItem(99, 1), // unknown original item is ignored
    ]);

    expect($sync->linesForRefund($order, $refund))->toBe([
        ['line_number' => 1, 'quantity' => 2.0],
        ['line_number' => 2, 'quantity' => 0.0],
    ]);
});

it('returns no lines for an amount-only refund', function () {
    stubSettings();
    $sync = new RefundSync(new Options, new Logger, new PdfStorage, static fn () => Mockery::mock(Client::class));

    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('get_meta')->with(Meta::LINE_MAP)->andReturn(json_encode([10 => 1]));
    $refund = Mockery::mock('WC_Order_Refund');
    $refund->shouldReceive('get_items')->andReturn([]);

    expect($sync->linesForRefund($order, $refund))->toBe([]);
});

it('turns an amount-only refund into a proportional price reduction', function () {
    stubSettings();
    $sync = new RefundSync(new Options, new Logger, new PdfStorage, static fn () => Mockery::mock(Client::class));

    $product = Mockery::mock('WC_Order_Item_Product');
    $product->shouldReceive('get_quantity')->andReturn(2.0);
    $shipping = Mockery::mock('WC_Order_Item_Shipping');
    $shipping->shouldReceive('get_total')->andReturn('10.00');

    $order = Mockery::mock('WC_Order');
    $order->shouldReceive('get_total')->andReturn('246.00'); // 200 net + 23% ... gross
    $order->shouldReceive('get_meta')->with(Meta::LINE_MAP)->andReturn(json_encode([10 => 1, 20 => 2]));
    $order->shouldReceive('get_item')->with(10)->andReturn($product);
    $order->shouldReceive('get_item')->with(20)->andReturn($shipping);
    $order->shouldReceive('get_line_total')->with($product, false, false)->andReturn(190.0);

    // 10% refund -> every net unit price down by 10%.
    expect($sync->proportionalLines($order, 24.60))->toBe([
        ['line_number' => 1, 'after_unit_price' => 85.5],
        ['line_number' => 2, 'after_unit_price' => 9.0],
    ]);

    // Full refund or zero -> not proportional (line refund expected).
    expect($sync->proportionalLines($order, 246.0))->toBe([])
        ->and($sync->proportionalLines($order, 0.0))->toBe([]);
});
