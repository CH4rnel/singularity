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
            'metadata' => ['nullable', 'array:source,medium,campaign,partner,network,token,action_type'],
            'metadata.source' => ['nullable', 'string', 'max:100'],
            'metadata.medium' => ['nullable', 'string', 'max:100'],
            'metadata.campaign' => ['nullable', 'string', 'max:100'],
            'metadata.partner' => ['nullable', 'string', 'max:100'],
            'metadata.network' => ['nullable', 'string', 'max:100'],
            'metadata.token' => ['nullable', 'string', 'max:100'],
            'metadata.action_type' => ['nullable', 'string', 'max:100'],
        ]);

        SiteEvent::create([
            ...$validated,
            'user_id' => $request->user()?->id,
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true], 202);
    }
}
