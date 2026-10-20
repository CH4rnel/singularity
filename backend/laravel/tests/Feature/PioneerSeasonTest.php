<?php

use App\Models\BridgeRequest;
use App\Models\PioneerCheckpoint;
use App\Models\PioneerFinisher;
use App\Models\User;
use App\Services\PioneerSeasonService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

const PIONEER_WALLET = '0x1111111111111111111111111111111111111111';

beforeEach(function () {
    $this->withoutVite();

    config([
        'pioneer.season' => 's1',
        'pioneer.starts_at' => '2026-11-04 00:00:00',
        'pioneer.ends_at' => '2026-12-01 23:59:59',
        'pioneer.min_swap_usd' => 5,
        'pioneer.min_liquidity_usd' => 10,
        'pioneer.return_after_hours' => 24,
        'pioneer.round_trip_minutes' => 60,
        'pioneer.bridge_required' => true,
        'pioneer.review_first' => 50,
        'pioneer.excluded_addresses' => [],
        'crm.admin_wallets' => [],
        'crm.admin_user_ids' => [],
    ]);

    // `activity_events` belongs to the Telegram bot and is created by it, not
    // by a migration, so the test builds the bot's own schema.
    Schema::create('activity_events', function (Blueprint $table) {
        $table->id();
        $table->string('kind');
        $table->float('usd')->nullable();
        $table->string('sym_in')->nullable();
        $table->float('amt_in')->nullable();
        $table->string('sym_out')->nullable();
        $table->float('amt_out')->nullable();
        $table->string('user_addr')->nullable();
        $table->string('tx_hash')->nullable();
        $table->integer('block')->nullable();
        $table->text('meta')->nullable();
        $table->string('created_at')->nullable();
    });
});

afterEach(function () {
    Carbon::setTestNow();
});

function indexed(string $kind, float|int|null $usd, string $at, string $hash, string $in = 'CYBER', string $out = 'USDC', string $address = PIONEER_WALLET): void
{
    DB::table('activity_events')->insert([
        'kind' => $kind,
        'usd' => $usd,
        'sym_in' => $in,
        'sym_out' => $out,
        'user_addr' => $address,
        'tx_hash' => $hash,
        'created_at' => $at,
    ]);
}

function completedBridge(array $attributes): BridgeRequest
{
    return BridgeRequest::query()->forceCreate([
        'direction' => 'sol_to_evm',
        'token' => 'CYBER',
        'source_chain' => 'solana',
        'source_nonce' => random_int(1, PHP_INT_MAX),
        'sender_address' => 'So1anaSender',
        'recipient_address' => '0x9999999999999999999999999999999999999999',
        'source_tx_hash' => 'sol-'.uniqid(),
        'destination_tx_hash' => '0xdest'.uniqid(),
        'amount' => '1000',
        'status' => 'completed',
        'completed_at' => '2026-11-05 10:00:00',
        ...$attributes,
    ]);
}

function pioneerXp(User $user): array
{
    return DB::table('xp_entries')
        ->where('user_id', $user->id)
        ->where('source', 'pioneer')
        ->orderBy('reference')
        ->pluck('amount', 'reference')
        ->all();
}

it('records nothing before the season opens', function () {
    Carbon::setTestNow('2026-11-01 12:00:00');
    $user = User::factory()->create(['wallet_address' => PIONEER_WALLET]);
    indexed('swap', 50, '2026-10-30 10:00:00', '0xearly');

    $this->actingAs($user)->get('/pioneer-season')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Growth/PioneerSeason')
            ->where('season.state', 'upcoming')
            ->where('me.checkpoints', []));

    expect(PioneerCheckpoint::count())->toBe(0);
});

