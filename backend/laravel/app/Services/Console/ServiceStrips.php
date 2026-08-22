<?php

namespace App\Services\Console;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A day of a service's life as twenty-four characters.
 *
 * The board used to answer "is it up" with the last sweep, which is true and
 * useless: a service that flapped eleven times overnight and is up right now
 * reads exactly like one that never moved. An hour-per-cell strip is the
 * smallest thing that shows the difference, and it is read at a glance rather
 * than by comparing timestamps in a table.
 *
 * Worst status wins inside an hour, and a missing hour stays missing — that
 * is when the monitor was down, which is a different fact from the service
 * being down and must not be painted as either up or out.
 */
class ServiceStrips
{
    private const HOURS = 24;

    /** @var array<string, array<int, string>>|null */
    private ?array $strips = null;

    /**
     * @return array<string, array<int, string>> service => 24 cells, oldest first
     */
    public function all(): array
    {
        if ($this->strips !== null) {
            return $this->strips;
        }

        $now = CarbonImmutable::now('UTC')->startOfHour();
        $since = $now->subHours(self::HOURS - 1);

        $rows = DB::table('service_checks')
            ->where('checked_at', '>=', $since)
            ->selectRaw("service, strftime('%Y-%m-%dT%H', checked_at) as hour")
            ->selectRaw("sum(case when status = 'down' then 1 else 0 end) as down")
            ->selectRaw("sum(case when status = 'degraded' then 1 else 0 end) as degraded")
            ->selectRaw("sum(case when status = 'unknown' then 1 else 0 end) as unknown")
            ->selectRaw("sum(case when status = 'off' then 1 else 0 end) as off")
            ->selectRaw("sum(case when status = 'up' then 1 else 0 end) as up")
            ->groupBy('service', 'hour')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $buckets[$row->service][$row->hour] = match (true) {
                (int) $row->down > 0 => 'down',
                (int) $row->degraded > 0 => 'degraded',
                (int) $row->unknown > 0 => 'unknown',
                (int) $row->up > 0 => 'up',
                (int) $row->off > 0 => 'off',
                default => 'gap',
            };
        }

        $hours = [];

        for ($i = self::HOURS - 1; $i >= 0; $i--) {
            $hours[] = $now->subHours($i)->format('Y-m-d\TH');
        }

        $strips = [];

        foreach ($buckets as $service => $found) {
            $strips[$service] = array_map(
                fn (string $hour): string => $found[$hour] ?? 'gap',
                $hours,
            );
        }

        return $this->strips = $strips;
    }

    /** @return array<int, string>|null */
    public function for(string $service): ?array
    {
        return $this->all()[$service] ?? null;
    }
}
