<?php

use App\Models\AnalyticsAddress;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSession;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsIngestService;
use App\Services\Analytics\FundingVerifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The ingest side: what a browser can and cannot make this database believe.
 *
 * Almost every test here is about a claim being made twice. A wallet retries,
 * a page reloads, a beacon fires and a fetch fires, a balance ticks up and
 * down — and none of it may move a number on the dashboard. The rest is about
 * what a claim may contain: an allowlist that a call site cannot widen by
 * accident, and a first-touch attribution a later campaign cannot steal.
 */
function ingest(array $payload): TestResponse
{
    return test()->postJson('/api/analytics/events', $payload);
}

function ingestBatch(array $events, ?string $userId = null, ?string $sessionId = null): array
{
    return [
        'user' => [
            'id' => $userId ?? (string) Str::uuid(),
            'platform' => 'web',
            'app_version' => 'v0.12.0',
            'language' => 'en-GB',
        ],
        'session' => ['id' => $sessionId ?? (string) Str::uuid()],
        'events' => array_map(fn (array $event) => [
            'event_id' => $event['event_id'] ?? (string) Str::uuid(),
            'event' => $event['event'],
            'properties' => $event['properties'] ?? null,
        ], $events),
    ];
}

test('an anonymous installation is created on its first batch', function () {
    $id = (string) Str::uuid();

    ingest(ingestBatch([['event' => 'app_opened']], $id))->assertStatus(202);

    $user = AnalyticsUser::find($id);

    expect($user)->not->toBeNull()
        ->and($user->platform)->toBe('web')
        ->and($user->app_version)->toBe('v0.12.0')
        ->and($user->first_seen_at)->not->toBeNull()
        // Nothing has happened yet beyond opening the app, and opening the app
        // is not activation.
        ->and($user->activated_at)->toBeNull()
        ->and($user->funded_at)->toBeNull();
});

test('the same event id is never stored twice', function () {
    $id = (string) Str::uuid();
    $eventId = (string) Str::uuid();

    $payload = ingestBatch(
        [['event' => 'swap_completed', 'event_id' => $eventId, 'properties' => ['chain' => 'cyberia']]],
        $id,
    );

    ingest($payload)->assertStatus(202)->assertJson(['accepted' => 1, 'duplicates' => 0]);
    // The same batch again — an outbox replayed after a failed flush.
    ingest($payload)->assertStatus(202)->assertJson(['accepted' => 0, 'duplicates' => 1]);

    expect(AnalyticsEvent::where('event', 'swap_completed')->count())->toBe(1);
});

test('a replayed activation does not move the milestone', function () {
    $id = (string) Str::uuid();
    $eventId = (string) Str::uuid();

    ingest(ingestBatch([['event' => 'swap_completed', 'event_id' => $eventId]], $id));

    $first = AnalyticsUser::find($id)->activated_at;

    Carbon::setTestNow(Carbon::now()->addDays(3));
    ingest(ingestBatch([['event' => 'swap_completed', 'event_id' => $eventId]], $id));
    Carbon::setTestNow();

    expect(AnalyticsUser::find($id)->activated_at->timestamp)->toBe($first->timestamp);
});

test('activation is a settled action, never an opened screen', function () {
    $id = (string) Str::uuid();

    ingest(ingestBatch([
        ['event' => 'app_opened'],
        ['event' => 'wallet_created'],
        ['event' => 'swap_opened'],
        ['event' => 'swap_quote_received'],
        // Broadcast is not settlement, and this wallet says so on screen.
        ['event' => 'transaction_submitted', 'properties' => ['watchable' => true]],
    ], $id));

    expect(AnalyticsUser::find($id)->activated_at)->toBeNull();

    ingest(ingestBatch([['event' => 'transaction_confirmed']], $id));

    $user = AnalyticsUser::find($id);

    expect($user->activated_at)->not->toBeNull()
        ->and($user->activation_event)->toBe('transaction_confirmed');
});

