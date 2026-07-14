<?php

use App\Models\BridgeRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

const EVM_RECIPIENT = '0xd8dA6BF26964aF9D7eEd9e03E53415D37aA96045';
const ATTACKER_RECIPIENT = '0x000000000000000000000000000000000000dEaD';

beforeEach(function () {
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('bridge.chains.yenten.deposit_address', 'YCentralWallet111111111111111111111');
    config()->set('bridge.chains.yenten.relayer_wif', 'central-wif');
    config()->set('bridge.chains.yenten.hd_seed', str_repeat('ab', 32));
    config()->set('bridge.chains.yenten.balance_api_urls', ['https://api.yentencoin.info']);
    config()->set('bridge.routes.yenten_to_evm.enabled', true);
    config()->set('bridge.routes.evm_to_yenten.enabled', true);
    config()->set('bridge.routes.yenten_to_evm.coming_soon', false);
    config()->set('bridge.routes.evm_to_yenten.coming_soon', false);
});

function prepareYenten(string $recipient = EVM_RECIPIENT): array
{
    return test()->postJson('/bridge/prepare', [
        'direction' => 'yenten_to_evm',
        'token' => 'YTN',
        'recipient_address' => $recipient,
    ])->json('bridge_request');
}

/** Fake the deposit-address balance (satoshis) and the mint relay. */
function fakeYentenBalance(int $satoshis): void
{
    Http::fake([
        'api.yentencoin.info/unspent/*' => Http::response([
            'result' => $satoshis > 0
                ? [['txid' => str_repeat('a', 64), 'index' => 0, 'value' => $satoshis, 'height' => 5]]
                : [],
            'error' => null,
        ]),
    ]);
    Process::fake([
        '*relay-mint*' => Process::result(output: json_encode(['txHash' => '0xytnmint'])),
    ]);
}

test('prepare returns a unique deposit address, commits the recipient and stores the key', function () {
    $one = prepareYenten();
    $two = prepareYenten();

    expect($one['deposit_address'])->toStartWith('Y')
        ->and($one['recipient_address'])->toBe(EVM_RECIPIENT)
        ->and($one['status'])->toBe('awaiting_deposit')
        ->and($one['deposit_address'])->not->toBe($two['deposit_address']);

    // The response never leaks the key, but it is stored (encrypted) for sweeping.
    expect($one)->not->toHaveKey('deposit_wif');

    $stored = BridgeRequest::find($one['id']);
    expect($stored->deposit_wif)->toMatch('/^[KL][1-9A-HJ-NP-Za-km-z]{50,52}$/')
        ->and($stored->getRawOriginal('deposit_wif'))->not->toBe($stored->deposit_wif); // encrypted at rest
});

test('claim mints the detected deposit balance to the committed recipient', function () {
    $prepared = prepareYenten();
    // User deposited 1.5 YTN (8-dec satoshis) — any amount, no input.
    fakeYentenBalance(150000000);

    $this->postJson('/bridge/claim', [
        'id' => $prepared['id'],
        // Attacker-supplied recipient — must be ignored.
        'recipient_address' => ATTACKER_RECIPIENT,
    ])->assertOk();

    $request = BridgeRequest::find($prepared['id']);
    expect($request->status)->toBe('completed')
        ->and($request->recipient_address)->toBe(EVM_RECIPIENT)
        ->and((float) $request->amount)->toBe(1.5);

    // 1.5 YTN → 18-dec wrapper units, minted to the committed recipient only.
    Process::assertRan(fn ($p) => str_contains(implode(' ', $p->command), 'relay-mint')
        && str_contains(implode(' ', $p->command), EVM_RECIPIENT)
        && str_contains(implode(' ', $p->command), '1500000000000000000')
        && ! str_contains(implode(' ', $p->command), ATTACKER_RECIPIENT));
});

