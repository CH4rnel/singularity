<?php

use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\User;
use App\Services\CrmSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/** Fake the Solana RPC: program detection + a getProgramAccounts holder set. */
function fakeHolderRpc(array $accounts = []): void
{
    Http::fake(function ($request) use ($accounts) {
        $method = $request->data()['method'] ?? '';
        if ($method === 'getAccountInfo') {
            return Http::response(['result' => ['value' => ['owner' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA']]]);
        }
        if ($method === 'getProgramAccounts') {
            return Http::response(['result' => $accounts]);
        }

        return Http::response([]);
    });
}

/** Shape one getProgramAccounts entry for an owner holding `amount` base units. */
function holderAccount(string $owner, string $amount): array
{
    return ['account' => ['data' => ['parsed' => ['info' => [
        'owner' => $owner,
        'tokenAmount' => ['amount' => $amount],
    ]]]]];
}

test('platform users are imported as contacts and linked back', function () {
    $user = User::factory()->create([
        'wallet_address' => '0x'.str_repeat('a', 40),
    ]);

    $imported = app(CrmSyncService::class)->importPlatformUsers();

    expect($imported)->toBe(1);
    $this->assertDatabaseHas('crm_contacts', [
        'user_id' => $user->id,
        'type' => 'holder',
        'source' => 'platform',
        'evm_address' => '0x'.str_repeat('a', 40),
    ]);
});

test('a user without a wallet is imported as a lead', function () {
    User::factory()->create(['wallet_address' => null, 'solana_wallet_address' => null]);

    app(CrmSyncService::class)->importPlatformUsers();

    $this->assertDatabaseHas('crm_contacts', ['type' => 'lead', 'source' => 'platform']);
});

test('bridge addresses are imported and deduplicated against platform users', function () {
    $evm = '0x'.str_repeat('b', 40);
    $user = User::factory()->create(['wallet_address' => $evm]);
    app(CrmSyncService::class)->importPlatformUsers();

    BridgeRequest::create([
        'user_id' => $user->id,
        'direction' => 'evm_to_sol',
        'token' => 'CYBER.sol',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0x'.str_repeat('1', 64),
        'source_nonce' => 1,
        'sender_address' => $evm,
        'recipient_address' => 'So1anaRecipientAddrrrrrrrrrrrrrrrrrrrrrrr11',
        'amount' => '1.5',
        'status' => 'completed',
    ]);

    $imported = app(CrmSyncService::class)->importBridgeUsers();

    // sender collapses into the existing platform contact; recipient is new.
    expect($imported)->toBe(2);
    expect(CrmContact::where('evm_address', $evm)->count())->toBe(1);
    $this->assertDatabaseHas('crm_contacts', [
        'solana_address' => 'So1anaRecipientAddrrrrrrrrrrrrrrrrrrrrrrr11',
        'source' => 'bridge',
    ]);
});

test('whales are imported from the bot table when present', function () {
    Schema::create('tg_sol_wallets', function ($table) {
        $table->id();
        $table->string('tg_user_id')->nullable();
        $table->string('solana_address')->nullable();
        $table->string('balance_raw')->nullable();
        $table->boolean('is_whale')->default(0);
    });

    DB::table('tg_sol_wallets')->insert([
        'tg_user_id' => '12345',
        'solana_address' => 'Wha1eAddrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr11',
        'balance_raw' => '15000000000000', // 15,000,000 with 6 decimals
        'is_whale' => 1,
    ]);

    $imported = app(CrmSyncService::class)->importWhales();

    expect($imported)->toBe(1);
    $this->assertDatabaseHas('crm_contacts', [
        'solana_address' => 'Wha1eAddrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr11',
        'type' => 'whale',
        'source' => 'whale_bot',
    ]);
});

test('importing whales is a no-op when the bot table is absent', function () {
    expect(app(CrmSyncService::class)->importWhales())->toBe(0);
});

test('the sync endpoint runs every importer', function () {
    fakeHolderRpc();
    $user = User::factory()->crmAdmin()->create();

    $this->actingAs($user)
        ->post(route('crm.sync'))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('crm_contacts', ['user_id' => $user->id]);
});

test('the balances-only command skips imports and refreshes the requested batch', function () {
    $sync = Mockery::mock(CrmSyncService::class);
    $sync->shouldNotReceive('syncAll');
    $sync->shouldReceive('refreshBalances')->once()->with(25)->andReturn(7);
    app()->instance(CrmSyncService::class, $sync);

    $this->artisan('crm:sync --balances-only --limit=25')
        ->expectsOutputToContain('Refreshed on-chain balances for 7 contacts.')
        ->assertSuccessful();
});

test('holder discovery and wallet balance refreshes are scheduled automatically', function () {
    $events = collect(app(Schedule::class)->events());

    $holderSync = $events->first(fn ($event) => str_contains((string) $event->command, 'crm:sync')
        && ! str_contains((string) $event->command, '--balances-only'));
    $walletRefresh = $events->first(fn ($event) => str_contains(
        (string) $event->command,
        'crm:sync --balances-only --limit=100',
    ));

    expect($holderSync !== null)->toBeTrue();
    expect($holderSync->expression)->toBe('0 0 * * *');
    expect($holderSync->withoutOverlapping)->toBeTrue();

    expect($walletRefresh !== null)->toBeTrue();
    expect($walletRefresh->expression)->toBe('*/30 * * * *');
    expect($walletRefresh->withoutOverlapping)->toBeTrue();
});

test('wallet refresh updates the oldest evm and solana contacts in one batch', function () {
    $evm = CrmContact::factory()->create([
        'evm_address' => '0x'.str_repeat('a', 40),
        'solana_address' => null,
        'last_synced_at' => now()->subHours(3),
    ]);
    $solana = CrmContact::factory()->create([
        'evm_address' => null,
        'solana_address' => 'So1anaWa11etrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr11',
        'last_synced_at' => now()->subHours(2),
    ]);
    $next = CrmContact::factory()->create([
        'evm_address' => '0x'.str_repeat('b', 40),
        'solana_address' => null,
        'last_synced_at' => now()->subHour(),
    ]);

    Http::fake(function ($request) {
        return match ($request->data()['method'] ?? '') {
            'eth_getBalance' => Http::response(['result' => '0xde0b6b3a7640000']),
            'getTokenAccountsByOwner' => Http::response(['result' => ['value' => [
                holderAccount('ignored-owner', '2500000'),
            ]]]),
            default => Http::response([], 500),
        };
    });

    $refreshed = app(CrmSyncService::class)->refreshBalances(2);

    expect($refreshed)->toBe(2)
        ->and($evm->refresh()->cyber_balance)->toBe('1.000000000000000000')
        ->and($solana->refresh()->cyber_sol_balance)->toBe('2.500000')
        ->and($next->refresh()->cyber_balance)->toBeNull();
});

test('on-chain holders are imported with balances and whale tier', function () {
    fakeHolderRpc([
        holderAccount('Ho1derSma11rrrrrrrrrrrrrrrrrrrrrrrrrrrrr11', '5000000000'),      // 5,000 CYBER.sol
        holderAccount('Wha1eBigggggggggggggggggggggggggggggggggg11', '15000000000000'), // 15,000,000 -> whale
        holderAccount('Emptyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy11', '0'),              // skipped
    ]);

    $imported = app(CrmSyncService::class)->importHolders();

    expect($imported)->toBe(2);
    $this->assertDatabaseHas('crm_contacts', [
        'solana_address' => 'Ho1derSma11rrrrrrrrrrrrrrrrrrrrrrrrrrrrr11',
        'type' => 'holder',
        'source' => 'holder',
    ]);
    $this->assertDatabaseHas('crm_contacts', [
        'solana_address' => 'Wha1eBigggggggggggggggggggggggggggggggggg11',
        'type' => 'whale',
    ]);
    $this->assertDatabaseMissing('crm_contacts', [
        'solana_address' => 'Emptyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy11',
    ]);
});

test('importing holders refreshes an existing contact without clobbering it', function () {
    $user = User::factory()->create([
        'name' => 'Known Trader',
        'solana_wallet_address' => 'Ho1derSma11rrrrrrrrrrrrrrrrrrrrrrrrrrrrr11',
    ]);
    app(CrmSyncService::class)->importPlatformUsers();

    fakeHolderRpc([
        holderAccount('Ho1derSma11rrrrrrrrrrrrrrrrrrrrrrrrrrrrr11', '15000000000000'),
    ]);
    app(CrmSyncService::class)->importHolders();

    // one contact, kept its name/source, refreshed balance + promoted to whale
    expect(CrmContact::where('solana_address', 'Ho1derSma11rrrrrrrrrrrrrrrrrrrrrrrrrrrrr11')->count())->toBe(1);
    $this->assertDatabaseHas('crm_contacts', [
        'solana_address' => 'Ho1derSma11rrrrrrrrrrrrrrrrrrrrrrrrrrrrr11',
        'name' => 'Known Trader',
        'source' => 'platform',
        'type' => 'whale',
    ]);
});
