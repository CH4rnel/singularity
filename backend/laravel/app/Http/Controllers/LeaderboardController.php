<?php

namespace App\Http\Controllers;

use App\Services\GamificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public XP leaderboard. Deliberately public: the ranking is the social proof
 * that makes the progression worth chasing, and every row links to a profile
 * page, so it doubles as the discovery surface for the member directory.
 *
 * Signed-in visitors also get their own standing, which is the part that
 * actually brings people back — "you are #34, two swaps from #33".
 */
class LeaderboardController extends Controller
{
    public function __invoke(Request $request, GamificationService $gamification): Response
    {
        $user = $request->user();

        return Inertia::render('Leaderboard', [
            'rows' => $gamification->leaderboard(50),
            'me' => $user ? [
                'user_id' => $user->id,
                ...$gamification->publicProgressFor($user),
            ] : null,
        ]);
    }
}