test('claim with no deposit yet is retryable and does not brick the request', function () {
    $prepared = prepareYenten();
    fakeYentenBalance(0);

    $this->postJson('/bridge/claim', ['id' => $prepared['id']])
        ->assertStatus(422)
        ->assertJsonPath('retryable', true);

    expect(BridgeRequest::find($prepared['id'])->status)->toBe('awaiting_deposit');
});

test('prepare returns the monitoring deadline for the deposit address', function () {
    config()->set('bridge.chains.yenten.deposit_ttl_minutes', 60);

    $prepared = prepareYenten();
    $expiresAt = Carbon::parse($prepared['expires_at']);

    expect($expiresAt->gt(now()->addMinutes(55)))->toBeTrue()
        ->and($expiresAt->lt(now()->addMinutes(65)))->toBeTrue();
});

test('claim reports an unconfirmed deposit instead of "no deposit"', function () {
    $prepared = prepareYenten();

    Http::fake([
        'api.yentencoin.info/unspent/*' => Http::response([
            'result' => [['txid' => str_repeat('d', 64), 'index' => 0, 'value' => 150000000, 'height' => 0]],
            'error' => null,
        ]),
    ]);
    Process::fake();

    $this->postJson('/bridge/claim', ['id' => $prepared['id']])
        ->assertStatus(422)
        ->assertJsonPath('retryable', true)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'confirmation'));

    expect(BridgeRequest::find($prepared['id'])->status)->toBe('awaiting_deposit');
    Process::assertNothingRan();
});

test('claim after the deposit window expires closes the request and stops polling', function () {
    config()->set('bridge.chains.yenten.deposit_ttl_minutes', 60);

    $prepared = prepareYenten();
    fakeYentenBalance(0);

    $this->travel(61)->minutes();

    $this->postJson('/bridge/claim', ['id' => $prepared['id']])
        ->assertStatus(422)
        ->assertJsonPath('expired', true);

    expect(BridgeRequest::find($prepared['id'])->status)->toBe('expired');

    // Later claims answer instantly: a 500-only fake proves the Yenten API
    // is no longer consulted for an expired address.
    Http::fake(['api.yentencoin.info/*' => Http::response([], 500)]);

    $this->postJson('/bridge/claim', ['id' => $prepared['id']])
        ->assertStatus(422)
        ->assertJsonPath('expired', true);
});

test('a deposit that landed in time is honored even when claimed late', function () {
    config()->set('bridge.chains.yenten.deposit_ttl_minutes', 60);

    $prepared = prepareYenten();
    fakeYentenBalance(150000000);

    $this->travel(90)->minutes();

    $this->postJson('/bridge/claim', ['id' => $prepared['id']])->assertOk();

    expect(BridgeRequest::find($prepared['id'])->status)->toBe('completed');
});

test('claim rejects a request that is not awaiting a deposit', function () {
    $prepared = prepareYenten();
    BridgeRequest::find($prepared['id'])->update(['status' => 'completed']);

    $this->postJson('/bridge/claim', ['id' => $prepared['id']])->assertStatus(422);
});

test('each request has its own deposit address, so deposits never cross', function () {
    $victim = prepareYenten(EVM_RECIPIENT);
    $attacker = prepareYenten(ATTACKER_RECIPIENT);

    // The victim's address holds a deposit; the attacker's is empty.
    Http::fake([
        'api.yentencoin.info/unspent/'.rawurlencode($attacker['deposit_address']).'*' => Http::response(['result' => [], 'error' => null]),
        'api.yentencoin.info/unspent/*' => Http::response([
            'result' => [['txid' => str_repeat('c', 64), 'index' => 0, 'value' => 100000000, 'height' => 5]],
            'error' => null,
        ]),
    ]);
    Process::fake(['*relay-mint*' => Process::result(output: json_encode(['txHash' => '0xok']))]);

    // Attacker's request finds nothing on its own address → retryable, no mint.
    $this->postJson('/bridge/claim', ['id' => $attacker['id']])->assertStatus(422);
    expect(BridgeRequest::find($attacker['id'])->status)->toBe('awaiting_deposit');
});
