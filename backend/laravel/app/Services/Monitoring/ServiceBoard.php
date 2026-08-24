<?php

namespace App\Services\Monitoring;

use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The board: everything the monitor has learned, arranged for reading.
 *
 * Pure reads. The sweep decides what is true; this decides how it is put in
 * front of a person, which is a separate job with separate failure modes — a
 * dashboard that quietly recomputes state while rendering is a dashboard that
 * disagrees with its own alerts.
 *
 * Uptime here is deliberately narrow: the share of *conclusive* checks that
 * came back healthy. Sweeps where we could not tell are excluded from both
 * halves of the fraction rather than counted as failures, so a heartbeat
 * outage does not retroactively invent downtime for twenty services.
 */
class ServiceBoard
{
    public function __construct(
        private ServiceRegistry $registry,
        private ServiceUsageService $usage,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $latest = $this->latestChecks();
        $uptime = ['24h' => $this->uptime(24), '7d' => $this->uptime(24 * 7)];
        $incidents = $this->openIncidents();
        $usage = $this->usage->all();
        $fleet = HeartbeatFleet::load();

        $services = [];

        foreach ($this->registry->all() as $key => $definition) {
            $check = $latest[$key] ?? null;
            $status = $check === null
                ? ServiceStatus::Unknown
                : ServiceStatus::from($check->status);

            $services[] = [
                'key' => $key,
                'group' => $definition->group,
                'label' => $definition->label,
                'note' => $definition->note,
                'url' => $definition->url,
                'critical' => $definition->critical,
                'deployed' => $definition->deployed,
                'probed' => $definition->isProbed(),
                'status' => $status->value,
                'reason' => $this->detailOf($check)['reason'] ?? null,
                'detail' => Arr::except($this->detailOf($check), ['reason']) ?: null,
                'latency_ms' => $check?->latency_ms,
                'checked_at' => $check?->checked_at?->toIso8601String(),
                'uptime_24h' => $uptime['24h'][$key] ?? null,
                'uptime_7d' => $uptime['7d'][$key] ?? null,
                'incident' => $incidents[$key] ?? null,
                'usage' => $usage[$key] ?? null,
            ];
        }

        return [
            'services' => $services,
            'summary' => $this->summary($services),
            // One card per machine that reports. There is more than one: the
            // server runs the chain and the site, and the operator's own
            // machine runs LainOS, which was never deployed to the server at
            // all.
            'hosts' => $this->hosts($fleet),
            'incidents' => $this->recentIncidents(),
            'idle' => $this->idle($services),
        ];
    }

    /**
     * A check's detail, always an array.
     *
     * `reason` is stored alongside the detail rather than in its own column
     * so the whole answer travels as one JSON document; splitting it back out
     * here keeps the UI from having to know that.
     *
     * @return array<string, mixed>
     */
    private function detailOf(?ServiceCheck $check): array
    {
        return is_array($check?->detail) ? $check->detail : [];
    }

    /** @return array<string, ServiceCheck> */
    private function latestChecks(): array
    {
        $checks = ServiceCheck::query()
            ->whereIn('id', ServiceCheck::latestIds())
            ->get();

        $latest = [];

        foreach ($checks as $check) {
            $latest[$check->service] = $check;
        }

        return $latest;
    }

    /**
     * Share of conclusive checks that were healthy, per service.
     *
     * @return array<string, float|null>
     */
    private function uptime(int $hours): array
    {
        $rows = ServiceCheck::query()
            ->select('service')
            ->selectRaw("sum(case when status = 'up' then 1 else 0 end) as healthy")
            ->selectRaw("sum(case when status in ('up', 'degraded', 'down') then 1 else 0 end) as conclusive")
            ->where('checked_at', '>=', CarbonImmutable::now()->subHours($hours))
            ->groupBy('service')
            ->get();

        $uptime = [];

        foreach ($rows as $row) {
            $conclusive = (int) $row->conclusive;
            $uptime[$row->service] = $conclusive === 0
                ? null
                : round((int) $row->healthy / $conclusive * 100, 2);
        }

        return $uptime;
    }

