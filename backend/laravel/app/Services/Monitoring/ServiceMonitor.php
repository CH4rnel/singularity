<?php

namespace App\Services\Monitoring;

use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Services\TelegramOpsNotifier;
use Carbon\CarbonImmutable;

/**
 * One sweep: probe everything, write it down, and decide who needs telling.
 *
 * The alerting rule is the whole design. Alerts fire on *transitions* and
 * never on state, because the alternative — a message every five minutes for
 * as long as something is broken — ends with the channel muted, and a muted
 * channel is worse than none: it looks like monitoring while being silence.
 *
 * So an incident is a row, not a cache entry. It is opened once, after enough
 * consecutive failures that a single network hiccup on this host cannot open
 * it; announced once; reminded about at most once a day; and announced again
 * when it closes, because "it's back" is the half people are waiting for.
 *
 * `unknown` never opens an incident and never closes one. Going blind is not
 * the same as going down, and a monitor that shouts when it loses sight of
 * something teaches people to ignore it at exactly the wrong moment.
 */
class ServiceMonitor
{
    public function __construct(
        private ServiceRegistry $registry,
        private ServiceProbe $probe,
        private TelegramOpsNotifier $telegram,
    ) {}

    /**
     * @return array<string, ProbeResult>
     */
    public function sweep(bool $alert = false): array
    {
        $definitions = array_values($this->registry->all());
        // Some faults are only visible as a difference between two sweeps — a
        // container whose restart counter is climbing reads as `running` every
        // time you look at it — so the probe is handed what the last sweep saw.
        $results = $this->probe->probeAll($definitions, $this->lastSeen());
        $now = CarbonImmutable::now();

        $transitions = [];

        foreach ($definitions as $definition) {
            $result = $results[$definition->key] ?? ProbeResult::unknown('not-probed');

            ServiceCheck::create([
                'service' => $definition->key,
                'status' => $result->status->value,
                'latency_ms' => $result->latencyMs,
                'detail' => $result->reason === null
                    ? $result->detail
                    : ['reason' => $result->reason] + $result->detail,
                'checked_at' => $now,
            ]);

            $transition = $this->reconcile($definition, $result, $now);

            if ($transition !== null) {
                $transitions[] = $transition;
            }
        }

        if ($alert && $transitions !== [] && config('monitoring.alerts.enabled', true)) {
            $this->announce($transitions);
        }

        return $results;
    }

    /**
     * The detail each service reported last time, for probes that compare.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lastSeen(): array
    {
        $checks = ServiceCheck::query()
            ->whereIn('id', ServiceCheck::latestIds())
            ->get(['service', 'detail']);

        $seen = [];

        foreach ($checks as $check) {
            $seen[$check->service] = is_array($check->detail) ? $check->detail : [];
        }

        return $seen;
    }

    /**
     * Move the service's incident record to match what we just saw.
     *
     * @return array{kind: string, definition: ServiceDefinition, incident: ServiceIncident}|null
     */
    private function reconcile(
        ServiceDefinition $definition,
        ProbeResult $result,
        CarbonImmutable $now,
    ): ?array {
        /** @var ServiceIncident|null $open */
        $open = ServiceIncident::query()->open()->where('service', $definition->key)->first();

        if ($result->status === ServiceStatus::Unknown) {
            return null;
        }

        if (! $result->status->isIncident()) {
            if ($open === null) {
                return null;
            }

            $open->update(['resolved_at' => $now]);

            return ['kind' => 'resolved', 'definition' => $definition, 'incident' => $open];
        }

        if ($open !== null) {
            // A service that slid from degraded to down is the same incident
            // with worse news; recording the worst state it reached is what
            // makes the history readable afterwards.
            if ($open->status !== $result->status->value
                && ServiceStatus::from($open->status)->severity() < $result->status->severity()
            ) {
                $open->update([
                    'status' => $result->status->value,
                    'reason' => $result->reason,
                    'detail' => $result->detail,
                ]);

                return ['kind' => 'worsened', 'definition' => $definition, 'incident' => $open];
            }

            return $this->maybeRemind($definition, $open, $now);
        }

        if (! $this->confirmed($definition, $now)) {
            return null;
        }

        $incident = ServiceIncident::create([
            'service' => $definition->key,
            'status' => $result->status->value,
            'reason' => $result->reason,
            'detail' => $result->detail,
            'started_at' => $now,
        ]);

        return ['kind' => 'opened', 'definition' => $definition, 'incident' => $incident];
    }

