<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsUser;
use App\Services\Analytics\EventTaxonomy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One installation of the wallet, in full.
 *
 * The aggregate half of this used to be a page of its own; it is one of the
 * six questions on "Числа" now. What stayed is the thing an aggregate cannot
 * do — reading a single drop-off instead of inferring it from a percentage —
 * and it sits behind the same `EnsureCrmAdmin` wallet allowlist as the rest of
 * the console, answering 404 to everyone else.
 */
class ProductAnalyticsController extends Controller
{
    /**
     * One anonymous installation, as a timeline.
     *
     * Everything here is already in the tables; the page exists so a drop-off
     * can be looked at rather than inferred from a percentage. What it
     * deliberately does not show is the addresses: they are stored to verify
     * funding and to price a drip, neither of which anyone reads by eye, and
     * printing them would turn a debugging screen into a way of finding out
     * which wallet belongs to which visitor. The count is shown instead —
     * enough to tell "this user linked nothing" from "this user linked three".
     */
    public function show(Request $request, string $user): Response
    {
        $record = AnalyticsUser::query()->findOrFail($user);

        $timeline = $record->events()
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['event', 'chain', 'properties', 'created_at', 'session_id']);

        $sessions = $record->sessions()
            ->orderByDesc('started_at')
            ->limit(20)
            ->get(['id', 'started_at', 'last_activity_at', 'ended_at', 'platform', 'app_version']);

        return Inertia::render('crm/Install', [
            'user' => [
                'id' => $record->id,
                'first_seen_at' => $record->first_seen_at?->toIso8601String(),
                'last_seen_at' => $record->last_seen_at?->toIso8601String(),
                'platform' => $record->platform,
                'app_version' => $record->app_version,
                'language' => $record->language,
                'source' => $record->source,
                'medium' => $record->medium,
                'campaign' => $record->campaign,
                'content' => $record->content,
                'referrer' => $record->referrer,
                'landing_path' => $record->landing_path,
                'wallet_created_at' => $record->wallet_created_at?->toIso8601String(),
                'internal_at' => $record->internal_at?->toIso8601String(),
                'internal_reason' => $record->internal_reason,
                'wallet_origin' => $record->wallet_origin,
                'funded_at' => $record->funded_at?->toIso8601String(),
                'funded_chain' => $record->funded_chain,
                'funded_source' => $record->funded_source,
                'activated_at' => $record->activated_at?->toIso8601String(),
                'activation_event' => $record->activation_event,
                'first_transaction_at' => $record->first_transaction_at?->toIso8601String(),
                // A number, never the addresses themselves. See above.
                'linked_addresses' => $record->addresses()->count(),
            ],
            'timeline' => $timeline,
            'sessions' => $sessions,
            'meaningful' => EventTaxonomy::MEANINGFUL,
            /*
             * How many installations are stuck at the same step this month.
             * One person stuck is an anecdote; six hundred of them is the one
             * place in this funnel where a product decision can still be made,
             * so the number travels with the dossier rather than being looked
             * up separately.
             */
            'peers' => $this->peers($record),
        ]);
    }

    /**
     * Say that this installation is one of ours, or that it is not.
     *
     * A configured address list catches the wallets we remembered to write
     * down, which is not the same set as the wallets we test from — the first
     * install this system ever recorded was an operator on an address that
     * appears on no list anywhere. Somebody has to be able to look at a
     * timeline, recognise their own afternoon in it, and say so.
     *
     * Reversible on purpose, and reversible by the same one click: this is a
     * judgement, and a judgement that cannot be withdrawn is one people stop
     * making. The mark changes no event and deletes nothing — it only decides
     * which side of the default filter the installation falls on.
     */
    public function internal(Request $request, string $user): RedirectResponse
    {
        $record = AnalyticsUser::query()->findOrFail($user);

        $mark = $request->boolean('internal');

        $record->forceFill([
            'internal_at' => $mark ? Carbon::now('UTC') : null,
            'internal_reason' => $mark ? 'manual' : null,
        ])->save();

        return back();
    }

    /**
     * The cohort this installation is an example of.
     *
     * @return array{step: string, count: int, days: int}
     */
    private function peers(AnalyticsUser $record): array
    {
        $days = 30;
        $since = now()->subDays($days);

        $step = match (true) {
            $record->activated_at !== null => 'activated',
            $record->funded_at !== null => 'funded',
            $record->wallet_created_at !== null => 'wallet',
            default => 'opened',
        };

        $query = AnalyticsUser::query()->where('created_at', '>=', $since);

        match ($step) {
            'activated' => $query->whereNotNull('activated_at'),
            'funded' => $query->whereNotNull('funded_at')->whereNull('activated_at'),
            'wallet' => $query->whereNotNull('wallet_created_at')->whereNull('funded_at'),
            default => $query->whereNull('wallet_created_at'),
        };

        return ['step' => $step, 'count' => $query->count(), 'days' => $days];
    }
}
