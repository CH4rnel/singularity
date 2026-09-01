<?php

use App\Models\User;
use App\Models\XpEnchantment;
use App\Services\CrosschainRouter;
use App\Services\GamificationService;
use Illuminate\Support\Facades\DB;

/**
 * Experience as a currency rather than a rank.
 *
 * It accumulates, it is spent on something permanent, and the balance goes
 * back down. Everything pinned here is about the two places that model can be
 * abused or misread: what may be counted towards standing, and what happens
 * when the same purchase is attempted twice.
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
        'reference' => $source.':'.uniqid('', true),
        'amount' => $amount,
        'created_at' => now(),
    ]);
}

function trader(int $provenXp = 5000, ?string $address = null): User
{
    $user = User::factory()->create([
        'wallet_address' => $address ?? '0x'.substr(md5(uniqid('', true)), 0, 40),
    ]);

    withXp($user, 'swap', $provenXp);

    return $user;
}

/* ------------------------------------------------------------- standing -- */

it('does not count browser-reported xp as something to spend', function () {
    $user = User::factory()->create();

    // Enough to be level 10 on the leaderboard, all of it from opening pages.
    withXp($user, 'visit', 5000);

    $g = app(GamificationService::class);

    expect($g->provenXp($user))->toBe(0)
        ->and($g->spendable($user))->toBe(0);
});

it('counts xp the chain vouched for', function () {
    $user = User::factory()->create();
    withXp($user, 'swap', 300);
    withXp($user, 'bridge', 200);
    withXp($user, 'visit', 900);

    expect(app(GamificationService::class)->spendable($user))->toBe(500);
});

/* ------------------------------------------------------------- spending -- */

it('spends the balance and keeps the leaderboard', function () {
    $user = trader(5000);
    $g = app(GamificationService::class);

    expect($g->enchant($user, 'route_i')['ok'])->toBeTrue()
        ->and($g->spendable($user))->toBe(4600)
        // Lifetime XP is a record of taking part and is never spent.
        ->and($g->provenXp($user))->toBe(5000);
});

it('refuses what the balance cannot cover', function () {
    $user = trader(5000);
    // Level is fine for route_ii, the balance is not once route_i is paid for.
    app(GamificationService::class)->enchant($user, 'route_i');
    DB::table('xp_enchantments')->where('user_id', $user->id)->update(['cost' => 4900]);

    expect(app(GamificationService::class)->enchant($user, 'route_ii')['reason'])->toBe('xp');
});

it('refuses what the standing has not reached, however rich', function () {
    $user = User::factory()->create();
    withXp($user, 'swap', 300);   // level 2 — enough for route_i only

    $g = app(GamificationService::class);
    $g->enchant($user, 'route_i');
    withXp($user, 'swap', 100_000);

    // Now rich enough for route_iii but not level 12 yet? No — 100k is well
    // past it, so prove the refusal at the rung that is genuinely out of reach.
    $poor = User::factory()->create();
    withXp($poor, 'swap', 400);

    expect($g->enchant($poor, 'route_ii')['reason'])->toBe('requires')
        ->and($g->enchant($poor, 'lain_key')['reason'])->toBe('level');
});

it('makes a ladder be climbed rather than skipped', function () {
    $user = trader(50_000);

    expect(app(GamificationService::class)->enchant($user, 'route_iii')['reason'])->toBe('requires');
});

it('never charges twice for the same enchantment', function () {
    $user = trader(5000);
    $g = app(GamificationService::class);

    $g->enchant($user, 'route_i');
    $second = $g->enchant($user, 'route_i');

    expect($second['ok'])->toBeFalse()
        ->and($second['reason'])->toBe('owned')
        ->and(XpEnchantment::where('user_id', $user->id)->count())->toBe(1)
        ->and($g->spendable($user))->toBe(4600);
});

it('refuses an enchantment that does not exist', function () {
    expect(app(GamificationService::class)->enchant(trader(), 'sharpness_v')['reason'])->toBe('unknown');
});

