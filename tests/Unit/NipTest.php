<?php

declare(strict_types=1);

use BillTo\WooCommerce\Support\Nip;

it('validates NIP checksums', function (string $nip, bool $valid) {
    expect(Nip::isValid($nip))->toBe($valid);
})->with([
    ['5261040828', true],
    ['526-104-08-28', true],
    ['PL 5261040828', true],
    ['5261040829', false],
    ['0000000000', false],
    ['123', false],
    ['abc', false],
]);

it('normalises NIP to digits', function () {
    expect(Nip::normalize('PL 526-104-08-28'))->toBe('5261040828');
});

it('recognises EU VAT identifiers', function () {
    expect(Nip::normalizeEuVat('DE 123 456 789'))->toBe('DE123456789')
        ->and(Nip::normalizeEuVat('de123456789'))->toBe('DE123456789')
        ->and(Nip::normalizeEuVat('123456789'))->toBeNull()
        ->and(Nip::normalizeEuVat('D'))->toBeNull();
});
