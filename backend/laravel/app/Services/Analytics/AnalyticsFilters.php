<?php

namespace App\Services\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What slice of the data the dashboard is asking about.
 *
 * Two kinds of filter, and the difference decides where each is applied.
 * Platform, app version, source and campaign are properties of an
 * *installation*, so they narrow the population and then everything is
 * measured inside it — a funnel filtered by campaign is that campaign's own
 * funnel. Chain is a property of an *event*, so it narrows what counts as
 * activity without changing who is in the denominator.
 *
 * `includeInternal` is neither: it is the default population itself. Our own
 * installations are left out of every report unless it is set, because the
 * operators use this wallet more than anyone and a rate computed over them is
 * a description of testing. `?internal=1` puts them back for the times that
 * question is the one being asked.
 */
readonly class AnalyticsFilters
{
    public function __construct(
        public Carbon $from,
        public Carbon $to,
        public ?string $platform = null,
        public ?string $appVersion = null,
        public ?string $source = null,
        public ?string $campaign = null,
        public ?string $chain = null,
        public bool $includeInternal = false,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $days = max(1, min((int) $request->query('days', 30), 365));

        $to = $request->query('to')
            ? Carbon::parse((string) $request->query('to'), 'UTC')->endOfDay()
            : Carbon::now('UTC');

        $from = $request->query('from')
            ? Carbon::parse((string) $request->query('from'), 'UTC')->startOfDay()
            : $to->copy()->subDays($days)->startOfDay();

        $clean = fn (string $key): ?string => match (true) {
            ! is_string($request->query($key)) => null,
            trim((string) $request->query($key)) === '' => null,
            default => mb_substr(trim((string) $request->query($key)), 0, 100),
        };

        return new self(
            from: $from,
            to: $to,
            platform: $clean('platform'),
            appVersion: $clean('app_version'),
            source: $clean('source'),
            campaign: $clean('campaign'),
            chain: $clean('chain'),
            includeInternal: in_array(
                (string) $request->query('internal', ''),
                ['1', 'true', 'on'],
                true,
            ),
        );
    }

    /** Whether anything narrows the population, as opposed to the activity. */
    public function narrowsUsers(): bool
    {
        return $this->platform !== null
            || $this->appVersion !== null
            || $this->source !== null
            || $this->campaign !== null;
    }

    public function days(): int
    {
        return max(1, (int) $this->from->diffInDays($this->to));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'days' => $this->days(),
            'platform' => $this->platform,
            'app_version' => $this->appVersion,
            'source' => $this->source,
            'campaign' => $this->campaign,
            'chain' => $this->chain,
            'internal' => $this->includeInternal,
        ];
    }
}
