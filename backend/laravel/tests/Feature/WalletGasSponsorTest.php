<?php

use App\Models\GasSponsorship;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use kornrunner\Keccak;

const GAS_STATION = '0x00000000000000000000000000000000000000f0';
const GAS_HOLDER = '0x1111111111111111111111111111111111111111';

function gasSelector(string $signature): string
{
    return '0x'.substr(Keccak::hash($signature, 256), 0, 8);
}

/** One ABI word from a decimal value, in bcmath so wei amounts survive. */
function gasWord(string|int $value): string
{
    $decimal = (string) $value;
    $hex = '';

    while (bccomp($decimal, '0') > 0) {
        $hex = dechex((int) bcmod($decimal, '16')).$hex;
        $decimal = bcdiv($decimal, '16', 0);
    }

    return str_pad($hex === '' ? '0' : $hex, 64, '0', STR_PAD_LEFT);
}

/** ABI-encode `canClaim`'s (bool ok, string reason) return. */
function gasCanClaim(bool $ok, string $reason = ''): string
{
    $hex = bin2hex($reason);
    $padded = str_pad($hex, (int) (ceil(strlen($hex) / 64) ?: 1) * 64, '0');

    return '0x'
        .gasWord($ok ? 1 : 0)
        .gasWord(0x40)
        .gasWord(strlen($reason))
        .$padded;
}

/** ABI-encode `summary()`: eight uints and a bool. */
function gasSummary(array $overrides = []): string
{
    $values = array_merge([
        'tank' => '100000000000000000000',
        'drip' => '10000000000000000',
        'ceiling' => '1000000000000000',
        'cooldown' => 21600,
        'dailyCap' => '5000000000000000000',
        'remaining' => '5000000000000000000',
        'served' => 7,
        'spent' => '70000000000000000',
        'paused' => 0,
    ], $overrides);

    return '0x'.implode('', array_map(gasWord(...), array_values($values)));
}

/**
 * The chain and the index, faked together: an eth_call dispatched by selector,
 * and the explorer's token list for one address.
 */
function fakeGasStation(array $calls = [], array $tokens = []): void
{
    Http::fake([
        'rpc.cyberia.church' => function ($request) use ($calls) {
            $data = (string) ($request->data()['params'][0]['data'] ?? '');

            $responses = array_merge([
                'summary()' => gasSummary(),
                'canClaim(address)' => gasCanClaim(true),
                'cooldownRemaining(address)' => '0x'.gasWord(0),
            ], $calls);

            foreach ($responses as $signature => $result) {
                if (str_starts_with($data, gasSelector($signature))) {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
                }
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x']);
        },
        'explorer.cyberia.church/*' => Http::response([
            'status' => $tokens === [] ? '0' : '1',
            'message' => $tokens === [] ? 'No tokens found' : 'OK',
            'result' => $tokens === [] ? [] : $tokens,
        ]),
    ]);
}

/** One indexed ERC-20 holding, as Blockscout reports it. */
function gasToken(string $balance = '1000000', string $type = 'ERC-20'): array
{
    return [
        'balance' => $balance,
        'contractAddress' => '0xdc25597b19799010047f17e9591efe08efd40077',
        'decimals' => '6',
        'name' => 'USD Coin',
        'symbol' => 'USDC',
        'type' => $type,
    ];
}

function fakeClaimScript(string $txHash = '0xdeadbeef', string $amount = '10000000000000000'): void
{
    Process::fake([
        '*gas-station.ts*' => Process::result(
            output: json_encode([
                'status' => 'success',
                'txHash' => $txHash,
                'address' => GAS_HOLDER,
                'amount' => $amount,
            ]),
            exitCode: 0,
        ),
    ]);
}

beforeEach(function () {
    config()->set('wallet.sponsor.enabled', true);
    config()->set('wallet.sponsor.station', GAS_STATION);
    // Not a key: nothing in these tests signs anything, and `enabled()` only
    // asks whether one was configured.
    config()->set('wallet.sponsor.private_key', 'configured-elsewhere');
    config()->set('bridge.chains.cyberia.rpc_url', 'https://rpc.cyberia.church');
    config()->set('wallet.sponsor.explorer_api', 'https://explorer.cyberia.church/api');

    Cache::flush();
});

it('reports the station and where an address stands with it', function () {
    fakeGasStation(tokens: [gasToken()]);

    $this->getJson('/api/wallet/gas?address='.GAS_HOLDER)
        ->assertOk()
        ->assertJsonPath('enabled', true)
        ->assertJsonPath('station', GAS_STATION)
        ->assertJsonPath('drip', '10000000000000000')
        ->assertJsonPath('address.ok', true)
        ->assertJsonPath('address.reason', 'ok')
        ->assertJsonPath('address.grounds', 'tokens');
});

it('says sponsorship is off rather than failing when no station is configured', function () {
    config()->set('wallet.sponsor.station', '');

    $this->getJson('/api/wallet/gas')
        ->assertOk()
        ->assertJsonPath('enabled', false);

    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])
        ->assertStatus(404)
        ->assertJsonPath('reason', 'disabled');
});

