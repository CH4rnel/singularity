<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->withoutVite();
});

it('serves a public profile at its on-chain nickname', function () {
    $user = User::factory()->create([
        'name' => 'Local name',
        'onchain_nickname' => 'cyberia_priest',
    ]);

    $this->get('/cyberia_priest')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile.id', $user->id)
            ->where('profile.name', 'cyberia_priest'));

    expect($user->profile_url)->toBe('/cyberia_priest');
});

it('permanently redirects a legacy id URL and preserves its query string', function () {
    $user = User::factory()->create([
        'onchain_nickname' => 'cyberia_priest',
    ]);

    $this->get(route('users.legacy', [
        'user' => $user,
        'posts' => 2,
    ]))
        ->assertStatus(Response::HTTP_MOVED_PERMANENTLY)
        ->assertRedirect(route('users.show', [
            'user' => 'cyberia_priest',
            'posts' => 2,
        ]));
});

it('keeps the legacy URL when no canonical nickname exists', function () {
    $user = User::factory()->create(['onchain_nickname' => null]);

    $this->get(route('users.legacy', $user))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile.id', $user->id));

    expect($user->profile_url)->toBe("/u/{$user->id}");
});

it('does not let a nickname shadow an application route', function () {
    $user = User::factory()->create(['onchain_nickname' => 'feed']);

    expect($user->profile_url)->toBe("/u/{$user->id}");

    $this->get(route('users.legacy', $user))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile.id', $user->id));

    $this->get('/feed')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Feed'));
});

it('returns not found for unknown and numeric canonical handles', function () {
    $this->get('/missing_handle')->assertNotFound();
    $this->get('/999999')->assertNotFound();
});

it('keeps cached on-chain nicknames unique', function () {
    User::factory()->create(['onchain_nickname' => 'unique_handle']);

    expect(fn () => User::factory()->create([
        'onchain_nickname' => 'unique_handle',
    ]))->toThrow(QueryException::class);
});

/**
 * The chain half of a public profile.
 *
 * The page showed a wall and a DAO feed on a platform whose whole point is
 * what happens on-chain, so somebody with hundreds of swaps looked like they
 * had done nothing. Two sources answer two questions: achievements are
 * permanent and cover all of history, the event feed is recent and says so.
 */
/**
 * The indexer's table, created the way the Telegram bot creates it.
 *
 * It belongs to another program that owns the same SQLite file and has no
 * Laravel migration, so the tests stand it up rather than skipping — the
 * controller's `Schema::hasTable` branch covers the deploys where it is
 * genuinely absent.
 */
function indexerTable(): void
{
    DB::statement('CREATE TABLE IF NOT EXISTS activity_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL, usd REAL,
        sym_in TEXT, amt_in REAL, sym_out TEXT, amt_out REAL,
        user_addr TEXT, tx_hash TEXT, block INTEGER, meta TEXT, created_at TEXT)');
}

function onchainEvent(string $address, string $kind, array $extra = []): void
{
    DB::table('activity_events')->insert(array_merge([
        'kind' => $kind,
        'user_addr' => $address,
        'tx_hash' => '0x'.bin2hex(random_bytes(32)),
        'created_at' => now(),
    ], $extra));
}

it('shows what the address did on-chain', function () {
    indexerTable();
    $address = '0x'.str_repeat('a', 40);
    $user = User::factory()->create(['wallet_address' => $address]);

    onchainEvent($address, 'swap', ['usd' => 12.5, 'sym_in' => 'CYBER', 'sym_out' => 'USDC']);
    onchainEvent($address, 'swap');
    onchainEvent($address, 'bridge');

    $this->get(route('users.legacy', $user))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onchain.kinds.swap', 2)
            ->where('onchain.kinds.bridge', 1)
            ->has('onchain.events', 3));
});

it('matches the address whatever case the indexer wrote it in', function () {
    indexerTable();
    $user = User::factory()->create(['wallet_address' => '0x'.str_repeat('a', 40)]);

    // The bot writes checksummed addresses; the column on users is lowercase.
    onchainEvent('0x'.str_repeat('A', 40), 'swap');

    $this->get(route('users.legacy', $user))
        ->assertInertia(fn (Assert $page) => $page->where('onchain.kinds.swap', 1));
});

it('says nothing on-chain for somebody with no wallet', function () {
    $user = User::factory()->create(['wallet_address' => null]);

    $this->get(route('users.legacy', $user))
        ->assertInertia(fn (Assert $page) => $page
            ->where('onchain.kinds', [])
            ->where('achievements', []));
});

it('does not attribute another address activity', function () {
    indexerTable();
    $user = User::factory()->create(['wallet_address' => '0x'.str_repeat('a', 40)]);
    onchainEvent('0x'.str_repeat('b', 40), 'swap');

    $this->get(route('users.legacy', $user))
        ->assertInertia(fn (Assert $page) => $page->where('onchain.kinds', []));
});
