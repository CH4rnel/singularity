<?php

use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\ServiceCheck;
use App\Models\ServiceHeartbeat;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Services\Analytics\ProductDigest;
use App\Services\TelegramOpsNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The daily report and the channel that carries it.
 *
 * What is pinned here is what makes the report worth reading rather than what
 * makes it render: that what is broken comes first and says why, that money
 * and people are named rather than counted per event kind, that a total which
 * did not move is not repeated, that an empty population is a missing section
 * rather than a row of zeros — and that a second operator who has not started
 * the bot cannot silently swallow the whole message.
 */
beforeEach(function () {
    config()->set('services.telegram_ops.bot_token', 'test-token');
    config()->set('services.telegram_ops.chat_id', '111');
    config()->set('services.telegram_ops.analytics_chat_id', null);

    // The report reads USD quotes on its way past the gas station. Those are
    // somebody else's HTTP calls; fake them so the assertions below can be
    // about Telegram and nothing else.
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response([]),
    ]);
});

/** The Python bot's tables, created the way `bot/db.py` creates them. */
function botTables(): void
{
    DB::statement('CREATE TABLE IF NOT EXISTS chat_members (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        first_seen TEXT, last_seen TEXT, PRIMARY KEY (chat_id, user_id))');
    DB::statement('CREATE TABLE IF NOT EXISTS tg_wallets (
        user_id INTEGER PRIMARY KEY, address TEXT NOT NULL, created_at TEXT)');
    DB::statement("CREATE TABLE IF NOT EXISTS pending_rewards (
        chat_id INTEGER NOT NULL, user_id INTEGER NOT NULL,
        amount TEXT NOT NULL DEFAULT '0', updated_at TEXT,
        PRIMARY KEY (chat_id, user_id))");
}

/** Only the calls this feature made, so a price lookup cannot pass for a send. */
function telegramRequests(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'api.telegram.org'))
        ->map(fn (array $pair) => $pair[0])
        ->values()
        ->all();
}

function analyticsUser(array $attributes = []): string
{
    $id = (string) Str::uuid();

    DB::table('analytics_users')->insert(array_merge([
        'id' => $id,
        'created_at' => Carbon::now('UTC')->subHours(2),
        'first_seen_at' => Carbon::now('UTC')->subHours(2),
        'last_seen_at' => Carbon::now('UTC')->subHours(1),
        'platform' => 'web',
        'app_version' => 'v1.0.0',
        'landing_path' => '/wallet',
    ], $attributes));

    return $id;
}

/** The bot's on-chain feed, created the way `bot/db.py` creates it. */
function activityTable(): void
{
    DB::statement('CREATE TABLE IF NOT EXISTS activity_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, usd REAL,
        sym_in TEXT, amt_in REAL, sym_out TEXT, amt_out REAL, user_addr TEXT,
        tx_hash TEXT, block INTEGER, meta TEXT, created_at TEXT)');
}

function activity(string $kind, string $address, ?float $usd, array $extra = []): void
{
    DB::table('activity_events')->insert(array_merge([
        'kind' => $kind,
        'usd' => $usd,
        'user_addr' => $address,
        'tx_hash' => Str::random(40),
        'created_at' => Carbon::now('UTC')->subHours(2)->toDateTimeString(),
    ], $extra));
}

it('marks the weakest step of the funnel on its one line', function () {
    // Three installs, all of which made a wallet, none of which funded it.
    foreach (range(1, 3) as $i) {
        analyticsUser(['wallet_created_at' => Carbon::now('UTC')->subHour()]);
    }

    expect(app(ProductDigest::class)->toText(days: 7))
        ->toContain('установки 3 → кошелёк 3 → ⚠️ пополнили 0');
});

it('leaves the wallet out on a day nobody opened it', function () {
    $text = app(ProductDigest::class)->toText(days: 7);

    expect($text)->not->toContain('КОШЕЛЁК')
        ->and($text)->not->toContain('⚠️ пополнили');
});

it('says a quiet day in one sentence instead of a page of zeros', function () {
    $text = app(ProductDigest::class)->toText(days: 1);

    expect($text)->toContain('Ничего не сломалось')
        ->and($text)->not->toContain(': 0');
});

