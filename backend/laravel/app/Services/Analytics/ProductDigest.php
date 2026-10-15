<?php

namespace App\Services\Analytics;

use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Services\Console\ConsoleFeed;
use App\Services\GasSponsorService;
use App\Services\Monitoring\HeartbeatFleet;
use App\Services\Monitoring\IncidentText;
use App\Services\Monitoring\ServiceRegistry;
use App\Services\Monitoring\ServiceStatus;
use App\Support\ChainActivity;
use App\Support\Handles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The morning report, composed for an operator reading a phone.
 *
 * The first version was the dashboard read aloud — North Star, funnel,
 * features, gamification, Telegram, on-chain, acquisition, gas — and it was
 * not read, because almost all of it was the same every morning: WAFU 0, the
 * funnel stopping at "funded" again, 658 chat members, 840 people owed rewards.
 * A line that says yesterday's thing teaches the reader to skip the message
 * that carries it. So this one is built the other way round:
 *
 *  - **What needs a person comes first**: services that are broken right now
 *    (with *why*, in the container's own words), the host's day in one line
 *    with what is eating it, the short failures nobody was paged about, and
 *    the console's own queue — overdue tasks, failed bridges, a new whale.
 *  - **Then what people did, with names and money**: pump.fun buys in dollars
 *    and new holders, swaps, domains — who, how much, how many were new —
 *    instead of a count per event kind.
 *  - **Everything else appears only when it moved.** A section with nothing in
 *    it is not printed at all; a total that did not change is not repeated.
 *    The wallet funnel is one line and marks its own weakest step.
 *  - A day with nothing in it says so in one sentence.
 *
 * Reads only. It signs nothing, sends nothing on its own, and a table it does
 * not find is a missing section rather than an error: the bot's tables belong
 * to another program that may not be deployed beside this one.
 */
class ProductDigest
{
    /** Event kinds as a reader says them, for the on-chain section. */
    private const KINDS = [
        'pumpfun_buy' => 'pump.fun, покупки CYBER.sol',
        'swap' => 'Обмены',
        'liq_add' => 'Ликвидность внесли',
        'liq_remove' => 'Ликвидность забрали',
        'lend_supplied' => 'Лендинг: внесли',
        'lend_withdrew' => 'Лендинг: вывели',
        'lend_borrowed' => 'Лендинг: заняли',
        'lend_repaid' => 'Лендинг: погасили',
        'stake' => 'Стейкинг',
        'unstake' => 'Сняли со стейкинга',
        'convert' => 'Конвертации CYBER.sol',
        'bridge' => 'Мост',
        'nft_mint' => 'NFT выпустили',
        'nft_sale' => 'NFT купили',
        'domain' => 'Домены',
        'zone' => 'Доменные зоны',
        'predict_market' => 'Рынки прогнозов',
        'predict_bet' => 'Ставки на прогнозы',
    ];

    private const FEATURES = [
        'swap' => 'обмен',
        'bridge' => 'мост',
        'send' => 'отправка',
        'staking' => 'стейкинг',
        'liquidity' => 'ликвидность',
        'nft' => 'NFT',
    ];

    public function __construct(
        private readonly ProductMetricsService $metrics,
        private readonly GasSponsorService $sponsor,
        private readonly ServiceRegistry $registry,
        private readonly ConsoleFeed $console,
    ) {}

    /**
     * Build the report.
     *
     * @return array{title: string, period: string, sections: array<int, array{title: string, lines: array<int, string>}>}
     */
    public function build(int $days = 1, ?Carbon $now = null): array
    {
        $to = ($now ?? Carbon::now('UTC'))->copy();
        $from = $to->copy()->subDays($days);
        $local = $to->copy()->setTimezone((string) config('crm.console.timezone', 'Europe/Moscow'));

        $current = new AnalyticsFilters(from: $from, to: $to);
        $previous = new AnalyticsFilters(from: $from->copy()->subDays($days), to: $from->copy());

        $sections = array_values(array_filter([
            $this->attention($from, $to),
            $this->onchain($from, $to, $days),
            $this->people($from, $to),
            $this->wallet($current, $previous),
            $this->gamification($from, $to),
            $this->gasStation($current),
        ]));

        if ($sections === []) {
            $sections[] = ['title' => '😴 Тихо', 'lines' => [
                'Ничего не сломалось, и никто ничего не сделал — ни на чейне, ни в кошельке.',
            ]];
        }

        return [
            'title' => ($days === 1 ? '📊 Cyberia · сутки' : "📊 Cyberia · {$days} дн.")
                .' до '.$local->format('d.m H:i'),
            'period' => $from->toDateTimeString().' → '.$to->toDateTimeString().' UTC',
            'sections' => $sections,
        ];
    }

    /** The report as one Telegram message. */
    public function toTelegram(int $days = 1, ?Carbon $now = null): string
    {
        $report = $this->build($days, $now);

        $out = ['<b>'.$this->e($report['title']).'</b>'];

        foreach ($report['sections'] as $section) {
            $out[] = '';
            $out[] = '<b>'.$this->e($section['title']).'</b>';

            foreach ($section['lines'] as $line) {
                $out[] = $this->e($line);
            }
        }

        $out[] = '';
        $out[] = '<a href="'.$this->e(url('/crm')).'">Пульт</a>';

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

    /* ------------------------------------------------- needs a person -- */

    /**
     * What is broken, and what is waiting on somebody.
     *
     * Open incidents are listed every morning for as long as they stay open —
     * this is where a non-critical outage lives now that the alert channel
     * stopped repeating it twice a day — with the cause the host sent.
     */
    private function attention(Carbon $from, Carbon $to): array
    {
        $lines = [
            ...$this->openIncidents(),
            ...$this->hostDay($from, $to),
            ...$this->blips($from, $to),
            ...$this->queue($from),
        ];

        return $lines === [] ? [] : ['title' => '⚠️ Требует внимания', 'lines' => $lines];
    }

    /** @return array<int, string> */
    private function openIncidents(): array
    {
        if (! Schema::hasTable('service_incidents')) {
            return [];
        }

        $incidents = ServiceIncident::query()->open()->get()
            ->map(fn (ServiceIncident $incident) => [$incident, $this->registry->find($incident->service)])
            // Critical first, then the longest-running: a three-week outage of
            // something minor still outranks nothing, but not the site.
            ->sortBy(fn (array $pair) => [
                $pair[1]?->critical ? 0 : 1,
                -$pair[0]->durationSeconds(),
            ]);

        $lines = [];

        foreach ($incidents as [$incident, $definition]) {
            $status = ServiceStatus::tryFrom((string) $incident->status) ?? ServiceStatus::Down;
            $reason = IncidentText::reason($incident->reason);

            $lines[] = $status->emoji().' '.($definition?->label ?? $incident->service).' '
                .IncidentText::status($status).' '.IncidentText::duration($incident->durationSeconds())
                .($reason === '' ? '' : ': '.$reason);

            foreach (IncidentText::facts($this->latestDetail($incident)) as $fact) {
                $lines[] = '    '.$fact;
            }
        }

        return $lines;
    }

    /**
     * What the last sweep saw, rather than what was written on the incident
     * the day it opened: a three-week outage described by its first five
     * minutes said "15 restarts" while the counter stood at 34 thousand.
     *
     * @return array<string, mixed>
     */
    private function latestDetail(ServiceIncident $incident): array
    {
        $check = ServiceCheck::query()
            ->where('service', $incident->service)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first(['detail', 'status']);

        $detail = $check !== null && ServiceStatus::tryFrom((string) $check->status)?->isIncident() && is_array($check->detail)
            ? $check->detail
            : $incident->detail;

        return is_array($detail) ? $detail : [];
    }

    /**
     * The host's whole day in one line, when the day was heavy.
     *
     * A host that sits at three quarters of its alert threshold all day never
     * opens an incident and never gets better either; that is a fact about
     * the machine, and it belongs here once a day rather than nowhere.
     *
     * @return array<int, string>
     */
    private function hostDay(Carbon $from, Carbon $to): array
    {
        $definition = $this->registry->find('host');

        if ($definition === null || ! Schema::hasTable('service_checks')) {
            return [];
        }

        // Already listed above with its own facts.
        if (ServiceIncident::query()->open()->where('service', 'host')->exists()) {
            return [];
        }

        $loads = [];
        $cpus = 1;

        foreach (ServiceCheck::query()
            ->where('service', 'host')
            ->whereBetween('checked_at', [$from, $to])
            ->get(['detail']) as $check) {
            $detail = is_array($check->detail) ? $check->detail : [];

            if (! is_array($detail['load'] ?? null)) {
                continue;
            }

            $loads[] = (float) ($detail['load'][1] ?? $detail['load'][0] ?? 0);
            $cpus = max(1, (int) ($detail['cpus'] ?? $cpus));
        }

        if ($loads === []) {
            return [];
        }

        $average = array_sum($loads) / count($loads);
        $limit = (float) $definition->checkOption('max_load_per_cpu', 3.0);

        if ($average / $cpus < $limit * 0.6) {
            return [];
        }

        $lines = ['🟡 Хост загружен весь день: нагрузка в среднем '.round($average, 1).' на '.$cpus
            .' '.$this->plural($cpus, 'ядро', 'ядра', 'ядер').' ('.round($average / $cpus, 1).' на ядро, порог '.$limit.'), пик '.round(max($loads), 1)];

        $top = IncidentText::top(HeartbeatFleet::load()->for(null)->metrics()['top'] ?? null);

        if ($top !== null) {
            $lines[] = '    '.$top;
        }

        return $lines;
    }

    /**
     * Incidents that opened and closed inside the window without anyone being
     * told — the quiet period swallowed them, which is right for each one and
     * wrong for the pattern. Seven of them in a day is a fact worth knowing.
     *
     * @return array<int, string>
     */
    private function blips(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('service_incidents')) {
            return [];
        }

        $rows = ServiceIncident::query()
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('resolved_at')
            ->whereNull('notified_at')
            ->get();

        $lines = [];

        foreach ($rows->groupBy('service') as $service => $incidents) {
            $label = $this->registry->find((string) $service)?->label ?? $service;
            $minutes = (int) round($incidents->avg(fn (ServiceIncident $i) => $i->durationSeconds()) / 60);
            $reason = IncidentText::reason($incidents->last()->reason);

            $lines[] = '↯ '.$label.': '.$incidents->count().' '
                .$this->plural($incidents->count(), 'короткий сбой', 'коротких сбоя', 'коротких сбоев')
                .' (в среднем '.$minutes.' мин'.($reason === '' ? '' : ', '.$reason).'), без уведомлений';
        }

        return $lines;
    }

    /**
     * The console's own queue — the same judgement "Сейчас" makes, minus the
     * incidents (above, with more detail) and minus whatever an operator put
     * down until later. Read in a guard: the report must not fail because one
     * of the console's sources did.
     *
     * @return array<int, string>
     */
    private function queue(Carbon $from): array
    {
        try {
            $items = $this->console->cached()['attention'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('Digest could not read the console queue', ['error' => $e->getMessage()]);

            return [];
        }

        $lines = [];
        $stale = [];

        foreach ($items as $item) {
            $p = $item['params'] ?? [];
            $evidence = $item['evidence'] ?? [];
            $since = isset($item['since']) ? Carbon::parse($item['since']) : null;

            if (($item['kind'] ?? null) === 'task' && ($since === null || $since->lt($from))) {
                // Overdue before today: already said yesterday. One line for
                // all of them, so a month-old backlog cannot push today's news
                // off the screen — the board is where they are worked on.
                $stale[] = $item;

                continue;
            }

            $line = match ($item['kind'] ?? null) {
                'task' => '📌 Сегодня просрочена задача «'.Str::limit((string) $p['task'], 70).'»'
                    .(($p['assignee'] ?? '') !== '' ? ' — '.$p['assignee'] : ''),
                // A whale nobody can price or name is a row, not news.
                'whale' => (float) ($evidence['value'] ?? 0) > 0
                    ? '🐋 Новый кит: '.$p['name'].' — $'.number_format((float) $evidence['value'], 0, '.', ' ')
                    : null,
                // The console warns early; the morning only when it is close.
                'gas' => (int) ($p['drips'] ?? 0) < 20
                    ? '⛽ Газ-станция почти пуста: '.$p['tank'].' CYBER, это ~'.$p['drips'].' капель'
                    : null,
                'retention' => '📉 Удержание D7 упало: '.$p['now'].'% (было '.$p['before'].'%), когорта '.$p['cohort'],
                'bridge' => '🌉 Переводов через мост упало: '.$p['count'].', последний — '
                    .$p['amount'].' '.$p['token'].' ('.$p['direction'].')',
                default => null,
            };

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($stale !== []) {
            $oldest = collect($stale)->sortBy('since')->first();
            $people = collect($stale)->pluck('params.assignee')->filter()->countBy()
                ->map(fn (int $n, string $who) => $who.' '.$n)->implode(', ');

            $lines[] = '📌 Давно просрочено задач: '.count($stale)
                .($people !== '' ? ' ('.$people.')' : '')
                .', самая старая — «'.Str::limit((string) ($oldest['params']['task'] ?? ''), 50).'»'
                .(isset($oldest['since']) ? ' с '.Carbon::parse($oldest['since'])->format('d.m') : '');
        }

        return $lines;
    }

    /* ----------------------------------------------- people and money -- */

    /**
     * On-chain activity as the indexer saw it — the only section that can see
     * a person who never touched our frontend — said as money and people
     * rather than a count per event kind.
     */
    private function onchain(Carbon $from, Carbon $to, int $days): array
    {
        if (! Schema::hasTable('activity_events')) {
            return [];
        }

        $rows = DB::table('activity_events')->whereBetween('created_at', [$from, $to])->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $was = DB::table('activity_events')
            ->whereBetween('created_at', [$from->copy()->subDays($days), $from])
            ->count();

        $addresses = $rows->pluck('user_addr')->filter()->unique();
        $seenBefore = DB::table('activity_events')
            ->where('created_at', '<', $from)
            ->whereIn('user_addr', $addresses->all())
            ->distinct()
            ->pluck('user_addr');
        $new = $addresses->diff($seenBefore)->count();
        $usd = (float) $rows->sum(fn ($row) => (float) ($row->usd ?? 0));

        $lines = [
            $this->join(
                $rows->count().' '.$this->plural($rows->count(), 'действие', 'действия', 'действий')
                    .' от '.$addresses->count().' '.$this->plural($addresses->count(), 'адреса', 'адресов', 'адресов')
                    .($new > 0 ? ' ('.$new.' '.$this->plural($new, 'новый', 'новых', 'новых').')' : '')
                    .($usd > 0 ? ', '.$this->usd($usd) : ''),
                $this->delta($rows->count(), $was),
            ),
        ];

        foreach ($rows->groupBy('kind')->sortByDesc(fn ($group) => $group->sum(fn ($row) => (float) ($row->usd ?? 0)) * 1e6 + $group->count()) as $kind => $group) {
            $sum = (float) $group->sum(fn ($row) => (float) ($row->usd ?? 0));
            $people = $group->pluck('user_addr')->filter()->unique()->count();
            $parts = [
                $group->count().($sum > 0 ? ' на '.$this->usd($sum) : '')
                    .' от '.$people.' '.$this->plural($people, 'адреса', 'адресов', 'адресов'),
            ];

            $holders = $group->where('meta', 'new_holder')->count();

            if ($holders > 0) {
                $parts[] = $holders.' '.$this->plural($holders, 'новый холдер', 'новых холдера', 'новых холдеров');
            }

            $biggest = $group->sortByDesc(fn ($row) => (float) ($row->usd ?? 0))->first();

            if ($biggest !== null && (float) ($biggest->usd ?? 0) >= 10 && $group->count() > 1) {
                $parts[] = 'крупнейшая '.$this->usd((float) $biggest->usd).' — '.$this->who($biggest->user_addr);
            } elseif ($group->count() === 1 && $biggest?->user_addr) {
                $detail = ChainActivity::detail($biggest);
                $parts[] = $this->who($biggest->user_addr).($detail !== null ? ' ('.$detail.')' : '');
            }

            $lines[] = '· '.(self::KINDS[$kind] ?? $kind).': '.implode(' · ', $parts);
        }

        return ['title' => '⛓ На чейне', 'lines' => $lines];
    }

    /**
     * New people on the site and in the Telegram chats — only the change. The
     * totals are on the console; repeating "658 members" every morning is
     * the line that taught everyone to skip this message.
     */
    private function people(Carbon $from, Carbon $to): array
    {
        $lines = [];

        if (Schema::hasTable('users')) {
            $accounts = DB::table('users')->whereBetween('created_at', [$from, $to])->count();

            if ($accounts > 0) {
                $lines[] = 'Новых аккаунтов на сайте: '.$accounts.' (всего '.DB::table('users')->count().')';
            }
        }

        if (Schema::hasTable('chat_members') && Schema::hasTable('tg_wallets')) {
            $window = [$from->toDateTimeString(), $to->toDateTimeString()];
            $joined = DB::table('chat_members')->whereBetween('first_seen', $window)->distinct()->count('user_id');
            $linked = DB::table('tg_wallets')->whereBetween('created_at', $window)->count();

            if ($joined > 0 || $linked > 0) {
                $members = DB::table('chat_members')->distinct()->count('user_id');
                $wallets = DB::table('chat_members')
                    ->whereIn('user_id', DB::table('tg_wallets')->select('user_id'))
                    ->distinct()
                    ->count('user_id');

                $lines[] = 'Telegram: '.implode(', ', array_filter([
                    $joined > 0 ? '+'.$joined.' в чатах' : null,
                    $linked > 0 ? '+'.$linked.' '.$this->plural($linked, 'привязал', 'привязали', 'привязали').' кошелёк' : null,
                ])).' — с кошельком '.$wallets.' из '.$members.' ('.$this->pct($this->rate($wallets, $members)).')';
            }
        }

        return $lines === [] ? [] : ['title' => '👥 Новые люди', 'lines' => $lines];
    }

    /* ----------------------------------------------------- the product -- */

    /**
     * The wallet's funnel as one line, its weakest step marked, plus the
     * features somebody actually used. Absent on a day nobody opened it.
     */
    private function wallet(AnalyticsFilters $current, AnalyticsFilters $previous): array
    {
        $steps = $this->metrics->mainFunnel($current);
        $wafu = $this->metrics->weeklyActiveFundedUsers($current);
        $wafuWas = $this->metrics->weeklyActiveFundedUsers($previous);
        $labels = [
            'first_open' => 'установки',
            'wallet' => 'кошелёк',
            'funded' => 'пополнили',
            'activated' => 'действие',
            'retained' => 'вернулись',
        ];

        $lines = [];

        if (($steps[0]['value'] ?? 0) > 0) {
            // The weakest step is the biggest proportional loss, and only
            // among steps that had somebody to lose — a 0 → 0 transition is an
            // empty funnel, not a conversion problem.
            $worst = null;

            foreach ($steps as $i => $step) {
                if ($step['of_previous'] !== null && ($worst === null || $step['of_previous'] < $steps[$worst]['of_previous'])) {
                    $worst = $i;
                }
            }

            $parts = [];

            foreach ($steps as $i => $step) {
                $parts[] = ($i === $worst ? '⚠️ ' : '').($labels[$step['key']] ?? $step['key']).' '.$step['value'];
            }

            $lines[] = implode(' → ', $parts);
        }

        if ($wafu > 0 || $wafuWas > 0) {
            $lines[] = $this->join('WAFU (активные пополненные за 7 дн.): '.$wafu, $this->delta($wafu, $wafuWas));
        }

        $before = collect($this->metrics->productUsage($previous))->keyBy('feature');
        $used = [];

        foreach ($this->metrics->productUsage($current) as $row) {
            if ($row['actions'] === 0) {
                continue;
            }

            $rate = $row['success_rate'] === null || $row['success_rate'] >= 100 ? '' : ', успех '.$this->pct($row['success_rate']);
            $used[] = $this->join(
                (self::FEATURES[$row['feature']] ?? $row['feature']).' '.$row['actions'],
                $this->delta($row['actions'], (int) ($before[$row['feature']]['actions'] ?? 0)),
            ).$rate;
        }

        if ($used !== []) {
            $lines[] = 'Действия: '.implode(', ', $used);
        }

        foreach (array_slice($this->metrics->acquisition($current, 5), 0, 5) as $row) {
            // Direct visits are the default, not a channel; a campaign is.
            if ($row['source'] === 'direct') {
                continue;
            }

            $lines[] = 'Пришли из '.$row['source'].': '.$row['users'].' → кошелёк '.$row['wallets'].' → пополнили '.$row['funded'];
        }

        return $lines === [] ? [] : ['title' => '📱 Кошелёк', 'lines' => $lines];
    }

    /**
     * The quest system in one line — on the days anyone played.
     */
    private function gamification(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('xp_entries')) {
            return [];
        }

        $window = fn () => DB::table('xp_entries')->whereBetween('created_at', [$from, $to]);
        $players = $window()->distinct()->count('user_id');

        if ($players === 0) {
            return [];
        }

        $parts = [
            $players.' '.$this->plural($players, 'игрок', 'игрока', 'игроков'),
            ((int) $window()->sum('amount')).' XP',
        ];

        if (Schema::hasTable('user_quests')) {
            $done = DB::table('user_quests')->whereNotNull('completed_at')->whereBetween('completed_at', [$from, $to])->count();

            if ($done > 0) {
                $parts[] = $done.' '.$this->plural($done, 'задание выполнено', 'задания выполнено', 'заданий выполнено');
            }
        }

        return ['title' => '🎮 Квесты', 'lines' => [implode(', ', $parts)]];
    }

    /**
     * Only when the station did something. An empty or low tank is already
     * in the attention section, from the console's own judgement of it.
     */
    private function gasStation(AnalyticsFilters $current): array
    {
        if (! $this->sponsor->enabled()) {
            return [];
        }

        $gas = $this->metrics->gasSponsorship($current);

        if ($gas['transactions'] === 0) {
            return [];
        }

        return ['title' => '⛽ Газ-станция', 'lines' => [
            'Выдала '.$gas['transactions'].' '.$this->plural($gas['transactions'], 'каплю', 'капли', 'капель')
                .' на '.$gas['addresses'].' '.$this->plural($gas['addresses'], 'адрес', 'адреса', 'адресов'),
        ]];
    }

    /* ------------------------------------------------------------- helpers -- */

    /**
     * A name for an address where the console knows one — a CRM contact by
     * either of its addresses — and the shortened address otherwise.
     */
    private function who(?string $address): string
    {
        $address = trim((string) $address);

        if ($address === '') {
            return '?';
        }

        if (Schema::hasTable('crm_contacts')) {
            $contact = DB::table('crm_contacts')
                ->whereNull('deleted_at')
                ->where(fn ($query) => $query
                    ->whereRaw('lower(evm_address) = ?', [strtolower($address)])
                    ->orWhere('solana_address', $address))
                ->first(['name', 'telegram', 'x_handle']);

            // The whale sync files numeric Telegram ids in that column, and
            // "@7687744665" names nobody.
            $telegram = Handles::telegramUsername($contact?->telegram);
            $name = $contact->name ?? null;
            $name = $name ?: ($telegram !== null ? '@'.$telegram : null);
            $name = $name ?: ($contact?->x_handle ? 'x.com/'.$contact->x_handle : null);

            if ($name) {
                return $name.' ('.ChainActivity::short($address).')';
            }
        }

        return ChainActivity::short($address);
    }

    private function usd(float $value): string
    {
        return '$'.number_format($value, $value >= 100 ? 0 : 2, '.', ' ');
    }

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

    private function plural(int $n, string $one, string $few, string $many): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => $one,
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => $few,
            default => $many,
        };
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
