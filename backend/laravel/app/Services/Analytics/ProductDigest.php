<?php

namespace App\Services\Analytics;

use App\Services\GasSponsorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The daily product report, composed for an operator reading a phone.
 *
 * `/crm/numbers` already answers every one of these questions, and nobody
 * opens it. That is the whole reason this class exists: a dashboard is a place
 * you have to decide to go to, and a report arrives whether or not you
 * remembered it. So this is deliberately *not* a dump of the dashboard —
 * everything here earns its line:
 *
 *  - Every number carries its change against the previous window of the same
 *    length. A count with no delta cannot tell you whether it is good.
 *  - The funnel names its own worst step, because the point of a funnel is the
 *    one place to work on next, and finding it by eye every morning is the
 *    chore that stops people reading.
 *  - Sections with nothing in them say so in one line instead of printing
 *    zeros. A report that is mostly zeros gets skimmed, and the day one of
 *    those zeros becomes a one, nobody notices.
 *  - It reaches past the wallet into the two populations the wallet's own
 *    analytics cannot see — the gamification ledger and the Telegram chats —
 *    because the biggest gap on this project lives between them.
 *
 * Reads only. It signs nothing, sends nothing on its own, and a table it does
 * not find is a missing section rather than an error: the bot's tables belong
 * to another program that may not be deployed beside this one.
 */
class ProductDigest
{
    public function __construct(
        private readonly ProductMetricsService $metrics,
        private readonly GasSponsorService $sponsor,
    ) {}

    /**
     * Build the report.
     *
     * @return array{title: string, sections: array<int, array{title: string, lines: array<int, string>}>}
     */
    public function build(int $days = 1, ?Carbon $now = null): array
    {
        $to = ($now ?? Carbon::now('UTC'))->copy();
        $from = $to->copy()->subDays($days);

        $current = new AnalyticsFilters(from: $from, to: $to);
        $previous = new AnalyticsFilters(
            from: $from->copy()->subDays($days),
            to: $from->copy(),
        );

        return [
            'title' => $days === 1
                ? '📊 Cyberia — сводка за сутки'
                : "📊 Cyberia — сводка за {$days} дн.",
            'period' => $from->toDateTimeString().' → '.$to->toDateTimeString().' UTC',
            'sections' => array_values(array_filter([
                $this->northStar($current, $previous),
                $this->funnel($current),
                $this->features($current, $previous),
                $this->gamification($from, $to, $days),
                $this->telegram(),
                $this->onchain($from, $to, $days),
                $this->acquisition($current),
                $this->gasStation($current),
            ])),
        ];
    }

    /** The report as one Telegram message. */
    public function toTelegram(int $days = 1, ?Carbon $now = null): string
    {
        $report = $this->build($days, $now);

        $out = ['<b>'.$this->e($report['title']).'</b>', '<i>'.$this->e($report['period']).'</i>'];

        foreach ($report['sections'] as $section) {
            $out[] = '';
            $out[] = '<b>'.$this->e($section['title']).'</b>';

            foreach ($section['lines'] as $line) {
                $out[] = $this->e($line);
            }
        }

        return implode("\n", $out);
    }

    /** The report as plain text, for a terminal. */
    public function toText(int $days = 1, ?Carbon $now = null): string
    {
        $report = $this->build($days, $now);

        $out = [$report['title'], $report['period']];

        foreach ($report['sections'] as $section) {
            $out[] = '';
            $out[] = mb_strtoupper($section['title']);

            foreach ($section['lines'] as $line) {
                $out[] = '  '.$line;
            }
        }

        return implode("\n", $out);
    }

    /* ------------------------------------------------------------ sections -- */

    private function northStar(AnalyticsFilters $current, AnalyticsFilters $previous): array
    {
        $now = $this->metrics->weeklyActiveFundedUsers($current);
        $then = $this->metrics->weeklyActiveFundedUsers($previous);
        $overview = $this->metrics->overview($current);

        return [
            'title' => '⭐ Северная звезда',
            'lines' => [
                $this->join('WAFU (активные пополненные за 7д): '.$now, $this->delta($now, $then)),
                'DAU '.$overview['dau'].' · WAU '.$overview['wau'].' · MAU '.$overview['mau'],
                'Установок за период: '.$overview['new_users']
                    .', вернулось: '.$overview['returning_users'],
            ],
        ];
    }

