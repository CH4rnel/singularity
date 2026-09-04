<?php

namespace App\Services\Console;

use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\ServiceCheck;
use App\Services\CyberiaPrices;
use App\Services\GasSponsorService;
use App\Services\Monitoring\ServiceRegistry;
use App\Services\WalletPriceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Состояние проекта одним блоком — то, что пульт и так знает, сказанное словами.
 *
 * The room used to hand LainOS twenty lines of chat and nothing else, and then
 * told it not to invent numbers. Both halves were right, and together they made
 * one useless correspondent: every question about the project — is anything
 * down, what is the tank at, what does CYBER cost, did a bridge request get
 * stuck — came back as "я этого не вижу, посмотри в линзе". The console is the
 * one thing here that *can* see all of it, so now it says so before it asks.
 *
 * Three rules, the same three the lenses hold to:
 *
 *  - **Read the caches the lenses share.** The queue is ConsoleFeed's own
 *    cached build and the machine states are the last sweep's rows, so LainOS
 *    and the operator reading the screen beside it cannot end up quoting two
 *    different numbers at each other.
 *  - **Nothing unreadable becomes a zero.** An unreadable price, an absent
 *    indexer table and an empty tank are three different facts; the first two
 *    say "не прочитано" and "нет данных". A model handed a zero explains the
 *    zero.
 *  - **Everything carries its age.** A briefing without timestamps keeps
 *    sounding true after its collector dies, so the sweep, the quotes, the
 *    pool snapshot and the sync each print when they last ran.
 *
 * Read-only, and cheap on purpose. The head and the indexer lag are the ones
 * the monitor already fetched in its sweep, the prices are the wallet's own
 * five-minute quotes, the pools are the indexer's snapshot; the single chain
 * read is the gas station's `summary()`, cached for thirty seconds and already
 * performed by the queue this briefing opens with. That is what keeps one chat
 * message from fanning out into a dozen RPC requests.
 */
class ConsoleBriefing
{
    public const CACHE_KEY = 'crm.console.briefing';

    /** How long one composition is reused. Shorter than a person can read it. */
    private const TTL = 60;

    /** Feed kinds in the operators' own words; an unknown kind prints raw. */
    private const KINDS = [
        'incident' => 'инцидент',
        'task' => 'просроченная задача',
        'whale' => 'кит',
        'gas' => 'бак заправки',
        'retention' => 'удержание',
        'bridge' => 'мост',
    ];

    private const SEVERITIES = [
        'critical' => 'критично',
        'warning' => 'тревога',
        'money' => 'деньги',
        'neutral' => 'к сведению',
        'unknown' => 'нет данных',
    ];

    public function __construct(
        private ConsoleFeed $feed,
        private ServiceRegistry $registry,
        private GasSponsorService $gas,
        private WalletPriceService $prices,
        private CyberiaPrices $poolPrices,
    ) {}