test('a chain nobody can watch activates on broadcast instead', function () {
    $id = (string) Str::uuid();

    // A user-added network with no receipt watcher: refusing to count this
    // person would be a fact about our instrumentation, not about them.
    ingest(ingestBatch([[
        'event' => 'transaction_submitted',
        'properties' => ['watchable' => false, 'chain' => 'custom-evm'],
    ]], $id));

    expect(AnalyticsUser::find($id)->activated_at)->not->toBeNull();
});

test('the first settled action also writes a first_transaction row', function () {
    $id = (string) Str::uuid();

    ingest(ingestBatch([['event' => 'swap_completed', 'properties' => ['chain' => 'cyberia', 'amount_usd' => 12.5]]], $id));
    ingest(ingestBatch([['event' => 'swap_completed', 'properties' => ['chain' => 'cyberia']]], $id));

    expect(AnalyticsUser::find($id)->first_transaction_at)->not->toBeNull()
        // Written by the server, once — a client cannot send this name at all.
        ->and(AnalyticsEvent::where('event', 'first_transaction')->count())->toBe(1);
});

test('wallet_created stamps the onboarding milestone with its branch', function () {
    $id = (string) Str::uuid();

    ingest(ingestBatch([['event' => 'wallet_imported', 'properties' => ['origin' => 'imported']]], $id));

    $user = AnalyticsUser::find($id);

    expect($user->wallet_created_at)->not->toBeNull()
        ->and($user->wallet_origin)->toBe('imported');
});

/* ------------------------------------------------------------- privacy -- */

test('only allowlisted properties survive, whatever a caller sends', function () {
    $id = (string) Str::uuid();

    ingest(ingestBatch([[
        'event' => 'swap_completed',
        'properties' => [
            'chain' => 'cyberia',
            'amount_usd' => 42.18,
            // None of these exist in the taxonomy, and a future call site
            // handing one over must lose the field rather than the event.
            'seed' => 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about',
            'password' => 'hunter2',
            'to' => '0x1234567890123456789012345678901234567890',
            'privateKey' => '0x'.str_repeat('a', 64),
        ],
    ]], $id))->assertStatus(202);

    $event = AnalyticsEvent::where('event', 'swap_completed')->first();

    expect($event->properties)->toBe(['chain' => 'cyberia', 'amount_usd' => 42.18]);
});

test('a secret smuggled into an allowlisted field is still refused', function () {
    $id = (string) Str::uuid();

    ingest(ingestBatch([[
        'event' => 'swap_completed',
        'properties' => [
            // The one hole an allowlist of keys leaves: the right field with
            // the wrong variable in it.
            'route' => '0x'.str_repeat('bee5', 16),
            'asset' => 'abandon abandon abandon abandon abandon abandon',
            'chain' => 'cyberia',
        ],
    ]], $id));

    expect(AnalyticsEvent::where('event', 'swap_completed')->first()->properties)
        ->toBe(['chain' => 'cyberia']);
});

test('an unknown event is dropped without costing the rest of the batch', function () {
    $id = (string) Str::uuid();

    ingest(ingestBatch([
        ['event' => 'something_a_future_release_invented'],
        ['event' => 'swap_completed'],
    ], $id))->assertStatus(202)->assertJson(['accepted' => 1, 'ignored' => 1]);

    expect(AnalyticsEvent::where('user_id', $id)->orderBy('id')->pluck('event')->all())
        // The swap, plus the `first_transaction` this server writes beside it.
        ->toBe(['swap_completed', 'first_transaction']);
});

/* --------------------------------------------------------- attribution -- */

test('attribution is first touch and a later campaign cannot take it', function () {
    $id = (string) Str::uuid();

    $payload = ingestBatch([['event' => 'first_open']], $id);
    $payload['user']['attribution'] = [
        'source' => 'twitter',
        'campaign' => 'launch',
        'referrer' => 'https://x.com/some/thread?utm=1',
        'landing_path' => '/download?token=abc',
    ];

    ingest($payload);

    $second = ingestBatch([['event' => 'app_opened']], $id);
    $second['user']['attribution'] = ['source' => 'retargeting', 'campaign' => 'winback'];

    ingest($second);

    $user = AnalyticsUser::find($id);

    expect($user->source)->toBe('twitter')
        ->and($user->campaign)->toBe('launch')
        // Origin only: a full referring URL is somebody's browsing history.
        ->and($user->referrer)->toBe('https://x.com')
        // And a landing path without whatever was in its query string.
        ->and($user->landing_path)->toBe('/download');
});

