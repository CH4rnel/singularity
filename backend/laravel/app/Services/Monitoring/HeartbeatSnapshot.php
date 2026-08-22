<?php

namespace App\Services\Monitoring;

use App\Models\ServiceHeartbeat;
use Carbon\CarbonImmutable;

/**
 * The host's last report, with the questions the registry asks of it.
 *
 * Every accessor returns null for "the host did not tell us", which is a
 * different answer from "it told us zero" and is carried all the way to the
 * board: a container the reporter never mentioned is `unknown`, a container it
 * reported as exited is `down`. Collapsing those two would make a typo in the
 * container name look exactly like an outage.
 */
final class HeartbeatSnapshot
{
    /** @param array<string, mixed> $payload */
    private function __construct(
        public readonly ?string $host,
        public readonly ?CarbonImmutable $reportedAt,
        private readonly array $payload,
        private readonly int $staleSeconds,
    ) {}

    public static function fromRecord(ServiceHeartbeat $record): self
    {
        return new self(
            $record->host,
            CarbonImmutable::parse($record->reported_at),
            is_array($record->payload) ? $record->payload : [],
            self::staleSeconds(),
        );
    }

    /** A machine nobody has ever heard from. */
    public static function absent(?string $host = null): self
    {
        return new self($host, null, [], self::staleSeconds());
    }

    private static function staleSeconds(): int
    {
        return (int) config('monitoring.heartbeat.stale_seconds', 240);
    }

    /** @param array<string, mixed> $payload */
    public static function fake(array $payload, ?CarbonImmutable $reportedAt = null): self
    {
        return new self(
            $payload['host'] ?? 'test',
            $reportedAt ?? CarbonImmutable::now(),
            $payload,
            self::staleSeconds(),
        );
    }

    public function missing(): bool
    {
        return $this->reportedAt === null;
    }

    public function stale(): bool
    {
        return $this->reportedAt === null
            || $this->reportedAt->diffInSeconds(CarbonImmutable::now(), true) > $this->staleSeconds;
    }

    public function ageSeconds(): ?int
    {
        return $this->reportedAt?->diffInSeconds(CarbonImmutable::now(), true);
    }

    /**
     * One container as the host saw it, or null when it was not mentioned.
     *
     * @return array{name: string, state: string, status: string, restarts: int}|null
     */
    public function container(string $name): ?array
    {
        foreach ($this->payload['containers'] ?? [] as $container) {
            if (! is_array($container) || ($container['name'] ?? null) !== $name) {
                continue;
            }

            return [
                'name' => $name,
                'state' => (string) ($container['state'] ?? 'unknown'),
                'status' => (string) ($container['status'] ?? ''),
                'restarts' => (int) ($container['restarts'] ?? 0),
            ];
        }

        return null;
    }

    /** null when the host reported no tmux inventory at all. */
    public function hasTmux(string $session): ?bool
    {
        $sessions = $this->payload['tmux'] ?? null;

        return is_array($sessions) ? in_array($session, $sessions, true) : null;
    }

    public function processCount(string $name): ?int
    {
        $processes = $this->payload['processes'] ?? null;

        if (! is_array($processes) || ! array_key_exists($name, $processes)) {
            return null;
        }

        return (int) $processes[$name];
    }

    /**
     * What systemd says about a unit, or null when the host did not report it.
     *
     * The supervisor's own answer, rather than a pattern match against a
     * process list: `pgrep -f` matches the command line of whatever is running
     * the reporting script too, and a monitor that counts itself is worse than
     * no monitor.
     */
    public function unitState(string $name): ?string
    {
        $units = $this->payload['units'] ?? null;

        return is_array($units) && is_string($units[$name] ?? null)
            ? $units[$name]
            : null;
    }

    /** @return array{log_age_seconds: int|null, log_size_mb: float|null}|null */
    public function cron(string $name): ?array
    {
        $crons = $this->payload['crons'] ?? null;

        if (! is_array($crons) || ! is_array($crons[$name] ?? null)) {
            return null;
        }

        $entry = $crons[$name];

        return [
            'log_age_seconds' => isset($entry['log_age_seconds']) ? (int) $entry['log_age_seconds'] : null,
            'log_size_mb' => isset($entry['log_size_mb']) ? (float) $entry['log_size_mb'] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function metrics(): array
    {
        return [
            'load' => $this->payload['load'] ?? null,
            'cpus' => isset($this->payload['cpus']) ? (int) $this->payload['cpus'] : null,
            'memory' => $this->payload['memory'] ?? null,
            'swap' => $this->payload['swap'] ?? null,
            'disk' => $this->payload['disk'] ?? null,
            'uptime_seconds' => isset($this->payload['uptime_seconds'])
                ? (int) $this->payload['uptime_seconds']
                : null,
        ];
    }

    /** Every container the host reported, for the ones nobody put in the registry. */
    public function containerNames(): array
    {
        return array_values(array_filter(array_map(
            fn ($container) => is_array($container) ? ($container['name'] ?? null) : null,
            $this->payload['containers'] ?? [],
        )));
    }
}
