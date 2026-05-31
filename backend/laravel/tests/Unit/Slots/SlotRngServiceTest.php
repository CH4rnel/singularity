<?php

use App\Services\Slots\SlotRngService;

it('is deterministic for the same seeds and nonce', function () {
    $rng = new SlotRngService;
    $weights = [
        ['mint' => 'A', 'weight' => 0.5],
        ['mint' => 'B', 'weight' => 0.3],
        ['mint' => 'C', 'weight' => 0.2],
    ];

    $serverSeed = str_repeat('a', 64);
    $clientSeed = 'client-xyz';
    $nonce = 42;

    $first = $rng->spin($serverSeed, $clientSeed, $nonce, $weights);
    $second = $rng->spin($serverSeed, $clientSeed, $nonce, $weights);

    expect($first)->toEqual($second);
    expect($first['reels'])->toHaveCount(3);
    expect($first['reels'][0])->toHaveCount(3);
});

it('produces frequencies near the configured weights', function () {
    $rng = new SlotRngService;
    $weights = [
        ['mint' => 'A', 'weight' => 0.5],
        ['mint' => 'B', 'weight' => 0.3],
        ['mint' => 'C', 'weight' => 0.2],
    ];

    $serverSeed = bin2hex(random_bytes(16));
    $counts = ['A' => 0, 'B' => 0, 'C' => 0];
    $cells = 0;

    for ($n = 0; $n < 10_000; $n++) {
        $spin = $rng->spin($serverSeed, 'seed', $n, $weights);
        foreach ($spin['reels'] as $row) {
            foreach ($row as $mint) {
                $counts[$mint]++;
                $cells++;
            }
        }
    }

    expect(abs(($counts['A'] / $cells) - 0.5))->toBeLessThan(0.03);
    expect(abs(($counts['B'] / $cells) - 0.3))->toBeLessThan(0.03);
    expect(abs(($counts['C'] / $cells) - 0.2))->toBeLessThan(0.03);
});

it('hashes the server seed reproducibly', function () {
    $rng = new SlotRngService;
    $seed = 'deadbeef';

    expect($rng->hashServerSeed($seed))->toBe(hash('sha256', $seed));
});

it('rejects empty weights', function () {
    $rng = new SlotRngService;

    expect(fn () => $rng->spin('seed', 'client', 0, []))->toThrow(DomainException::class);
});
