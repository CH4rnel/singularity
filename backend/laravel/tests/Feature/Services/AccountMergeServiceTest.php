<?php

use App\Enums\TeamRole;
use App\Exceptions\AccountMergeConflictException;
use App\Models\Activity;
use App\Models\Dao;
use App\Models\Proposal;
use App\Models\Reaction;
use App\Models\Team;
use App\Models\User;
use App\Models\UserDepositAddress;
use App\Services\AccountMergeService;
use App\Services\UserDepositAddressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Throwaway test-only seed — never a real one.
const MERGE_TEST_SEED = 'abababababababababababababababababababababababababababababab';

beforeEach(function () {
    config()->set('bridge.chains.bitcoin.hd_seed', MERGE_TEST_SEED);
});

test('reassigns simple user_id tables to the survivor', function () {
    $survivor = User::factory()->create();
    $absorbed = User::factory()->create();

    $activity = Activity::factory()->create(['user_id' => $absorbed->id]);
    $dao = Dao::factory()->create(['user_id' => $absorbed->id]);

    app(AccountMergeService::class)->merge($survivor, $absorbed);

    expect($activity->fresh()->user_id)->toBe($survivor->id);
    expect($dao->fresh()->user_id)->toBe($survivor->id);
});

test('copies identity fields from the absorbed account only where the survivor is null', function () {
    $survivor = User::factory()->create(['wallet_address' => null, 'twitter_id' => 'keep-mine']);
    $absorbed = User::factory()->create(['wallet_address' => '0xabsorbed', 'twitter_id' => null]);

    app(AccountMergeService::class)->merge($survivor, $absorbed);

    expect($survivor->fresh()->wallet_address)->toBe('0xabsorbed');
    expect($survivor->fresh()->twitter_id)->toBe('keep-mine');
    expect($absorbed->fresh()->wallet_address)->toBeNull();
});

test('throws and rolls back the whole merge on a conflicting identity field', function () {
    $survivor = User::factory()->create(['solana_wallet_address' => 'SurvivorAddress1111111111111111111111111']);
    $absorbed = User::factory()->create(['solana_wallet_address' => 'AbsorbedAddress1111111111111111111111111']);

    $activity = Activity::factory()->create(['user_id' => $absorbed->id]);

    expect(fn () => app(AccountMergeService::class)->merge($survivor, $absorbed))
        ->toThrow(AccountMergeConflictException::class);

    expect($absorbed->fresh()->merged_into_id)->toBeNull();
    expect($absorbed->fresh()->solana_wallet_address)->toBe('AbsorbedAddress1111111111111111111111111');
    expect($activity->fresh()->user_id)->toBe($absorbed->id);
});

test('drops the absorbed account\'s duplicate reaction on collision, keeping the survivor\'s', function () {
    $survivor = User::factory()->create();
    $absorbed = User::factory()->create();
    $sharedProposal = Proposal::factory()->create();

    $survivorReaction = Reaction::create([
        'user_id' => $survivor->id,
        'reactable_type' => Proposal::class,
        'reactable_id' => $sharedProposal->id,
        'emoji' => '👍',
    ]);
    $absorbedDuplicate = Reaction::create([
        'user_id' => $absorbed->id,
        'reactable_type' => $survivorReaction->reactable_type,
        'reactable_id' => $survivorReaction->reactable_id,
        'emoji' => $survivorReaction->emoji,
    ]);
    $absorbedUnique = Reaction::create([
        'user_id' => $absorbed->id,
        'reactable_type' => Proposal::class,
        'reactable_id' => Proposal::factory()->create()->id,
        'emoji' => '🔥',
    ]);

    app(AccountMergeService::class)->merge($survivor, $absorbed);

    expect($survivorReaction->fresh()->user_id)->toBe($survivor->id);
    expect(Reaction::find($absorbedDuplicate->id))->toBeNull();
    expect($absorbedUnique->fresh()->user_id)->toBe($survivor->id);
});

