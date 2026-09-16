<?php

declare(strict_types=1);

use BillTo\WooCommerce\Sync\BuyerScenario;

it('classifies buyers by country and tax id', function (string $country, bool $taxId, string $expected) {
    expect(BuyerScenario::classify($country, $taxId))->toBe($expected);
})->with([
    ['PL', false, BuyerScenario::PL_B2C],
    ['pl', true, BuyerScenario::PL_B2B],
    ['', false, BuyerScenario::PL_B2C],
    ['DE', false, BuyerScenario::EU_B2C],
    ['DE', true, BuyerScenario::EU_B2B],
    ['US', false, BuyerScenario::NON_EU],
    ['US', true, BuyerScenario::NON_EU],
    ['GB', true, BuyerScenario::NON_EU],
]);

it('knows which scenarios are domestic', function () {
    expect(BuyerScenario::isDomestic(BuyerScenario::PL_B2B))->toBeTrue()
        ->and(BuyerScenario::isDomestic(BuyerScenario::EU_B2C))->toBeFalse();
});