it('verifies all five checkpoints from server records and pays each once', function () {
    Carbon::setTestNow('2026-11-08 12:00:00');
    $user = User::factory()->create(['wallet_address' => PIONEER_WALLET]);

    completedBridge(['user_id' => $user->id, 'completed_at' => '2026-11-05 10:00:00']);
    indexed('swap', 12.5, '2026-11-05 11:00:00', '0xSWAP');
    indexed('liq_add', 40, '2026-11-05 12:00:00', '0xlp');
    indexed('swap', 6, '2026-11-07 09:00:00', '0xback', 'USDC', 'ASH');

    $this->actingAs($user)->get('/pioneer-season')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('season.state', 'open')
            ->has('me.checkpoints', 5)
            ->where('me.checkpoints.3.reference', 'swap:0xswap')
            ->where('me.checkpoints.3.explorer_url', 'https://explorer.cyberia.church/tx/0xswap')
            ->where('me.checkpoints.5.reference', 'swap:0xback')
            ->where('me.finisher.status', 'pending')
            ->where('me.finisher.position', 1)
            ->where('me.xp_earned', 350)
            ->has('leaderboard', 1)
            ->where('stats.checkpoints.5', 1));

    expect(pioneerXp($user))->toBe([
        's1:01' => 25, 's1:02' => 100, 's1:03' => 50, 's1:04' => 75, 's1:05' => 100,
    ]);

    // The finisher is dated by the last checkpoint that happened on chain.
    $finisher = PioneerFinisher::query()->sole();
    expect($finisher->completed_at->toDateTimeString())->toBe('2026-11-08 12:00:00');

    // A second visit and a sweep pay nothing new.
    $this->actingAs($user)->get('/pioneer-season')->assertOk();
    $this->artisan('pioneer:verify')->assertSuccessful();

    expect(array_sum(pioneerXp($user)))->toBe(350)
        ->and(PioneerCheckpoint::count())->toBe(5)
        ->and(PioneerFinisher::count())->toBe(1);
});

it('does not count dust, unpriced, out-of-window, round-trip or circular swaps', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $user = User::factory()->create(['wallet_address' => PIONEER_WALLET]);

    indexed('swap', 4.99, '2026-11-05 10:00:00', '0xdust');
    indexed('swap', null, '2026-11-05 10:05:00', '0xunpriced');
    indexed('swap', 100, '2026-11-03 23:59:00', '0xbefore');
    indexed('swap', 50, '2026-11-06 10:00:00', '0xthere', 'CYBER', 'USDC');
    indexed('swap', 0.5, '2026-11-06 10:40:00', '0xbackagain', 'USDC', 'CYBER');
    indexed('swap', 50, '2026-11-07 10:00:00', '0xcircle', 'CYBER', 'CYBER');

    app(PioneerSeasonService::class)->verify($user);

    expect(PioneerCheckpoint::query()->where('checkpoint', PioneerSeasonService::SWAP)->exists())->toBeFalse();

    // A swap whose reverse came more than an hour later is a trade, not a loop.
    indexed('swap', 50, '2026-11-08 10:00:00', '0xreal', 'CYBER', 'USDC');
    indexed('swap', 50, '2026-11-08 12:00:00', '0xlater', 'USDC', 'CYBER');

    app(PioneerSeasonService::class)->verify($user);

    expect(PioneerCheckpoint::query()->where('checkpoint', PioneerSeasonService::SWAP)->value('reference'))
        ->toBe('swap:0xreal');
});

it('needs a later UTC date and a full day for the return checkpoint', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $user = User::factory()->create(['wallet_address' => PIONEER_WALLET]);

    indexed('swap', 20, '2026-11-05 23:00:00', '0xlate');
    indexed('liq_add', 20, '2026-11-06 01:00:00', '0xmidnight');

    app(PioneerSeasonService::class)->verify($user);

    expect(PioneerCheckpoint::query()->where('checkpoint', PioneerSeasonService::RETURN)->exists())->toBeFalse();

    indexed('liq_add', 20, '2026-11-06 23:30:00', '0xnextday');

    app(PioneerSeasonService::class)->verify($user);

    expect(PioneerCheckpoint::query()->where('checkpoint', PioneerSeasonService::RETURN)->value('reference'))
        ->toBe('liquidity:0xnextday');
});

it('credits one bridge request to one profile', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $sender = User::factory()->create(['wallet_address' => PIONEER_WALLET]);
    $recipient = User::factory()->create(['wallet_address' => '0x2222222222222222222222222222222222222222']);

    completedBridge([
        'sender_address' => PIONEER_WALLET,
        'recipient_address' => '0x2222222222222222222222222222222222222222',
    ]);

    $service = app(PioneerSeasonService::class);
    $service->verify($sender);
    $service->verify($recipient);

    expect(PioneerCheckpoint::query()->where('checkpoint', PioneerSeasonService::BRIDGE)->pluck('user_id')->all())
        ->toBe([$sender->id]);
});