    /**
     * The briefing as the room hands it over.
     *
     * @return array{at: string, sections: list<array{title: string, lines: list<string>}>}
     */
    public function cached(): array
    {
        /** @var array{at: string, sections: list<array{title: string, lines: list<string>}>} */
        return Cache::remember(self::CACHE_KEY, self::TTL, fn (): array => $this->build());
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{at: string, sections: list<array{title: string, lines: list<string>}>}
     */
    public function build(): array
    {
        return [
            'at' => CarbonImmutable::now()->toIso8601String(),
            'sections' => array_values(array_filter([
                $this->queue(),
                $this->machines(),
                $this->chain(),
                $this->bridge(),
                $this->product(),
                $this->board(),
            ], fn (?array $section): bool => $section !== null)),
        ];
    }

    /**
     * The same thing as plain text, which is the only form a model reads.
     *
     * @param  array{at: string, sections: list<array{title: string, lines: list<string>}>}|null  $briefing
     */
    public function toText(?array $briefing = null): string
    {
        $briefing ??= $this->cached();
        $out = [];

        foreach ($briefing['sections'] as $section) {
            $out[] = '## '.$section['title'];
            foreach ($section['lines'] as $line) {
                $out[] = $line;
            }
            $out[] = '';
        }

        return trim(implode("\n", $out));
    }

    /* -------------------------------------------------------------- Сейчас -- */

    /**
     * The queue, straight out of the build the badge and the banner render.
     *
     * Rendered generically — severity, kind, time in state, then the item's own
     * parameters — rather than through per-kind sentences. The browser holds
     * two dictionaries for these rows and a third copy here would drift from
     * both; a new feed kind must also be readable the day it is added, not the
     * day somebody remembers to phrase it.
     *
     * @return array{title: string, lines: list<string>}
     */
    private function queue(): array
    {
        $queue = $this->feed->cached();
        $attention = $queue['attention'] ?? [];
        $quiet = $queue['quiet'] ?? [];

        if ($attention === []) {
            return [
                'title' => 'Очередь пульта («Сейчас»)',
                'lines' => [
                    'Пусто — ничего не требует человека.',
                    'Последний обход: '.$this->since($quiet['last_sweep'] ?? null).'; ответили '
                        .(int) ($quiet['answered'] ?? 0).' из '.(int) ($quiet['registered'] ?? 0).' сервисов.',
                ],
            ];
        }

        $lines = ['Требуют человека: '.count($attention).'.'];

        foreach (array_slice($attention, 0, 12) as $item) {
            $lines[] = '· '.implode(' · ', array_filter([
                self::SEVERITIES[$item['severity'] ?? ''] ?? (string) ($item['severity'] ?? ''),
                self::KINDS[$item['kind'] ?? ''] ?? (string) ($item['kind'] ?? ''),
                $this->duration($item['duration_seconds'] ?? null),
                $this->params($item['params'] ?? []),
            ]));
        }

        if (count($attention) > 12) {
            $lines[] = '· … и ещё '.(count($attention) - 12).' в очереди.';
        }

        $sleeping = count($queue['watch'] ?? []);

        if ($sleeping > 0) {
            $lines[] = 'Отложено до утра: '.$sleeping.'.';
        }

        return ['title' => 'Очередь пульта («Сейчас»)', 'lines' => $lines];
    }

    /* -------------------------------------------------------------- Машины -- */

    /**
     * What the last sweep found, by group, with the unhealthy named.
     *
     * `unknown` is counted apart from both halves and never called down: a
     * reporter that died says nothing about what it was reporting on, and one
     * dead heartbeat printed as an outage is twenty invented ones.
     *
     * @return array{title: string, lines: list<string>}
     */
    private function machines(): array
    {
        $latest = $this->latestChecks();
        $registered = count($this->registry->all());
        $lines = [];
        $bad = [];
        $silent = [];

        foreach ($this->registry->grouped() as $group => $definitions) {
            $counts = ['up' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0, 'off' => 0];

            foreach ($definitions as $definition) {
                $check = $latest[$definition->key] ?? null;
                $status = $check?->status ?? 'unknown';
                $counts[$status] = ($counts[$status] ?? 0) + 1;

                if ($status === 'unknown') {
                    $silent[] = $definition->label;
                }

                if (in_array($status, ['down', 'degraded'], true)) {
                    $detail = (array) ($check?->detail ?? []);
                    $reason = is_string($detail['reason'] ?? null) ? $detail['reason'] : null;

                    $bad[] = '· '.$definition->label.': '.$status
                        .($reason !== null ? ' ('.$reason.')' : '')
                        .($definition->critical ? ' [критичный]' : '');
                }
            }

            $lines[] = $group.': '.$counts['up'].' из '.count($definitions).' здоровы'
                .($counts['down'] > 0 ? ', лежат '.$counts['down'] : '')
                .($counts['degraded'] > 0 ? ', деградируют '.$counts['degraded'] : '')
                .($counts['unknown'] > 0 ? ', молчат '.$counts['unknown'] : '')
                .'.';
        }

        $sweep = DB::table('service_checks')->max('checked_at');
        $lines[] = 'Последний обход: '.$this->since($sweep ? CarbonImmutable::parse($sweep)->toIso8601String() : null).'.';

        /*
         * What is wrong gets named, and so does what is *silent* — but they
         * are two different statements and only one of them is an outage.
         *
         * The whole registry reading unknown is the monitor having stopped,
         * and it is the only case where that is worth saying: six silent
         * reporters under a sweep that ran four minutes ago is six dead
         * reporters, and blaming the sweep for them is the same kind of lie
         * this briefing exists not to tell.
         */
        if ($silent !== [] && count($silent) === $registered) {
            $lines[] = 'Молчат все '.$registered.' — встал сам обход, а не сервисы.';
        } elseif ($silent !== []) {
            $lines[] = 'Молчат '.count($silent).': '.implode(', ', array_slice($silent, 0, 8))
                .(count($silent) > 8 ? ' и ещё '.(count($silent) - 8) : '')
                .' — это молчание репортёра, а не падение сервиса.';
        }

        return ['title' => 'Машины', 'lines' => [...$lines, ...array_slice($bad, 0, 12)]];
    }

    /* ---------------------------------------------------------------- Цепь -- */

    /**
     * The chain as of the last sweep, plus what the coin is worth.
     *
     * Nothing here is fetched for the briefing. The head and the indexer lag
     * were read by the monitor minutes ago; the prices are the wallet's own
     * five-minute quotes; the pool figures are the indexer's snapshot. Anything
     * fresher than that is a tool call, and only the daemon has tools.
     *
     * @return array{title: string, lines: list<string>}
     */
    private function chain(): array
    {
        $latest = $this->latestChecks();
        $lines = [];

        $rpc = $latest['cyberia-rpc'] ?? null;

        if ($rpc !== null) {
            $detail = (array) ($rpc->detail ?? []);
            $lines[] = 'Cyberia (49406): статус '.$rpc->status
                .', блок '.($detail['block'] ?? 'не прочитан')
                .', голова обновлялась '.(isset($detail['head_age_seconds'])
                    ? $this->duration((int) $detail['head_age_seconds']).' назад'
                    : 'неизвестно когда')
                .', RPC отвечает за '.($rpc->latency_ms !== null ? $rpc->latency_ms.' мс' : '—')
                .' (замер '.$this->since($rpc->checked_at?->toIso8601String()).').';
        } else {
            $lines[] = 'Cyberia (49406): обход ещё не читал ноду — состояние цепи неизвестно.';
        }

        $explorer = $latest['explorer'] ?? null;

        if ($explorer !== null) {
            $detail = (array) ($explorer->detail ?? []);
            $lines[] = 'Blockscout: статус '.$explorer->status
                .', проиндексирован блок '.($detail['indexed_block'] ?? 'не прочитан')
                .(isset($detail['lag_blocks']) ? ', отставание '.$detail['lag_blocks'].' блоков' : '').'.';
        }

        foreach ($this->money() as $line) {
            $lines[] = $line;
        }

        foreach ($this->station() as $line) {
            $lines[] = $line;
        }

        return ['title' => 'Цепь', 'lines' => $lines];
    }

    /**
     * Prices and pools: the coin, the tokens with a route to a dollar, and how
     * deep the pools behind those prices are.
     *
     * @return list<string>
     */
    private function money(): array
    {
        $quotes = $this->prices->quotes();
        $cyber = $quotes['prices']['cyberia'] ?? null;

        $lines = [
            'CYBER: '.($cyber === null ? 'цена не прочитана' : '$'.$this->usd((float) $cyber))
                .' (котировки от '.$this->since($quotes['fetchedAt'] ?? null).').',
        ];

        if (! Schema::hasTable('dex_pools')) {
            $lines[] = 'Пулы Ritual: снимка индексатора нет на этой машине — цены токенов и TVL недоступны.';

            return $lines;
        }

        $pools = DB::table('dex_pools')->get();

        if ($pools->isEmpty()) {
            $lines[] = 'Пулы Ritual: снимок пуст — индексатор ничего не записал.';

            return $lines;
        }

        $priced = $this->poolPrices->priceFromPools($pools);
        $symbols = $this->poolPrices->poolSymbols($pools);
        $tvl = 0.0;
        $unpriced = 0;

        foreach ($pools as $pool) {
            $value = $this->poolPrices->poolTvl($pool, $priced);

            if ($value === null) {
                $unpriced++;

                continue;
            }

            $tvl += $value;
        }

        $snapshot = DB::table('dex_pools')->max('updated_at');

        $lines[] = 'Пулы Ritual: '.$pools->count().' шт., TVL ≈ $'.$this->usd($tvl)
            .($unpriced > 0 ? ' (без цены '.$unpriced.')' : '')
            .', снимок от '.$this->since($snapshot ? CarbonImmutable::parse($snapshot)->toIso8601String() : null).'.';

        $named = [];
        $zeroed = [];

        foreach ($priced as $address => $price) {
            $symbol = $symbols[$address] ?? null;

            if ($symbol === null || $symbol === '') {
                continue;
            }

            // A price of exactly zero is a walk that reached the token through
            // an empty side, not a token worth nothing. It is named apart so a
            // reader does not quote "$0" as a market fact.
            if ((float) $price > 0) {
                $named[$symbol] = (float) $price;

                continue;
            }

            $zeroed[] = $symbol;
        }

        ksort($named);
        sort($zeroed);

        if ($zeroed !== []) {
            $lines[] = 'Без внятной цены (пул пуст с одной стороны): '.implode(', ', array_unique($zeroed)).'.';
        }

        if ($named !== []) {
            $lines[] = 'Цены токенов: '.implode(', ', array_map(
                fn (string $symbol, float $price): string => $symbol.' $'.$this->usd($price),
                array_keys(array_slice($named, 0, 14, true)),
                array_values(array_slice($named, 0, 14, true)),
            )).(count($named) > 14 ? ' и ещё '.(count($named) - 14) : '').'.';
        }

        return $lines;
    }

    /**
     * The gas station, which is the one contract whose state decides whether a
     * newcomer's first transaction happens at all.
     *
     * @return list<string>
     */
    private function station(): array
    {
        if (! $this->gas->enabled()) {
            return ['Заправка: выключена на этом сервере.'];
        }

        $summary = $this->gas->summary();

        if ($summary === null) {
            return ['Заправка: контракт не прочитан — это проблема RPC, а не пустой бак.'];
        }

        // Every figure the station answers with is wei; an operator says CYBER.
        $tank = (float) $this->gas->cyber($summary['tank']);
        $drip = (float) $this->gas->cyber($summary['drip']);

        return ['Заправка: в баке '.round($tank, 3).' CYBER'
            .($drip > 0 ? ' (≈'.(int) floor($tank / $drip).' заправок по '.$drip.')' : '')
            .', сегодня осталось '.$this->gas->cyber($summary['remainingToday'])
            .' из '.$this->gas->cyber($summary['dailyCap']).' CYBER'
            .', всего выдано '.$summary['served']
            .($summary['paused'] ? ', ПРИОСТАНОВЛЕНА' : '').'.'];
    }

    /* ---------------------------------------------------------------- Мост -- */

    /**
     * The bridge ledger: what is in flight now, and how the last month went.
     *
     * In-flight is anything that is neither finished nor abandoned, which is
     * deliberately defined by exclusion — the relay gained states (paying out,
     * awaiting liquidity, burn pending) after this queue was written, and a
     * list of "the states we know about" would have quietly stopped counting
     * them.
     *
     * @return array{title: string, lines: list<string>}|null
     */
    private function bridge(): ?array
    {
        if (! Schema::hasTable('bridge_requests')) {
            return null;
        }

        $done = ['completed', 'failed', 'expired', 'cancelled', 'awaiting_deposit'];

        $flight = DB::table('bridge_requests')
            ->whereNotIn('status', $done)
            ->selectRaw('count(*) as c, min(created_at) as oldest')
            ->first();

        $lines = [];
        $count = (int) ($flight->c ?? 0);

        $lines[] = $count === 0
            ? 'В работе сейчас: ничего.'
            : 'В работе сейчас: '.$count.', самая старая заявка ждёт '
                .$this->since($flight->oldest ? CarbonImmutable::parse($flight->oldest)->toIso8601String() : null).'.';

        $month = DB::table('bridge_requests')
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(30))
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $lines[] = $month === []
            ? 'За 30 дней: ни одной заявки.'
            : 'За 30 дней: '.implode(', ', array_map(
                fn (string $status, int $c): string => $status.' '.$c,
                array_keys($month),
                array_map('intval', array_values($month)),
            )).'.';

        return ['title' => 'Мост', 'lines' => $lines];
    }

