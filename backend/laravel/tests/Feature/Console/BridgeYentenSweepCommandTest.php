<?php

use App\Models\BridgeRequest;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('bridge.chains.yenten.deposit_address', 'YCentralWallet111111111111111111111');
    config()->set('bridge.chains.yenten.hd_seed', str_repeat('ab', 32));
});

function completedYentenRequest(array $overrides = []): BridgeRequest
{
    return BridgeRequest::create(array_merge([
        'direction' => 'yenten_to_evm',
        'token' => 'YTN',
        'source_chain' => 'yenten',
        'source_tx_hash' => 'ytn-'.uniqid(),
        'source_nonce' => 0,
        'recipient_address' => '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045',
        'deposit_address' => 'YDeposit'.uniqid(),
        'amount' => '2',
        'status' => 'completed',
        'swept' => false,
    ], $overrides));
}

test('sweeps unswept completed requests into the central wallet', function () {
    Process::fake([
        '*sweep*' => Process::result(output: json_encode(['txHash' => 'ytnsweep'])),
    ]);

    $request = completedYentenRequest();

    $this->artisan('bridge:yenten-sweep')->assertExitCode(0);

    expect($request->fresh()->swept)->toBeTrue();
    Process::assertRan(fn ($p) => str_contains(implode(' ', $p->command), 'sweep')
        && str_contains(implode(' ', $p->command), 'YCentralWallet111111111111111111111'));
});

test('skips already-swept requests', function () {
    Process::fake(['*sweep*' => Process::result(output: json_encode(['txHash' => 'x']))]);

    completedYentenRequest(['swept' => true]);

    $this->artisan('bridge:yenten-sweep')->expectsOutputToContain('Nothing to sweep')->assertExitCode(0);

    Process::assertNothingRan();
});

test('leaves the request unswept when the sweep script fails', function () {
    Process::fake(['*sweep*' => Process::result(errorOutput: 'no utxos', exitCode: 1)]);

    $request = completedYentenRequest();

    $this->artisan('bridge:yenten-sweep')->assertExitCode(0);

    expect($request->fresh()->swept)->toBeFalse();
});