    private function funnel(AnalyticsFilters $current): array
    {
        $steps = $this->metrics->mainFunnel($current);
        $labels = [
            'first_open' => 'Первый запуск',
            'wallet' => 'Создан кошелёк',
            'funded' => 'Пополнен',
            'activated' => 'Первое действие',
            'retained' => 'Вернулся после',
        ];

        $lines = [];
        $worst = null;

        foreach ($steps as $step) {
            $label = $labels[$step['key']] ?? $step['key'];
            $of = $step['of_previous'] === null ? '' : ' ('.$this->pct($step['of_previous']).' от пред.)';
            $lines[] = $label.': '.$step['value'].$of;

            // The worst step is the biggest proportional loss, and only among
            // steps that had somebody to lose — a 0 → 0 transition is not a
            // conversion problem, it is an empty funnel.
            if ($step['of_previous'] !== null && ($worst === null || $step['of_previous'] < $worst['of_previous'])) {
                $worst = $step + ['label' => $label];
            }
        }

        if ($steps[0]['value'] === 0) {
            return ['title' => '🚪 Воронка кошелька', 'lines' => ['За период никто не открыл кошелёк.']];
        }

        if ($worst !== null) {
            $lines[] = '';
            $lines[] = '⚠️ Узкое место: «'.$worst['label'].'» — доходит '.$this->pct($worst['of_previous']).'.';
        }

        return ['title' => '🚪 Воронка кошелька', 'lines' => $lines];
    }

    private function features(AnalyticsFilters $current, AnalyticsFilters $previous): array
    {
        $labels = [
            'swap' => 'Обмен',
            'bridge' => 'Мост',
            'send' => 'Отправка',
            'staking' => 'Стейкинг',
            'liquidity' => 'Ликвидность',
            'nft' => 'NFT',
        ];

        $before = collect($this->metrics->productUsage($previous))->keyBy('feature');
        $lines = [];

        foreach ($this->metrics->productUsage($current) as $row) {
            if ($row['actions'] === 0 && (int) ($before[$row['feature']]['actions'] ?? 0) === 0) {
                continue;
            }

            $label = $labels[$row['feature']] ?? $row['feature'];
            $was = (int) ($before[$row['feature']]['actions'] ?? 0);
            $rate = $row['success_rate'] === null ? '' : ', успех '.$this->pct($row['success_rate']);

            $lines[] = $this->join($label.': '.$row['actions'].' действ.', $this->delta($row['actions'], $was))
                .' / '.$row['users'].' польз.'.$rate;
        }

        if ($lines === []) {
            return ['title' => '🧰 Функции', 'lines' => ['Ни одной завершённой операции ни за этот период, ни за предыдущий.']];
        }

        return ['title' => '🧰 Функции', 'lines' => $lines];
    }

    /**
     * The gamification ledger, which the wallet's analytics cannot see: XP is
     * credited to a *site account*, and most wallet installations never sign
     * into one. Two separate populations, and reporting only the first is how
     * a quest system runs for months without anyone noticing nobody plays it.
     */
    private function gamification(Carbon $from, Carbon $to, int $days): array
    {
        if (! Schema::hasTable('xp_entries') || ! Schema::hasTable('user_stats')) {
            return [];
        }

        $window = fn (string $table) => DB::table($table)->whereBetween('created_at', [$from, $to]);
        $prior = fn (string $table) => DB::table($table)
            ->whereBetween('created_at', [$from->copy()->subDays($days), $from]);

        $xp = (int) $window('xp_entries')->sum('amount');
        $xpWas = (int) $prior('xp_entries')->sum('amount');
        $players = $window('xp_entries')->distinct()->count('user_id');
        $playersWas = $prior('xp_entries')->distinct()->count('user_id');

        $lines = [
            $this->join('Игроков за период: '.$players, $this->delta($players, $playersWas)),
            $this->join('Начислено XP: '.$xp, $this->delta($xp, $xpWas)),
        ];

        $bySource = $window('xp_entries')
            ->select('source', DB::raw('count(*) as c'))
            ->groupBy('source')
            ->orderByDesc('c')
            ->limit(4)
            ->get();

        if ($bySource->isNotEmpty()) {
            $lines[] = 'Источники: '.$bySource
                ->map(fn ($r) => $r->source.' ×'.$r->c)
                ->implode(', ');
        }

        if (Schema::hasTable('user_quests')) {
            $done = DB::table('user_quests')
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [$from, $to])
                ->count();
            $started = DB::table('user_quests')->whereBetween('updated_at', [$from, $to])->count();
            $lines[] = 'Заданий выполнено: '.$done.' из '.$started.' начатых';
        }

        $streak = DB::table('user_stats')->max('current_streak');
        $lines[] = 'Лучшая активная серия: '.((int) $streak).' дн.';

        // The number that says whether any of this is reaching anyone. It is
        // deliberately the *total* population, not the window's: a quest system
        // is judged by how much of the user base it has ever moved.
        $everPlayed = DB::table('user_stats')->where('xp', '>', 0)->count();
        $accounts = Schema::hasTable('users') ? DB::table('users')->count() : 0;

        if ($accounts > 0) {
            $lines[] = 'Всего аккаунтов с XP: '.$everPlayed.' из '.$accounts
                .' ('.$this->pct($this->rate($everPlayed, $accounts)).')';
        }

