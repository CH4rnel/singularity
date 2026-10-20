<?php

use App\Models\Contribution;
use App\Models\ContributionEpoch;
use App\Models\ContributionRepository;
use App\Models\ContributionReward;
use App\Models\ContributorAccount;
use App\Models\User;
use App\Services\Contributions\EpochBuilder;
use App\Services\Contributions\MerkleTree;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The open-source reward engine end to end: GitHub is read, the guard rules,
 * an epoch splits the pool into a tree, and the proofs come back out of the
 * API. GitHub is faked; everything after it is the real code.
 */
beforeEach(function () {
    Cache::flush();
    config([
        'contributions.repositories' => ['lain-labs/os'],
        'contributions.github.api' => 'https://api.github.test',
        'contributions.pool.amount' => '1000',
        'contributions.pool.decimals' => 18,
        'contributions.pool.max_share_bps' => 10_000,
        'contributions.pool.min_points' => 0,
        'contributions.epochs.first_starts_at' => '2026-11-01 00:00:00',
        'contributions.epochs.length_days' => 30,
    ]);
    $this->travelTo(now()->parse('2026-12-05 00:00:00'));
});

/**
 * @return array<string, mixed>
 */
function pull(int $number, string $login, int $id, string $mergedAt, array $extra = []): array
{
    return array_merge([
        'number' => $number,
        'title' => "Change {$number}",
        'html_url' => "https://github.com/lain-labs/os/pull/{$number}",
        'user' => ['id' => $id, 'login' => $login, 'type' => 'User'],
        'labels' => [],
        'merged_at' => $mergedAt,
        'updated_at' => $mergedAt,
    ], $extra);
}

function fakeGithub(array $pulls, array $details, array $issues = [], bool $verified = true): void
{
    Http::fake(function ($request) use ($pulls, $details, $issues, $verified) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_ends_with($path, '/contents/.cyberia/rewards.json') => $verified
                ? Http::response(['content' => base64_encode('{"chainId":49406}')])
                : Http::response(['message' => 'Not Found'], 404),
            $path === '/repos/lain-labs/os/pulls' => Http::response($pulls),
            preg_match('#^/repos/lain-labs/os/pulls/(\d+)$#', $path, $m) === 1 => Http::response($details[(int) $m[1]]),
            $path === '/repos/lain-labs/os/issues' => Http::response($issues),
            preg_match('#^/user/(\d+)$#', $path, $m) === 1 => Http::response([
                'id' => (int) $m[1],
                'login' => 'acct'.$m[1],
                'type' => 'User',
                // Account 666 was made yesterday; everyone else is old.
                'created_at' => $m[1] === '666' ? '2026-11-01T00:00:00Z' : '2019-01-01T00:00:00Z',
            ]),
            default => Http::response([], 404),
        };
    });
}

function seedPullRequests(): void
{
    fakeGithub(
        [
            pull(1, 'alice', 101, '2026-11-05T10:00:00Z'),
            pull(2, 'bob', 102, '2026-11-06T10:00:00Z'),
            pull(3, 'alice', 101, '2026-11-07T10:00:00Z'),
            pull(4, 'sybil', 666, '2026-11-08T10:00:00Z'),
            pull(5, 'carol', 103, '2026-11-09T10:00:00Z'),
            pull(6, 'dependabot[bot]', 49699333, '2026-11-09T11:00:00Z', ['user' => ['id' => 49699333, 'login' => 'dependabot[bot]', 'type' => 'Bot']]),
            pull(7, 'dave', 104, '2026-11-10T10:00:00Z'),
            // Closed without merging: not a contribution.
            pull(8, 'erin', 105, '2026-11-10T10:00:00Z', ['merged_at' => null]),
        ],
        [
            1 => ['additions' => 80, 'deletions' => 20, 'commits' => 2, 'merged_by' => ['login' => 'lev']],
            2 => ['additions' => 40, 'deletions' => 10, 'commits' => 1, 'merged_by' => ['login' => 'lev']],
            3 => ['additions' => 1, 'deletions' => 1, 'commits' => 1, 'merged_by' => ['login' => 'lev']],
            4 => ['additions' => 500, 'deletions' => 0, 'commits' => 9, 'merged_by' => ['login' => 'lev']],
            5 => ['additions' => 100, 'deletions' => 0, 'commits' => 3, 'merged_by' => ['login' => 'carol']],
            6 => ['additions' => 900, 'deletions' => 900, 'commits' => 1, 'merged_by' => ['login' => 'lev']],
            7 => ['additions' => 60, 'deletions' => 40, 'commits' => 1, 'merged_by' => ['login' => 'lev']],
        ],
        [
            ['number' => 20, 'title' => 'Bridge stalls', 'html_url' => 'https://github.com/lain-labs/os/issues/20', 'user' => ['id' => 102, 'login' => 'bob', 'type' => 'User'], 'labels' => [['name' => 'bug']], 'state_reason' => 'completed', 'closed_at' => '2026-11-12T10:00:00Z'],
            ['number' => 21, 'title' => 'Please add moon', 'html_url' => 'https://github.com/lain-labs/os/issues/21', 'user' => ['id' => 103, 'login' => 'carol', 'type' => 'User'], 'labels' => [], 'state_reason' => 'completed', 'closed_at' => '2026-11-12T11:00:00Z'],
            // GitHub lists pull requests among issues; they are not issues.
            ['number' => 1, 'title' => 'Change 1', 'html_url' => 'x', 'user' => ['id' => 101, 'login' => 'alice'], 'labels' => [], 'pull_request' => [], 'closed_at' => '2026-11-05T10:00:00Z'],
        ],
    );
}

