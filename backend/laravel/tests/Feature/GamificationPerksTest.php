<?php

use App\Models\User;
use App\Services\CrosschainRouter;
use App\Services\GamificationService;
use Illuminate\Support\Facades\DB;

/**
 * What a level is worth, and what it is allowed to be built on.
 *
 * XP was a number that did nothing, so nobody chased it. Making it worth money
 * changes what a level has to be: `visit` XP is credited on the browser's word,
 * which was harmless while a level was a scoreboard and is a faucet the moment
 * a level discounts a fee. Everything here is about that line.
 */
beforeEach(function () {
    config()->set('crosschain.fee.bps', 75);
    config()->set('crosschain.fee.max_bps', 300);
    config()->set('crosschain.fee.address', '0x'.str_repeat('f', 40));
});

function withXp(User $user, string $source, int $amount): void
{
    DB::table('xp_entries')->insert([
        'user_id' => $user->id,
        'source' => $source,
        'reference' => $source.':'.uniqid(),
        'amount' => $amount,
        'created_at' => now(),
    ]);
}

it('does not count browser-reported xp towards a perk', function () {
    $user = User::factory()->create(['wallet_address' => '0x'.str_repeat('a', 40)]);

    // Enough to be level 10 on the leaderboard, all of it from opening pages.
    withXp($user, 'visit', 5000);

    $standing = app(GamificationService::class)->standing($user);

    expect($standing['proven_xp'])->toBe(0)
        ->and($standing['proven_level'])->toBe(1)
        ->and($standing['perks'])->toBe([]);
});

it('counts xp the chain vouched for', function () {
    $user = User::factory()->create(['wallet_address' => '0x'.str_repeat('b', 40)]);

    withXp($user, 'swap', 300);
    withXp($user, 'bridge', 200);
    withXp($user, 'visit', 900);

    $standing = app(GamificationService::class)->standing($user);

    expect($standing['proven_xp'])->toBe(500)
        ->and($standing['xp'])->toBe(0)   // user_stats is written by awards, not by raw rows
        ->and($standing['perks'])->toHaveKey('crosschain_fee_discount');
});

it('takes the highest ladder step at or below the level', function () {
    $g = app(GamificationService::class);

    expect($g->perksFor(1))->toBe([])
        ->and($g->perksFor(2)['crosschain_fee_discount'])->toBe(10)
        ->and($g->perksFor(3)['crosschain_fee_discount'])->toBe(10)
        ->and($g->perksFor(21)['crosschain_fee_discount'])->toBe(100)
        ->and($g->perksFor(40)['crosschain_fee_discount'])->toBe(100);
});

it('charges an unknown address the whole fee', function () {
    expect(app(CrosschainRouter::class)->feeBps('0x'.str_repeat('9', 40)))->toBe(75);
});

it('charges nothing extra when no address is given', function () {
    expect(app(CrosschainRouter::class)->feeBps())->toBe(75);
});

it('takes a real discount off a real fee', function () {
    $address = '0x'.str_repeat('c', 40);
    $user = User::factory()->create(['wallet_address' => $address]);

    // 1000 proven XP is level 4 on the default curve → 20% off.
    withXp($user, 'swap', 1000);

    expect(app(CrosschainRouter::class)->feeBps($address))->toBe(60);
});

it('waives the fee entirely at the top of the ladder', function () {
    $address = '0x'.str_repeat('d', 40);
    $user = User::factory()->create(['wallet_address' => $address]);

    withXp($user, 'bridge', 30_000);

    expect(app(CrosschainRouter::class)->feeBps($address))->toBe(0);
});

it('gives a merged account no standing of its own', function () {
    $address = '0x'.str_repeat('e', 40);
    $keeper = User::factory()->create();
    $merged = User::factory()->create([
        'wallet_address' => $address,
        'merged_into_id' => $keeper->id,
    ]);

    withXp($merged, 'swap', 5000);

    // The address resolves to a record that is no longer a person.
    expect(app(CrosschainRouter::class)->feeBps($address))->toBe(75);
});

it('matches an address whatever case it arrives in', function () {
    $user = User::factory()->create(['wallet_address' => '0x'.str_repeat('a', 40)]);
    withXp($user, 'swap', 1000);

    expect(app(CrosschainRouter::class)->feeBps('0x'.str_repeat('A', 40)))->toBe(60);
});

it('reports the discount so the screen can show it', function () {
    $address = '0x'.str_repeat('c', 40);
    $user = User::factory()->create(['wallet_address' => $address]);
    withXp($user, 'swap', 1000);

    $this->getJson('/api/wallet/crosschain?user='.$address)
        ->assertOk()
        ->assertJsonPath('fee.full_bps', 75)
        ->assertJsonPath('fee.bps', 60)
        ->assertJsonPath('fee.discount', 20);
});

it('asks for no fee at all rather than zero basis points', function () {
    $address = '0x'.str_repeat('d', 40);
    $user = User::factory()->create(['wallet_address' => $address]);
    withXp($user, 'bridge', 30_000);

    // feeAddress() already refuses to name a recipient for a zero fee, and the
    // quote body must not carry an appFees entry asking for nothing.
    expect(app(CrosschainRouter::class)->feeBps($address))->toBe(0);
});