it('puts a broken service first and says why it is broken', function () {
    config()->set('monitoring.services.sig-provider', [
        'group' => 'infra',
        'label' => 'Blockscout sig-provider',
        'check' => ['type' => 'heartbeat', 'container' => 'sig-provider'],
        'usage' => null,
    ]);

    ServiceIncident::create([
        'service' => 'sig-provider',
        'status' => 'down',
        'reason' => 'crash-loop',
        'detail' => [
            'container' => 'sig-provider',
            'status' => 'Restarting (127) 5 seconds ago',
            'restarts' => 34005,
            'exit_code' => 127,
            'last_log' => 'error while loading shared libraries: libssl.so.3',
        ],
        'started_at' => now()->subDays(24),
        'notified_at' => now()->subDays(24),
    ]);

    $text = app(ProductDigest::class)->toText(days: 1);

    expect($text)->toContain('ТРЕБУЕТ ВНИМАНИЯ')
        ->and($text)->toContain('Blockscout sig-provider лежит 24 дн.: контейнер падает')
        ->and($text)->toContain('libssl.so.3')
        ->and(strpos($text, 'ТРЕБУЕТ ВНИМАНИЯ'))->toBeLessThan(strpos($text, 'Blockscout'));
});

it('counts the short failures nobody was paged about', function () {
    foreach ([8, 12] as $minutes) {
        ServiceIncident::create([
            'service' => 'host',
            'status' => 'degraded',
            'reason' => 'host-pressure',
            'started_at' => now()->subHours(3),
            'resolved_at' => now()->subHours(3)->addMinutes($minutes),
        ]);
    }

    expect(app(ProductDigest::class)->toText(days: 1))
        ->toContain('2 коротких сбоя (в среднем 10 мин, хост перегружен), без уведомлений');
});

it('reports a host that was heavy all day, and who was eating it', function () {
    foreach (range(1, 6) as $i) {
        ServiceCheck::create([
            'service' => 'host',
            'status' => 'up',
            'detail' => ['cpus' => 4, 'load' => [11.5, 11.0, 10.9]],
            'checked_at' => now()->subHours($i),
        ]);
    }

    ServiceHeartbeat::query()->create([
        'host' => 'cyber.main',
        'payload' => ['host' => 'cyber.main', 'top' => [['name' => 'backend', 'cpu' => 171.1, 'mem_mb' => 246]]],
        'reported_at' => now(),
    ]);

    $text = app(ProductDigest::class)->toText(days: 1);

    expect($text)->toContain('Хост загружен весь день: нагрузка в среднем 11 на 4 ядра (2.8 на ядро')
        ->and($text)->toContain('больше всего CPU: backend 171%');
});

it('says the money, the new holders and who they are', function () {
    activityTable();

    $whale = 'FjDZWDNZ'.str_repeat('a', 36);
    CrmContact::query()->create(['name' => 'Kirill', 'solana_address' => $whale, 'type' => 'investor', 'status' => 'active']);

    activity('pumpfun_buy', $whale, 425.05, ['meta' => 'new_holder']);
    activity('pumpfun_buy', 'HFKto1R6'.str_repeat('b', 36), 157.76, ['meta' => 'new_holder']);
    activity('pumpfun_buy', 'HFKto1R6'.str_repeat('b', 36), 46.60);
    activity('swap', '0x'.str_repeat('c', 40), 12.5);

    // Seen before the window: not a new address.
    activity('swap', '0x'.str_repeat('c', 40), 3.0, ['created_at' => Carbon::now('UTC')->subDays(3)->toDateTimeString()]);

    $text = app(ProductDigest::class)->toText(days: 1);

    expect($text)->toContain('4 действия от 3 адресов (2 новых), $642 (новое)')
        ->and($text)->toContain('pump.fun, покупки CYBER.sol: 3 на $629 от 2 адресов · 2 новых холдера · крупнейшая $425 — Kirill (FjDZWD…aaaa)')
        ->and($text)->toContain('Обмены: 1 на $12.50 от 1 адреса');
});

it('omits the telegram line when nobody new came', function () {
    botTables();

    DB::table('chat_members')->insert([
        'chat_id' => -100, 'user_id' => 1,
        'first_seen' => Carbon::now('UTC')->subMonth()->toDateTimeString(),
        'last_seen' => Carbon::now('UTC')->toDateTimeString(),
    ]);

    // 658 members yesterday and 658 today is not news.
    expect(app(ProductDigest::class)->toText(days: 1))->not->toContain('Telegram');
});

