<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Funnel-event ingest for the whole site (the landing included, hence the
 * CSRF exemption in bootstrap/app.php). Session cookies still resolve the
 * user when present, so events tie back to accounts where possible.
 */
class SiteEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'uuid'],
            'event' => ['required', 'string', Rule::in(SiteEvent::EVENTS)],
            'page' => ['nullable', 'string', 'max:255'],
            'wallet_address' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        SiteEvent::create([
            ...$validated,
            'user_id' => $request->user()?->id,
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true], 202);
    }
}
