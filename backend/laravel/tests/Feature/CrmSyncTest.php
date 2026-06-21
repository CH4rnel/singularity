<?php

use App\Models\BridgeRequest;
use App\Models\CrmContact;
use App\Models\User;
use App\Services\CrmSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    $user = User::factory()->create(['wallet_address' => '0x'.str_repeat('c', 40)]);

    $this->actingAs($user)
        ->post(route('crm.sync'))
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('crm_contacts', ['user_id' => $user->id]);
});