        return ['title' => '🎮 Геймификация', 'lines' => $lines];
    }

    /**
     * The Telegram chats, which are the largest population this project has
     * and the one furthest from the product. The bot's tables live in the same
     * database but belong to another program, so every one of them is checked
     * before it is read.
     */
    private function telegram(): array
    {
        if (! Schema::hasTable('chat_members') || ! Schema::hasTable('tg_wallets')) {
            return [];
        }

        $members = DB::table('chat_members')->distinct()->count('user_id');

        if ($members === 0) {
            return [];
        }

        $linked = DB::table('chat_members')
            ->whereIn('user_id', DB::table('tg_wallets')->select('user_id'))
            ->distinct()
            ->count('user_id');

        $lines = [
            'Участников чатов: '.$members,
            'Из них привязали кошелёк: '.$linked.' ('.$this->pct($this->rate($linked, $members)).')',
        ];

        if (Schema::hasTable('pending_rewards')) {
            $waiting = DB::table('pending_rewards')->distinct()->count('user_id');

            if ($waiting > 0) {
                $lines[] = 'Ждут наград без кошелька: '.$waiting
                    .' — это готовая аудитория, которой некуда платить.';
            }
        }

        return ['title' => '💬 Телеграм', 'lines' => $lines];
    }

    /**
     * On-chain activity as the indexer saw it. Kept apart from the wallet's own
     * funnel because it counts *addresses acting on the chain*, whoever they
     * are and whatever they used — the only section here that can see a person
     * who never touched our frontend.
     */
    private function onchain(Carbon $from, Carbon $to, int $days): array
    {
        if (! Schema::hasTable('activity_events')) {
            return [];
        }

        $rows = DB::table('activity_events')
            ->whereBetween('created_at', [$from, $to])
            ->select('kind', DB::raw('count(*) as c'), DB::raw('count(distinct user_addr) as u'))
            ->groupBy('kind')
            ->orderByDesc('c')
            ->get();

        if ($rows->isEmpty()) {
            return ['title' => '⛓ Ончейн', 'lines' => ['Индексатор не записал ни одного события за период.']];
        }

        $was = DB::table('activity_events')
            ->whereBetween('created_at', [$from->copy()->subDays($days), $from])
            ->count();
        $total = (int) $rows->sum('c');

        $lines = [
            $this->join('Событий: '.$total, $this->delta($total, $was))
                .' от '.DB::table('activity_events')->whereBetween('created_at', [$from, $to])->distinct()->count('user_addr').' адресов',
        ];

        foreach ($rows as $row) {
            $lines[] = '· '.$row->kind.': '.$row->c.' ('.$row->u.' адр.)';
        }

        return ['title' => '⛓ Ончейн', 'lines' => $lines];
    }

    private function acquisition(AnalyticsFilters $current): array
    {
        $rows = array_slice($this->metrics->acquisition($current, 5), 0, 5);

        if ($rows === []) {
            return [];
        }

        $lines = [];

        foreach ($rows as $row) {
            $source = $row['source'] === 'direct' ? 'прямые заходы' : $row['source'];
            $lines[] = $source.': '.$row['users'].' → кошелёк '.$row['wallets']
                .' → пополнен '.$row['funded'];
        }

        return ['title' => '📥 Откуда пришли', 'lines' => $lines];
    }

    private function gasStation(AnalyticsFilters $current): array
    {
        if (! $this->sponsor->enabled()) {
            return [];
        }

        $gas = $this->metrics->gasSponsorship($current);
        $summary = $this->sponsor->summary();

        $lines = [
            'Выдано за период: '.$gas['transactions'].' капель на '.$gas['addresses'].' адресов',
        ];

        if ($summary === null) {
            $lines[] = '⚠️ Станцию не удалось прочитать — это проблема RPC, а не пустой бак.';

            return ['title' => '⛽ Газ-станция', 'lines' => $lines];
        }

        $drip = $summary['drip'] === '0' ? '1' : $summary['drip'];
        $left = (int) bcdiv($summary['tank'], $drip, 0);

        $lines[] = 'В баке: '.$left.' капель'.($summary['paused'] ? ' [ПАУЗА]' : '');

        if ($left < 20) {
            $lines[] = '⚠️ Бак почти пуст.';
        }

        return ['title' => '⛽ Газ-станция', 'lines' => $lines];
    }

    /* ------------------------------------------------------------- helpers -- */

    /** Two fragments with one space between them, and none when the second is empty. */
    private function join(string $head, string $tail): string
    {
        return $tail === '' ? $head : $head.' '.$tail;
    }

    private function delta(int|float $now, int|float $then): string
    {
        if ($then === 0 || $then === 0.0) {
            return $now > 0 ? '(новое)' : '';
        }

        $change = (int) round((($now - $then) / $then) * 100);

        return match (true) {
            $change > 0 => '(+'.$change.'%)',
            $change < 0 => '('.$change.'%)',
            default => '(=)',
        };
    }

    private function pct(?float $value): string
    {
        return $value === null ? '—' : round($value, 1).'%';
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round(($part / $whole) * 100, 1);
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
