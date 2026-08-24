<?php

use App\Models\AnalyticsUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The two builders every product-analytics test starts from.
 *
 * They live here rather than in whichever test file happened to need them
 * first, because a helper defined inside one test file is a helper that works
 * only while that file is also being run — a suite that passes as a directory
 * and fails as a single file teaches people to stop running single files.
 */

/**
 * One installation, with whatever milestones the test needs.
 */
function installation(array $attributes = []): AnalyticsUser
{
    $now = Carbon::now('UTC');

    return tap(new AnalyticsUser, fn (AnalyticsUser $user) => $user->forceFill([
        'id' => (string) Str::uuid(),
        'created_at' => $now,
        'first_seen_at' => $now,
        'last_seen_at' => $now,
        'platform' => 'web',
        'app_version' => 'v0.12.0',
        ...$attributes,
    ])->save());
}

function logEvent(AnalyticsUser $user, string $name, ?Carbon $at = null, array $properties = []): void
{
    DB::table('analytics_events')->insert([
        'event_id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'session_id' => null,
        'event' => $name,
        'chain' => $properties['chain'] ?? null,
        'properties' => $properties === [] ? null : json_encode($properties),
        'created_at' => $at ?? Carbon::now('UTC'),
    ]);
}