    /* ------------------------------------------------------------- Продукт -- */

    /**
     * The thirty-day floor of the console, as the tiles already computed it.
     *
     * @return array{title: string, lines: list<string>}
     */
    private function product(): array
    {
        $tiles = $this->feed->cached()['tiles'] ?? [];
        $lines = [];

        foreach ($tiles as $tile) {
            $value = $tile['value'] ?? null;
            $printed = match (true) {
                $value === null => 'не прочитано',
                ($tile['unit'] ?? '') === 'usd' => '$'.$this->usd((float) $value),
                ($tile['unit'] ?? '') === 'fraction' => $value.' из '.($tile['of'] ?? '?'),
                default => (string) $value,
            };

            $lines[] = '· '.$this->tileName((string) ($tile['key'] ?? '')).': '.$printed
                .($this->params($tile['params'] ?? []) !== '' ? ' ('.$this->params($tile['params'] ?? []).')' : '');
        }

        return ['title' => 'Продукт за 30 дней', 'lines' => $lines === [] ? ['Плитки не собраны.'] : $lines];
    }

    private function tileName(string $key): string
    {
        return match ($key) {
            'funded_active' => 'активных пополненных кошельков за неделю',
            'installs' => 'установок кошелька',
            'swaps' => 'объём свопов',
            'bridge' => 'переводов через мост',
            'services' => 'сервисов здоровы',
            'tasks' => 'активных задач',
            default => $key,
        };
    }

