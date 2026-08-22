<?php

namespace App\Services\Monitoring;

/**
 * The five things a service can be.
 *
 * The vocabulary is small on purpose and the distinctions in it are the ones
 * that change what a person does next:
 *
 *   up        It answered, and the answer was right.
 *   degraded  It answered, and something in the answer is wrong — slow, a
 *             stale chain head, an index falling behind, a certificate about
 *             to expire. Nothing is broken yet; this is the window in which
 *             it can be fixed cheaply.
 *   down      It did not answer, or answered wrongly.
 *   unknown   We could not find out. A missing heartbeat says the reporter
 *             died and says nothing about what it was reporting on, and
 *             printing that as `down` would send someone to fix a healthy
 *             service while the real fault stayed invisible.
 *   off       It is deliberately not running. Something the repo carries and
 *             nobody deployed is not an outage.
 *
 * Only `down` and `degraded` open incidents. `unknown` never does — an
 * alerting system that shouts when it goes blind trains people to ignore it.
 */
enum ServiceStatus: string
{
    case Up = 'up';
    case Degraded = 'degraded';
    case Down = 'down';
    case Unknown = 'unknown';
    case Off = 'off';

    /** Whether this state is worth opening an incident and telling someone about. */
    public function isIncident(): bool
    {
        return $this === self::Down || $this === self::Degraded;
    }

    /** Whether this state counts towards uptime at all. */
    public function counts(): bool
    {
        return $this !== self::Unknown && $this !== self::Off;
    }

    /** Worst-first, so a group's badge can be the worst thing in it. */
    public function severity(): int
    {
        return match ($this) {
            self::Down => 4,
            self::Degraded => 3,
            self::Unknown => 2,
            self::Off => 1,
            self::Up => 0,
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Up => '🟢',
            self::Degraded => '🟡',
            self::Down => '🔴',
            self::Unknown => '⚪',
            self::Off => '⚫',
        };
    }
}
