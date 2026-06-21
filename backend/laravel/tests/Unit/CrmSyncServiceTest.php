<?php

use App\Actions\Wallet\ReadCyberSolBalance;
use App\Services\CrmSyncService;

function makeSyncService(): CrmSyncService
{
    return new CrmSyncService(new ReadCyberSolBalance);
}

test('a balance at or above the threshold qualifies as a whale', function () {
    $service = makeSyncService();

    expect($service->shouldBeWhale(10_000_000.0, 10_000_000))->toBeTrue();
    expect($service->shouldBeWhale(25_000_000.0, 10_000_000))->toBeTrue();
});

test('a balance below the threshold does not qualify', function () {
    $service = makeSyncService();

    expect($service->shouldBeWhale(9_999_999.0, 10_000_000))->toBeFalse();
});

test('a null balance never qualifies', function () {
    expect(makeSyncService()->shouldBeWhale(null, 10_000_000))->toBeFalse();
});
