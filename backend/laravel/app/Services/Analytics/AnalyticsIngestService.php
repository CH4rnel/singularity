<?php

namespace App\Services\Analytics;

use App\Models\AnalyticsAddress;
use App\Models\AnalyticsSession;
use App\Models\AnalyticsUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything that turns a batch of claims from a browser into rows.
 *
 * Three rules shape this class, and all three exist because the client is not
 * trustworthy in the way a dashboard needs it to be:
 *
 *   Duplicates are impossible, not unlikely. Every event carries an id the
 *   client minted once, and the insert is `insertOrIgnore` against a unique
 *   index — a retried flush, a replayed outbox and a beacon that fired twice
 *   all collapse into the row that is already there. Milestones are stamped
 *   only when the insert actually inserted, so nothing downstream can be
 *   double-counted by a retry either.
 *
 *   Milestones are write-once. `funded_at`, `activated_at` and
 *   `first_transaction_at` are the numerators of the headline metrics; if a
 *   client could move them, a client could set them.
 *
 *   Attribution is first-touch. The campaign that acquired a user keeps the
 *   credit no matter how many times a later link re-touches them, which is the
 *   single most common way an acquisition report ends up flattering the wrong
 *   channel.
 */
class AnalyticsIngestService
{
    /**
     * How far back a client's own timestamp may pull an event.
     *
     * Server time is the source of truth, with one correction: a wallet that
     * was offline flushes its outbox late, and filing yesterday's swap under
     * today would move a retention cohort. So a client time that is in the
     * past and inside this window is honoured, and anything else — a future
     * timestamp, a device whose clock is a year out — falls back to now.
     */
    private const MAX_BACKDATE_HOURS = 48;

    public function __construct(private FundingVerifier $funding) {}

    public function enabled(): bool
    {
        return (bool) config('analytics.enabled', true);
    }

    /**
     * Record a batch.
     *
     * @param  array{
     *     user: array{id: string, platform?: ?string, app_version?: ?string, language?: ?string, attribution?: array<string, ?string>},
     *     session?: ?array{id: string, previous_id?: ?string},
     *     events: array<int, array{event_id: string, event: string, properties?: array<string, mixed>, client_time?: ?string}>
     * }  $payload
     * @return array{accepted: int, duplicates: int, ignored: int}
     */
    public function ingest(array $payload): array
    {
        if (! $this->enabled()) {
            return ['accepted' => 0, 'duplicates' => 0, 'ignored' => count($payload['events'] ?? [])];
        }

        $now = Carbon::now('UTC');
        $user = $this->resolveUser($payload['user'], $now);

        if (isset($payload['session']['id'])) {
            $this->touchSession($user, $payload['session'], $now);
        }

        $accepted = 0;
        $duplicates = 0;
        $ignored = 0;

        foreach ($payload['events'] ?? [] as $event) {
            $name = (string) ($event['event'] ?? '');

            // An unknown name is dropped and the rest of the batch is kept: a
            // wallet on an older release still reports the events it does
            // know, and a retired name must not cost us the ones beside it.
            if (! EventTaxonomy::isKnown($name)) {
                $ignored++;

                continue;
            }

            $outcome = $this->record(
                $user,
                (string) $event['event_id'],
                $name,
                EventTaxonomy::sanitize($event['properties'] ?? []),
                $payload['session']['id'] ?? null,
                $this->stampFor($event['client_time'] ?? null, $now),
                $event['client_time'] ?? null,
            );

            $outcome ? $accepted++ : $duplicates++;
        }

        return ['accepted' => $accepted, 'duplicates' => $duplicates, 'ignored' => $ignored];
    }

