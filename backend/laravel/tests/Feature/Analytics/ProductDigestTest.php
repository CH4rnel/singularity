<?php

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
 * makes it render: that it names the funnel's worst step, that a delta against
 * the previous window is attached to counts, that an empty population is a
 * missing section rather than a row of zeros, and — the reason this exists at
 * all — that a second operator who has not started the bot cannot silently
 * swallow the whole message.
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

it('names the worst step of the funnel', function () {
    // Three installs, all of which made a wallet, none of which funded it.
    foreach (range(1, 3) as $i) {
        analyticsUser(['wallet_created_at' => Carbon::now('UTC')->subHour()]);
    }

    $text = app(ProductDigest::class)->toText(days: 7);

    expect($text)->toContain('Узкое место')
        ->and($text)->toContain('Пополнен');
});

it('reports an empty funnel as empty rather than inventing a bottleneck', function () {
    $text = app(ProductDigest::class)->toText(days: 7);

    expect($text)->toContain('никто не открыл кошелёк')
        ->and($text)->not->toContain('Узкое место');
});

it('attaches a change against the previous window', function () {
    $user = User::factory()->create();

    // One XP award inside the window, none before it.
    DB::table('xp_entries')->insert([
        'user_id' => $user->id,
        'source' => 'visit',
        'reference' => 'day:'.Carbon::now('UTC')->toDateString(),
        'amount' => 10,
        'created_at' => Carbon::now('UTC')->subHours(2),
    ]);

    expect(app(ProductDigest::class)->toText(days: 1))->toContain('(новое)');
});

it('omits the telegram section when the bot has no members', function () {
    expect(app(ProductDigest::class)->toText(days: 7))->not->toContain('ТЕЛЕГРАМ');
});

it('reports the telegram population and how little of it has a wallet', function () {
    // These tables belong to the Python bot (services/telegram-bot/bot/db.py),
    // which owns the same SQLite file. There is no Laravel migration for them,
    // so the test stands them up exactly as the bot does — the digest reads
    // them through Schema::hasTable for the deploys where they are absent, and
    // that branch is covered by the test above.
    botTables();

    foreach (range(1, 4) as $i) {
        DB::table('chat_members')->insert([
            'chat_id' => -100,
            'user_id' => $i,
            'first_seen' => Carbon::now('UTC')->subDay(),
            'last_seen' => Carbon::now('UTC'),
        ]);
    }

    DB::table('tg_wallets')->insert([
        'user_id' => 1,
        'address' => '0x'.str_repeat('a', 40),
        'created_at' => Carbon::now('UTC'),
    ]);

    $text = app(ProductDigest::class)->toText(days: 7);

    expect($text)->toContain('Участников чатов: 4')
        ->and($text)->toContain('привязали кошелёк: 1 (25%)');
});

it('counts the people owed chat rewards that have nowhere to be paid', function () {
    botTables();

    DB::table('chat_members')->insert([
        'chat_id' => -100, 'user_id' => 7,
        'first_seen' => Carbon::now('UTC')->subDay(), 'last_seen' => Carbon::now('UTC'),
    ]);
    DB::table('pending_rewards')->insert([
        'chat_id' => -100, 'user_id' => 7,
        'amount' => '1000000000000000000', 'updated_at' => Carbon::now('UTC'),
    ]);

    expect(app(ProductDigest::class)->toText(days: 7))
        ->toContain('Ждут наград без кошелька: 1');
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
        ->expectsOutputToContain('СЕВЕРНАЯ ЗВЕЗДА')
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
