<?php

use App\Http\Controllers\Api\WalletAccountController;
use App\Models\User;

/**
 * Deleting the account from inside the wallet.
 *
 * The rules worth pinning: it needs a session and a signature made moments
 * ago — never just a remembered cookie — and it deletes the account.
 */
it('refuses nobody', function () {
    $this->deleteJson('/api/wallet/account', ['confirm' => 'delete'])->assertUnauthorized();
});

it('refuses a session that has not signed recently', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.wallet_signed_at' => time() - WalletAccountController::FRESH_SECONDS - 1])
        ->deleteJson('/api/wallet/account', ['confirm' => 'delete'])
        ->assertStatus(423);

    $this->actingAs($user)
        ->deleteJson('/api/wallet/account', ['confirm' => 'delete'])
        ->assertStatus(423);

    expect($user->fresh())->not->toBeNull();
});

it('wants the word, not an accident', function () {
    $this->actingAs(User::factory()->create())
        ->withSession(['auth.wallet_signed_at' => time()])
        ->deleteJson('/api/wallet/account', ['confirm' => 'yes'])
        ->assertUnprocessable();
});

it('deletes the account after a fresh signature', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.wallet_signed_at' => time()])
        ->deleteJson('/api/wallet/account', ['confirm' => 'delete'])
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(User::query()->find($user->id))->toBeNull();
    $this->assertGuest();
});