it('ignores a bridge request missing the destination hash', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $user = User::factory()->create(['wallet_address' => PIONEER_WALLET]);

    completedBridge(['user_id' => $user->id, 'destination_tx_hash' => null]);

    app(PioneerSeasonService::class)->verify($user);

    expect(PioneerCheckpoint::query()->where('checkpoint', PioneerSeasonService::BRIDGE)->exists())->toBeFalse();
});

it('leaves team and treasury addresses out entirely', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    config(['pioneer.excluded_addresses' => [PIONEER_WALLET]]);
    $user = User::factory()->create(['wallet_address' => PIONEER_WALLET]);
    indexed('swap', 50, '2026-11-05 10:00:00', '0xteam');

    $this->actingAs($user)->get('/pioneer-season')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('me.excluded', true));

    expect(PioneerCheckpoint::count())->toBe(0)
        ->and(pioneerXp($user))->toBe([]);
});

it('finishes without the bridge when the season does not require it', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    config(['pioneer.bridge_required' => false, 'pioneer.review_first' => 0]);
    $user = User::factory()->create(['wallet_address' => PIONEER_WALLET]);

    indexed('swap', 20, '2026-11-05 10:00:00', '0xa');
    indexed('liq_add', 20, '2026-11-07 10:00:00', '0xb');

    $result = app(PioneerSeasonService::class)->verify($user);

    // Past the reviewed first finishers, approval is automatic and pays the bonus.
    expect($result['finished'])->toBeTrue()
        ->and(PioneerFinisher::query()->sole()->status)->toBe(PioneerFinisher::APPROVED)
        ->and(pioneerXp($user))->toHaveKey('s1:complete', 250)
        ->and(app(PioneerSeasonService::class)->isApprovedPioneer($user))->toBeTrue();
});

it('lets a reviewer approve or reject a pending finisher', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    config(['pioneer.bridge_required' => false]);
    $approved = User::factory()->create(['wallet_address' => PIONEER_WALLET]);
    $rejected = User::factory()->create(['wallet_address' => '0x3333333333333333333333333333333333333333']);

    foreach ([[$approved, PIONEER_WALLET], [$rejected, '0x3333333333333333333333333333333333333333']] as [$user, $address]) {
        indexed('swap', 20, '2026-11-05 10:00:00', '0xs'.$user->id, address: $address);
        indexed('stake', 20, '2026-11-07 10:00:00', '0xk'.$user->id, address: $address);
        app(PioneerSeasonService::class)->verify($user);
    }

    expect(PioneerFinisher::query()->where('status', PioneerFinisher::PENDING)->count())->toBe(2)
        ->and(app(PioneerSeasonService::class)->isApprovedPioneer($approved))->toBeFalse();

    $this->artisan('pioneer:review')->expectsOutputToContain('#'.$approved->id)->assertSuccessful();
    $this->artisan('pioneer:review', ['--approve' => $approved->id, '--by' => 'lev'])->assertSuccessful();
    $this->artisan('pioneer:review', ['--reject' => $rejected->id, '--note' => 'sybil'])->assertSuccessful();

    expect(app(PioneerSeasonService::class)->isApprovedPioneer($approved))->toBeTrue()
        ->and(pioneerXp($approved))->toHaveKey('s1:complete', 250)
        ->and(pioneerXp($rejected))->not->toHaveKey('s1:complete')
        ->and(app(PioneerSeasonService::class)->leaderboard())->toHaveCount(1);
});

it('does not pass the wallet checkpoint for a profile that never showed up', function () {
    Carbon::setTestNow('2026-11-10 12:00:00');
    $idle = User::factory()->create(['wallet_address' => PIONEER_WALLET]);

    $this->artisan('pioneer:verify')->assertSuccessful();

    expect(PioneerCheckpoint::count())->toBe(0);

    $this->actingAs($idle)->get('/pioneer-season')->assertOk();

    expect(PioneerCheckpoint::query()->where('user_id', $idle->id)->pluck('checkpoint')->all())
        ->toBe([PioneerSeasonService::WALLET]);
});