it('indexes merged work and writes a reason on everything it refuses', function () {
    seedPullRequests();

    $this->artisan('contributions:sync')->assertSuccessful();

    $verdicts = Contribution::query()->orderBy('number')->get()
        ->mapWithKeys(fn (Contribution $c) => ["{$c->kind}#{$c->number}" => [$c->status, $c->reason, $c->points]])
        ->all();

    expect($verdicts)->toBe([
        'pull_request#1' => ['counted', null, 2000],
        'pull_request#2' => ['counted', null, 1585],
        'pull_request#3' => ['rejected', 'trivial', 0],
        'pull_request#4' => ['rejected', 'accountTooNew', 0],
        'pull_request#5' => ['rejected', 'selfMerged', 0],
        'pull_request#6' => ['rejected', 'bot', 0],
        'pull_request#7' => ['counted', null, 2000],
        'issue#20' => ['counted', null, 300],
        'issue#21' => ['rejected', 'notAccepted', 0],
    ]);

    expect(ContributionRepository::query()->first()->synced_at)->not->toBeNull()
        ->and(ContributorAccount::query()->where('github_id', '666')->value('github_created_at'))->not->toBeNull();
});

it('writes nothing twice and never re-judges a reviewed row', function () {
    seedPullRequests();
    $this->artisan('contributions:sync')->assertSuccessful();

    $this->artisan('contributions:review', ['action' => 'count', 'target' => 'lain-labs/os#5'])->assertSuccessful();
    $this->artisan('contributions:review', ['action' => 'reject', 'target' => 'lain-labs/os#7', 'reason' => 'copied'])->assertSuccessful();

    ContributionRepository::query()->update(['synced_at' => null]);
    $this->artisan('contributions:sync')->assertSuccessful();

    expect(Contribution::query()->count())->toBe(9)
        ->and(Contribution::query()->where('number', 5)->value('status'))->toBe('counted')
        ->and(Contribution::query()->where('number', 7)->first()->only('status', 'reason'))->toBe(['status' => 'rejected', 'reason' => 'copied']);
});

it('pays nothing from a repository that has not proven who controls it', function () {
    fakeGithub([pull(1, 'alice', 101, '2026-11-05T10:00:00Z')], [1 => ['additions' => 80, 'deletions' => 20, 'merged_by' => ['login' => 'lev']]], [], verified: false);

    $this->artisan('contributions:sync')->assertSuccessful();

    expect(Contribution::query()->count())->toBe(0)
        ->and(ContributionRepository::query()->first()->verification_error)->toBe('missing');
});

it('caps counted pull requests per author per day', function () {
    config(['contributions.sybil.max_per_day' => 2]);
    fakeGithub(
        [pull(1, 'alice', 101, '2026-11-05T10:00:00Z'), pull(2, 'alice', 101, '2026-11-05T11:00:00Z'), pull(3, 'alice', 101, '2026-11-05T12:00:00Z'), pull(4, 'alice', 101, '2026-11-06T09:00:00Z')],
        array_fill_keys([1, 2, 3, 4], ['additions' => 30, 'deletions' => 0, 'merged_by' => ['login' => 'lev']]),
    );

    $this->artisan('contributions:sync')->assertSuccessful();

    expect(Contribution::query()->orderBy('number')->pluck('reason')->all())->toBe([null, null, 'dailyCap', null]);
});