test('the current build is refreshed while the campaign is not', function () {
    $id = (string) Str::uuid();

    ingest(ingestBatch([['event' => 'app_opened']], $id));

    $later = ingestBatch([['event' => 'app_opened']], $id);
    $later['user']['app_version'] = 'v0.13.0';
    $later['user']['platform'] = 'desktop';

    ingest($later);

    $user = AnalyticsUser::find($id);

    expect($user->app_version)->toBe('v0.13.0')
        ->and($user->platform)->toBe('desktop');
});

/* ------------------------------------------------------------ sessions -- */

test('a session is opened once and the one it replaced is closed', function () {
    $id = (string) Str::uuid();
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();

    ingest(ingestBatch([['event' => 'app_opened']], $id, $first));
    ingest(ingestBatch([['event' => 'app_opened']], $id, $first));

    expect(AnalyticsSession::where('user_id', $id)->count())->toBe(1);

    $next = ingestBatch([['event' => 'session_started']], $id, $second);
    $next['session']['previous_id'] = $first;

    ingest($next);

    expect(AnalyticsSession::where('user_id', $id)->count())->toBe(2)
        ->and(AnalyticsSession::find($first)->ended_at)->not->toBeNull()
        ->and(AnalyticsSession::find($second)->ended_at)->toBeNull();
});

/* ------------------------------------------------------------- funding -- */

test('funding on an unreadable chain is recorded as the claim it is', function () {
    $id = (string) Str::uuid();
    ingest(ingestBatch([['event' => 'app_opened']], $id));

    test()->postJson('/api/analytics/funding', [
        'user_id' => $id,
        'chain' => 'litecoin',
    ])->assertStatus(202)->assertJson(['funded' => true, 'verified' => false]);

    $user = AnalyticsUser::find($id);

    expect($user->funded_at)->not->toBeNull()
        ->and($user->funded_source)->toBe('client')
        ->and($user->funded_chain)->toBe('litecoin')
        // No address was sent, and none is stored.
        ->and(AnalyticsAddress::where('user_id', $id)->count())->toBe(0);
});

test('funding on a readable chain is confirmed against it', function () {
    Http::fake([
        '*rpc.cyberia.church*' => Http::response(['result' => '0x2386f26fc10000']),
    ]);

    $id = (string) Str::uuid();
    ingest(ingestBatch([['event' => 'app_opened']], $id));

    test()->postJson('/api/analytics/funding', [
        'user_id' => $id,
        'chain' => 'cyberia',
        'address' => '0xAAf26832db3557daF540B0B09DeE06C24B8A38BB',
    ])->assertStatus(202)->assertJson(['funded' => true, 'verified' => true]);

    $user = AnalyticsUser::find($id);

    expect($user->funded_source)->toBe('onchain')
        // Stored lowercased, and only because this chain can be read.
        ->and(AnalyticsAddress::where('user_id', $id)->first()->address)
        ->toBe('0xaaf26832db3557daf540b0b09dee06c24b8a38bb');
});

test('an empty address on a readable chain is not funded', function () {
    Http::fake([
        '*rpc.cyberia.church*' => Http::response(['result' => '0x0']),
        '*explorer.cyberia.church*' => Http::response(['status' => '0', 'result' => []]),
    ]);

    $id = (string) Str::uuid();
    ingest(ingestBatch([['event' => 'app_opened']], $id));

    test()->postJson('/api/analytics/funding', [
        'user_id' => $id,
        'chain' => 'cyberia',
        'address' => '0xAAf26832db3557daF540B0B09DeE06C24B8A38BB',
    ])->assertStatus(202)->assertJson(['funded' => false]);

    expect(AnalyticsUser::find($id)->funded_at)->toBeNull();
});

