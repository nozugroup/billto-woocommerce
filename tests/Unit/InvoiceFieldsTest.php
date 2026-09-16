<?php

declare(strict_types=1);

use BillTo\WooCommerce\Checkout\InvoiceFields;
use BillTo\WooCommerce\Support\Options;

it('lets consumers ask for an invoice without a NIP by default', function () {
    stubSettings();
    $fields = new InvoiceFields(new Options);

    expect($fields->validateNip(true, '', 'PL'))->toBeNull()
        ->and($fields->validateNip(false, '', 'PL'))->toBeNull();
});

it('requires a NIP for invoices only when the "companies only" setting is on', function () {
    stubSettings(['nip_required' => 'yes']);
    $fields = new InvoiceFields(new Options);

    expect($fields->validateNip(true, '', 'PL'))->not->toBeNull()
        ->and($fields->validateNip(false, '', 'PL'))->toBeNull()
        ->and($fields->validateNip(true, '5261040828', 'PL'))->toBeNull();
});

it('checks the Polish checksum and the EU VAT shape', function () {
    stubSettings();
    $fields = new InvoiceFields(new Options);

    expect($fields->validateNip(true, '5261040828', 'PL'))->toBeNull()
        ->and($fields->validateNip(true, '5261040829', 'PL'))->not->toBeNull()
        ->and($fields->validateNip(true, 'DE123456789', 'DE'))->toBeNull()
        ->and($fields->validateNip(true, '123456789', 'DE'))->toBeNull() // country prefix added implicitly
        ->and($fields->validateNip(true, '!!', 'DE'))->not->toBeNull()
        ->and($fields->validateNip(true, 'anything', 'US'))->toBeNull();
});
