<?php

namespace App\Services\Monitoring;

/**
 * What one probe found.
 *
 * `reason` is a short stable key (`timeout`, `stale-head`, `crash-loop`), not
 * a sentence: it is what the UI translates, what an alert groups by, and what
 * a person greps for six months from now. Anything human-readable belongs in
 * `detail`, which is stored verbatim and never matched on.
 */
final class ProbeResult
{
    /** @param array<string, mixed> $detail */
    private function __construct(
        public readonly ServiceStatus $status,
        public readonly ?string $reason = null,
        public readonly array $detail = [],
        public readonly ?int $latencyMs = null,
    ) {}

    /** @param array<string, mixed> $detail */
    public static function up(array $detail = [], ?int $latencyMs = null): self
    {
        return new self(ServiceStatus::Up, null, $detail, $latencyMs);
    }

    /** @param array<string, mixed> $detail */
    public static function degraded(string $reason, array $detail = [], ?int $latencyMs = null): self
    {
        return new self(ServiceStatus::Degraded, $reason, $detail, $latencyMs);
    }

    /** @param array<string, mixed> $detail */
    public static function down(string $reason, array $detail = [], ?int $latencyMs = null): self
    {
        return new self(ServiceStatus::Down, $reason, $detail, $latencyMs);
    }

    /** @param array<string, mixed> $detail */
    public static function unknown(string $reason, array $detail = []): self
    {
        return new self(ServiceStatus::Unknown, $reason, $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function off(string $reason = 'not-deployed', array $detail = []): self
    {
        return new self(ServiceStatus::Off, $reason, $detail);
    }
}