    /**
     * Write one event and, if it is new, let it move the milestones.
     *
     * Returns false when the id was already here, which is the whole duplicate
     * defence: the uniqueness lives in the database rather than in a check that
     * two concurrent requests could both pass.
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(
        AnalyticsUser $user,
        string $eventId,
        string $event,
        array $properties,
        ?string $sessionId,
        Carbon $at,
        ?string $clientTime = null,
    ): bool {
        $inserted = DB::table('analytics_events')->insertOrIgnore([
            'event_id' => $eventId,
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'event' => $event,
            'chain' => $properties['chain'] ?? null,
            'properties' => $properties === [] ? null : json_encode($properties),
            'created_at' => $at,
            'client_time' => $this->parse($clientTime),
        ]);

        if ($inserted === 0) {
            return false;
        }

        $this->applyMilestones($user, $event, $properties, $at, $sessionId);

        return true;
    }

    /**
     * The wallet reports an address it believes has been funded.
     *
     * Kept off the event endpoint on purpose: this is the only call in the
     * whole system that carries an address, so the general event stream never
     * touches one. The answer comes back so the client can stop asking.
     *
     * @return array{funded: bool, source: ?string, verified: bool}
     */
    public function reportFunding(string $userId, string $chain, ?string $address): array
    {
        if (! $this->enabled()) {
            return ['funded' => false, 'source' => null, 'verified' => false];
        }

        $user = AnalyticsUser::find($userId);

        if ($user === null) {
            return ['funded' => false, 'source' => null, 'verified' => false];
        }

        if ($address !== null && $this->funding->isVerifiable($chain)) {
            $this->linkAddress($user, $chain, $address);
        }

        // Already funded: the milestone is write-once, and a balance that goes
        // up and down must not re-fire it. Reporting the existing answer lets
        // the client stop asking without pretending nothing happened.
        if ($user->funded_at !== null) {
            return ['funded' => true, 'source' => $user->funded_source, 'verified' => $user->funded_source === 'onchain'];
        }

        $verified = $address !== null && $this->funding->hasBalance($chain, $address);

        // A chain this server cannot read is not a chain where the claim is
        // false — it is one where the claim is all we have. Recorded as such,
        // and counted apart from the confirmed ones on the dashboard.
        if (! $verified && $this->funding->isVerifiable($chain) && $address !== null) {
            return ['funded' => false, 'source' => null, 'verified' => false];
        }

        $this->stampFunded($user, $chain, $verified ? 'onchain' : 'client');

        return ['funded' => true, 'source' => $user->funded_source, 'verified' => $verified];
    }

    /**
     * Mark a user funded and write the single `wallet_funded` row that goes
     * with it. Both halves happen once, together, or not at all.
     */
    public function stampFunded(AnalyticsUser $user, string $chain, string $source): void
    {
        if ($user->funded_at !== null) {
            return;
        }

        $at = Carbon::now('UTC');

        $user->forceFill([
            'funded_at' => $at,
            'funded_chain' => $chain,
            'funded_source' => $source,
        ])->save();

        $this->record(
            $user,
            (string) Str::uuid(),
            'wallet_funded',
            ['chain' => $chain, 'verified' => $source === 'onchain'],
            null,
            $at,
        );
    }

    /**
     * Attach an address to an installation — only ever for a chain this server
     * can actually read, and never as an identity: nothing looks a user up by
     * their address except the sponsored-gas join, which needs it to price a
     * drip that has already been paid for.
     */
    public function linkAddress(AnalyticsUser $user, string $chain, string $address): void
    {
        if (! $this->funding->isVerifiable($chain)) {
            return;
        }

        $normalized = $this->funding->normalize($chain, $address);

        if ($normalized === null) {
            return;
        }

        AnalyticsAddress::query()->insertOrIgnore([
            'user_id' => $user->id,
            'chain' => $chain,
            'address' => $normalized,
            'created_at' => Carbon::now('UTC'),
        ]);

        $this->markInternalByAddress($user, $normalized);
    }

