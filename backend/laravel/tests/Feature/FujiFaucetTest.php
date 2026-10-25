<?php

use App\Models\FujiFaucetClaim;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

/** ethers' documentation key: a real secp256k1 key that holds nothing anywhere. */
const FUJI_FAUCET_KEY = '0x0123456789012345678901234567890123456789012345678901234567890123';
const FUJI_FAUCET_ADDRESS = '0x14791697260e4c9a71f18484c9f997b308e59325';
const FUJI_RECIPIENT = '0x2222222222222222222222222222222222222222';
const FUJI_TREASURY = '0x96cd117786ebc0fdbef56a6559feb2bd2bae3393';

/** 7.2e15 wei + 1, what eth_gasPrice answers at the Ethereum-priced minimum. */
const FUJI_GAS_PRICE = '0x19945ca2620001';

/** A wei amount as a JSON-RPC quantity, in bcmath so 256-bit values survive. */
function fujiHex(string $decimal): string
{
    $hex = '';

    while (bccomp($decimal, '0') > 0) {
        $hex = dechex((int) bcmod($decimal, '16')).$hex;
        $decimal = bcdiv($decimal, '16', 0);
    }

    return '0x'.($hex === '' ? '0' : $hex);
}

/**
 * The chain, faked by method: the gas price, the RewardManager's recipient,
 * and balances for the faucet and for whoever is asking.
 */
function fakeFujiChain(string $tankWei = '200000000000000000000000', string $recipientWei = '0', ?string $rewardAddress = FUJI_TREASURY): void
{
    Http::fake([
        'fuji.test/*' => function ($request) use ($tankWei, $recipientWei, $rewardAddress) {
            $method = $request->data()['method'] ?? '';
            $params = $request->data()['params'] ?? [];

            $result = match ($method) {
                'eth_gasPrice' => FUJI_GAS_PRICE,
                'eth_call' => '0x'.str_pad(substr((string) $rewardAddress, 2), 64, '0', STR_PAD_LEFT),
                'eth_getBalance' => fujiHex(
                    strtolower((string) ($params[0] ?? '')) === FUJI_FAUCET_ADDRESS ? $tankWei : $recipientWei,
                ),
                default => null,
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
        },
    ]);
}

function fakeFujiSend(string $txHash = '0xabc123', bool $confirmed = true): void
{
    $lines = ['Relayer: '.FUJI_FAUCET_ADDRESS, json_encode(['broadcastTxHash' => $txHash])];

    if ($confirmed) {
        $lines[] = json_encode(['txHash' => $txHash]);
    }

    Process::fake([
        '*relay-native-transfer.ts*' => Process::result(
            output: implode("\n", $lines),
            errorOutput: $confirmed ? '' : 'Native transfer receipt not observed',
            exitCode: $confirmed ? 0 : 1,
        ),
    ]);
}

beforeEach(function () {
    config()->set('fuji.faucet.enabled', true);
    config()->set('fuji.faucet.private_key', FUJI_FAUCET_KEY);
    config()->set('fuji.faucet.drip_cyber', '10000');
    config()->set('fuji.faucet.cooldown_hours', 24);
    config()->set('fuji.faucet.daily_per_ip', 3);
    config()->set('fuji.faucet.daily_total', 20);
    config()->set('fuji.treasury', FUJI_TREASURY);
    config()->set('bridge.chains.cyberia_fuji.rpc_url', 'https://fuji.test/rpc');

    Cache::flush();
});

it('renders the page with the chain MetaMask needs', function () {
    $this->get('/fuji')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Fuji')
            ->where('network.chainId', 49407)
            ->where('network.currency.symbol', 'CYBER')
            ->where('treasury', FUJI_TREASURY));
});

it('sends /faucet to the page', function () {
    $this->get('/faucet')->assertRedirect('/fuji');
});

it('reports the live fee, where fees go and the faucet tank', function () {
    fakeFujiChain();

    $this->getJson('/api/fuji/faucet')
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('chainId', 49407)
        ->assertJsonPath('gasPrice', '7200000000000001')
        ->assertJsonPath('feeRecipient', FUJI_TREASURY)
        ->assertJsonPath('faucet', FUJI_FAUCET_ADDRESS)
        ->assertJsonPath('drip', '10000000000000000000000')
        ->assertJsonPath('tank', '200000000000000000000000')
        ->assertJsonMissingPath('address');
});