    /** @return array<string, array<string, mixed>> */
    private function openIncidents(): array
    {
        $open = [];

        foreach (ServiceIncident::query()->open()->get() as $incident) {
            $open[$incident->service] = [
                'id' => $incident->id,
                'status' => $incident->status,
                'reason' => $incident->reason,
                'started_at' => $incident->started_at?->toIso8601String(),
                'duration_seconds' => $incident->durationSeconds(),
                'notified' => $incident->notified_at !== null,
            ];
        }

        return $open;
    }

    /** @return array<int, array<string, mixed>> */
    private function recentIncidents(int $limit = 30): array
    {
        $labels = array_map(fn (ServiceDefinition $d) => $d->label, $this->registry->all());

        return ServiceIncident::query()
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (ServiceIncident $incident) => [
                'id' => $incident->id,
                'service' => $incident->service,
                'label' => $labels[$incident->service] ?? $incident->service,
                'status' => $incident->status,
                'reason' => $incident->reason,
                'started_at' => $incident->started_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'duration_seconds' => $incident->durationSeconds(),
            ])
            ->all();
    }

    /**
     * The services nobody has used in a month.
     *
     * The list the operator asked for, and the reason `unmeasured` exists as
     * a state: only services this app can actually count appear here, so
     * nothing lands on it merely because its usage is recorded elsewhere.
     *
     * @param  array<int, array<string, mixed>>  $services
     * @return array<int, array<string, mixed>>
     */
    private function idle(array $services): array
    {
        $idle = array_values(array_filter(
            $services,
            fn (array $service) => ($service['usage']['measured'] ?? false)
                && (int) ($service['usage']['count_30d'] ?? 0) === 0,
        ));

        usort($idle, function (array $a, array $b) {
            // Never used at all sorts above merely dormant: they are
            // different findings, and the first one is usually a launch that
            // never happened rather than a product that faded.
            $ai = $a['usage']['idle_days'] ?? PHP_INT_MAX;
            $bi = $b['usage']['idle_days'] ?? PHP_INT_MAX;

            return $bi <=> $ai;
        });

        return $idle;
    }

    /** @param array<int, array<string, mixed>> $services */
    private function summary(array $services): array
    {
        $counts = ['up' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0, 'off' => 0];

        foreach ($services as $service) {
            $counts[$service['status']] = ($counts[$service['status']] ?? 0) + 1;
        }

        $criticalDown = array_values(array_filter(
            $services,
            fn (array $s) => $s['critical'] && in_array($s['status'], ['down', 'degraded'], true),
        ));

        return [
            'counts' => $counts,
            'total' => count($services),
            'critical_down' => count($criticalDown),
            'measured' => count(array_filter($services, fn (array $s) => $s['usage']['measured'] ?? false)),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function hosts(HeartbeatFleet $fleet): array
    {
        $hosts = [];

        foreach ($fleet->all() as $host => $snapshot) {
            $hosts[] = [
                'host' => $host,
                'reported_at' => $snapshot->reportedAt?->toIso8601String(),
                'age_seconds' => $snapshot->ageSeconds(),
                'stale' => $snapshot->stale(),
                'metrics' => $snapshot->metrics(),
                // Containers running on the machine that nobody put in the
                // registry. Not an error — but an unmonitored container is
                // exactly how a service gets forgotten, so it is printed.
                'unregistered' => $this->unregisteredContainers($snapshot),
            ];
        }

        return $hosts;
    }

    /** @return array<int, string> */
    private function unregisteredContainers(HeartbeatSnapshot $heartbeat): array
    {
        $registered = [];

        foreach ($this->registry->all() as $definition) {
            $container = $definition->checkOption('container');

            if (is_string($container)) {
                $registered[] = $container;
            }
        }

        return array_values(array_diff($heartbeat->containerNames(), $registered));
    }

    /** Rows written per day, so the retention setting can be sanity-checked. */
    public function checksPerDay(): int
    {
        return (int) DB::table('service_checks')
            ->where('checked_at', '>=', CarbonImmutable::now()->subDay())
            ->count();
    }
}
