<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use kornrunner\Keccak;

const PROFILE_CONTRACT = '0x00000000000000000000000000000000000000cc';
const WALLET = '0x5555555555555555555555555555555555555555';

function selector(string $signature): string
{
    return '0x'.substr(Keccak::hash($signature, 256), 0, 8);
}

/** ABI-encode a string return value (offset + length + padded data). */
function abiString(string $value): string
{
    $hex = bin2hex($value);
    $padded = str_pad($hex, (int) (ceil(strlen($hex) / 64) ?: 1) * 64, '0');

    return '0x'
        .str_pad('20', 64, '0', STR_PAD_LEFT)
        .str_pad(dechex(strlen($value)), 64, '0', STR_PAD_LEFT)
        .$padded;
}

/** ABI-encode a uint256[] return value. */
function abiUintArray(array $ids): string
{
    $out = '0x'
        .str_pad('20', 64, '0', STR_PAD_LEFT)
        .str_pad(dechex(count($ids)), 64, '0', STR_PAD_LEFT);

    foreach ($ids as $id) {
        $out .= str_pad(dechex($id), 64, '0', STR_PAD_LEFT);
    }

    return $out;
}

/** Http::fake responder dispatching eth_call by function selector. */
function fakeProfileRpc(array $responses): void
{
    Http::fake([
        'rpc.cyberia.church' => function ($request) use ($responses) {
            $data = (string) ($request->data()['params'][0]['data'] ?? '');

            foreach ($responses as $signature => $result) {
                if (str_starts_with($data, selector($signature))) {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
                }
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x']);
        },
    ]);
}

beforeEach(function () {
    config()->set('services.profile.contract_address', PROFILE_CONTRACT);
    config()->set('services.bridge.relayer_private_key', '0x'.str_repeat('1', 64));
    config()->set('bridge.chains.cyberia.rpc_url', 'https://rpc.cyberia.church');
});

it('serves nickname and achievements with on-chain earned state', function () {
    fakeProfileRpc([
        'nicknameOf(address)' => abiString('lain'),
        'achievementsOf(address)' => abiUintArray([1, 4]),
    ]);

    $this->actingAs(User::factory()->create(['wallet_address' => WALLET]))
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('profileContract', PROFILE_CONTRACT)
            ->where('nickname', 'lain')
            ->where('achievements', function ($achievements) {
                $byKey = collect($achievements)->keyBy('key');

                return $byKey->get('first_swap')['earned'] === true
                    && $byKey->get('converter')['earned'] === true
                    && $byKey->get('first_bridge')['earned'] === false;
            }));
});

it('hides the on-chain identity section while the contract is not configured', function () {
    config()->set('services.profile.contract_address', null);

    $this->actingAs(User::factory()->create(['wallet_address' => WALLET]))
        ->get('/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('profileContract', null)
            ->where('achievements', []));
});

it('sets a nickname on-chain through the relayer script', function () {
    fakeProfileRpc([
        // Nickname is free.
        'nicknameOwner(bytes32)' => '0x'.str_repeat('0', 64),
        'nicknameOf(address)' => abiString('neuromancer'),
        'achievementsOf(address)' => abiUintArray([]),
    ]);

    Process::fake([
        '*profile-admin*' => Process::result(
            output: json_encode(['txHash' => '0xnick', 'status' => 'success']),
            exitCode: 0,
        ),
    ]);

    $user = User::factory()->create(['wallet_address' => WALLET]);

    $this->actingAs($user)
        ->from('/profile')
        ->patch('/profile/nickname', ['nickname' => 'neuromancer'])
        ->assertRedirect('/profile')
        ->assertSessionHas('status', 'nickname-updated');

    expect($user->fresh()->name)->toBe('neuromancer');

    Process::assertRan(fn ($p) => str_contains(
        is_array($p->command) ? implode(' ', $p->command) : $p->command,
        'set-nickname',
    ));
});

it('rejects a nickname owned by another wallet', function () {
    fakeProfileRpc([
        'nicknameOwner(bytes32)' => '0x'.str_pad('deadbeef', 64, '0', STR_PAD_LEFT),
    ]);

    Process::fake();

    $this->actingAs(User::factory()->create(['wallet_address' => WALLET]))
        ->from('/profile')
        ->patch('/profile/nickname', ['nickname' => 'taken_nick'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrors('nickname');

    Process::assertNothingRan();
});

it('rejects malformed nicknames without touching the chain', function () {
    Process::fake();

    $user = User::factory()->create(['wallet_address' => WALLET]);

    foreach (['ab', 'UPPER', 'has space', str_repeat('a', 21)] as $bad) {
        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile/nickname', ['nickname' => $bad])
            ->assertSessionHasErrors('nickname');
    }

    Process::assertNothingRan();
});

it('awards newly detected achievements on-chain', function () {
    fakeProfileRpc([
        'achievementsOf(address)' => abiUintArray([]),
        'nicknameOf(address)' => abiString(''),
    ]);

    Process::fake([
        '*profile-admin*' => Process::result(
            output: json_encode(['txHash' => '0xaward', 'status' => 'success']),
            exitCode: 0,
        ),
    ]);

    // Indexed on-chain activity: one swap + one liquidity add for this wallet.
    if (! Schema::hasTable('activity_events')) {
        Schema::create('activity_events', function ($table) {
            $table->increments('id');
            $table->string('kind');
            $table->string('user_addr')->nullable();
            $table->timestamps();
        });
    }

    DB::table('activity_events')->insert([
        ['kind' => 'swap', 'user_addr' => WALLET],
        ['kind' => 'liq_add', 'user_addr' => strtoupper(WALLET)],
    ]);

    $this->actingAs(User::factory()->create(['wallet_address' => WALLET]))
        ->from('/profile')
        ->post('/profile/achievements/check')
        ->assertRedirect('/profile')
        ->assertSessionHas('status', 'achievements-awarded:2');

    Process::assertRan(function ($p) {
        $command = is_array($p->command) ? implode(' ', $p->command) : $p->command;

        // FIRST_SWAP (1) and LIQUIDITY_FARMER (3) in one awardBatch call.
        return str_contains($command, 'award')
            && str_contains($command, WALLET)
            && str_ends_with($command, '1 3');
    });
});

it('reports when nothing new was earned', function () {
    fakeProfileRpc([
        'achievementsOf(address)' => abiUintArray([]),
        'nicknameOf(address)' => abiString(''),
    ]);

    Process::fake();

    $this->actingAs(User::factory()->create(['wallet_address' => WALLET]))
        ->from('/profile')
        ->post('/profile/achievements/check')
        ->assertRedirect('/profile')
        ->assertSessionHas('status', 'achievements-none');

    Process::assertNothingRan();
});
