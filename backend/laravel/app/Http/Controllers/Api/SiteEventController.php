<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteEvent;
use App\Services\GamificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Funnel-event ingest for the whole site (the landing included, hence the
 * CSRF exemption in bootstrap/app.php). Session cookies still resolve the
 * user when present, so events tie back to accounts where possible.
 *
 * For signed-in users a page view also keeps the daily streak alive. Nothing
 * of value is paid from here — a browser can claim it opened a page, not that
 * it swapped — so the only progression this endpoint can move is the visit
 * award and the exploration quest.
 */
class SiteEventController extends Controller
{
    /** Events that count as "the user showed up today". */
    private const VISIT_EVENTS = ['page_view', 'landing_view'];

    public function store(Request $request, GamificationService $gamification): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'event' => ['required', 'string', Rule::in(SiteEvent::EVENTS)],
            'page' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array:source,medium,campaign,partner,network,token,action_type'],
            'metadata.source' => ['nullable', 'string', 'max:100'],
            'metadata.medium' => ['nullable', 'string', 'max:100'],
            'metadata.campaign' => ['nullable', 'string', 'max:100'],
            'metadata.partner' => ['nullable', 'string', 'max:100'],
            'metadata.network' => ['nullable', 'string', 'max:100'],
            'metadata.token' => ['nullable', 'string', 'max:100'],
            'metadata.action_type' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        SiteEvent::create([
            ...$validated,
            'user_id' => $user?->id,
            'created_at' => now(),
        ]);

        if ($user && in_array($validated['event'], self::VISIT_EVENTS, true)) {
            $gamification->recordAction($user, 'page_view', page: $validated['page'] ?? null);
        }

        return response()->json(['ok' => true], 202);
    }
}
