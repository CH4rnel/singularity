<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where the wallet reports what happened.
 *
 * Stateless, like everything under `/api`: no session, no cookie, no CSRF
 * token, and the client is told to omit credentials — an analytics endpoint
 * that could see which account the browser is signed into would be linking two
 * identities this product deliberately keeps apart.
 *
 * It answers 202 for anything it can parse, including a batch it decided to
 * drop entirely. That is not laziness: analytics is not allowed to be a
 * dependency of sending money, so the only thing a client can usefully learn
 * from this endpoint is that it was heard. A 4xx would tempt a future version
 * of the client into retry logic that competes with a swap for the network.
 */
class AnalyticsIngestController extends Controller
{
    /** The shells and surfaces this wallet runs in. Anything else is 'web'. */
    private const PLATFORMS = ['web', 'pwa', 'desktop', 'mobile', 'telegram', 'extension'];

    public function __construct(private AnalyticsIngestService $ingest) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user.id' => ['required', 'uuid'],
            'user.platform' => ['nullable', 'string', 'in:'.implode(',', self::PLATFORMS)],
            'user.app_version' => ['nullable', 'string', 'max:32'],
            'user.language' => ['nullable', 'string', 'max:12'],
            'user.attribution' => ['nullable', 'array:source,medium,campaign,content,referrer,landing_path'],
            'user.attribution.source' => ['nullable', 'string', 'max:100'],
            'user.attribution.medium' => ['nullable', 'string', 'max:100'],
            'user.attribution.campaign' => ['nullable', 'string', 'max:100'],
            'user.attribution.content' => ['nullable', 'string', 'max:100'],
            'user.attribution.referrer' => ['nullable', 'string', 'max:500'],
            'user.attribution.landing_path' => ['nullable', 'string', 'max:500'],

            'session' => ['nullable', 'array:id,previous_id'],
            'session.id' => ['required_with:session', 'uuid'],
            'session.previous_id' => ['nullable', 'uuid'],

            'events' => ['required', 'array', 'max:'.config('analytics.max_batch', 20)],
            'events.*.event_id' => ['required', 'uuid'],
            'events.*.event' => ['required', 'string', 'max:48'],
            // Contents are filtered against EventTaxonomy's allowlist, not
            // here: a rule per property would be a second list to keep in sync
            // with the one that already decides what may be stored.
            'events.*.properties' => ['nullable', 'array'],
            'events.*.client_time' => ['nullable', 'date'],
        ]);

        return response()->json($this->ingest->ingest($data), 202);
    }

    /**
     * "This wallet has received something."
     *
     * The only call in the system that carries an address, and it is separate
     * from the event stream for exactly that reason — no ordinary event can
     * ever be the thing that leaked one. The address is stored only when the
     * chain is one this server can read (see `config/analytics.php`), and the
     * milestone is stamped once regardless of how often this is called.
     */
    public function funding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'chain' => ['required', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:128'],
        ]);

        return response()->json(
            $this->ingest->reportFunding($data['user_id'], $data['chain'], $data['address'] ?? null),
            202,
        );
    }
}
