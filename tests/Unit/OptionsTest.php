<?php

declare(strict_types=1);

use BillTo\WooCommerce\Support\Options;
use Brain\Monkey\Functions;

it('resolves the base url per environment', function () {
    stubSettings(['environment' => Options::ENV_SANDBOX]);
    expect((new Options)->baseUrl())->toBe(Options::URL_SANDBOX)->and((new Options)->isSandbox())->toBeTrue();

    stubSettings(['environment' => Options::ENV_CUSTOM, 'custom_url' => 'https://dev.billto.test/api/v1/']);
    expect((new Options)->baseUrl())->toBe('https://dev.billto.test/api/v1');

    stubSettings(['environment' => Options::ENV_CUSTOM, 'custom_url' => '']);
    expect((new Options)->baseUrl())->toBe(Options::URL_PRODUCTION);
});

it('maps delivery modes to behaviour flags', function () {
    stubSettings(['delivery_mode' => Options::DELIVERY_BILLTO_EMAIL]);
    expect((new Options)->billtoSendsEmail())->toBeTrue()->and((new Options)->attachPdfToWcEmail())->toBeFalse();

    stubSettings(['delivery_mode' => Options::DELIVERY_WC_ATTACHMENT]);
    expect((new Options)->billtoSendsEmail())->toBeFalse()->and((new Options)->attachPdfToWcEmail())->toBeTrue();

    stubSettings(['delivery_mode' => Options::DELIVERY_LINK_ONLY]);
    expect((new Options)->billtoSendsEmail())->toBeFalse()->and((new Options)->attachPdfToWcEmail())->toBeFalse();
});

it('strips the wc- prefix from paid statuses and tolerates bad values', function () {
    stubSettings(['paid_statuses' => ['wc-processing', 'completed']]);
    expect((new Options)->paidStatuses())->toBe(['processing', 'completed']);

    stubSettings(['paid_statuses' => 'garbage']);
    expect((new Options)->paidStatuses())->toBe(['processing', 'completed']);
});

it('exposes chosen series ids as null when unset', function () {
    stubSettings();
    expect((new Options)->seriesId())->toBeNull()->and((new Options)->korSeriesId())->toBeNull();

    stubSettings(['series_id' => ' abc ', 'kor_series_id' => 'kor-1']);
    expect((new Options)->seriesId())->toBe('abc')->and((new Options)->korSeriesId())->toBe('kor-1');
});

it('reads buyer scenario settings with safe defaults', function () {
    stubSettings();
    expect((new Options)->ossMode())->toBe(Options::OSS_PL_VAT)
        ->and((new Options)->euB2bEnabled())->toBeTrue()
        ->and((new Options)->nonEuEnabled())->toBeTrue()
        ->and((new Options)->ossSeriesId())->toBeNull();

    stubSettings(['oss_mode' => 'nonsense', 'eu_b2b_enabled' => 'no', 'oss_series_id' => 'oss-1']);
    expect((new Options)->ossMode())->toBe(Options::OSS_PL_VAT)
        ->and((new Options)->euB2bEnabled())->toBeFalse()
        ->and((new Options)->ossSeriesId())->toBe('oss-1');

    stubSettings(['oss_mode' => Options::OSS_INVOICE]);
    expect((new Options)->ossMode())->toBe(Options::OSS_INVOICE);
});

it('reads amount, payment and VIES settings with safe defaults', function () {
    stubSettings(['amount_mode' => Options::AMOUNT_GROSS]);
    expect((new Options)->amountEntryMode())->toBe('gross')
        ->and((new Options)->unpaidGateways())->toBe(['cod', 'bacs'])
        ->and((new Options)->isUnpaidGateway('cod'))->toBeTrue()
        ->and((new Options)->isUnpaidGateway('cheque'))->toBeFalse()
        ->and((new Options)->isUnpaidGateway(null))->toBeFalse()
        ->and((new Options)->settleStatus())->toBe('completed')
        ->and((new Options)->negativeLinesMode())->toBe(Options::NEGATIVE_DISTRIBUTE)
        ->and((new Options)->amountRefundMode())->toBe(Options::REFUND_PROPORTIONAL)
        ->and((new Options)->viesCheck())->toBe(Options::VIES_BLOCK)
        ->and((new Options)->resyncOnEdit())->toBeTrue();

    stubSettings(['amount_mode' => Options::AMOUNT_AUTO, 'settle_status' => 'wc-on-hold', 'vies_check' => 'bogus', 'negative_lines' => 'skip']);
    Functions\when('wc_prices_include_tax')->justReturn(false);
    expect((new Options)->amountEntryMode())->toBe('net')
        ->and((new Options)->settleStatus())->toBe('on-hold')
        ->and((new Options)->viesCheck())->toBe(Options::VIES_BLOCK)
        ->and((new Options)->negativeLinesMode())->toBe(Options::NEGATIVE_SKIP);

    Functions\when('wc_prices_include_tax')->justReturn(true);
    expect((new Options)->amountEntryMode())->toBe('gross');
});
