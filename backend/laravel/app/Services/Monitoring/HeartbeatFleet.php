<?php

namespace App\Services\Monitoring;

use App\Models\ServiceHeartbeat;
use Carbon\CarbonImmutable;

/**
 * Every host that reports, and which one a given service lives on.
 *
 * There is more than one machine. `cyber.main` runs the chain, the explorer
 * and the site; LainOS runs on the operator's own machine and has never been
 * deployed to the server at all; `services/cyberia-node` is a prepared second
 * node waiting for a third. A monitor that assumed one host would report the
 * operator's daemon as missing from a server it was never installed on — a
 * false alarm that never stops, which is the fastest way to teach someone to
 * ignore a board.
 *
 * So a registry entry may name its host. An entry that does not name one takes
 * the default: the single host reporting, or the most recent when several do.
 * A named host that has never reported reads `unknown`, never `down` — the
 * distinction this whole system is built on.
 */
final class HeartbeatFleet
{
    /** @param array<string, HeartbeatSnapshot> $snapshots */
    private function __construct(
        private readonly array $snapshots,
        private readonly ?string $defaultHost,
    ) {}

    public static function load(): self
    {
        $snapshots = [];
        $default = null;

        $records = ServiceHeartbeat::query()->orderByDesc('reported_at')->get();

        foreach ($records as $record) {
            $snapshots[$record->host] = HeartbeatSnapshot::fromRecord($record);
            $default ??= $record->host;
        }

        return new self($snapshots, (string) config('monitoring.heartbeat.default_host') ?: $default);
    }

    /** @param array<string, HeartbeatSnapshot> $snapshots */
    public static function fake(array $snapshots, ?string $default = null): self
    {
        return new self($snapshots, $default ?? array_key_first($snapshots));
    }

    /**
     * The snapshot for a service, or an empty one when that host has never
     * been heard from. Never null: "we have never heard from this machine" is
     * an answer the probes already know how to render.
     */
    public function for(?string $host): HeartbeatSnapshot
    {
        $host ??= $this->defaultHost;

        return $host === null
            ? HeartbeatSnapshot::absent()
            : ($this->snapshots[$host] ?? HeartbeatSnapshot::absent($host));
    }

    /** @return array<string, HeartbeatSnapshot> */
    public function all(): array
    {
        return $this->snapshots;
    }

    public function isEmpty(): bool
    {
        return $this->snapshots === [];
    }

    public function lastReportedAt(): ?CarbonImmutable
    {
        $times = array_filter(array_map(
            fn (HeartbeatSnapshot $snapshot) => $snapshot->reportedAt,
            $this->snapshots,
        ));

        return $times === [] ? null : max($times);
    }
}
