<?php

use App\Models\Contribution;
use App\Services\Contributions\ContributionScorer;
use App\Services\Contributions\EpochAllocator;
use App\Services\Contributions\MerkleTree;
use App\Services\Contributions\SybilGuard;
use Brick\Math\BigInteger;
use Illuminate\Support\Carbon;

/**
 * The pure half of the open-source reward engine: what a contribution is
 * worth, whether it counts, how a pool splits and the tree the contract
 * verifies. Every rule that moves money is pinned here without a database.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function candidate(array $overrides = []): array
{
    return array_merge([
        'kind' => Contribution::PULL_REQUEST,
        'login' => 'alice',
        'account_type' => 'User',
        'account_created_at' => Carbon::parse('2020-01-01'),
        'banned' => false,
        'accepted_at' => Carbon::parse('2026-11-10 12:00'),
        'merged_by' => 'maintainer',
        'additions' => 40,
        'deletions' => 10,
        'labels' => [],
        'state_reason' => null,
        'counted_same_day' => 0,
    ], $overrides);
}

function guard(array $rules = []): SybilGuard
{
    return new SybilGuard(
        array_merge(['min_account_age_days' => 90, 'refuse_self_merged' => true, 'max_per_day' => 3, 'excluded_logins' => ['coreteam']], $rules),
        ContributionScorer::fromConfig(),
        ['bug', 'accepted'],
    );
}

it('pins the tree to the root OpenZeppelin’s StandardMerkleTree builds', function () {
    // The same vector, and the same root, is in crypto/hardhat/test/CyberiaContributorRewards.ts,
    // where it was also checked against @openzeppelin/merkle-tree itself.
    $leaves = [
        MerkleTree::leaf(7, '0x1111111111111111111111111111111111111111', '600'),
        MerkleTree::leaf(7, '0x2222222222222222222222222222222222222222', '300'),
        MerkleTree::leaf(7, '0x3333333333333333333333333333333333333333', '100'),
    ];

    $tree = new MerkleTree($leaves);

    expect($tree->root())->toBe('0x083bfce98a998d787c2c1d823f255756eef2807cf8dac1e9624dd37198e41a5d');

    foreach ($leaves as $leaf) {
        expect(MerkleTree::verify($tree->root(), $leaf, $tree->proof($leaf)))->toBeTrue();
    }

    // A leaf of another epoch is another leaf: a proof never crosses roots.
    expect(MerkleTree::leaf(8, '0x1111111111111111111111111111111111111111', '600'))->not->toBe($leaves[0]);
});

it('builds a one-leaf tree whose root is the leaf', function () {
    $leaf = MerkleTree::leaf(1, '0x1111111111111111111111111111111111111111', '1000000000000000000000000');
    $tree = new MerkleTree([$leaf]);

    expect($tree->root())->toBe($leaf)
        ->and($tree->proof($leaf))->toBe([]);
});

it('splits a pool by points and never pays out more than it holds', function () {
    $shares = (new EpochAllocator)->allocate('1000', ['0xa' => 300, '0xb' => 200, '0xc' => 100], 10_000, 0);

    expect($shares)->toBe(['0xa' => '500', '0xb' => '333', '0xc' => '166']);

    $paid = array_reduce($shares, fn (BigInteger $sum, string $s) => $sum->plus($s), BigInteger::zero());
    expect($paid->isLessThanOrEqualTo(1000))->toBeTrue();
});

it('caps one contributor and shares what the cap frees with everybody under it', function () {
    // Alice alone would take 90%; at a 50% cap the other half goes to the rest,
    // in proportion, instead of staying unpaid.
    $shares = (new EpochAllocator)->allocate('1000', ['0xa' => 900, '0xb' => 60, '0xc' => 40], 5_000, 0);

    expect($shares)->toBe(['0xa' => '500', '0xb' => '300', '0xc' => '200']);
});

it('leaves out contributors under the minimum and pays nothing from an empty pool', function () {
    $allocator = new EpochAllocator;

    expect($allocator->allocate('1000', ['0xa' => 900, '0xb' => 50], 10_000, 100))->toBe(['0xa' => '1000'])
        ->and($allocator->allocate('0', ['0xa' => 900], 10_000, 0))->toBe([])
        ->and($allocator->allocate('1000', [], 10_000, 0))->toBe([]);
});

it('scores size on a capped log curve and gives trivial work nothing', function () {
    $scorer = ContributionScorer::fromConfig();

    expect($scorer->score(Contribution::PULL_REQUEST, 2, 1))->toBe(0)
        ->and($scorer->score(Contribution::PULL_REQUEST, 50, 50))->toBe(2000)
        ->and($scorer->score(Contribution::PULL_REQUEST, 50_000, 0))->toBe(3000)
        ->and($scorer->score(Contribution::DOCUMENTATION, 50, 50))->toBe(1200)
        ->and($scorer->score(Contribution::ISSUE, 0, 0))->toBe(300)
        ->and($scorer->score(Contribution::PULL_REQUEST, 50, 50, 1.5))->toBe(3000);
});

it('reads documentation from a label or a docs: title', function () {
    $scorer = ContributionScorer::fromConfig();

    expect($scorer->kindOfPullRequest('docs: explain epochs', []))->toBe(Contribution::DOCUMENTATION)
        ->and($scorer->kindOfPullRequest('Fix bridge', ['Documentation']))->toBe(Contribution::DOCUMENTATION)
        ->and($scorer->kindOfPullRequest('Dockerfile cleanup', []))->toBe(Contribution::PULL_REQUEST);
});

it('counts honest work and refuses each sybil pattern with its own reason', function (array $overrides, ?string $reason) {
    expect(guard()->refusal(candidate($overrides)))->toBe($reason);
})->with([
    'honest pull request' => [[], null],
    'a GitHub App' => [['account_type' => 'Bot', 'login' => 'dependabot[bot]'], SybilGuard::BOT],
    'a [bot] login' => [['login' => 'renovate[bot]'], SybilGuard::BOT],
    'the excluded core team' => [['login' => 'CoreTeam'], SybilGuard::EXCLUDED],
    'a banned account' => [['banned' => true], SybilGuard::BANNED],
    'an account made last month' => [['account_created_at' => Carbon::parse('2026-10-20')], SybilGuard::ACCOUNT_TOO_NEW],
    'an account whose age is unknown' => [['account_created_at' => null], SybilGuard::ACCOUNT_TOO_NEW],
    'merged by its author' => [['merged_by' => 'Alice'], SybilGuard::SELF_MERGED],
    'a typo fix' => [['additions' => 1, 'deletions' => 1], SybilGuard::TRIVIAL],
    'the fourth of the day' => [['counted_same_day' => 3], SybilGuard::DAILY_CAP],
    'an accepted issue' => [['kind' => Contribution::ISSUE, 'labels' => ['Bug'], 'state_reason' => 'completed'], null],
    'an unlabelled issue' => [['kind' => Contribution::ISSUE, 'labels' => [], 'state_reason' => 'completed'], SybilGuard::NOT_ACCEPTED],
    'an issue closed as not planned' => [['kind' => Contribution::ISSUE, 'labels' => ['bug'], 'state_reason' => 'not_planned'], SybilGuard::NOT_ACCEPTED],
]);
