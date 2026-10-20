<?php

use App\Models\BridgeRequest;
use App\Services\BridgeAdmissionService;
use App\Services\BridgeService;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Fixtures\BridgeNativeSourceProof as Proof;

beforeEach(function () {
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('services.bridge.evm_bridge_address', '0x'.str_repeat('2', 40));
    config()->set('bridge.chains.solana.rpc_url', 'https://native-broadcast.test');
    config()->set('bridge.chains.solana.deposit_address', Proof::HOT_WALLET);
    Http::preventStrayRequests();
});

function nativeBroadcastRequest(bool $convert): BridgeRequest
{
    $request = BridgeRequest::create([
        // Signed by the sender: these tests are about the relay, not B03.
        'recipient_proof' => 'signature',
        'recipient_proven_at' => now(),
        'direction' => 'sol_to_evm',
        'token' => 'CYBER.sol',
        'source_chain' => 'solana',
        'source_tx_hash' => 'solana-broadcast-test',
        'source_nonce' => 0,
        'sender_address' => Proof::SOLANA_SENDER,
        'recipient_address' => '0x5555555555555555555555555555555555555555',
        'amount' => '1',
        'fee_amount' => '0',
        'convert_to_native' => $convert,
        'status' => BridgeRequest::PENDING,
    ]);

    app(BridgeAdmissionService::class)->commit($request, null);
    Http::fake(['https://native-broadcast.test' => Http::response(['result' => Proof::solana($request)])]);

    return $request;
}

test('a native relay receipt timeout retains its first mint broadcast even when conversion output shares a chunk', function (bool $convert) {
    $request = nativeBroadcastRequest($convert);
    $mintHash = '0x'.str_repeat('a', 64);
    $stdout = json_encode(['broadcastTxHash' => $mintHash])."\n";

    if ($convert) {
        $stdout .= json_encode(['broadcastTxHash' => '0x'.str_repeat('b', 64)])."\n";
    }

    $pending = Mockery::mock(PendingProcess::class);
    $pending->shouldReceive('env')->once()->andReturnSelf();
    $pending->shouldReceive('timeout')->once()->andReturnSelf();
    $pending->shouldReceive('run')->once()->andReturnUsing(function ($command, $output) use ($stdout) {
        $output('out', $stdout);
        $process = new Symfony\Component\Process\Process(['true']);

        throw new ProcessTimedOutException(
            new Symfony\Component\Process\Exception\ProcessTimedOutException(
                $process,
                Symfony\Component\Process\Exception\ProcessTimedOutException::TYPE_GENERAL,
            ),
            new ProcessResult($process),
        );
    });
    Process::shouldReceive('path')->once()->andReturn($pending);

    $service = app(BridgeService::class);

    expect($service->processSolToEvm($request))->toBeFalse()
        ->and($request->refresh()->status)->toBe(BridgeRequest::PAYING_OUT)
        ->and($request->destination_tx_hash)->toBe($mintHash)
        ->and($request->payout_broadcast_at)->not->toBeNull()
        ->and($request->completed_at)->toBeNull();

    // The mocked process is permitted only once, including on a retry.
    $service->processSolToEvm($request->refresh());
    Http::assertSentCount(1);
})->with(['plain release' => [false], 'conversion' => [true]]);

test('a completed native conversion keeps the final recipient payout and conversion result', function () {
    $request = nativeBroadcastRequest(true);
    $mintHash = '0x'.str_repeat('a', 64);
    $payoutHash = '0x'.str_repeat('b', 64);

    Process::fake([
        '*relay-bridge*' => Process::result(output: json_encode(['broadcastTxHash' => $mintHash])."\n"
            .json_encode(['broadcastTxHash' => $payoutHash])."\n"
            .json_encode(['txHash' => $payoutHash, 'converted' => true, 'status' => 'success']),
        ),
    ]);

    $service = app(BridgeService::class);

    expect($service->processSolToEvm($request))->toBeTrue()
        ->and($request->refresh()->status)->toBe(BridgeRequest::COMPLETED)
        ->and($request->destination_tx_hash)->toBe($payoutHash)
        ->and($request->converted)->toBeTrue();

    Http::assertSentCount(1);
});
