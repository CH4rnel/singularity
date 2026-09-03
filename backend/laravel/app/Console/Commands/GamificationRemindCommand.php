<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ProgressNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only notification that brings anybody back.
 *
 * Everything the gamification system sent until now was a receipt: you levelled
 * up, you finished a quest — messages that arrive *after* the thing you were
 * being encouraged to do. Nobody has ever returned to an app because it
 * confirmed what they had already done. "Я о них даже не помню" is a statement
 * about the absence of exactly this command.
 *
 * Two reasons to write, and both have to be true, not manufactured:
 *
 *  - A streak that dies tonight. It is the only genuinely time-boxed thing in
 *    the system, it belongs to the person rather than to us, and losing it is
 *    the one loss they will feel.
 *  - A daily quest already started and left one step short. The step is small
 *    and known, so the message can name it.
 *
 * Quiet hours are solved by *when this runs* rather than by a per-user setting
 * nobody would ever open: once a day, in the evening, in the timezone the
 * people here actually live in. At most one message per person per day, and
 * only to people who registered for push — a bell notification for somebody who
 * is not here is precisely the thing that already does not work.
 */
#[Signature('gamification:remind {--dry-run : List who would be written to, and why} {--force : Ignore today\'s already-sent guard}')]
#[Description('Remind people whose streak dies tonight, or whose daily quest is one step short')]
class GamificationRemindCommand extends Command
{
    public function handle(): int
    {
        $today = Carbon::now('UTC')->toDateString();
        $yesterday = Carbon::now('UTC')->subDay()->toDateString();

        $candidates = User::query()
            ->join('user_stats', 'user_stats.user_id', '=', 'users.id')
            ->whereNull('users.merged_into_id')
            // Only people this can actually reach. Writing to a bell that
            // nobody will open is the failure this command exists to end.
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('push_subscriptions')
                ->whereColumn('push_subscriptions.subscribable_id', 'users.id')
                ->where('push_subscriptions.subscribable_type', User::class))
            ->select('users.*', 'user_stats.current_streak', 'user_stats.last_active_on')
            ->get();

        $sent = 0;

        foreach ($candidates as $user) {
            if (! $this->option('force') && $this->alreadyWrittenToday($user, $today)) {
                continue;
            }

            $reason = $this->reasonFor($user, $today, $yesterday);

            if ($reason === null) {
                continue;
            }

            $this->line(sprintf('%-4s %-20s %s', $user->id, $user->name, $reason['why']));

            if ($this->option('dry-run')) {
                continue;
            }

            $user->notify($reason['notification']);
            $sent++;
        }

        $this->newLine();
        $this->line(sprintf(
            'Reachable %d · written to %d%s',
            $candidates->count(),
            $this->option('dry-run') ? 0 : $sent,
            $this->option('dry-run') ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{why: string, notification: ProgressNotification}|null
     */
    private function reasonFor(User $user, string $today, string $yesterday): ?array
    {
        // This column arrives three ways — a Carbon from a cast, a bare
        // "Y-m-d" from SQLite, and a "Y-m-d H:i:s" when something wrote a
        // datetime into a date column. Compare days, not strings.
        $lastActive = $this->day($user->last_active_on);

        // Already here today: there is nothing to save and nothing to nag about.
        if ($lastActive === $today) {
            return null;
        }

        $streak = (int) $user->current_streak;

        if ($streak >= 2 && $lastActive === $yesterday) {
            return [
                'why' => "streak of {$streak} dies tonight",
                'notification' => new ProgressNotification(
                    type: 'progress.streak_at_risk',
                    title: [
                        'en' => 'Your {days}-day streak ends tonight',
                        'ru' => 'Серия из {days} дней прервётся сегодня',
                        'zh' => '你的 {days} 天连续记录今晚结束',
                    ],
                    body: [
                        'en' => 'Open Cyberia before midnight UTC to keep it.',
                        'ru' => 'Загляните в Cyberia до полуночи UTC, чтобы сохранить её.',
                        'zh' => '在 UTC 午夜前打开 Cyberia 即可保住它。',
                    ],
                    url: '/profile',
                    replace: ['days' => $streak],
                ),
            ];
        }

        $quest = $this->unfinishedQuest($user, $today);

        if ($quest !== null) {
            return [
                'why' => "quest {$quest->quest_key} at {$quest->progress}/{$quest->target}",
                'notification' => new ProgressNotification(
                    type: 'progress.quest_nearly_done',
                    title: [
                        'en' => 'One step from finishing today',
                        'ru' => 'Один шаг до выполнения',
                        'zh' => '距离完成只差一步',
                    ],
                    body: [
                        'en' => '{done} of {target} done. Finish it before midnight UTC.',
                        'ru' => 'Сделано {done} из {target}. Закончите до полуночи UTC.',
                        'zh' => '已完成 {done}/{target}。请在 UTC 午夜前完成。',
                    ],
                    url: '/profile',
                    replace: ['done' => (int) $quest->progress, 'target' => (int) $quest->target],
                ),
            ];
        }

        return null;
    }

    /** The date part of whatever shape this timestamp arrived in. */
    private function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 10);
    }

    /** A daily quest started today and left short of its target. */
    private function unfinishedQuest(User $user, string $today): ?object
    {
        return DB::table('user_quests')
            ->where('user_id', $user->id)
            ->where('period_key', $today)
            ->whereNull('completed_at')
            ->where('progress', '>', 0)
            ->whereColumn('progress', '<', 'target')
            ->orderByDesc('progress')
            ->first();
    }

    /** At most one nudge a day, whichever of the two reasons it was. */
    private function alreadyWrittenToday(User $user, string $today): bool
    {
        return DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('notifiable_type', User::class)
            ->where('created_at', '>=', $today.' 00:00:00')
            ->where(fn ($query) => $query
                ->where('data', 'like', '%progress.streak_at_risk%')
                ->orWhere('data', 'like', '%progress.quest_nearly_done%'))
            ->exists();
    }
}