    /* --------------------------------------------------------------- Доска -- */

    /**
     * The board and the base: what is owed, and how fresh what we know about
     * people is.
     *
     * The sync's own row is what dates the base — `crm_contacts.last_synced_at`
     * is the half-hourly balance refresh talking, and a fresh date over a run
     * that read nothing is the exact lie a freshness date exists to prevent.
     *
     * @return array{title: string, lines: list<string>}
     */
    private function board(): array
    {
        $active = CrmTask::query()->active()->count();
        $overdue = CrmTask::query()->overdue()->count();
        $unassigned = CrmTask::query()->active()->whereNull('assigned_to_user_id')->count();

        $lines = [
            'Задачи: активных '.$active.', просрочено '.$overdue.', без исполнителя '.$unassigned.'.',
        ];

        $machine = CrmTask::query()
            ->where('external_id', 'like', 'lainos:%')
            ->where('created_at', '>=', CarbonImmutable::now()->subDay())
            ->count();

        if ($machine > 0) {
            $lines[] = 'Из них за сутки прислал сам LainOS: '.$machine.'.';
        }

        $byType = CrmContact::query()
            ->selectRaw('type, count(*) as c')
            ->groupBy('type')
            ->pluck('c', 'type')
            ->all();

        $lines[] = $byType === []
            ? 'Люди: база пуста.'
            : 'Люди: '.implode(', ', array_map(
                fn (string $type, int $c): string => $type.' '.$c,
                array_keys($byType),
                array_map('intval', array_values($byType)),
            )).'.';

        if (Schema::hasTable('crm_syncs')) {
            $sync = DB::table('crm_syncs')->orderByDesc('id')->first();

            $lines[] = $sync === null
                ? 'Подгрузка: не запускалась ни разу.'
                : 'Подгрузка: '.$this->since($sync->finished_at
                    ? CarbonImmutable::parse($sync->finished_at)->toIso8601String()
                    : null)
                    .', новых '.(int) $sync->added.', продали '.(int) $sync->sold
                    .($sync->note ? ', неполная: '.$sync->note : '').'.';
        }

        return ['title' => 'Доска и люди', 'lines' => $lines];
    }

