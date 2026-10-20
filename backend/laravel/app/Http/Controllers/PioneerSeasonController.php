<?php

namespace App\Http\Controllers;

use App\Services\PioneerSeasonService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /pioneer-season: the checkpoints, the signed-in visitor's progress and the
 * season board.
 *
 * Opening the page is also a verification: the visitor's records are checked
 * against the indexer and the bridge table first, so somebody who just swapped
 * sees the checkpoint without waiting for the sweep. Verification only reads
 * server-side records and is idempotent, so a reload is the "check again"
 * button and costs at most a few indexed queries.
 */
class PioneerSeasonController extends Controller
{
    public function __invoke(Request $request, PioneerSeasonService $pioneer): Response
    {
        $user = $request->user();

        if ($user) {
            $pioneer->verify($user, present: true);
        }

        return Inertia::render('Growth/PioneerSeason', [
            'season' => [
                'key' => $pioneer->seasonKey(),
                'title' => (string) config('pioneer.title'),
                'state' => $pioneer->state(),
                'starts_at' => $pioneer->startsAt()->toIso8601String(),
                'ends_at' => $pioneer->endsAt()->toIso8601String(),
                'min_swap_usd' => (float) config('pioneer.min_swap_usd'),
                'min_liquidity_usd' => (float) config('pioneer.min_liquidity_usd'),
                'return_after_hours' => (int) config('pioneer.return_after_hours'),
                'review_first' => (int) config('pioneer.review_first'),
                'xp_complete' => (int) config('pioneer.xp.complete', 0),
            ],
            'checkpoints' => array_values(array_map(fn (array $definition) => [
                ...$definition,
                'required' => in_array($definition['number'], $pioneer->requiredCheckpoints(), true),
                'xp' => (int) config("pioneer.xp.{$definition['number']}", 0),
            ], $pioneer->definitions())),
            'me' => $user ? $pioneer->progressFor($user) : null,
            'leaderboard' => $pioneer->leaderboard(),
            'stats' => $pioneer->stats(),
        ]);
    }
}
