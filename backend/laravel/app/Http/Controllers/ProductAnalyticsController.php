<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\EventTaxonomy;
use App\Services\Analytics\ProductMetricsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The wallet's product dashboard, inside the CRM the operator already uses.
 *
 * Deliberately not a second admin app: it renders through Inertia with the
 * same components, sits behind the same `EnsureCrmAdmin` wallet allowlist, and
 * answers the same way to anyone else — 404, so its existence is not
 * discoverable by an ordinary signed-in user.
 *
 * `/crm/analytics` answers "did visitors to the site convert". This answers
 * the question one product further in: of the people who installed the wallet,
 * who funded it, who used it, and who came back.
 */
class ProductAnalyticsController extends Controller
{
    public function __construct(private ProductMetricsService $metrics) {}

    public function index(Request $request): Response
    {
        $filters = AnalyticsFilters::fromRequest($request);

        return Inertia::render('crm/Product', [
            'filters' => $filters->toArray(),
            'options' => $this->metrics->filterOptions(),
            'overview' => $this->metrics->overview($filters),
            'series' => $this->metrics->activeOverTime($filters),
            'mainFunnel' => $this->metrics->mainFunnel($filters),
            'productFunnels' => $this->metrics->productFunnels($filters),
            'activation' => $this->metrics->activation($filters),
            'cohorts' => $this->metrics->retentionCohorts($filters),
            'acquisition' => $this->metrics->acquisition($filters),
            'usage' => $this->metrics->productUsage($filters),
            'errors' => $this->metrics->errors($filters),
            'gas' => $this->metrics->gasSponsorship($filters),
            'recent' => $this->recentUsers($filters),
            'meaningful' => EventTaxonomy::MEANINGFUL,
        ]);
    }

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

        return Inertia::render('crm/ProductUser', [
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
        ]);
    }

    /**
     * The newest installations, as a way into the explorer.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentUsers(AnalyticsFilters $filters): array
    {
        return AnalyticsUser::query()
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->when($filters->platform, fn ($query, $value) => $query->where('platform', $value))
            ->when($filters->appVersion, fn ($query, $value) => $query->where('app_version', $value))
            ->when($filters->source, fn ($query, $value) => $query->where('source', $value))
            ->when($filters->campaign, fn ($query, $value) => $query->where('campaign', $value))
            ->orderByDesc('created_at')
            ->limit(30)
            ->get([
                'id', 'created_at', 'last_seen_at', 'platform', 'app_version',
                'source', 'campaign', 'wallet_created_at', 'funded_at', 'activated_at',
            ])
            ->map(fn (AnalyticsUser $user) => [
                'id' => $user->id,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
                'platform' => $user->platform,
                'app_version' => $user->app_version,
                'source' => $user->source,
                'campaign' => $user->campaign,
                'wallet' => $user->wallet_created_at !== null,
                'funded' => $user->funded_at !== null,
                'activated' => $user->activated_at !== null,
            ])
            ->all();
    }
}