    /* ------------------------------------------------------------- helpers -- */

    /**
     * The newest check per service, keyed by service.
     *
     * @return array<string, ServiceCheck>
     */
    private function latestChecks(): array
    {
        $checks = [];

        foreach (ServiceCheck::query()->whereIn('id', ServiceCheck::latestIds())->get() as $check) {
            $checks[$check->service] = $check;
        }

        return $checks;
    }

    /** A feed item's parameters, minus the empties and the flags. */
    private function params(array $params): string
    {
        $parts = [];

        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $parts[] = $key.'='.(is_float($value) ? round($value, 2) : (string) $value);
        }

        return implode(', ', array_slice($parts, 0, 6));
    }

    /** How long ago an ISO instant was, or that it never happened. */
    private function since(?string $iso): string
    {
        if ($iso === null) {
            return 'никогда';
        }

        return $this->duration(max(0, CarbonImmutable::now()->getTimestamp() - CarbonImmutable::parse($iso)->getTimestamp())).' назад';
    }

    /** Seconds as an operator says them. */
    private function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        return match (true) {
            $seconds < 60 => $seconds.' с',
            $seconds < 3600 => intdiv($seconds, 60).' мин',
            $seconds < 86400 => intdiv($seconds, 3600).' ч '.intdiv($seconds % 3600, 60).' мин',
            default => intdiv($seconds, 86400).' дн '.intdiv($seconds % 86400, 3600).' ч',
        };
    }

    /**
     * Dollars, with enough digits that a price is never printed as "0".
     *
     * The last branch is the one that matters: this chain's tokens trade at
     * eight and ten zeros, and a formatter that rounds those to "0" hands a
     * reader the one number that is certainly wrong — a fraction of a cent and
     * nothing at all look identical, and only one of them is worth saying.
     */
    private function usd(float $value): string
    {
        if ($value === 0.0) {
            return '0';
        }

        $small = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return match (true) {
            abs($value) >= 1000 => number_format($value, 0, '.', ' '),
            abs($value) >= 1 => number_format($value, 2, '.', ' '),
            abs($value) >= 0.01 => number_format($value, 4, '.', ''),
            $small !== '0' && $small !== '' => $small,
            default => sprintf('%.2e', $value),
        };
    }
}
