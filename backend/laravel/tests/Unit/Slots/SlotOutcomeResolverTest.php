<?php

use App\Models\SlotPool;
use App\Services\Slots\SlotOutcomeResolver;
use App\Services\Slots\SlotRngService;

function makePool(array $overrides = []): SlotPool
{
    $pool = new SlotPool(array_merge([
        'name' => 'test',
        'status' => 'active',
        'hot_wallet_address' => 'HOT',
        'burn_bps' => 200,
        'house_edge_bps' => 400,
        'jackpot_threshold_bps' => 10,
        'max_single_win_bps' => 2000,
        'jackpot_basket_bps' => 2500,
        'jackpot_basket_size' => 5,
    ], $overrides));
    $pool->id = 1;

    return $pool;
}

function snapshotOf(array $weights): array
{
    return array_map(fn (array $w) => [
        'mint' => $w['mint'],
        'symbol' => strtoupper($w['mint']),
        'decimals' => 6,
        'logo_url' => null,
        'weight' => $w['weight'],
        'balance' => bcmul('1000000', '1000000', 0), // 1_000_000 UI tokens raw
        'min_bet' => '0',
        'max_bet' => null,
        'token_program' => 'token',
    ], $weights);
}

it('returns loss when middle row is not three of a kind', function () {
    $resolver = new SlotOutcomeResolver;
    $weights = [['mint' => 'A', 'weight' => 0.5], ['mint' => 'B', 'weight' => 0.5]];
    $snapshot = snapshotOf($weights);

    $reels = [['A', 'B', 'A'], ['A', 'B', 'A'], ['A', 'B', 'A']];
    $result = $resolver->resolve(makePool(), 'A', '1000000', $reels, jackpotRoll: 9999, snapshot: $snapshot);

    expect($result['outcome'])->toBe('loss');
    expect($result['prize'])->toBe([]);
    expect($result['burn_amount'])->toBe('20000'); // 2% of 1_000_000
});

it('pays a single token on a regular three-of-a-kind', function () {
    $resolver = new SlotOutcomeResolver;
    $weights = [['mint' => 'A', 'weight' => 0.5], ['mint' => 'B', 'weight' => 0.5]];
    $snapshot = snapshotOf($weights);

    $reels = [['A', 'A', 'A'], ['A', 'A', 'A'], ['A', 'A', 'A']];
    $result = $resolver->resolve(makePool(), 'A', '1000000', $reels, jackpotRoll: 9999, snapshot: $snapshot);

    expect($result['outcome'])->toBe('win');
    expect($result['prize'])->toHaveCount(1);
    expect($result['prize'][0]['mint'])->toBe('A');
    expect(bccomp($result['prize'][0]['amount'], '0', 0))->toBeGreaterThan(0);
});

it('promotes to jackpot when probe hits AND row matches', function () {
    $resolver = new SlotOutcomeResolver;
    $weights = [['mint' => 'A', 'weight' => 0.6], ['mint' => 'B', 'weight' => 0.4]];
    $snapshot = snapshotOf($weights);

    $reels = [['A', 'A', 'A'], ['A', 'A', 'A'], ['A', 'A', 'A']];
    $result = $resolver->resolve(makePool(), 'A', '1000000', $reels, jackpotRoll: 5, snapshot: $snapshot);

    expect($result['outcome'])->toBe('jackpot');
    expect(count($result['prize']))->toBeGreaterThan(0);
});

it('approximates expected RTP empirically', function () {
    $rng = new SlotRngService;
    $resolver = new SlotOutcomeResolver;
    $pool = makePool(['jackpot_threshold_bps' => 0]); // disable jackpot for clean RTP

    $weights = [
        ['mint' => 'A', 'weight' => 1 / 3],
        ['mint' => 'B', 'weight' => 1 / 3],
        ['mint' => 'C', 'weight' => 1 / 3],
    ];
    $snapshot = snapshotOf($weights);

    $serverSeed = bin2hex(random_bytes(16));
    $totalBet = '0';
    $totalWon = '0';
    $iters = 30_000;
    $betSize = '1000000';

    for ($n = 0; $n < $iters; $n++) {
        $spin = $rng->spin($serverSeed, 'c', $n, $weights);
        $outcome = $resolver->resolve($pool, 'A', $betSize, $spin['reels'], $spin['jackpotRoll'], $snapshot);
        $totalBet = bcadd($totalBet, $betSize, 0);
        if ($outcome['outcome'] === 'win') {
            $totalWon = bcadd($totalWon, $outcome['prize'][0]['amount'], 0);
        }
    }

    $rtp = (float) bcdiv($totalWon, $totalBet, 6);
    $expected = (10_000 - $pool->house_edge_bps) / 10_000.0;

    // Empirical RTP should sit close to (1 - edge). Caps and discrete rounding
    // shave a bit off, so we allow a wide band.
    expect($rtp)->toBeGreaterThan($expected * 0.7);
    expect($rtp)->toBeLessThan($expected * 1.3);
});
