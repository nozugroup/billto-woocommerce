<?php

declare(strict_types=1);

use BillTo\WooCommerce\Api\ApiException;

it('classifies statuses', function () {
    expect((new ApiException('x', 0))->isRetryable())->toBeTrue()
        ->and((new ApiException('x', 503))->isRetryable())->toBeTrue()
        ->and((new ApiException('x', 429))->isRetryable())->toBeTrue()
        ->and((new ApiException('x', 429, ['usage' => ['used' => 1, 'limit' => 1]]))->isRetryable())->toBeFalse()
        ->and((new ApiException('x', 429, ['usage' => []]))->isPlanLimit())->toBeTrue()
        ->and((new ApiException('x', 422))->isRetryable())->toBeFalse()
        ->and((new ApiException('x', 409))->isConflict())->toBeTrue()
        ->and((new ApiException('x', 401))->isAuth())->toBeTrue();
});

it('flattens validation errors', function () {
    $e = new ApiException('invalid', 422, ['errors' => ['items.0.vat_type' => ['bad rate'], 'currency' => ['a', 'b']]]);

    expect($e->validationMessages())->toBe(['items.0.vat_type: bad rate', 'currency: a', 'currency: b']);
});