it('reports who joined the chats and linked a wallet', function () {
    // These tables belong to the Python bot (services/telegram-bot/bot/db.py),
    // which owns the same SQLite file. There is no Laravel migration for them,
    // so the test stands them up exactly as the bot does.
    botTables();

    foreach (range(1, 4) as $i) {
        DB::table('chat_members')->insert([
            'chat_id' => -100,
            'user_id' => $i,
            'first_seen' => Carbon::now('UTC')->subHours(3)->toDateTimeString(),
            'last_seen' => Carbon::now('UTC')->toDateTimeString(),
        ]);
    }

    DB::table('tg_wallets')->insert([
        'user_id' => 1,
        'address' => '0x'.str_repeat('a', 40),
        'created_at' => Carbon::now('UTC')->subHour()->toDateTimeString(),
    ]);

    expect(app(ProductDigest::class)->toText(days: 1))
        ->toContain('Telegram: +4 в чатах, +1 привязал кошелёк — с кошельком 1 из 4 (25%)');
});

it('escapes the report for telegram HTML', function () {
    expect(app(ProductDigest::class)->toTelegram(days: 1))
        ->toContain('<b>')
        ->and(app(ProductDigest::class)->toTelegram(days: 1))->toContain('📊');
});

/* ---------------------------------------------------------------- channel -- */

it('sends to every configured operator', function () {
    config()->set('services.telegram_ops.chat_id', '111, 222');

    expect(app(TelegramOpsNotifier::class)->send('hello'))->toBeTrue();

    expect(collect(telegramRequests())->map(fn ($r) => $r['chat_id'])->all())
        ->toEqualCanonicalizing(['111', '222']);
});

it('still delivers when one operator has not started the bot', function () {
    config()->set('services.telegram_ops.chat_id', '111 222');

    Http::fake(fn ($request) => ($request['chat_id'] ?? null) === '222'
        ? Http::response(['ok' => false, 'description' => 'chat not found'], 400)
        : Http::response(['ok' => true]));

    // True, because a human got it. An incident that re-alerts every five
    // minutes over the operator who has not pressed Start is worse than the
    // incident it is reporting.
    expect(app(TelegramOpsNotifier::class)->send('hello'))->toBeTrue();
});

it('sends the report to the analytics list when one is set', function () {
    config()->set('services.telegram_ops.chat_id', '111');
    config()->set('services.telegram_ops.analytics_chat_id', '333,444');

    expect(app(TelegramOpsNotifier::class)->recipients(TelegramOpsNotifier::ANALYTICS))
        ->toBe(['333', '444'])
        ->and(app(TelegramOpsNotifier::class)->recipients(TelegramOpsNotifier::OPS))
        ->toBe(['111']);
});

it('falls back to the alert list when no analytics list is set', function () {
    expect(app(TelegramOpsNotifier::class)->recipients(TelegramOpsNotifier::ANALYTICS))->toBe(['111']);
});

it('splits a report too long for one telegram message', function () {
    app(TelegramOpsNotifier::class)->sendTo('111', implode("\n", array_fill(0, 500, str_repeat('x', 40))));

    // 500 lines of 41 characters is ~20500, over a 3800-character limit.
    expect(telegramRequests())->toHaveCount(6);
});

it('prints without sending unless asked', function () {
    $this->artisan('analytics:digest --days=7')
        ->expectsOutputToContain('Cyberia · 7 дн.')
        ->assertSuccessful();

    expect(telegramRequests())->toBeEmpty();
});

it('sends when asked', function () {
    $this->artisan('analytics:digest --days=1 --send')->assertSuccessful();

    expect(telegramRequests())->toHaveCount(1)
        ->and(telegramRequests()[0]['chat_id'])->toBe('111')
        ->and((string) telegramRequests()[0]['text'])->toContain('Cyberia');
});

it('says so when there is nobody to send to', function () {
    config()->set('services.telegram_ops.chat_id', null);
    config()->set('services.telegram_ops.analytics_chat_id', null);

    $this->artisan('analytics:digest --send')
        ->expectsOutputToContain('Nobody to send to')
        ->assertSuccessful();

    expect(telegramRequests())->toBeEmpty();
});

it('says a task once on the day it falls due and folds the old backlog into one line', function () {
    $owner = User::factory()->create(['name' => 'netrunner']);

    foreach (['Old one', 'Older one'] as $i => $title) {
        CrmTask::query()->create([
            'title' => $title,
            'status' => 'open',
            'priority' => 'normal',
            'assigned_to_user_id' => $owner->id,
            'due_at' => now()->subDays(30 + $i),
        ]);
    }

    CrmTask::query()->create([
        'title' => 'Due this morning',
        'status' => 'open',
        'priority' => 'normal',
        'due_at' => now()->subHours(3),
    ]);

    $text = app(ProductDigest::class)->toText(days: 1);

    expect($text)->toContain('Сегодня просрочена задача «Due this morning»')
        ->and($text)->toContain('Давно просрочено задач: 2 (netrunner 2), самая старая — «Older one»')
        ->and($text)->not->toContain('«Old one»');
});
