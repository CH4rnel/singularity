<?php

namespace App\Services\Console;

use App\Models\ConsoleSnooze;
use Carbon\CarbonImmutable;

/**
 * "Not now, and not until morning."
 *
 * Deliberately a mechanism rather than a checkbox: a duty operator meets the
 * same list a dozen times a day, and a row that cannot be put down is a row
 * that teaches the whole list to be ignored. What is snoozed is still true —
 * it simply moves out of "requires action" and into the watch list with its
 * wake-up time printed, so nothing ever disappears silently.
 */
class Snooze
{
    /**
     * Keys that are asleep right now, with the moment they wake up.
     *
     * Read fresh every time and deliberately not memoised on the instance: a
     * long-lived container (Octane, and the router's cached controller inside
     * a test) would otherwise hand a second request the first one's answer,
     * and a row that was woken up would stay asleep until the process died.
     * It is one indexed query over a table that holds a handful of rows.
     *
     * @return array<string, CarbonImmutable>
     */
    public function active(): array
    {
        $active = [];

        foreach (ConsoleSnooze::query()->active()->get() as $snooze) {
            $active[$snooze->key] = CarbonImmutable::parse($snooze->snoozed_until);
        }

        return $active;
    }

    public function until(string $key): ?CarbonImmutable
    {
        return $this->active()[$key] ?? null;
    }

    /** Put one item down until a given moment, or until the next morning. */
    public function put(string $key, ?CarbonImmutable $until = null, ?int $userId = null): CarbonImmutable
    {
        $until ??= $this->morning();

        ConsoleSnooze::query()->updateOrCreate(
            ['key' => $key],
            ['snoozed_until' => $until, 'user_id' => $userId],
        );

        return $until;
    }

    public function wake(string $key): void
    {
        ConsoleSnooze::query()->where('key', $key)->delete();
    }

    /**
     * The next nine in the morning, in the timezone the operators live in.
     *
     * Stored in UTC like every other timestamp — the hour is a local fact
     * about a working day, the instant is not.
     */
    public function morning(): CarbonImmutable
    {
        $hour = (int) config('crm.console.morning_hour', 9);
        $zone = (string) config('crm.console.timezone', config('app.timezone', 'UTC'));

        $local = CarbonImmutable::now($zone);
        $morning = $local->setTime($hour, 0);

        if ($morning->lessThanOrEqualTo($local)) {
            $morning = $morning->addDay();
        }

        return $morning->setTimezone('UTC');
    }

    /** Snoozes that already woke up, cleared so the table stays small. */
    public function prune(): int
    {
        return ConsoleSnooze::query()->where('snoozed_until', '<=', now()->subDay())->delete();
    }
}