    /**
     * Whether the last few checks agree that this is really broken.
     *
     * One failed probe is usually this host's own network, and opening an
     * incident on it costs more trust than the incident is worth.
     */
    private function confirmed(ServiceDefinition $definition, CarbonImmutable $now): bool
    {
        $required = max(1, (int) config('monitoring.alerts.failures_before_alert', 2));

        $recent = ServiceCheck::query()
            ->where('service', $definition->key)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit($required)
            ->pluck('status');

        if ($recent->count() < $required) {
            return false;
        }

        return $recent->every(fn (string $status) => ServiceStatus::from($status)->isIncident());
    }

    /** @return array{kind: string, definition: ServiceDefinition, incident: ServiceIncident}|null */
    private function maybeRemind(
        ServiceDefinition $definition,
        ServiceIncident $incident,
        CarbonImmutable $now,
    ): ?array {
        $hours = (int) config('monitoring.alerts.reminder_hours', 12);
        $since = $incident->reminded_at ?? $incident->notified_at ?? $incident->started_at;

        if ($since === null || CarbonImmutable::parse($since)->addHours($hours)->isFuture()) {
            return null;
        }

        $incident->update(['reminded_at' => $now]);

        return ['kind' => 'reminder', 'definition' => $definition, 'incident' => $incident];
    }

    /**
     * One message per sweep, however many services changed.
     *
     * Five services going down at once is almost always one cause — the host,
     * the proxy, the network — and five separate messages would hide that.
     *
     * @param  array<int, array{kind: string, definition: ServiceDefinition, incident: ServiceIncident}>  $transitions
     */
    private function announce(array $transitions): void
    {
        $lines = [];

        foreach ($transitions as $transition) {
            $definition = $transition['definition'];
            $incident = $transition['incident'];
            $status = ServiceStatus::from($incident->status);

            $lines[] = match ($transition['kind']) {
                'resolved' => '🟢 <b>'.e($definition->label).'</b> recovered after '
                    .$this->duration($incident->durationSeconds()),
                'reminder' => $status->emoji().' <b>'.e($definition->label).'</b> still '
                    .$status->value.' — '.$this->duration($incident->durationSeconds()),
                'worsened' => $status->emoji().' <b>'.e($definition->label).'</b> got worse: '
                    .e((string) $incident->reason),
                default => $status->emoji().' <b>'.e($definition->label).'</b> is '
                    .$status->value.' — '.e((string) $incident->reason),
            };
        }

        $sent = $this->telegram->send(
            "🩺 <b>Cyberia services</b>\n\n".implode("\n", $lines)
            ."\n\n<a href=\"".e(url('/crm/services')).'">Board</a>',
        );

        if (! $sent) {
            return;
        }

        // Only stamp what was actually delivered: an unconfigured or refusing
        // Telegram must leave the incident looking un-announced, so the next
        // sweep tries again instead of going quiet about a live outage.
        foreach ($transitions as $transition) {
            if ($transition['kind'] === 'opened') {
                $transition['incident']->update(['notified_at' => CarbonImmutable::now()]);
            }
        }
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 90) {
            return $seconds.'s';
        }

        if ($seconds < 5400) {
            return round($seconds / 60).'m';
        }

        return $seconds < 172800
            ? round($seconds / 3600, 1).'h'
            : round($seconds / 86400, 1).'d';
    }
}