    /**
     * Recognise one of our own installations.
     *
     * An address is the only handle these tables have on who a person is, and
     * it is used here for the one purpose that improves the numbers rather
     * than identifying anybody: keeping the operators out of the rates that
     * are supposed to describe strangers. The mark is stamped once and never
     * cleared automatically — an install that tested from a listed address
     * stays an internal install, because its whole history was testing.
     */
    private function markInternalByAddress(AnalyticsUser $user, string $address): void
    {
        if ($user->internal_at !== null) {
            return;
        }

        $internal = array_map(
            fn ($value) => strtolower((string) $value),
            (array) config('analytics.internal.wallets', []),
        );

        if (! in_array(strtolower($address), $internal, true)) {
            return;
        }

        $user->forceFill([
            'internal_at' => Carbon::now('UTC'),
            'internal_reason' => 'address',
        ])->save();
    }

    /* ------------------------------------------------------------- users -- */

    /**
     * Find or create the installation, then bring the mutable half up to date.
     *
     * Attribution is written only into a row that is being created. Platform,
     * version and language are refreshed every time, because "which build is
     * this person on" is a question about now, while "where did they come
     * from" is a question about once.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveUser(array $data, Carbon $now): AnalyticsUser
    {
        $id = (string) $data['id'];
        $user = AnalyticsUser::find($id);

        if ($user === null) {
            $attribution = $this->attribution($data['attribution'] ?? []);

            $user = new AnalyticsUser;
            $user->forceFill([
                'id' => $id,
                'created_at' => $now,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'platform' => $this->label($data['platform'] ?? null, 24),
                'app_version' => $this->label($data['app_version'] ?? null, 32),
                'language' => $this->label($data['language'] ?? null, 12),
                ...$attribution,
            ])->save();

            return $user;
        }

        $user->forceFill([
            'last_seen_at' => $now,
            'platform' => $this->label($data['platform'] ?? null, 24) ?? $user->platform,
            'app_version' => $this->label($data['app_version'] ?? null, 32) ?? $user->app_version,
            'language' => $this->label($data['language'] ?? null, 12) ?? $user->language,
        ])->save();

        return $user;
    }

    /**
     * First-touch attribution, cleaned.
     *
     * The referrer is reduced to its origin before it is stored. A full
     * referring URL is a page somebody was reading, which is a browsing
     * history we have no use for — the acquisition report only ever asks which
     * site sent them.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, ?string>
     */
    private function attribution(array $raw): array
    {
        return [
            'source' => $this->label($raw['source'] ?? null, 100),
            'medium' => $this->label($raw['medium'] ?? null, 100),
            'campaign' => $this->label($raw['campaign'] ?? null, 100),
            'content' => $this->label($raw['content'] ?? null, 100),
            'referrer' => $this->origin($raw['referrer'] ?? null),
            'landing_path' => $this->path($raw['landing_path'] ?? null),
        ];
    }