test('reassigns notifications and push subscriptions to the survivor', function () {
    $survivor = User::factory()->create();
    $absorbed = User::factory()->create();

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Test',
        'notifiable_type' => User::class,
        'notifiable_id' => $absorbed->id,
        'data' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection(config('webpush.database_connection'))->table(config('webpush.table_name'))->insert([
        'subscribable_type' => User::class,
        'subscribable_id' => $absorbed->id,
        'endpoint' => 'https://push.example.test/'.Str::random(20),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(AccountMergeService::class)->merge($survivor, $absorbed);

    expect(DB::table('notifications')->where('notifiable_id', $survivor->id)->count())->toBe(1);
    expect(DB::table('notifications')->where('notifiable_id', $absorbed->id)->count())->toBe(0);

    $pushTable = DB::connection(config('webpush.database_connection'))->table(config('webpush.table_name'));
    expect($pushTable->where('subscribable_id', $survivor->id)->count())->toBe(1);
    expect($pushTable->where('subscribable_id', $absorbed->id)->count())->toBe(0);
});

test('revokes the absorbed account\'s API tokens and sessions instead of migrating them', function () {
    $survivor = User::factory()->create();
    $absorbed = User::factory()->create();

    $absorbed->createToken('test-token');
    DB::table('sessions')->insert([
        'id' => Str::random(40),
        'user_id' => $absorbed->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'pest',
        'payload' => base64_encode('test'),
        'last_activity' => time(),
    ]);

    expect($absorbed->tokens()->count())->toBe(1);

    app(AccountMergeService::class)->merge($survivor, $absorbed);

    expect($absorbed->tokens()->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $absorbed->id)->count())->toBe(0);
});

test('never reassigns team_members or user_deposit_addresses rows', function () {
    // The factory already gives every user its own personal team + owner
    // membership row — capture both sides' baselines before adding an
    // extra membership for the absorbed account to prove untouched.
    $survivor = User::factory()->create();
    $absorbed = User::factory()->create();
    $survivorMembershipsBefore = DB::table('team_members')->where('user_id', $survivor->id)->count();

    $extraTeam = Team::factory()->create();
    DB::table('team_members')->insert([
        'team_id' => $extraTeam->id,
        'user_id' => $absorbed->id,
        'role' => TeamRole::Owner->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $absorbedMembershipsBefore = DB::table('team_members')->where('user_id', $absorbed->id)->pluck('team_id')->sort()->values();

    $depositService = app(UserDepositAddressService::class);
    $addressBefore = $depositService->addressesFor($absorbed)['bitcoin'];
    $wifBefore = $depositService->wif('bitcoin', $absorbed->id);

    app(AccountMergeService::class)->merge($survivor, $absorbed);

    expect(DB::table('team_members')->where('user_id', $absorbed->id)->pluck('team_id')->sort()->values()->all())
        ->toBe($absorbedMembershipsBefore->all());
    expect(DB::table('team_members')->where('user_id', $survivor->id)->count())->toBe($survivorMembershipsBefore);

    $depositRow = UserDepositAddress::query()->where('chain', 'bitcoin')->where('address', $addressBefore)->first();
    expect($depositRow)->not->toBeNull();
    expect($depositRow->user_id)->toBe($absorbed->id);

    // Sweeping still derives the same key from the address row's own,
    // untouched user_id — merge must never change which id sweeps it.
    expect($depositService->wif('bitcoin', $absorbed->id))->toBe($wifBefore);
});

test('is idempotent: merging the same pair twice only merges once', function () {
    $survivor = User::factory()->create();
    $absorbed = User::factory()->create(['wallet_address' => '0xabsorbed']);

    app(AccountMergeService::class)->merge($survivor, $absorbed);
    expect($survivor->fresh()->wallet_address)->toBe('0xabsorbed');

    // Second call is a no-op — must not throw, must not touch anything.
    app(AccountMergeService::class)->merge($survivor->fresh(), $absorbed->fresh());

    expect($absorbed->fresh()->merged_into_id)->toBe($survivor->id);
});

test('merging a user into itself is a no-op', function () {
    $user = User::factory()->create();

    app(AccountMergeService::class)->merge($user, $user);

    expect($user->fresh()->merged_into_id)->toBeNull();
});
