<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AchievementService;
use App\Services\TelegramOpsNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Re-detect earned achievements and mint what is owing.
 *
 * Until this existed, detection ran in exactly two places: when somebody
 * claimed a nickname, and when somebody pressed a button on /profile that they
 * had no reason to know about. The last award on prod was 2026-07-30, and a
 * badge earned on 2026-08-22 simply sat there. A badge nobody is watching for
 * has to be granted by something that runs on its own.
 *
 * Reads the chain and writes only badges. It is bounded per run and ordered by
 * who was here most recently, so the people who would actually notice are
 * checked first; `--all` is the backfill for everyone else, and `--user` is the
 * repair for one person.
 */
#[Signature('achievements:sync
    {--user= : One user id, instead of a sweep}
    {--limit=25 : How many users a sweep may check}
    {--all : Check every user with a wallet, ignoring the limit}
    {--dry-run : Report what is owing without minting it}
    {--force : Re-check users the last sweeps already looked at}
    {--alert : Tell the operators when an award fails}')]
#[Description('Detect and mint achievements that have been earned but not granted')]
class AchievementsSyncCommand extends Command
{
    /** Hours between repeats of the same failure alert. */
    private const ALERT_SILENCE_HOURS = 12;

    /**
     * How long a checked user is left alone.
     *
     * Without this the sweep's "most recently active first" ordering would
     * re-read the same handful of people every hour and never reach the tail.
     * A badge is not urgent — /profile still has a button for the impatient —
     * so the whole base rotating through in a few runs is the right trade.
     */
    private const RECHECK_HOURS = 6;

    public function handle(AchievementService $achievements, TelegramOpsNotifier $telegram): int
    {
        $users = $this->users();

        if ($users->isEmpty()) {
            $this->info('No users with a wallet to check.');

            return self::SUCCESS;
        }

        $awarded = 0;
        $failures = [];
        $unreadable = 0;

        $checked = 0;

        foreach ($users as $user) {
            $seen = 'achievements:checked:'.$user->id;

            if (! $this->option('force') && ! $this->option('user') && Cache::has($seen)) {
                continue;
            }

            $checked++;

            if (! $this->option('dry-run')) {
                Cache::put($seen, true, now()->addHours(self::RECHECK_HOURS));
            }

            if ($this->option('dry-run')) {
                $pending = $achievements->pending($user);

                match (true) {
                    $pending === null => $unreadable++,
                    $pending === [] => null,
                    default => $this->line(sprintf(
                        '%-4s %-42s owed: %s',
                        $user->id,
                        $user->wallet_address,
                        implode(', ', array_column($pending, 'key')),
                    )),
                };

                continue;
            }

            $result = $achievements->award($user);

            if ($result['unreadable']) {
                $unreadable++;

                continue;
            }

            foreach ($result['awarded'] as $definition) {
                $awarded++;
                $this->info(sprintf('%-4s %s → %s', $user->id, $user->wallet_address, $definition['key']));
            }

            if ($result['failed'] !== []) {
                $failures[] = [
                    'user' => $user,
                    'keys' => array_column($result['failed'], 'key'),
                ];
                $this->error(sprintf(
                    '%-4s %s earned %s and the mint failed',
                    $user->id,
                    $user->wallet_address,
                    implode(', ', array_column($result['failed'], 'key')),
                ));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Checked %d · awarded %d · failed %d · unreadable %d',
            $checked,
            $awarded,
            count($failures),
            $unreadable,
        ));

        if ($this->option('alert') && $failures !== []) {
            $this->notifyOperators($telegram, $failures);
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function users()
    {
        $query = User::query()
            ->whereNotNull('wallet_address')
            ->where('wallet_address', '!=', '');

        if ($this->option('user')) {
            return $query->where('id', (int) $this->option('user'))->get();
        }

        // Most recently active first: the people who would notice a missing
        // badge are the people who were here today.
        $query->leftJoin('user_stats', 'user_stats.user_id', '=', 'users.id')
            ->orderByRaw('user_stats.last_active_on IS NULL')
            ->orderByDesc('user_stats.last_active_on')
            ->orderByDesc('users.id')
            ->select('users.*');

        return $this->option('all')
            ? $query->get()
            : $query->limit(max(1, (int) $this->option('limit')))->get();
    }

    /**
     * @param  array<int, array{user: User, keys: array<int, string>}>  $failures
     */
    private function notifyOperators(TelegramOpsNotifier $telegram, array $failures): void
    {
        // A mint that fails will very likely fail again on the next sweep, so
        // this is a state, not an event: say it once, then stay quiet for half
        // a day rather than repeating it every hour.
        if (Cache::get('achievements:alerted-at') !== null) {
            return;
        }

        $lines = ['⚠️ Ачивки заслужены, но не выданы:'];

        foreach ($failures as $failure) {
            $lines[] = sprintf(
                '· %s (%s) — %s',
                $failure['user']->onchain_nickname ?: $failure['user']->name,
                $failure['user']->wallet_address,
                implode(', ', $failure['keys']),
            );
        }

        $lines[] = '';
        $lines[] = 'Выдача идёт через релеер, который делит nonce с ТГ-минтером. Проверьте его баланс и очередь.';

        if ($telegram->send(implode("\n", $lines))) {
            Cache::put('achievements:alerted-at', now()->toIso8601String(), now()->addHours(self::ALERT_SILENCE_HOURS));
        }
    }
}