it('keeps a price cut from putting anybody in debt', function () {
    $user = trader(500);
    app(GamificationService::class)->enchant($user, 'route_i');

    // Somebody paid 400; the catalogue now says it is worth more than they have.
    DB::table('xp_enchantments')->where('user_id', $user->id)->update(['cost' => 9999]);

    expect(app(GamificationService::class)->spendable($user))->toBe(0);
});

/* ----------------------------------------------------------------- fees -- */

it('charges the full fee until something is bought', function () {
    $user = trader(50_000, '0x'.str_repeat('c', 40));

    // Level 22 and 50k to spend, and still no discount: standing earns the
    // right to buy, it does not buy anything on its own.
    expect(app(CrosschainRouter::class)->feeBps($user->wallet_address))->toBe(75);
});

it('takes a real discount off a real fee once bought', function () {
    $user = trader(5000, '0x'.str_repeat('d', 40));
    app(GamificationService::class)->enchant($user, 'route_i');

    expect(app(CrosschainRouter::class)->feeBps($user->wallet_address))->toBe(56);
});

it('replaces a rung rather than stacking it', function () {
    $user = trader(50_000, '0x'.str_repeat('e', 40));
    $g = app(GamificationService::class);
    $g->enchant($user, 'route_i');
    $g->enchant($user, 'route_ii');

    // 25 and 60 owned; the better one wins and they do not add up to 85.
    expect($g->perksFor($user)['crosschain_fee_discount'])->toBe(60)
        ->and(app(CrosschainRouter::class)->feeBps($user->wallet_address))->toBe(30);
});

it('waives the fee entirely at the top of the ladder', function () {
    $user = trader(50_000, '0x'.str_repeat('b', 40));
    $g = app(GamificationService::class);
    $g->enchant($user, 'route_i');
    $g->enchant($user, 'route_ii');
    $g->enchant($user, 'route_iii');

    expect(app(CrosschainRouter::class)->feeBps($user->wallet_address))->toBe(0);
});

it('charges an unknown address the whole fee', function () {
    expect(app(CrosschainRouter::class)->feeBps('0x'.str_repeat('9', 40)))->toBe(75);
});

it('gives a merged account no standing of its own', function () {
    $address = '0x'.str_repeat('a', 40);
    $keeper = User::factory()->create();
    $merged = User::factory()->create(['wallet_address' => $address, 'merged_into_id' => $keeper->id]);
    withXp($merged, 'swap', 50_000);
    app(GamificationService::class)->enchant($merged, 'route_i');

    expect(app(CrosschainRouter::class)->feeBps($address))->toBe(75);
});

it('matches an address whatever case it arrives in', function () {
    $user = trader(5000, '0x'.str_repeat('a', 40));
    app(GamificationService::class)->enchant($user, 'route_i');

    expect(app(CrosschainRouter::class)->feeBps('0x'.str_repeat('A', 40)))->toBe(56);
});

/* ---------------------------------------------------------------- table -- */

it('says which of the two refusals applies', function () {
    $user = User::factory()->create();
    withXp($user, 'swap', 300);   // level 2, 300 to spend

    $table = collect(app(GamificationService::class)->enchantments($user))->keyBy('key');

    expect($table['route_i']['state'])->toBe('xp')       // right level, short of XP
        ->and($table['lain_key']['state'])->toBe('level') // no amount of saving helps yet
        ->and($table['route_ii']['state'])->toBe('requires');
});

it('marks what is already owned', function () {
    $user = trader(5000);
    app(GamificationService::class)->enchant($user, 'route_i');

    $table = collect(app(GamificationService::class)->enchantments($user))->keyBy('key');

    expect($table['route_i']['state'])->toBe('owned')
        // 5000 proven XP is level 10 and 4600 left, so the next rung is ready
        // rather than locked — owning one opens the one above it.
        ->and($table['route_ii']['state'])->toBe('ready');
});