it('says the faucet is closed without a key, and still reports the fee', function () {
    fakeFujiChain();
    config()->set('fuji.faucet.private_key', '');

    $this->getJson('/api/fuji/faucet')
        ->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('faucet', null)
        ->assertJsonPath('gasPrice', '7200000000000001');

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertNotFound()
        ->assertJsonPath('reason', 'disabled');
});

it('sends one drip from the faucet key and records it', function () {
    fakeFujiChain();
    fakeFujiSend('0xfeed');

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertOk()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('txHash', '0xfeed')
        ->assertJsonPath('amount', '10000000000000000000000')
        ->assertJsonPath('confirmed', true);

    $claim = FujiFaucetClaim::sole();

    expect($claim->address)->toBe(FUJI_RECIPIENT)
        ->and($claim->tx_hash)->toBe('0xfeed')
        ->and($claim->ip_hash)->toHaveLength(64);

    Process::assertRan(fn ($process) => $process->command === [
        'npx', 'tsx', 'scripts/relay-native-transfer.ts', FUJI_RECIPIENT, '10000000000000000000000',
    ]
        && $process->environment['BRIDGE_RELAYER_PRIVATE_KEY'] === FUJI_FAUCET_KEY
        && $process->environment['EVM_CHAIN_ID'] === '49407'
        && $process->environment['EVM_RPC_URL'] === 'https://fuji.test/rpc');
});

/**
 * The script prints the hash as soon as the transaction is in the pool. If the
 * receipt never comes the transaction may still land, so the drip counts.
 */
it('records a broadcast drip whose receipt never came, so it is not paid twice', function () {
    fakeFujiChain();
    fakeFujiSend('0xslow', confirmed: false);

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertOk()
        ->assertJsonPath('txHash', '0xslow')
        ->assertJsonPath('confirmed', false);

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'coolingDown');

    expect(FujiFaucetClaim::count())->toBe(1);
});

it('refuses an address inside its cooldown and says how long is left', function () {
    fakeFujiChain();
    fakeFujiSend();
    FujiFaucetClaim::factory()->create([
        'address' => FUJI_RECIPIENT,
        'created_at' => now()->subHours(20),
    ]);

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'coolingDown');

    $remaining = $this->getJson('/api/fuji/faucet?address='.FUJI_RECIPIENT)
        ->assertOk()
        ->json('address.cooldownRemaining');

    expect($remaining)->toBeGreaterThan(4 * 3600 - 60)->toBeLessThanOrEqual(4 * 3600);
    Process::assertNothingRan();
});

it('refuses an address that already holds a drip', function () {
    fakeFujiChain(recipientWei: '10000000000000000000000');
    fakeFujiSend();

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'hasGas');

    Process::assertNothingRan();
});

it('refuses a connection that has asked its share today', function () {
    fakeFujiChain();
    fakeFujiSend();
    $ipHash = hash('sha256', '127.0.0.1|'.config('app.key'));
    FujiFaucetClaim::factory()->count(3)->create(['ip_hash' => $ipHash]);

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'quota');

    Process::assertNothingRan();
});

it('stops at the daily total', function () {
    fakeFujiChain();
    fakeFujiSend();
    FujiFaucetClaim::factory()->count(20)->create();

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'dailyCap');
});

it('says the faucet is dry when the tank cannot cover a drip and its fee', function () {
    fakeFujiChain(tankWei: '10000000000000000000000');
    fakeFujiSend();

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'empty');

    Process::assertNothingRan();
});

it('fails closed when the send prints no hash', function () {
    fakeFujiChain();
    Process::fake([
        '*relay-native-transfer.ts*' => Process::result(output: '', errorOutput: 'insufficient funds', exitCode: 1),
    ]);

    $this->postJson('/api/fuji/faucet', ['address' => FUJI_RECIPIENT])
        ->assertServiceUnavailable()
        ->assertJsonPath('reason', 'unreadable');

    expect(FujiFaucetClaim::count())->toBe(0);
});

it('rejects something that is not an address', function () {
    $this->postJson('/api/fuji/faucet', ['address' => 'not-an-address'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('address');
});
