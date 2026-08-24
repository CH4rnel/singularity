<?php

namespace App\Services\Console;

use App\Models\CrmContact;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One line of text into one task.
 *
 * `написать киту про лимиты моста @lain !завтра #Nakamoto` — assignee, due
 * date and person, in the order they occur to whoever is typing. A form with
 * four fields is four decisions before the thought is written down, and the
 * thought is the part that gets lost.
 *
 * Parsing is deliberately forgiving and never fails: an `@name` nobody
 * matches stays in the title rather than throwing the line away, because a
 * task with a clumsy title is worth infinitely more than a rejected one.
 */
class TaskLine
{
    /**
     * @return array{title: string, assigned_to_user_id: int|null, due_at: string|null, crm_contact_id: int|null, unresolved: array<int, string>}
     */
    public static function parse(string $line, ?string $timezone = null): array
    {
        $timezone ??= (string) config('crm.console.timezone', config('app.timezone', 'UTC'));

        $assignee = null;
        $contact = null;
        $due = null;
        $unresolved = [];

        $title = preg_replace_callback(
            '/(?<lead>^|\s)(?<sigil>[@!#])(?<value>[^\s]+)/u',
            function (array $match) use (&$assignee, &$contact, &$due, &$unresolved, $timezone): string {
                $value = $match['value'];

                $resolved = match ($match['sigil']) {
                    '@' => $assignee = self::operator($value) ?? $assignee,
                    '!' => $due = self::date($value, $timezone) ?? $due,
                    '#' => $contact = self::contact($value) ?? $contact,
                    default => null,
                };

                if ($resolved === null) {
                    $unresolved[] = $match['sigil'].$value;

                    // Nothing matched, so the token belongs to the sentence.
                    return $match['lead'].$match['sigil'].$value;
                }

                return $match['lead'] === '' ? '' : ' ';
            },
            $line,
        ) ?? $line;

        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return [
            'title' => $title === '' ? trim($line) : $title,
            'assigned_to_user_id' => $assignee,
            'due_at' => $due,
            'crm_contact_id' => $contact,
            'unresolved' => $unresolved,
        ];
    }

    /** An operator, by name or by the start of their wallet. */
    private static function operator(string $value): ?int
    {
        $needle = mb_strtolower(trim($value, '@'));

        $user = User::crmOperators()
            ->get(['id', 'name', 'wallet_address'])
            ->first(fn (User $user) => mb_strtolower((string) $user->name) === $needle
                || str_starts_with(mb_strtolower((string) $user->name), $needle)
                || str_starts_with(mb_strtolower((string) $user->wallet_address), $needle));

        return $user?->id;
    }

    /** A contact, by name or telegram handle. */
    private static function contact(string $value): ?int
    {
        $needle = trim($value, '#@');

        $contact = CrmContact::query()
            ->where(fn ($query) => $query
                ->where('name', 'like', $needle.'%')
                ->orWhere('telegram', 'like', '%'.$needle.'%'))
            ->orderBy('id')
            ->first(['id']);

        return $contact?->id;
    }

    /**
     * A due date, in the words people actually type.
     *
     * Both languages, because the console is read in both and a Russian
     * "завтра" is exactly as likely as an English "tomorrow". Everything is
     * resolved against the operators' own timezone and stored in UTC — a task
     * due "today" means today where the person is.
     */
    private static function date(string $value, string $timezone): ?string
    {
        $needle = mb_strtolower(trim($value, '!'));
        $now = CarbonImmutable::now($timezone);

        $day = match ($needle) {
            'сегодня', 'today' => $now,
            'завтра', 'tomorrow' => $now->addDay(),
            'послезавтра' => $now->addDays(2),
            'неделя', 'week' => $now->addWeek(),
            'понедельник', 'monday' => $now->next(CarbonImmutable::MONDAY),
            'пятница', 'friday' => $now->next(CarbonImmutable::FRIDAY),
            default => null,
        };

        if ($day === null && preg_match('/^(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?$/', $needle, $parts) === 1) {
            $year = isset($parts[3]) ? (int) $parts[3] : $now->year;
            $year = $year < 100 ? 2000 + $year : $year;

            $day = $now->setDate($year, (int) $parts[2], (int) $parts[1]);
        }

        if ($day === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $needle) === 1) {
            $day = CarbonImmutable::parse($needle, $timezone);
        }

        return $day?->endOfDay()->setTimezone('UTC')->toDateTimeString();
    }
}