it('builds an epoch only for linked wallets and rolls everybody else forward', function () {
    seedPullRequests();
    $this->artisan('contributions:sync')->assertSuccessful();

    $alice = User::factory()->create(['wallet_address' => '0xAAAAaaaaAAAAaaaaAAAAaaaaAAAAaaaaAAAAaaaa']);
    $alice->forceFill(['github_id' => '101', 'github_username' => 'alice'])->save();
    $bob = User::factory()->create(['wallet_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']);
    $bob->forceFill(['github_id' => '102', 'github_username' => 'bob'])->save();
    // Dave (104) has counted work and no account: his points wait.

    $epoch = app(EpochBuilder::class)->build();

    expect($epoch->number)->toBe(1)
        ->and($epoch->status)->toBe(ContributionEpoch::BUILT)
        ->and($epoch->pool_raw)->toBe('1000000000000000000000');

    $rewards = $epoch->rewards()->orderBy('address')->get();

    // Alice 20 points, Bob 15.85 + 3 for the issue = 18.85: a 2000 : 1885 split.
    expect($rewards->pluck('points', 'address')->all())->toBe([
        '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => 2000,
        '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => 1885,
    ]);

    foreach ($rewards as $reward) {
        expect(MerkleTree::verify($epoch->merkle_root, MerkleTree::leaf(1, $reward->address, $reward->amount_raw), $reward->proof))->toBeTrue();
    }

    expect(Contribution::query()->where('github_id', '104')->value('contribution_epoch_id'))->toBeNull()
        ->and(Contribution::query()->where('github_id', '101')->where('status', 'counted')->value('contribution_epoch_id'))->toBe($epoch->id);

    // Dave links a wallet; the next epoch pays his old work.
    $dave = User::factory()->create(['wallet_address' => '0xdddddddddddddddddddddddddddddddddddddddd']);
    $dave->forceFill(['github_id' => '104'])->save();
    $this->travelTo(now()->parse('2027-01-01 00:00:00'));

    $second = app(EpochBuilder::class)->build();

    expect($second->number)->toBe(2)
        ->and($second->rewards()->pluck('address')->all())->toBe(['0xdddddddddddddddddddddddddddddddddddddddd']);
});

it('refuses to build with no pool, while the period is open, or over a published root', function () {
    seedPullRequests();
    $this->artisan('contributions:sync')->assertSuccessful();
    User::factory()->create(['wallet_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'])
        ->forceFill(['github_id' => '101'])->save();

    $builder = app(EpochBuilder::class);

    config(['contributions.pool.amount' => '0']);
    expect(fn () => $builder->build())->toThrow(RuntimeException::class, 'No pool is configured');

    config(['contributions.pool.amount' => '1000']);
    $this->travelTo(now()->parse('2026-11-20 00:00:00'));
    expect(fn () => $builder->build())->toThrow(RuntimeException::class, 'still open');

    $this->travelTo(now()->parse('2026-12-05 00:00:00'));
    $builder->build();
    $builder->markPublished(1, '0x'.str_repeat('ab', 32));
    expect(fn () => $builder->build(1))->toThrow(RuntimeException::class, 'published');
});

it('serves the board publicly, the account’s own verdicts to it, and only published proofs', function () {
    seedPullRequests();
    $this->artisan('contributions:sync')->assertSuccessful();

    $alice = User::factory()->create(['wallet_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']);
    $alice->forceFill(['github_id' => '101', 'github_username' => 'alice'])->save();

    $board = $this->getJson('/api/contributions')
        ->assertOk()
        ->assertJsonPath('me', null)
        ->assertJsonPath('pool.perEpoch', '1000')
        ->assertJsonPath('repositories.0.name', 'lain-labs/os')
        ->assertJsonPath('repositories.0.verified', true)
        ->json('leaders');

    $leaders = collect($board)->keyBy('login');

    expect($leaders['alice']['walletLinked'])->toBeTrue()
        ->and($leaders['dave']['walletLinked'])->toBeFalse()
        ->and($leaders->has('sybil'))->toBeFalse();

    $me = $this->actingAs($alice)->getJson('/api/contributions')->assertOk()->json('me');

    expect($me['github'])->toBe(['login' => 'alice'])
        ->and($me['pendingPoints'])->toEqual(20)
        ->and(collect($me['contributions'])->firstWhere('number', 3)['reason'])->toBe('trivial');

    $epoch = app(EpochBuilder::class)->build();

    // Built is a draft: nothing is claimable yet.
    $this->getJson('/api/contributions/claims/0xAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')
        ->assertOk()->assertJsonCount(0, 'claims');

    app(EpochBuilder::class)->markPublished($epoch->number, '0x'.str_repeat('cd', 32));

    $claim = $this->getJson('/api/contributions/claims/0xAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA')
        ->assertOk()->json('claims.0');

    expect($claim['epoch'])->toBe(1)
        ->and($claim['amountRaw'])->toBe(ContributionReward::query()->value('amount_raw'))
        ->and($claim['root'])->toBe($epoch->merkle_root);

    $this->getJson('/api/contributions/claims/not-an-address')->assertStatus(422);
});

it('bans an account and refuses its unpaid work', function () {
    seedPullRequests();
    $this->artisan('contributions:sync')->assertSuccessful();

    $this->artisan('contributions:review', ['action' => 'ban', 'target' => 'acct101', 'reason' => 'sybil ring'])->assertSuccessful();

    expect(Contribution::query()->where('github_id', '101')->where('status', 'counted')->count())->toBe(0);
});