test('a balance that moves cannot fund the same wallet twice', function () {
    $id = (string) Str::uuid();
    ingest(ingestBatch([['event' => 'app_opened']], $id));

    $service = app(AnalyticsIngestService::class);
    $user = AnalyticsUser::find($id);

    $service->stampFunded($user, 'cyberia', 'onchain');
    $first = AnalyticsUser::find($id)->funded_at;

    Carbon::setTestNow(Carbon::now()->addDay());
    $service->stampFunded(AnalyticsUser::find($id), 'bnb', 'client');
    Carbon::setTestNow();

    $user = AnalyticsUser::find($id);

    expect($user->funded_at->timestamp)->toBe($first->timestamp)
        ->and($user->funded_chain)->toBe('cyberia')
        ->and($user->funded_source)->toBe('onchain')
        // And exactly one `wallet_funded` row, however often it was stamped.
        ->and(AnalyticsEvent::where('event', 'wallet_funded')->count())->toBe(1);
});

test('an address is never stored for a chain this server cannot read', function () {
    $id = (string) Str::uuid();
    ingest(ingestBatch([['event' => 'app_opened']], $id));

    app(AnalyticsIngestService::class)->reportFunding(
        $id,
        'bnb',
        '0xAAf26832db3557daF540B0B09DeE06C24B8A38BB',
    );

    expect(AnalyticsAddress::count())->toBe(0)
        ->and(AnalyticsUser::find($id)->funded_source)->toBe('client');
});

test('a malformed address is refused rather than stored', function () {
    expect(app(FundingVerifier::class)->normalize('cyberia', 'not-an-address'))->toBeNull()
        ->and(app(FundingVerifier::class)->normalize('litecoin', 'ltc1qanything'))->toBeNull()
        ->and(app(FundingVerifier::class)->normalize('cyberia', '0xAAf26832db3557daF540B0B09DeE06C24B8A38BB'))
        ->toBe('0xaaf26832db3557daf540b0b09dee06c24b8a38bb');
});

/* ---------------------------------------------------------- timestamps -- */

test('a client clock may correct a late flush but never invent a date', function () {
    $id = (string) Str::uuid();
    $now = Carbon::parse('2026-08-21 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    $payload = ingestBatch([['event' => 'app_opened'], ['event' => 'swap_completed'], ['event' => 'nft_minted']], $id);
    // A flush delayed by two hours, a device whose clock is a year out, and
    // a device that thinks it is next week.
    $payload['events'][0]['client_time'] = $now->copy()->subHours(2)->toIso8601String();
    $payload['events'][1]['client_time'] = $now->copy()->subYear()->toIso8601String();
    $payload['events'][2]['client_time'] = $now->copy()->addWeek()->toIso8601String();

    ingest($payload);
    Carbon::setTestNow();

    $rows = AnalyticsEvent::where('user_id', $id)->get()->keyBy('event');

    expect($rows['app_opened']->created_at->toDateTimeString())->toBe('2026-08-21 10:00:00')
        ->and($rows['swap_completed']->created_at->toDateTimeString())->toBe('2026-08-21 12:00:00')
        ->and($rows['nft_minted']->created_at->toDateTimeString())->toBe('2026-08-21 12:00:00');
});

test('the endpoint takes no session cookie and needs no csrf token', function () {
    // Stateless by design: an analytics endpoint that could see which account
    // a browser is signed into would link two identities this product keeps
    // apart. A guest post with no token has to be accepted.
    ingest(ingestBatch([['event' => 'app_opened']]))->assertStatus(202);
});

test('switching analytics off writes nothing', function () {
    config()->set('analytics.enabled', false);

    ingest(ingestBatch([['event' => 'swap_completed']]))->assertStatus(202);

    expect(AnalyticsUser::count())->toBe(0)
        ->and(AnalyticsEvent::count())->toBe(0);
});