    private function label(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    /** Scheme and host of a referrer, or null. Never the path or the query. */
    private function origin(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = parse_url(trim($value));

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';

        return mb_substr($scheme.'://'.$parts['host'], 0, 255);
    }

    /** A path with no query string: `/download`, never `/download?token=…`. */
    private function path(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $path = parse_url(trim($value), PHP_URL_PATH);

        return is_string($path) && $path !== '' ? mb_substr($path, 0, 255) : null;
    }

    /* ---------------------------------------------------------- sessions -- */

    /**
     * Open this session if it is new, and close the one it replaced.
     *
     * The client decides where a session ends, because inactivity is something
     * only the client can observe; this side records the boundary and never
     * invents one.
     *
     * @param  array<string, mixed>  $session
     */
    private function touchSession(AnalyticsUser $user, array $session, Carbon $now): void
    {
        $id = (string) $session['id'];

        $existing = AnalyticsSession::find($id);

        if ($existing !== null) {
            $existing->forceFill(['last_activity_at' => $now])->save();

            return;
        }

        AnalyticsSession::query()->insertOrIgnore([
            'id' => $id,
            'user_id' => $user->id,
            'started_at' => $now,
            'last_activity_at' => $now,
            'platform' => $user->platform,
            'app_version' => $user->app_version,
        ]);

        /*
         * Close what this session replaced.
         *
         * The client names the session it succeeded, which covers the ordinary
         * case exactly. It does not cover a browser whose storage was cleared
         * between visits, or a second device that never knew about the first —
         * both leave a session open forever, and "open" is indistinguishable
         * from "still going" on the installation page.
         *
         * So the successor closes *every* older open session of the same
         * installation, dated to its own last activity, which is the last
         * moment anything is known to have happened in it. The named previous
         * id is now belt and braces rather than the mechanism.
         */
        AnalyticsSession::query()
            ->where('user_id', $user->id)
            ->whereKeyNot($id)
            ->whereNull('ended_at')
            ->update(['ended_at' => DB::raw('last_activity_at')]);
    }

    /* -------------------------------------------------------- milestones -- */

    /**
     * @param  array<string, mixed>  $properties
     */
    private function applyMilestones(
        AnalyticsUser $user,
        string $event,
        array $properties,
        Carbon $at,
        ?string $sessionId,
    ): void {
        $changes = [];

        if (in_array($event, ['wallet_created', 'wallet_imported'], true) && $user->wallet_created_at === null) {
            $changes['wallet_created_at'] = $at;
            $changes['wallet_origin'] = $event === 'wallet_created' ? 'created' : 'imported';
        }

        /*
         * A vault that existed before this client did.
         *
         * `wallet_created` is emitted at the one moment it is unambiguously
         * true — the phrase is sealed and the vault opens — which means it is
         * emitted for nobody who already had a wallet when the analytics
         * client shipped. Those installations then fund, swap and bridge while
         * the onboarding step stays empty, and the console draws a funnel
         * whose second bar is shorter than its third. That is not a small
         * cosmetic wrong: `wallets` is the denominator of the funding rate.
         *
         * So any event that could only have come from an open vault stamps the
         * milestone too, dated to that event and marked `existing` — the
         * wallet is not claimed to have been created then, only proved to have
         * existed by then. Later steps can no longer overtake earlier ones,
         * which is the invariant a funnel is.
         */
        if ($user->wallet_created_at === null && EventTaxonomy::provesWallet($event)) {
            $changes['wallet_created_at'] = $at;
            $changes['wallet_origin'] = 'existing';
        }

        if (EventTaxonomy::isMeaningful($event, $properties) && $user->activated_at === null) {
            $changes['activated_at'] = $at;
            $changes['activation_event'] = $event;
        }

        $firstTransaction = EventTaxonomy::isTransactional($event, $properties)
            && $user->first_transaction_at === null;

        if ($firstTransaction) {
            $changes['first_transaction_at'] = $at;
        }

        if ($changes === []) {
            return;
        }

        $user->forceFill($changes)->save();

        /*
         * `first_transaction` exists as a row so a user's timeline reads as a
         * story rather than as something the dashboard has to infer. It is
         * written by this server and cannot be sent by a client, so no retry
         * can produce a second one.
         */
        if ($firstTransaction) {
            $this->record(
                $user,
                (string) Str::uuid(),
                'first_transaction',
                array_filter(
                    [
                        'chain' => $properties['chain'] ?? null,
                        'transaction_type' => $properties['transaction_type'] ?? null,
                        'amount_usd' => $properties['amount_usd'] ?? null,
                    ],
                    fn ($value) => $value !== null,
                ),
                $sessionId,
                $at,
            );
        }
    }

    /* ---------------------------------------------------------- stamping -- */

    private function stampFor(mixed $clientTime, Carbon $now): Carbon
    {
        $parsed = $this->parse($clientTime);

        if ($parsed === null || $parsed->greaterThan($now)) {
            return $now;
        }

        return $parsed->diffInHours($now) > self::MAX_BACKDATE_HOURS ? $now : $parsed;
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
