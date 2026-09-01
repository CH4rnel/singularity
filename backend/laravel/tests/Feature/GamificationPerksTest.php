<?php

use App\Models\User;
use App\Models\XpEnchantment;
use App\Services\CrosschainRouter;
use App\Services\GamificationService;
use Illuminate\Support\Facades\DB;

/**
 * Experience as a currency, and the one rule about what it may buy.
 *
 * It is handed out for opening a page and can be farmed, so nothing it unlocks
 * is allowed to move money — no fee discounts, nothing another person pays
 * for. What it buys is access to parts of this project, where a farmed balance
 * takes nothing from anybody. There was a version that discounted a real
 * cross-chain fee and it forced the whole system to carry two kinds of XP to
 * defend itself; the first test here is what stops that coming back.
 */
beforeEach(function () {
    config()->set('crosschain.fee.bps', 75);
    config()->set('crosschain.fee.max_bps', 300);
    config()->set('crosschain.fee.address', '0x'.str_repeat('f', 40));
});

/** Award through the service, so user_stats stays the one source of truth. */
function withXp(User $user, string $source, int $amount): void
{
    app(GamificationService::class)->award($user, $source, $source.':'.uniqid('', true), $amount);
}

function player(int $xp = 50_000): User
{
    $user = User::factory()->create();
    withXp($user, 'swap', $xp);

    return $user;
}

/* ------------------------------------------------------------- earning -- */

it('pays for taking part anywhere, on the chain and off it', function () {
    $user = User::factory()->create();
    $g = app(GamificationService::class);

    $g->recordAction($user, 'post', '1');
    $g->recordAction($user, 'swap', '0xabc');

    // The wall and the chain both count. They stopped counting for one commit,
    // when XP was about to be worth money; nothing it buys is worth farming.
    expect($g->spendable($user))->toBeGreaterThan(20 + 25);
});

/* ------------------------------------------------------------- spending -- */

it('spends the balance and keeps the leaderboard', function () {
    $user = player();
    $g = app(GamificationService::class);

    expect($g->enchant($user, 'nocarrier')['ok'])->toBeTrue()
        ->and($g->spendable($user))->toBe(45_000)
        // Lifetime XP is the leaderboard and never falls when it is spent.
        ->and($g->statsFor($user)->xp)->toBe(50_000);
});

it('refuses what the balance cannot cover', function () {
    // Level 10, and short of the price.
    expect(app(GamificationService::class)->enchant(player(4500), 'nocarrier')['reason'])->toBe('xp');
});

it('refuses what the standing has not reached', function () {
    // Level 6. The balance is short too, and the level is what is reported,
    // because saving does not fix it.
    expect(app(GamificationService::class)->enchant(player(1600), 'nocarrier')['reason'])->toBe('level');
});

it('never charges twice for the same unlock', function () {
    $user = player();
    $g = app(GamificationService::class);

    $g->enchant($user, 'nocarrier');
    $second = $g->enchant($user, 'nocarrier');

    expect($second['ok'])->toBeFalse()
        ->and($second['reason'])->toBe('owned')
        ->and(XpEnchantment::where('user_id', $user->id)->count())->toBe(1)
        ->and($g->spendable($user))->toBe(45_000);
});

it('refuses an unlock that does not exist', function () {
    expect(app(GamificationService::class)->enchant(player(), 'sharpness_v')['reason'])->toBe('unknown');
});

it('keeps a price change from putting anybody in debt', function () {
    $user = player(6000);
    app(GamificationService::class)->enchant($user, 'nocarrier');

    // Somebody paid 5000; the ledger now says they paid more than they have.
    DB::table('xp_enchantments')->where('user_id', $user->id)->update(['cost' => 99_999]);

    expect(app(GamificationService::class)->spendable($user))->toBe(0);
});

/* --------------------------------------------------------------- unlocks -- */

it('never lets experience touch a fee', function () {
    $user = player();
    app(GamificationService::class)->enchant($user, 'nocarrier');

    expect(app(CrosschainRouter::class)->feeBps())->toBe(75);
});

it('unlocks the game and keeps it', function () {
    $user = player();
    $g = app(GamificationService::class);

    expect($g->enchant($user, 'nocarrier')['ok'])->toBeTrue()
        ->and($g->perksFor($user)['nocarrier'])->toBe(1);
});

/* ----------------------------------------------------------------- table -- */

it('says which of the two refusals applies', function () {
    $g = app(GamificationService::class);
    $state = fn (User $user): string => collect($g->enchantments($user))
        ->firstWhere('key', 'nocarrier')['state'];

    expect($state(player(1600)))->toBe('level')
        ->and($state(player(4500)))->toBe('xp')
        ->and($state(player(50_000)))->toBe('ready');
});

it('marks what is already owned', function () {
    $user = player();
    app(GamificationService::class)->enchant($user, 'nocarrier');

    expect(collect(app(GamificationService::class)->enchantments($user))
        ->firstWhere('key', 'nocarrier')['state'])->toBe('owned');
});
