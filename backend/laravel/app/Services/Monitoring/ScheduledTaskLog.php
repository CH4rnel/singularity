<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * When each scheduled command last finished, and whether it succeeded.
 *
 * Recorded by a listener on Laravel's own scheduler events rather than by
 * editing every entry in routes/console.php, so a command added tomorrow is
 * monitored the moment it is scheduled and nobody has to remember this file.
 *
 * The store is the cache, which means a flush erases it. That is why a command
 * with no record is reported `unknown` and never `down`: "it has not run since
 * the cache was cleared" and "it has never run" are indistinguishable from
 * here, and the scheduler's own check is what catches the case where nothing
 * is running at all.
 */
class ScheduledTaskLog
{
    /** Long enough that a daily command still has a record the next morning. */
    private const TTL_HOURS = 72;

    /** Written on every scheduled run, so the key must be cheap and stable. */
    public static function key(string $command): string
    {
        return 'monitoring.task:'.md5(self::normalise($command));
    }

    public static function record(string $command, bool $ok, ?float $runtimeMs = null): void
    {
        // Scheduled closures have no command line. They still prove the
        // scheduler ran, which is the more important of the two facts.
        if (trim($command) !== '') {
            Cache::put(self::key($command), [
                'command' => self::normalise($command),
                'at' => CarbonImmutable::now()->toIso8601String(),
                'ok' => $ok,
                'runtime_ms' => $runtimeMs === null ? null : (int) round($runtimeMs),
            ], now()->addHours(self::TTL_HOURS));
        }

        self::touchScheduler();
    }

    /**
     * "Something ran just now."
     *
     * A separate fact from any particular command having run, and the one that
     * catches the failure this project actually had: a host cron calling a php
     * binary that existed only inside the container, so that every scheduled
     * command lay dormant for months while each one individually looked
     * merely idle.
     */
    public static function touchScheduler(): void
    {
        Cache::put(
            'monitoring.scheduler.last',
            CarbonImmutable::now()->toIso8601String(),
            now()->addHours(self::TTL_HOURS),
        );
    }

    /** @return array{command: string, at: string, ok: bool, runtime_ms: int|null}|null */
    public static function last(string $command): ?array
    {
        $record = Cache::get(self::key($command));

        return is_array($record) ? $record : null;
    }

    public static function lastSchedulerRun(): ?CarbonImmutable
    {
        $value = Cache::get('monitoring.scheduler.last');

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    /**
     * Laravel hands the listener a full command line — the php binary, the
     * artisan path, quoting and all. What identifies a task is the artisan
     * command inside it, so that is what is stored and looked up.
     */
    public static function normalise(string $command): string
    {
        if (! str_contains($command, 'artisan')) {
            return trim($command);
        }

        $after = trim((string) preg_replace("/^.*artisan'?\"?\s*/s", '', $command));

        // Scheduled tasks are run with output redirected; that is plumbing,
        // not part of the command's identity.
        $after = trim((string) preg_replace('/\s*(>|2>).*$/s', '', $after));

        return trim(str_replace(["'", '"'], '', $after));
    }
}