it('sponsors an address that holds a token but no coin', function () {
    fakeGasStation(tokens: [gasToken()]);
    fakeClaimScript();

    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])
        ->assertOk()
        ->assertJsonPath('status', 'sent')
        ->assertJsonPath('txHash', '0xdeadbeef');

    $row = GasSponsorship::firstOrFail();

    expect($row->address)->toBe(GAS_HOLDER)
        ->and($row->grounds)->toBe('tokens')
        ->and($row->amount_wei)->toBe('10000000000000000')
        // The caller's IP is recorded as a hash and never as itself.
        ->and($row->ip_hash)->toHaveLength(64);

    Process::assertRan(function ($process) {
        $command = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return str_contains($command, 'gas-station.ts')
            && str_contains($command, 'claim')
            && str_contains($command, GAS_STATION);
    });
});

it('refuses an address that owns nothing here', function () {
    fakeGasStation(tokens: []);

    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'holdsNothing');

    expect(GasSponsorship::count())->toBe(0);
    Process::assertNothingRan();
});

it('accepts a wallet that signed into the site, holdings or not', function () {
    fakeGasStation(tokens: []);
    fakeClaimScript();

    User::factory()->create(['wallet_address' => GAS_HOLDER]);

    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])
        ->assertOk();

    expect(GasSponsorship::firstOrFail()->grounds)->toBe('account');
});

it('passes the contract refusal through in the contract vocabulary', function () {
    fakeGasStation(
        calls: ['canClaim(address)' => gasCanClaim(false, 'has gas')],
        tokens: [gasToken()],
    );

    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'hasGas');

    Process::assertNothingRan();
});

it('reports the cooldown the contract is counting', function () {
    fakeGasStation(
        calls: [
            'canClaim(address)' => gasCanClaim(false, 'cooling down'),
            'cooldownRemaining(address)' => '0x'.gasWord(3600),
        ],
        tokens: [gasToken()],
    );

    $this->getJson('/api/wallet/gas?address='.GAS_HOLDER)
        ->assertOk()
        ->assertJsonPath('address.reason', 'coolingDown')
        ->assertJsonPath('address.cooldownRemaining', 3600);
});

it('stops one caller after its daily quota, counting rows and not the cache', function () {
    config()->set('wallet.sponsor.daily_per_ip', 2);

    fakeGasStation(tokens: [gasToken()]);
    fakeClaimScript();

    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])->assertOk();

    GasSponsorship::query()->update(['tx_hash' => null]);
    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])->assertOk();

    // A flushed cache is not a fresh allowance: the quota is counted from rows.
    Cache::flush();

    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'quota');

    expect(GasSponsorship::count())->toBe(2);
});

it('treats an unreadable index as unknown rather than as owning nothing', function () {
    Http::fake([
        'rpc.cyberia.church' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => gasCanClaim(true),
        ]),
        'explorer.cyberia.church/*' => Http::response('', 502),
    ]);

    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'unreadable');
});

it('fails closed when the station itself cannot be read', function () {
    Http::fake([
        'rpc.cyberia.church' => Http::response('', 500),
        'explorer.cyberia.church/*' => Http::response(['result' => [gasToken()]]),
    ]);

    $this->postJson('/api/wallet/gas/claim', ['address' => GAS_HOLDER])
        ->assertStatus(503)
        ->assertJsonPath('reason', 'unreadable');

    Process::assertNothingRan();
});

it('rejects anything that is not an address', function () {
    $this->postJson('/api/wallet/gas/claim', ['address' => 'lain'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('address');
});

it('tells the wallet page whether fees can be sponsored at all', function () {
    // The page also renders USD quotes; nothing here should reach the network.
    Http::fake();

    $this->get('/wallet')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Wallet')
            ->where('sponsor.enabled', true)
            ->where('sponsor.chain', 'cyberia')
            ->etc());
});
